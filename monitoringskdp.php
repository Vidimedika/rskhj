<?php
/**
 * -----------------------------------------------------------------------------
 * DASHBOARD MONITORING BOOKING REGISTRASI
 * RS KARTIKA HUSADA JATIASIH - SMART IT FOR HEALTH
 * -----------------------------------------------------------------------------
 */

// 1. KONEKSI DATABASE (PDO)
$host = "192.168.10.29";
$db   = "rskhj";
$user = "root";
$pass = "1nternet";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div style='padding:20px; color:#ef4444; font-family:sans-serif; background:#fef2f2; border:1px solid #fecaca; border-radius:6px;'><h3>Koneksi Database Gagal:</h3> " . htmlspecialchars($e->getMessage()) . "</div>");
}

// FUNGSI FORMAT TANGGAL INDONESIA
function tglIndo($tanggal) {
    if (empty($tanggal) || $tanggal == '0000-00-00') return "-";
    $hari = array(1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu');
    $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
    
    $split = explode('-', $tanggal);
    if (count($split) !== 3) return $tanggal;
    
    $tgl_indo = $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
    $num_hari = date('N', strtotime($tanggal));
    return $hari[$num_hari] . ", " . $tgl_indo;
}

// FUNGSI SANITASI NOMOR TELEPON
function formatNomorWA($nomor) {
    $nomor = preg_replace('/[^0-9]/', '', (string)$nomor);
    if (empty($nomor)) return '';

    if (substr($nomor, 0, 2) === '08') {
        $nomor = '628' . substr($nomor, 2);
    } elseif (substr($nomor, 0, 1) === '8') {
        $nomor = '628' . substr($nomor, 1);
    }

    return $nomor;
}

// 2. LOGIKA FILTER DASHBOARD KUNJUNGAN BPJS
$tgl_awal         = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir        = $_GET['tgl_akhir'] ?? date('Y-m-d');
$tgl_terbit_awal  = $_GET['tgl_terbit_awal'] ?? '';
$tgl_terbit_akhir = $_GET['tgl_terbit_akhir'] ?? '';
$kd_poli          = $_GET['kd_poli'] ?? '';
$status_pasien    = $_GET['status_pasien'] ?? '';
$keyword          = trim($_GET['keyword'] ?? '');

$conditions = [
    "pj.png_jawab LIKE '%BPJS%'",
    "pj.png_jawab NOT LIKE '%Alkes BPJS%'",
    "rp.status_lanjut IN ('Ralan', 'Ranap')",
    "pol.nm_poli NOT LIKE '%IGD%'",
    "pol.nm_poli NOT LIKE '%GAWAT DARURAT%'",
    "pol.nm_poli NOT LIKE '%Alkes BPJS%'",
    "rp.kd_poli NOT IN ('IGD', 'IGDK')",
    "rp.stts != 'Batal'"
];
$params = [];

// Filter Tanggal Kunjungan
if (!empty($tgl_awal) && !empty($tgl_akhir)) {
    $conditions[] = "rp.tgl_registrasi BETWEEN ? AND ?";
    $params[] = $tgl_awal;
    $params[] = $tgl_akhir;
}

// Filter Tanggal Terbit SKDP
if (!empty($tgl_terbit_awal) && !empty($tgl_terbit_akhir)) {
    $conditions[] = "skdp.tgl_surat BETWEEN ? AND ?";
    $params[] = $tgl_terbit_awal;
    $params[] = $tgl_terbit_akhir;
}

if ($status_pasien === 'Rajal') {
    $conditions[] = "rp.status_lanjut = ?";
    $params[] = 'Ralan';
} elseif ($status_pasien === 'Ranap') {
    $conditions[] = "rp.status_lanjut = ?";
    $params[] = 'Ranap';
}

if (!empty($keyword)) {
    $conditions[] = "(skdp.no_surat LIKE ? OR skdp.no_sep LIKE ? OR rp.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ? OR d.nm_dokter LIKE ? OR pj.png_jawab LIKE ?)";
    $wildcard = "%" . $keyword . "%";
    for ($i = 0; $i < 7; $i++) {
        $params[] = $wildcard;
    }
}

$poli_conditions = $conditions;
$poli_params = $params;

if (!empty($kd_poli)) {
    $conditions[] = "rp.kd_poli = ?";
    $params[] = $kd_poli;
}

$where_sql = count($conditions) > 0 ? " WHERE " . implode(" AND ", $conditions) : "";
$where_poli_sql = count($poli_conditions) > 0 ? " WHERE " . implode(" AND ", $poli_conditions) : "";

// 3. QUERY UTAMA KUNJUNGAN BPJS DENGAN OPTIMALISASI SUBQUERY
$sql = "SELECT 
            rp.no_rawat,
            rp.tgl_registrasi AS tgl_kunjungan,
            rp.no_rkm_medis,
            p.nm_pasien,
            COALESCE(
                CASE
                    WHEN LOWER(rp.status_lanjut) IN ('ralan', 'rajal', 'rawat jalan') THEN
                        (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(pr.instruksi), '') SEPARATOR ' | ')
                         FROM pemeriksaan_ralan pr
                         WHERE pr.no_rawat = rp.no_rawat)
                    ELSE NULL
                END,
                '-'
            ) AS keterangan,
            COALESCE(p.no_tlp, '') AS no_tlp,
            CASE
                WHEN LOWER(rp.status_lanjut) IN ('ralan', 'rajal', 'rawat jalan') THEN 'Rajal'
                WHEN LOWER(rp.status_lanjut) IN ('ranap', 'rawat inap') THEN 'Ranap'
                ELSE rp.status_lanjut
            END AS status_kunjungan,
            pj.png_jawab AS jenis_bayar,
            d.nm_dokter,
            pol.nm_poli,
            COALESCE(skdp.no_sep, '-') AS no_sep,
            COALESCE(skdp.no_surat, '-') AS no_surat,
            COALESCE(skdp.tgl_surat, '-') AS tgl_surat,
            COALESCE(skdp.tgl_rencana, '-') AS tgl_rencana,
            IF(skdp.no_surat IS NULL, '-', 'Ada') AS status_skdp
        FROM reg_periksa rp
        INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
        INNER JOIN penjab pj ON rp.kd_pj = pj.kd_pj
        INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
        INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
        LEFT JOIN (
            SELECT
                sep.no_rawat,
                GROUP_CONCAT(DISTINCT sep.no_sep ORDER BY sep.no_sep SEPARATOR ', ') AS no_sep,
                GROUP_CONCAT(DISTINCT sk.no_surat SEPARATOR ', ') AS no_surat,
                MAX(sk.tgl_surat) AS tgl_surat,
                MAX(sk.tgl_rencana) AS tgl_rencana
            FROM bridging_sep sep
            LEFT JOIN bridging_surat_kontrol_bpjs sk ON sk.no_sep = sep.no_sep
            GROUP BY sep.no_rawat
        ) skdp ON skdp.no_rawat = rp.no_rawat
        $where_sql
        ORDER BY rp.tgl_registrasi DESC, rp.jam_reg DESC, rp.no_rawat DESC";

