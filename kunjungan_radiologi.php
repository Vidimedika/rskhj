<?php
// Daftar kunjungan pemeriksaan radiologi dari SIMRS Khanza.
$host = '192.168.10.29';
$user = 'root';
$pass = '1nternet';
$db   = 'rskhj';

$koneksi = mysqli_connect($host, $user, $pass, $db);
if (!$koneksi) {
    die('Koneksi database gagal.');
}
mysqli_set_charset($koneksi, 'utf8');

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$validDate = static function ($value, $fallback) {
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
};
$today = date('Y-m-d');
$tglAwal = $validDate($_GET['tgl_awal'] ?? $today, $today);
$tglAkhir = $validDate($_GET['tgl_akhir'] ?? $today, $today);
$statusPasien = trim((string)($_GET['status_pasien'] ?? ''));
$unitPerujuk = trim((string)($_GET['unit_perujuk'] ?? ''));
$cari = trim((string)($_GET['cari'] ?? ''));
$action = $_GET['action'] ?? '';
$escape = static function ($value) use ($koneksi) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
};

$conditions = ['pr.tgl_periksa BETWEEN ? AND ?'];
$params = [$tglAwal, $tglAkhir];
$types = 'ss';

if ($statusPasien === 'Rajal') {
    $conditions[] = "(pr.status = 'Ralan' OR (pr.status IS NULL AND rp.status_lanjut = 'Ralan'))";
} elseif ($statusPasien === 'Ranap') {
    $conditions[] = "(pr.status = 'Ranap' OR (pr.status IS NULL AND rp.status_lanjut = 'Ranap'))";
}

if ($unitPerujuk !== '') {
    if (strncmp($unitPerujuk, 'poli:', 5) === 0) {
        $kdPoli = substr($unitPerujuk, 5);
        $conditions[] = "(pr.status = 'Ralan' OR (pr.status IS NULL AND rp.status_lanjut = 'Ralan')) AND rp.kd_poli = ?";
        $params[] = $kdPoli;
        $types .= 's';
    } elseif (strncmp($unitPerujuk, 'bangsal:', 8) === 0) {
        $kdBangsal = substr($unitPerujuk, 8);
        $conditions[] = "(pr.status = 'Ranap' OR (pr.status IS NULL AND rp.status_lanjut = 'Ranap')) AND (
            SELECT k_sub.kd_bangsal 
            FROM kamar_inap ki_sub 
            JOIN kamar k_sub ON k_sub.kd_kamar = ki_sub.kd_kamar 
            WHERE ki_sub.no_rawat = pr.no_rawat 
            ORDER BY ki_sub.tgl_masuk DESC, ki_sub.jam_masuk DESC 
            LIMIT 1
        ) = ?";
        $params[] = $kdBangsal;
        $types .= 's';
    }
}

if ($cari !== '') {
    $conditions[] = '(pr.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ? OR pasien.nm_pasien LIKE ? OR jns.nm_perawatan LIKE ? OR pol.nm_poli LIKE ?)';
    $keyword = '%' . $cari . '%';
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
    $types .= 'sssss';
}

