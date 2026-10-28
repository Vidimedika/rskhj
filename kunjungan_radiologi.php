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

$validDate = static function ($value, $fallback) {
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
};
$today = date('Y-m-d');
$tglAwal = $validDate($_GET['tgl_awal'] ?? $today, $today);
$tglAkhir = $validDate($_GET['tgl_akhir'] ?? $today, $today);
$cari = trim((string)($_GET['cari'] ?? ''));
$action = $_GET['action'] ?? '';
$escape = static function ($value) use ($koneksi) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
};

$conditions = ['pr.tgl_periksa BETWEEN ? AND ?'];
$params = [$tglAwal, $tglAkhir];
$types = 'ss';
if ($cari !== '') {
    $conditions[] = '(pr.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ? OR pasien.nm_pasien LIKE ? OR jns.nm_perawatan LIKE ?)';
    $keyword = '%' . $cari . '%';
    array_push($params, $keyword, $keyword, $keyword, $keyword);
    $types .= 'ssss';
}

$sql = "SELECT pr.no_rawat, rp.no_rkm_medis, pasien.nm_pasien,
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
            --kh-green-dark: #008043;    /* Warna Hijau Gelap (Hover) */
            --kh-orange: #F15A24;        /* Warna Oranye Logo RS Kartika Husada */
            --kh-orange-dark: #d04515;   /* Warna Oranye Gelap (Hover) */
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
            background: linear-gradient(135deg, var(--kh-green), var(--kh-orange));
            color: white;
            padding: 18px 22px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 168, 89, 0.15);
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
            color: #2e6648;
            font-size: 12px;
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
            color: #437359; 
            font-size: 13px;
            display: flex;
            align-items: center;
        }

        .btn-primary {
            background-color: var(--kh-green);
            border-color: var(--kh-green);
            font-weight: 600;
        }

        .btn-primary:hover {
            background-color: var(--kh-green-dark);
            border-color: var(--kh-green-dark);
        }

        .btn-orange {
            background-color: var(--kh-orange);
            border-color: var(--kh-orange);
            color: white;
            font-weight: 600;
        }

        .btn-orange:hover {
            background-color: var(--kh-orange-dark);
            border-color: var(--kh-orange-dark);
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
        <div class="col-md-3">
            <label class="form-label mb-1" for="cari">Pencarian</label>
            <input class="form-control form-control-sm" type="search" id="cari" name="cari" value="<?= $escape($cari) ?>" placeholder="No. Rawat / RM / Pasien / Tindakan">
        </div>
        <div class="col-md-auto d-flex gap-2">
            <button class="btn btn-primary btn-sm px-3" type="submit"><i class="fa-solid fa-filter me-1"></i> Filter</button>
            <button class="btn btn-orange btn-sm px-3" type="submit" name="action" value="export"><i class="fa-solid fa-file-excel me-1"></i> Excel</button>
        </div>
        <div class="col-md-auto ms-auto count">
            <div>Jumlah Data: <strong class="ms-1 text-dark"><?= number_format(mysqli_num_rows($result), 0, ',', '.') ?></strong></div>
        </div>
    </form>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="table table-sm table-bordered table-hover mb-0" <?= $action === 'export' ? 'border="1"' : '' ?>>
            <thead>
                <tr>
                    <th class="text-center">No.</th>
                    <th>No. Rawat</th>
                    <th>No. R.M.</th>
                    <th>Nama Pasien</th>
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
            <?php $no = 1; while ($row = mysqli_fetch_assoc($result)): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= $escape($row['no_rawat']) ?></td>
                    <td><?= $escape($row['no_rkm_medis']) ?></td>
                    <td class="fw-semibold"><?= $escape($row['nm_pasien']) ?></td>
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
            <?php endwhile; ?>
            <?php if ($no === 1): ?>
                <tr><td colspan="13" class="text-center text-muted py-4">Tidak ada data kunjungan radiologi pada periode ini.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
<?php
mysqli_stmt_close($stmt);
mysqli_close($koneksi);
?>