// DROPDOWN FILTER POLIKLINIK
$sql_list_poli = "SELECT DISTINCT pol.kd_poli, pol.nm_poli
                  FROM reg_periksa rp
                  INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                  INNER JOIN penjab pj ON rp.kd_pj = pj.kd_pj
                  INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
                  INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
                  LEFT JOIN (
                      SELECT
                          sep.no_rawat,
                          GROUP_CONCAT(DISTINCT sep.no_sep SEPARATOR ', ') AS no_sep,
                          GROUP_CONCAT(DISTINCT sk.no_surat SEPARATOR ', ') AS no_surat,
                          MAX(sk.tgl_surat) AS tgl_surat
                      FROM bridging_sep sep
                      LEFT JOIN bridging_surat_kontrol_bpjs sk ON sk.no_sep = sep.no_sep
                      GROUP BY sep.no_rawat
                  ) skdp ON skdp.no_rawat = rp.no_rawat
                  $where_poli_sql
                  ORDER BY pol.nm_poli ASC";
$stmt_list_poli = $conn->prepare($sql_list_poli);
$stmt_list_poli->execute($poli_params);
$list_poli = $stmt_list_poli->fetchAll();

// 4. EXPORT EXCEL (.xls)
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    $stmt_export = $conn->prepare($sql);
    $stmt_export->execute($params);
    $rows_export = $stmt_export->fetchAll();

    $filename = "Monitoring_Kunjungan_BPJS_" . date('Ymd_His') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Cache-Control: max-age=0");

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Kunjungan BPJS</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
    echo '<body>';
    echo '<table border="1" style="border-collapse:collapse;">';
    echo '<tr style="background-color:#15803d; color:#ffffff; font-weight:bold; text-align:center;">';
    echo '<th>No</th><th>Tgl Kunjungan</th><th>Status Pasien</th><th>Jenis Bayar</th><th>No SEP</th><th>No SKDP</th><th>No Rawat</th><th>Tgl Terbit SKDP</th><th>Tgl Rencana Kontrol</th><th>No RM</th><th>Nama Pasien</th><th>No Telp</th><th>Dokter</th><th>Poli</th><th>Status SKDP</th><th>Keterangan</th>';
    echo '</tr>';

    $no_exp = 1;
    foreach ($rows_export as $r) {
        echo '<tr>';
        echo '<td style="text-align:center;">' . $no_exp++ . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars(tglIndo($r['tgl_kunjungan'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['status_kunjungan']) . '</td>';
        echo '<td>' . htmlspecialchars($r['jenis_bayar']) . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_sep']) . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_surat']) . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_rawat']) . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars($r['tgl_surat']) . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars(tglIndo($r['tgl_rencana'])) . '</td>';
        echo '<td style="mso-number-format:\'\@\'; text-align:center;">' . htmlspecialchars($r['no_rkm_medis']) . '</td>';
        echo '<td>' . htmlspecialchars($r['nm_pasien']) . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars(formatNomorWA($r['no_tlp'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['nm_dokter']) . '</td>';
        echo '<td>' . htmlspecialchars($r['nm_poli']) . '</td>';
        echo '<td>' . htmlspecialchars($r['status_skdp']) . '</td>';
        echo '<td>' . htmlspecialchars($r['keterangan']) . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

// EKSEKUSI DATA UTAMA
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total_kunjungan = count($rows);
$total_skdp = count(array_filter($rows, static function ($row) {
    return $row['status_skdp'] === 'Ada';
}));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring SKDP Pasien BPJS - RS Kartika Husada Jatiasih</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Tema Warna RS Kartika Husada: Hijau & Oranye */
            --primary: #15803d;         /* Hijau Utama */
            --primary-dark: #166534;    /* Hijau Gelap */
            --primary-light: #f0fdf4;   /* Hijau Sangat Muda (Background Form) */
            --accent: #f97316;          /* Oranye Utama (Aksen / Kartu Statistik) */
            --accent-light: #fff7ed;    /* Oranye Muda */
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-body: #f8fafc;
            --white: #ffffff;
        }

        * { box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px; background: var(--bg-body); margin: 0; padding: 24px; color: var(--text-main); }
        
        .container { background: var(--white); padding: 28px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05); max-width: 1440px; margin: 0 auto; border: 1px solid var(--border-color); border-top: 5px solid var(--primary); }
        
        .header-title { text-align: center; margin-bottom: 24px; }
        h2 { margin: 0 0 6px 0; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; font-size: 22px; }
        h4 { margin: 0; color: var(--text-muted); font-weight: 500; font-size: 13px; letter-spacing: 0.3px; }
        
        .summary-container { display: flex; gap: 20px; margin-bottom: 24px; }
        .stat-box { flex: 1; padding: 18px 20px; border-radius: 10px; border: 1px solid var(--border-color); text-align: left; background: var(--white); box-shadow: 0 2px 4px rgba(0,0,0,0.01); display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .stat-box::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: var(--accent); }
        .stat-box.box-total::before { background: var(--primary); }
        .stat-box small { font-size: 11px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-box div { font-size: 26px; font-weight: 700; margin-top: 6px; color: var(--text-main); }
        
        form { background: var(--primary-light); padding: 20px; border-radius: 10px; border: 1px solid #dcfce7; margin-bottom: 24px; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        label { font-weight: 600; font-size: 11px; text-transform: uppercase; color: var(--primary-dark); letter-spacing: 0.3px; }
        input[type="date"], input[type="text"], select { padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; background: var(--white); font-size: 12px; outline: none; transition: all 0.2s; color: var(--text-main); }
        input[type="date"]:focus, input[type="text"]:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(21, 128, 61, 0.1); }
        
        .btn-group { display: flex; gap: 8px; align-items: flex-end; margin-left: auto; flex-wrap: wrap; }
        .btn { padding: 9px 16px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .btn-submit { background: var(--primary); color: white; }
        .btn-submit:hover { background: var(--primary-dark); }
        .btn-export { background: #ea580c; color: white; } /* Tombol Oranye untuk Export Excel */
        .btn-export:hover { background: #c2410c; }
        .btn-reset { background: #475569; color: white; }
        .btn-reset:hover { background: #334155; }
        
        .table-responsive { max-height: 580px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; }
        table { border-collapse: collapse; width: 100%; background: var(--white); }
        th, td { border: 1px solid var(--border-color); padding: 11px 14px; text-align: center; }
        th { background: var(--primary); color: #fff; font-weight: 600; text-transform: uppercase; font-size: 11px; position: sticky; top: 0; z-index: 10; letter-spacing: 0.4px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        tr:hover { background-color: #f1f5f9; }
        
        .date-cell { white-space: nowrap; line-height: 1.4; }
        .date-cell .date-weekday { display: block; color: var(--accent); font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .date-cell .date-value { display: block; font-weight: 600; color: var(--text-main); }
        .text-nowrap { white-space: nowrap; }
        
        .badge-surat { font-weight: 700; color: #c2410c; background: var(--accent-light); padding: 4px 8px; border-radius: 4px; font-family: monospace; border: 1px solid #fed7aa; }
        .badge-sep { font-weight: 600; color: #334155; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-family: monospace; border: 1px solid #cbd5e1; }

        .footer { text-align: center; font-size: 11px; color: var(--text-muted); margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 18px; line-height: 1.6; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-title">
        <h2>Monitoring SKDP Pasien BPJS RS Kartika Husada Jatiasih</h2>
        <h4>Terintegrasi dengan SIMRS KHANZA &bull; Smart IT for Health</h4>
    </div>

    <!-- KARTU RINGKASAN REKAPITULASI STATISTIK -->
    <div class="summary-container">
        <div class="stat-box box-total">
            <small>Total Kunjungan BPJS</small>
            <div><?= number_format($total_kunjungan, 0, ',', '.') ?></div>
        </div>
        <div class="stat-box">
            <small>Total SKDP Ditemukan</small>
            <div style="color: var(--accent);"><?= number_format($total_skdp, 0, ',', '.') ?></div>
        </div>
    </div>

    <form method="get" action="">
        <div class="filter-row">
            <div class="filter-group">
                <label>Periode Tgl Kunjungan</label>
                <div>
                    <input type="date" name="tgl_awal" value="<?= htmlspecialchars($tgl_awal) ?>"> s/d 
                    <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($tgl_akhir) ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Tanggal Terbit SKDP</label>
                <div>
                    <input type="date" name="tgl_terbit_awal" value="<?= htmlspecialchars($tgl_terbit_awal) ?>"> s/d 
                    <input type="date" name="tgl_terbit_akhir" value="<?= htmlspecialchars($tgl_terbit_akhir) ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Poliklinik Tujuan</label>
                <select name="kd_poli" style="min-width: 170px;">
                    <option value="">-- Semua Poliklinik --</option>
                    <?php foreach ($list_poli as $p): ?>
                        <option value="<?= htmlspecialchars($p['kd_poli']) ?>" <?= $kd_poli === $p['kd_poli'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['nm_poli']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Status Pasien</label>
                <select name="status_pasien">
                    <option value="">-- Rajal &amp; Ranap --</option>
                    <option value="Rajal" <?= $status_pasien === 'Rajal' ? 'selected' : '' ?>>Rajal</option>
                    <option value="Ranap" <?= $status_pasien === 'Ranap' ? 'selected' : '' ?>>Ranap</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Pencarian Keyword</label>
                <input type="text" name="keyword" placeholder="No Rawat / SKDP / SEP / RM / Pasien" value="<?= htmlspecialchars($keyword) ?>" style="width: 210px;">
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-submit"><i class="fa-solid fa-filter"></i> Tampilkan</button>
                <button type="submit" name="action" value="export_excel" class="btn btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
                <a href="booking.php" class="btn btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width: 45px;">No</th>
                    <th>Tgl. Kunjungan</th>
                    <th>Status</th>
                    <th>Jenis Bayar</th>
                    <th>No. SEP</th>
                    <th>No. SKDP</th>
                    <th>No. Rawat</th>
                    <th>Tgl. Terbit SKDP</th>
                    <th>Tgl. Rencana Kontrol</th>
                    <th>No. RM</th>
                    <th>Nama Pasien</th>
                    <th>No. Telp / HP</th>
                    <th>Dokter Penanggung Jawab</th>
                    <th>Poliklinik</th>
                    <th>Status SKDP</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="16" style="padding: 30px; color: var(--text-muted); font-style: italic;">Data kunjungan BPJS tidak ditemukan untuk kriteria filter ini.</td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($rows as $row): 
                        $tgl_periksa_parts = explode(', ', tglIndo($row['tgl_rencana']), 2);
                        $tgl_kunjungan_parts = explode(', ', tglIndo($row['tgl_kunjungan']), 2);
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td class="date-cell">
                                <?php if (count($tgl_kunjungan_parts) === 2): ?>
                                    <span class="date-weekday"><?= htmlspecialchars($tgl_kunjungan_parts[0]) ?></span>
                                    <span class="date-value"><?= htmlspecialchars($tgl_kunjungan_parts[1]) ?></span>
                                <?php else: ?>
                                    <span class="date-value"><?= htmlspecialchars($tgl_kunjungan_parts[0]) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span style="font-weight: 600;"><?= htmlspecialchars($row['status_kunjungan']) ?></span></td>
                            <td><?= htmlspecialchars($row['jenis_bayar']) ?></td>
                            <td><span class="badge-sep"><?= htmlspecialchars($row['no_sep']) ?></span></td>
                            <td><span class="badge-surat"><?= htmlspecialchars($row['no_surat']) ?></span></td>
                            <td style="font-family: monospace;"><?= htmlspecialchars($row['no_rawat']) ?></td>
                            <td><?= htmlspecialchars($row['tgl_surat']) ?></td>
                            <td class="date-cell">
                                <?php if (count($tgl_periksa_parts) === 2): ?>
                                    <span class="date-weekday"><?= htmlspecialchars($tgl_periksa_parts[0]) ?></span>
                                    <span class="date-value"><?= htmlspecialchars($tgl_periksa_parts[1]) ?></span>
                                <?php else: ?>
                                    <span class="date-value"><?= htmlspecialchars($tgl_periksa_parts[0]) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><b><?= htmlspecialchars($row['no_rkm_medis']) ?></b></td>
                            <td class="text-nowrap" style="text-align: left; font-weight: 600;"><?= htmlspecialchars($row['nm_pasien']) ?></td>
                            <td><?= htmlspecialchars(formatNomorWA($row['no_tlp'])) ?></td>
                            <td class="text-nowrap" style="text-align: left;"><?= htmlspecialchars($row['nm_dokter']) ?></td>
                            <td class="text-nowrap" style="text-align: left;"><?= htmlspecialchars($row['nm_poli']) ?></td>
                            <td>
                                <?php if($row['status_skdp'] === 'Ada'): ?>
                                    <span style="color: #15803d; font-weight: 700; background: #f0fdf4; padding: 3px 8px; border-radius: 4px; border: 1px solid #bbf7d0;">Ada</span>
                                <?php else: ?>
                                    <span style="color: #64748b; font-weight: 600;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap" style="text-align: left; max-width: 220px; white-space: normal;">
                                <?= htmlspecialchars($row['keterangan'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        &copy; <?= date('Y'); ?> PT VIDI MEDIKA PRATAMA. All Rights Reserved.<br>
        <i>Smart IT for Health &bull; SIMRS Khanza Ecosystem</i>
    </div>
</div>

</body>
</html>