$sql = "SELECT pr.no_rawat, rp.no_rkm_medis, pasien.nm_pasien,
               CASE 
                   WHEN pr.status = 'Ranap' THEN 'Ranap'
                   WHEN pr.status = 'Ralan' THEN 'Rajal'
                   WHEN rp.status_lanjut = 'Ranap' THEN 'Ranap'
                   WHEN rp.status_lanjut = 'Ralan' THEN 'Rajal'
                   ELSE '-'
               END AS jenis_pasien,
               COALESCE(
                   CASE 
                       WHEN (pr.status = 'Ranap' OR (pr.status IS NULL AND rp.status_lanjut = 'Ranap')) THEN (
                           SELECT CASE 
                               WHEN b.nm_bangsal IS NOT NULL AND b.nm_bangsal != '' AND ki.kd_kamar IS NOT NULL AND ki.kd_kamar != ''
                                   THEN CONCAT(b.nm_bangsal, ' (', ki.kd_kamar, ')')
                               WHEN b.nm_bangsal IS NOT NULL AND b.nm_bangsal != ''
                                   THEN b.nm_bangsal
                               ELSE ki.kd_kamar
                           END
                           FROM kamar_inap ki
                           LEFT JOIN kamar k ON k.kd_kamar = ki.kd_kamar
                           LEFT JOIN bangsal b ON b.kd_bangsal = k.kd_bangsal
                           WHERE ki.no_rawat = pr.no_rawat
                           ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC
                           LIMIT 1
                       )
                       ELSE pol.nm_poli
                   END,
                   pol.nm_poli,
                   rp.kd_poli,
                   '-'
               ) AS unit_perujuk,
               pr.kd_jenis_prw, jns.nm_perawatan, pj.png_jawab AS jenis_bayar,
               dp.nm_dokter AS dokter_rad, pr.nip, petugas.nama AS petugas_rad,
               pr.dokter_perujuk AS kd_dokter_perujuk,
               dperujuk.nm_dokter AS dokter_perujuk,
               pr.tgl_periksa, pr.jam, pr.biaya,
               CASE WHEN EXISTS (
                   SELECT 1 FROM gambar_radiologi gr
                   WHERE gr.no_rawat = pr.no_rawat
                     AND gr.tgl_periksa = pr.tgl_periksa
                     AND gr.jam = pr.jam
               ) THEN 'ada' ELSE '-' END AS status_foto,
               CASE WHEN EXISTS (
                   SELECT 1 FROM hasil_radiologi hr
                   WHERE hr.no_rawat = pr.no_rawat
                     AND hr.tgl_periksa = pr.tgl_periksa
                     AND hr.jam = pr.jam
               ) THEN 'ada' ELSE '-' END AS status_hasil
        FROM periksa_radiologi pr
        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
        INNER JOIN pasien ON pasien.no_rkm_medis = rp.no_rkm_medis
        LEFT JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli
        LEFT JOIN penjab pj ON pj.kd_pj = rp.kd_pj
        LEFT JOIN jns_perawatan_radiologi jns ON jns.kd_jenis_prw = pr.kd_jenis_prw
        LEFT JOIN dokter dp ON dp.kd_dokter = pr.kd_dokter
        LEFT JOIN petugas ON petugas.nip = pr.nip
        LEFT JOIN dokter dperujuk ON dperujuk.kd_dokter = pr.dokter_perujuk
        WHERE " . implode(' AND ', $conditions) . '
        ORDER BY pr.tgl_periksa DESC, pr.jam DESC, pr.no_rawat';
$stmt = mysqli_prepare($koneksi, $sql);
if (!$stmt) {
    die('Query daftar kunjungan radiologi gagal disiapkan: ' . htmlspecialchars(mysqli_error($koneksi), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$dataKunjungan = [];
$rekapRajal = [];
$rekapRanap = [];
$totalBiaya = 0;
$totalRajal = 0;
$totalRanap = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $dataKunjungan[] = $row;
    $biaya = (float)($row['biaya'] ?? 0);
    $totalBiaya += $biaya;
    $unit = trim((string)($row['unit_perujuk'] ?? '-'));
    if ($unit === '') {
        $unit = '-';
    }

    if ($row['jenis_pasien'] === 'Ranap') {
        $totalRanap++;
        if (!isset($rekapRanap[$unit])) {
            $rekapRanap[$unit] = ['jumlah' => 0, 'biaya' => 0];
        }
        $rekapRanap[$unit]['jumlah']++;
        $rekapRanap[$unit]['biaya'] += $biaya;
    } else {
        $totalRajal++;
        if (!isset($rekapRajal[$unit])) {
            $rekapRajal[$unit] = ['jumlah' => 0, 'biaya' => 0];
        }
        $rekapRajal[$unit]['jumlah']++;
        $rekapRajal[$unit]['biaya'] += $biaya;
    }
}
$totalKunjungan = count($dataKunjungan);

uasort($rekapRajal, function ($a, $b) {
    return $b['jumlah'] <=> $a['jumlah'];
});
uasort($rekapRanap, function ($a, $b) {
    return $b['jumlah'] <=> $a['jumlah'];
});

// Ambil daftar poliklinik dan bangsal untuk filter dropdown Unit Perujuk (hanya yang memiliki data kunjungan radiologi)
$daftarPoli = [];
$daftarBangsal = [];
if ($action !== 'export') {
    // 1. Ambil unit perujuk yang memiliki data transaksi radiologi pada periode tanggal yang dipilih
    $stmtPoli = mysqli_prepare($koneksi, "SELECT DISTINCT pol.kd_poli, pol.nm_poli 
        FROM periksa_radiologi pr 
        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat 
        INNER JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli 
        WHERE pr.tgl_periksa BETWEEN ? AND ?
          AND (pr.status = 'Ralan' OR (pr.status IS NULL AND rp.status_lanjut = 'Ralan'))
          AND pol.nm_poli IS NOT NULL AND pol.nm_poli != ''
        ORDER BY pol.nm_poli ASC");
    if ($stmtPoli) {
        mysqli_stmt_bind_param($stmtPoli, 'ss', $tglAwal, $tglAkhir);
        mysqli_stmt_execute($stmtPoli);
        $resPoli = mysqli_stmt_get_result($stmtPoli);
        while ($pRow = mysqli_fetch_assoc($resPoli)) {
            $daftarPoli[] = $pRow;
        }
        mysqli_stmt_close($stmtPoli);
    }

    $stmtBangsal = mysqli_prepare($koneksi, "SELECT DISTINCT k.kd_bangsal, b.nm_bangsal 
        FROM periksa_radiologi pr 
        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat 
        INNER JOIN kamar_inap ki ON ki.no_rawat = pr.no_rawat 
        INNER JOIN kamar k ON k.kd_kamar = ki.kd_kamar 
        INNER JOIN bangsal b ON b.kd_bangsal = k.kd_bangsal 
        WHERE pr.tgl_periksa BETWEEN ? AND ?
          AND (pr.status = 'Ranap' OR (pr.status IS NULL AND rp.status_lanjut = 'Ranap'))
          AND b.nm_bangsal IS NOT NULL AND b.nm_bangsal != ''
        ORDER BY b.nm_bangsal ASC");
    if ($stmtBangsal) {
        mysqli_stmt_bind_param($stmtBangsal, 'ss', $tglAwal, $tglAkhir);
        mysqli_stmt_execute($stmtBangsal);
        $resBangsal = mysqli_stmt_get_result($stmtBangsal);
        while ($bRow = mysqli_fetch_assoc($resBangsal)) {
            $daftarBangsal[] = $bRow;
        }
        mysqli_stmt_close($stmtBangsal);
    }

    // 2. Jika pada periode tanggal ini belum ada data sama sekali, fallback ke semua unit yang pernah memiliki data di periksa_radiologi
    if (empty($daftarPoli) && empty($daftarBangsal)) {
        $resAllPoli = mysqli_query($koneksi, "SELECT DISTINCT pol.kd_poli, pol.nm_poli 
            FROM periksa_radiologi pr 
            INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat 
            INNER JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli 
            WHERE (pr.status = 'Ralan' OR (pr.status IS NULL AND rp.status_lanjut = 'Ralan'))
              AND pol.nm_poli IS NOT NULL AND pol.nm_poli != ''
            ORDER BY pol.nm_poli ASC");
        if ($resAllPoli) {
            while ($pRow = mysqli_fetch_assoc($resAllPoli)) {
                $daftarPoli[] = $pRow;
            }
        }

        $resAllBangsal = mysqli_query($koneksi, "SELECT DISTINCT k.kd_bangsal, b.nm_bangsal 
            FROM periksa_radiologi pr 
            INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat 
            INNER JOIN kamar_inap ki ON ki.no_rawat = pr.no_rawat 
            INNER JOIN kamar k ON k.kd_kamar = ki.kd_kamar 
            INNER JOIN bangsal b ON b.kd_bangsal = k.kd_bangsal 
            WHERE (pr.status = 'Ranap' OR (pr.status IS NULL AND rp.status_lanjut = 'Ranap'))
              AND b.nm_bangsal IS NOT NULL AND b.nm_bangsal != ''
            ORDER BY b.nm_bangsal ASC");
        if ($resAllBangsal) {
            while ($bRow = mysqli_fetch_assoc($resAllBangsal)) {
                $daftarBangsal[] = $bRow;
            }
        }
    }

    // 3. Pastikan unit yang saat ini dipilih tetap ada di dropdown
    if ($unitPerujuk !== '') {
        if (strncmp($unitPerujuk, 'poli:', 5) === 0) {
            $currCode = substr($unitPerujuk, 5);
            $found = false;
            foreach ($daftarPoli as $p) {
                if ($p['kd_poli'] === $currCode) { $found = true; break; }
            }
            if (!$found) {
                $qCheck = mysqli_query($koneksi, "SELECT kd_poli, nm_poli FROM poliklinik WHERE kd_poli = '" . mysqli_real_escape_string($koneksi, $currCode) . "'");
                if ($qCheck && ($rowC = mysqli_fetch_assoc($qCheck))) {
                    $daftarPoli[] = $rowC;
                }
            }
        } elseif (strncmp($unitPerujuk, 'bangsal:', 8) === 0) {
            $currCode = substr($unitPerujuk, 8);
            $found = false;
            foreach ($daftarBangsal as $b) {
                if ($b['kd_bangsal'] === $currCode) { $found = true; break; }
            }
            if (!$found) {
                $qCheck = mysqli_query($koneksi, "SELECT kd_bangsal, nm_bangsal FROM bangsal WHERE kd_bangsal = '" . mysqli_real_escape_string($koneksi, $currCode) . "'");
                if ($qCheck && ($rowC = mysqli_fetch_assoc($qCheck))) {
                    $daftarBangsal[] = $rowC;
                }
            }
        }
    }
}

if ($action === 'export') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Kunjungan_Radiologi_' . $tglAwal . '_sd_' . $tglAkhir . '.xls"');
    echo "\xEF\xBB\xBF";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kunjungan Radiologi - RS Kartika Husada Jatiasih</title>
    <?php if ($action !== 'export'): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --kh-green: #00A859;         /* Warna Hijau Logo RS Kartika Husada */
            --kh-green-dark: #0f7541;    /* Warna Hijau Tua RS Kartika Husada */
            --kh-green-darker: #0b5730;  /* Warna Hijau Gelap Hover */
            --kh-green-light: #e2f7ec;   /* Background Aksen Hijau Muda */
            --kh-bg: #f4fbf7;            /* Background lembut bernuansa hijau terang */
            --kh-card-bg: #ffffff;
            --kh-text: #2c3e50;
        }

        body { 
            background: var(--kh-bg); 
            color: var(--kh-text); 
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 13px;
        }

        .page { 
            padding: 24px; 
            max-width: 100%;
        }

        .app-header {
            background: linear-gradient(135deg, #00A859 0%, #0f7541 100%);
            color: white;
            padding: 18px 22px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 14px rgba(15, 117, 65, 0.2);
        }

        .app-header h1 {
            font-size: 1.3rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 0.3px;
        }

        .app-header .hospital-name { 
            font-size: 1.1rem; 
            font-weight: 600; 
            margin-top: 4px; 
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .app-header .integration-note { 
            display: inline-block; 
            margin-top: 6px; 
            font-size: .75rem; 
            background: rgba(255, 255, 255, 0.22); 
            padding: 2px 9px; 
            border-radius: 4px; 
            font-weight: 500;
        }

        .filter { 
            background: var(--kh-card-bg); 
            border: 1px solid #d4ede1; 
            border-radius: 10px; 
            padding: 18px; 
            margin-bottom: 18px; 
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .form-label {
            font-weight: 600;
            color: #0f7541;
            font-size: 12px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #00A859;
            box-shadow: 0 0 0 0.2rem rgba(0, 168, 89, 0.2);
        }

        .table-wrap { 
            overflow: auto; 
            background: var(--kh-card-bg); 
            border: 1px solid #d4ede1; 
            border-radius: 10px; 
            max-height: 70vh; 
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        table { 
            white-space: nowrap; 
            margin-bottom: 0 !important;
        }

        thead th { 
            position: sticky; 
            top: 0; 
            z-index: 2; 
            background: #e2f7ec !important; 
            color: var(--kh-green-dark); 
            font-weight: 700;
            border-bottom: 2px solid #a8dfc2 !important;
            padding: 11px 12px;
            font-size: 12.5px;
        }

        tbody td {
            padding: 9px 12px;
            vertical-align: middle;
        }

        tbody tr:nth-child(even) td { 
            background: #f9fcfb; 
        }

        tbody tr:hover td {
            background: #edf7f2;
        }

        .count { 
            color: #0f7541; 
            font-size: 13px;
            display: flex;
            align-items: center;
        }

        .btn-primary {
            background-color: var(--kh-green);
            border-color: var(--kh-green);
            color: white;
            font-weight: 600;
        }

        .btn-primary:hover, .btn-primary:focus {
            background-color: var(--kh-green-dark);
            border-color: var(--kh-green-dark);
            color: white;
        }

        .btn-orange, .btn-excel {
            background-color: var(--kh-green-dark);
            border-color: var(--kh-green-dark);
            color: white;
            font-weight: 600;
        }

        .btn-orange:hover, .btn-orange:focus, .btn-excel:hover, .btn-excel:focus {
            background-color: var(--kh-green-darker);
            border-color: var(--kh-green-darker);
            color: white;
        }

        .badge-status-ada {
            background-color: #d4edda;
            color: #155724;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
        }

        .badge-status-none {
            color: #adb5bd;
            font-weight: 600;
        }

        .badge-rajal {
            background-color: #e2f7ec;
            color: #008043;
            border: 1px solid #bce8d2;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            display: inline-block;
        }

        .badge-ranap {
            background-color: #e7f5ee;
            color: #0f7541;
            border: 1px solid #c1e7d3;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            display: inline-block;
        }

        .stat-card {
            background: var(--kh-card-bg);
            border: 1px solid #d4ede1;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            height: 100%;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
            background: #e2f7ec;
            color: #0f7541;
        }

        .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .stat-val {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1.2;
            color: #0f7541;
        }

        .stat-sub {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .rekap-card {
            background: var(--kh-card-bg);
            border: 1px solid #d4ede1;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            height: 100%;
        }

        .rekap-header {
            background: #f4fbf7;
            border-bottom: 1px solid #d4ede1;
            padding: 10px 14px;
            font-size: 12.5px;
            color: #0f7541;
        }

        .rekap-card thead th {
            background: #e2f7ec !important;
            color: var(--kh-green-dark) !important;
            font-weight: 700;
            border-bottom: 2px solid #a8dfc2 !important;
        }
    </style>
    <?php endif; ?>
</head>
<body>
<main class="page">
    <?php if ($action !== 'export'): ?>
    <div class="app-header">
        <h1><i class="fa-solid fa-hospital-user me-2"></i>Rekapitulasi Kunjungan Pemeriksaan Radiologi</h1>
        <div class="hospital-name">RS Kartika Husada Jatiasih</div>
        <div class="integration-note"><i class="fa-solid fa-link me-1"></i> Terintegrasi dengan SIMRS Khanza</div>
    </div>

    <form class="filter row g-3 align-items-end" method="get">
        <div class="col-md-2">
            <label class="form-label mb-1" for="tgl_awal">Tanggal Awal</label>
            <input class="form-control form-control-sm" type="date" id="tgl_awal" name="tgl_awal" value="<?= $escape($tglAwal) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="tgl_akhir">Tanggal Akhir</label>
            <input class="form-control form-control-sm" type="date" id="tgl_akhir" name="tgl_akhir" value="<?= $escape($tglAkhir) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="status_pasien">Jenis Pasien</label>
            <select class="form-select form-select-sm" id="status_pasien" name="status_pasien">
                <option value="">Semua (Rajal/Ranap)</option>
                <option value="Rajal" <?= $statusPasien === 'Rajal' ? 'selected' : '' ?>>Rajal</option>
                <option value="Ranap" <?= $statusPasien === 'Ranap' ? 'selected' : '' ?>>Ranap</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="unit_perujuk">Unit Perujuk</label>
            <select class="form-select form-select-sm" id="unit_perujuk" name="unit_perujuk">
                <option value="">Semua Unit</option>
                <?php if (!empty($daftarPoli)): ?>
                <optgroup label="Rawat Jalan (Poli)" data-type="poli">
                    <?php foreach ($daftarPoli as $p): ?>
                        <option value="poli:<?= $escape($p['kd_poli']) ?>" <?= $unitPerujuk === 'poli:' . $p['kd_poli'] ? 'selected' : '' ?>>
                            <?= $escape($p['nm_poli']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if (!empty($daftarBangsal)): ?>
                <optgroup label="Rawat Inap (Kamar/Bangsal)" data-type="bangsal">
                    <?php foreach ($daftarBangsal as $b): ?>
                        <option value="bangsal:<?= $escape($b['kd_bangsal']) ?>" <?= $unitPerujuk === 'bangsal:' . $b['kd_bangsal'] ? 'selected' : '' ?>>
                            <?= $escape($b['nm_bangsal']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1" for="cari">Pencarian</label>
            <input class="form-control form-control-sm" type="search" id="cari" name="cari" value="<?= $escape($cari) ?>" placeholder="No. Rawat / RM / Pasien / Tindakan">
        </div>
        <div class="col-md-auto d-flex gap-2">
            <button class="btn btn-primary btn-sm px-3" type="submit"><i class="fa-solid fa-filter me-1"></i> Filter</button>
            <button class="btn btn-excel btn-sm px-3" type="submit" name="action" value="export"><i class="fa-solid fa-file-excel me-1"></i> Excel</button>
        </div>
        <div class="col-md-auto ms-auto count">
            <div>Jumlah Data: <strong class="ms-1 text-dark"><?= number_format($totalKunjungan, 0, ',', '.') ?></strong></div>
        </div>
    </form>

    <!-- KARTU STATISTIK RINGKASAN (TETAP PALING ATAS) -->
    <div class="row g-3 mb-3">
        <div class="col-md-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e2f7ec; color:#00A859;">
                    <i class="fa-solid fa-users-rays"></i>
                </div>
                <div>
                    <div class="stat-label">Total Pemeriksaan</div>
                    <div class="stat-val"><?= number_format($totalKunjungan, 0, ',', '.') ?></div>
                    <div class="stat-sub">Semua unit perujuk</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e2f7ec; color:#0f7541;">
                    <i class="fa-solid fa-person-walking"></i>
                </div>
                <div>
                    <div class="stat-label">Rawat Jalan (Rajal)</div>
                    <div class="stat-val"><?= number_format($totalRajal, 0, ',', '.') ?></div>
                    <div class="stat-sub"><?= $totalKunjungan > 0 ? number_format(($totalRajal / $totalKunjungan) * 100, 1) : 0 ?>% dari total</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e2f7ec; color:#008043;">
                    <i class="fa-solid fa-bed-pulse"></i>
                </div>
                <div>
                    <div class="stat-label">Rawat Inap (Ranap)</div>
                    <div class="stat-val"><?= number_format($totalRanap, 0, ',', '.') ?></div>
                    <div class="stat-sub"><?= $totalKunjungan > 0 ? number_format(($totalRanap / $totalKunjungan) * 100, 1) : 0 ?>% dari total</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e2f7ec; color:#0b5730;">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>
                <div>
                    <div class="stat-label">Total Biaya</div>
                    <div class="stat-val">Rp <?= number_format($totalBiaya, 0, ',', '.') ?></div>
                    <div class="stat-sub">Akumulasi tarif radiologi</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-wrap mb-4">
        <table class="table table-sm table-bordered table-hover mb-0" <?= $action === 'export' ? 'border="1"' : '' ?>>
            <thead>
                <tr>
                    <th class="text-center">No.</th>
                    <th>No. Rawat</th>
                    <th>No. R.M.</th>
                    <th>Nama Pasien</th>
                    <th class="text-center">Jenis Pasien</th>
                    <th>Unit Perujuk</th>
                    <th>Pemeriksaan</th>
                    <th>Jenis Bayar</th>
                    <th>Dokter P.J. Rad</th>
                    <th>Petugas Rad</th>
                    <th>Dokter Perujuk</th>
                    <th>Tanggal</th>
                    <th class="text-end">Harga</th>
                    <th class="text-center">Foto</th>
                    <th class="text-center">Hasil</th>
                </tr>
            </thead>
            <tbody>
            <?php $no = 1; foreach ($dataKunjungan as $row): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= $escape($row['no_rawat']) ?></td>
                    <td><?= $escape($row['no_rkm_medis']) ?></td>
                    <td class="fw-semibold"><?= $escape($row['nm_pasien']) ?></td>
                    <td class="text-center">
                        <?php if ($row['jenis_pasien'] === 'Rajal'): ?>
                            <span class="badge-rajal">Rajal</span>
                        <?php elseif ($row['jenis_pasien'] === 'Ranap'): ?>
                            <span class="badge-ranap">Ranap</span>
                        <?php else: ?>
                            <span class="badge-status-none"><?= $escape($row['jenis_pasien']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $escape($row['unit_perujuk']) ?></td>
                    <td><?= $escape($row['nm_perawatan']) ?></td>
                    <td><?= $escape($row['jenis_bayar']) ?></td>
                    <td><?= $escape($row['dokter_rad']) ?></td>
                    <td><?= $escape($row['petugas_rad']) ?></td>
                    <td><?= $escape($row['dokter_perujuk']) ?></td>
                    <td class="text-center"><?= $escape($row['tgl_periksa']) ?></td>
                    <td class="text-end"><?= number_format((float)$row['biaya'], 0, ',', '.') ?></td>
                    <td class="text-center">
                        <?php if ($row['status_foto'] === 'ada'): ?>
                            <span class="badge-status-ada">ada</span>
                        <?php else: ?>
                            <span class="badge-status-none">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($row['status_hasil'] === 'ada'): ?>
                            <span class="badge-status-ada">ada</span>
                        <?php else: ?>
                            <span class="badge-status-none">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($dataKunjungan)): ?>
                <tr><td colspan="15" class="text-center text-muted py-4">Tidak ada data kunjungan radiologi pada periode ini.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($action !== 'export'): ?>
    <!-- TABEL REKAP UNIT PERUJUK (BAGIAN BAWAH) -->
    <div class="row g-3 mb-2">
        <!-- REKAP RAWAT JALAN -->
        <div class="col-lg-6">
            <div class="rekap-card">
                <div class="rekap-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa-solid fa-stethoscope me-1 text-success"></i>
                        <strong>Rekap Unit Rawat Jalan (Poliklinik)</strong>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        <?= count($rekapRajal) ?> Poli &bull; <?= number_format($totalRajal, 0, ',', '.') ?> Kunjungan
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 45px;">No.</th>
                                <th>Poliklinik Asal</th>
                                <th class="text-center" style="width: 100px;">Jumlah</th>
                                <th class="text-end" style="width: 140px;">Total Biaya</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rekapRajal)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada data rawat jalan pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php $noRajal = 1; foreach ($rekapRajal as $namaPoli => $d): ?>
                                <tr>
                                    <td class="text-center"><?= $noRajal++ ?></td>
                                    <td class="fw-semibold"><?= $escape($namaPoli) ?></td>
                                    <td class="text-center fw-bold text-success"><?= number_format($d['jumlah'], 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($d['biaya'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                        <?php if (!empty($rekapRajal)): ?>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="text-end">Total Rawat Jalan:</td>
                                <td class="text-center text-success"><?= number_format($totalRajal, 0, ',', '.') ?></td>
                                <td class="text-end">Rp <?= number_format(array_sum(array_column($rekapRajal, 'biaya')), 0, ',', '.') ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- REKAP RAWAT INAP -->
        <div class="col-lg-6">
            <div class="rekap-card">
                <div class="rekap-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa-solid fa-hospital me-1 text-success"></i>
                        <strong>Rekap Unit Rawat Inap (Kamar / Bangsal)</strong>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        <?= count($rekapRanap) ?> Ruangan &bull; <?= number_format($totalRanap, 0, ',', '.') ?> Kunjungan
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 45px;">No.</th>
                                <th>Kamar / Bangsal Asal</th>
                                <th class="text-center" style="width: 100px;">Jumlah</th>
                                <th class="text-end" style="width: 140px;">Total Biaya</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rekapRanap)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada data rawat inap pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php $noRanap = 1; foreach ($rekapRanap as $namaKamar => $d): ?>
                                <tr>
                                    <td class="text-center"><?= $noRanap++ ?></td>
                                    <td class="fw-semibold"><?= $escape($namaKamar) ?></td>
                                    <td class="text-center fw-bold text-success"><?= number_format($d['jumlah'], 0, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format($d['biaya'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                        <?php if (!empty($rekapRanap)): ?>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="text-end">Total Rawat Inap:</td>
                                <td class="text-center text-success"><?= number_format($totalRanap, 0, ',', '.') ?></td>
                                <td class="text-end">Rp <?= number_format(array_sum(array_column($rekapRanap, 'biaya')), 0, ',', '.') ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- TABEL REKAPITULASI DI BAGIAN BAWAH PADA EKSPOR EXCEL -->
    <br><br>
    <table border="1" cellpadding="5" cellspacing="0" style="margin-bottom: 15px;">
        <tr style="background:#00A859; color:white; font-size:14px; text-align:center; font-weight:bold;">
            <th colspan="4">REKAPITULASI PEMERIKSAAN RADIOLOGI BERDASARKAN UNIT PERUJUK</th>
        </tr>
        <tr>
            <td colspan="4" align="center">Periode: <?= $escape($tglAwal) ?> s/d <?= $escape($tglAkhir) ?> &bull; Total: <?= $totalKunjungan ?> Pemeriksaan (Rajal: <?= $totalRajal ?>, Ranap: <?= $totalRanap ?>) &bull; Total Biaya: Rp <?= number_format($totalBiaya, 0, ',', '.') ?></td>
        </tr>
    </table>

    <table border="1" cellpadding="4" cellspacing="0" style="margin-bottom: 15px;">
        <thead>
            <tr style="background:#e2f7ec; color:#0f7541; font-weight:bold;">
                <th colspan="4">REKAP UNIT RAWAT JALAN (POLIKLINIK)</th>
            </tr>
            <tr style="background:#f1f5f9; font-weight:bold;">
                <th style="width:40px; text-align:center;">No.</th>
                <th>Poliklinik Asal</th>
                <th style="text-align:center;">Jumlah Kunjungan</th>
                <th style="text-align:right;">Total Biaya (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rekapRajal)): ?>
                <tr><td colspan="4" align="center">Tidak ada data rawat jalan.</td></tr>
            <?php else: ?>
                <?php $noR = 1; foreach ($rekapRajal as $pName => $d): ?>
                    <tr>
                        <td align="center"><?= $noR++ ?></td>
                        <td><?= $escape($pName) ?></td>
                        <td align="center"><?= $d['jumlah'] ?></td>
                        <td align="right"><?= $d['biaya'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background:#f8fafc; font-weight:bold;">
                    <td colspan="2" align="right">Total Rawat Jalan:</td>
                    <td align="center"><?= $totalRajal ?></td>
                    <td align="right"><?= array_sum(array_column($rekapRajal, 'biaya')) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <table border="1" cellpadding="4" cellspacing="0" style="margin-bottom: 20px;">
        <thead>
            <tr style="background:#e2f7ec; color:#0f7541; font-weight:bold;">
                <th colspan="4">REKAP UNIT RAWAT INAP (KAMAR / BANGSAL)</th>
            </tr>
            <tr style="background:#f1f5f9; font-weight:bold;">
                <th style="width:40px; text-align:center;">No.</th>
                <th>Kamar / Bangsal Asal</th>
                <th style="text-align:center;">Jumlah Kunjungan</th>
                <th style="text-align:right;">Total Biaya (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rekapRanap)): ?>
                <tr><td colspan="4" align="center">Tidak ada data rawat inap.</td></tr>
            <?php else: ?>
                <?php $noB = 1; foreach ($rekapRanap as $bName => $d): ?>
                    <tr>
                        <td align="center"><?= $noB++ ?></td>
                        <td><?= $escape($bName) ?></td>
                        <td align="center"><?= $d['jumlah'] ?></td>
                        <td align="right"><?= $d['biaya'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background:#f8fafc; font-weight:bold;">
                    <td colspan="2" align="right">Total Rawat Inap:</td>
                    <td align="center"><?= $totalRanap ?></td>
                    <td align="right"><?= array_sum(array_column($rekapRanap, 'biaya')) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
</main>
<?php if ($action !== 'export'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusPasienEl = document.getElementById('status_pasien');
    const unitPerujukEl = document.getElementById('unit_perujuk');
    if (statusPasienEl && unitPerujukEl) {
        function filterUnitOptions() {
            const status = statusPasienEl.value;
            const optgroups = unitPerujukEl.querySelectorAll('optgroup');
            optgroups.forEach(function (group) {
                const type = group.getAttribute('data-type');
                if (!status) {
                    group.style.display = '';
                    group.disabled = false;
                } else if (status === 'Rajal') {
                    if (type === 'poli') {
                        group.style.display = '';
                        group.disabled = false;
                    } else {
                        group.style.display = 'none';
                        group.disabled = true;
                    }
                } else if (status === 'Ranap') {
                    if (type === 'bangsal') {
                        group.style.display = '';
                        group.disabled = false;
                    } else {
                        group.style.display = 'none';
                        group.disabled = true;
                    }
                }
            });
            const selectedOption = unitPerujukEl.options[unitPerujukEl.selectedIndex];
            if (selectedOption && selectedOption.parentElement && selectedOption.parentElement.disabled) {
                unitPerujukEl.value = '';
            }
        }
        statusPasienEl.addEventListener('change', filterUnitOptions);
        filterUnitOptions();
    }
});
</script>
<?php endif; ?>
</body>
</html>
<?php
mysqli_stmt_close($stmt);
mysqli_close($koneksi);
?>
