<?php
/**
 * Rekapitulasi Pemeriksaan Radiologi - RS Kartika Husada Jatiasih
 * Terintegrasi dengan SIMRS Khanza
 * 
 * Fitur:
 * - Daftar pemeriksaan radiologi dengan filter tanggal, status ekspertisi, dan pencarian
 * - Status ekspertisi radiologi (Sudah Terbaca / Belum Terbaca)
 * - Status foto dan hasil radiologi
 * - Summary statistik
 * - Export ke Excel
 */

// 1. KONEKSI DATABASE SIMRS Khanza
$host = '192.168.10.29';
$user = 'root';
$pass = '1nternet';
$db   = 'rskhj';

$koneksi = mysqli_connect($host, $user, $pass, $db);
if (!$koneksi) {
    die('Koneksi database gagal: ' . mysqli_connect_error());
}
mysqli_set_charset($koneksi, 'utf8');

// 2. HELPER FUNCTIONS
$validDate = static function ($value, $fallback) {
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
};
$escape = static function ($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
};

// 3. FILTER PARAMETERS
$today     = date('Y-m-d');
$tglAwal   = $validDate($_GET['tgl_awal'] ?? $today, $today);
$tglAkhir  = $validDate($_GET['tgl_akhir'] ?? $today, $today);
$cari      = trim((string)($_GET['cari'] ?? ''));
$filterEks = $_GET['filter_ekspertisi'] ?? ''; // 'sudah', 'belum', atau '' (semua)
$action    = $_GET['action'] ?? '';

// 4. BUILD QUERY
$conditions = ['pr.tgl_periksa BETWEEN ? AND ?'];
$params     = [$tglAwal, $tglAkhir];
$types      = 'ss';

if ($cari !== '') {
    $conditions[] = '(pr.no_rawat LIKE ? OR rp.no_rkm_medis LIKE ? OR pasien.nm_pasien LIKE ? OR jns.nm_perawatan LIKE ? OR dp.nm_dokter LIKE ?)';
    $keyword = '%' . $cari . '%';
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
    $types .= 'sssss';
}

// Filter ekspertisi via HAVING clause
$havingClause = '';
if ($filterEks === 'sudah') {
    $havingClause = 'HAVING status_ekspertisi = \'Sudah Dibaca\'';
} elseif ($filterEks === 'belum') {
    $havingClause = 'HAVING status_ekspertisi = \'Belum Dibaca\'';
}

$sql = "SELECT pr.no_rawat, rp.no_rkm_medis, pasien.nm_pasien,
               pr.kd_jenis_prw, jns.nm_perawatan, pj.png_jawab AS jenis_bayar,
               dp.nm_dokter AS dokter_rad, pr.nip, petugas.nama AS petugas_rad,
               pr.dokter_perujuk AS kd_dokter_perujuk,
               dperujuk.nm_dokter AS dokter_perujuk,
               pr.tgl_periksa, pr.jam, pr.biaya,
               rp.kd_pj,
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
               ) THEN 'ada' ELSE '-' END AS status_hasil,
               CASE WHEN EXISTS (
                   SELECT 1 FROM hasil_radiologi hr
                   WHERE hr.no_rawat = pr.no_rawat
                     AND hr.tgl_periksa = pr.tgl_periksa
                     AND hr.jam = pr.jam
                     AND hr.hasil <> ''
               ) THEN 'Sudah Dibaca' ELSE 'Belum Dibaca' END AS status_ekspertisi
        FROM periksa_radiologi pr
        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
        INNER JOIN pasien ON pasien.no_rkm_medis = rp.no_rkm_medis
        LEFT JOIN penjab pj ON pj.kd_pj = rp.kd_pj
        LEFT JOIN jns_perawatan_radiologi jns ON jns.kd_jenis_prw = pr.kd_jenis_prw
        LEFT JOIN dokter dp ON dp.kd_dokter = pr.kd_dokter
        LEFT JOIN petugas ON petugas.nip = pr.nip
        LEFT JOIN dokter dperujuk ON dperujuk.kd_dokter = pr.dokter_perujuk
        WHERE " . implode(' AND ', $conditions) . "
        $havingClause
        ORDER BY pr.tgl_periksa DESC, pr.jam DESC, pr.no_rawat";

$stmt = mysqli_prepare($koneksi, $sql);
if (!$stmt) {
    die('Query gagal disiapkan: ' . htmlspecialchars(mysqli_error($koneksi), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Collect all rows for statistics
$rows = [];
$totalBiaya = 0;
$totalSudah = 0;
$totalBelum = 0;
$totalFoto  = 0;
$totalHasil = 0;
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
    $totalBiaya += (float)$row['biaya'];
    if ($row['status_ekspertisi'] === 'Sudah Dibaca') $totalSudah++;
    else $totalBelum++;
    if ($row['status_foto'] === 'ada') $totalFoto++;
    if ($row['status_hasil'] === 'ada') $totalHasil++;
}
$totalData = count($rows);

// 5. EXPORT EXCEL
if ($action === 'export') {
    $filename = 'Pemeriksaan_Radiologi_RSKHJ_' . $tglAwal . '_sd_' . $tglAkhir . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pemeriksaan Radiologi - RS Kartika Husada Jatiasih</title>
    <?php if ($action !== 'export'): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        /* ======= DESIGN SYSTEM ======= */
        :root {
            --kh-primary: #0ea55a;
            --kh-primary-dark: #0b8a4b;
            --kh-primary-light: #e8f8f0;
            --kh-primary-glow: rgba(14, 165, 90, 0.15);
            --kh-accent: #f0652f;
            --kh-accent-dark: #d84f1c;
            --kh-accent-light: #fff4ef;

            --kh-bg: #f0f4f3;
            --kh-card: #ffffff;
            --kh-text: #1a2332;
            --kh-text-muted: #6b7b8d;
            --kh-border: #e2e8f0;

            --kh-success: #10b981;
            --kh-success-bg: #ecfdf5;
            --kh-danger: #ef4444;
            --kh-danger-bg: #fef2f2;
            --kh-warning: #f59e0b;
            --kh-warning-bg: #fffbeb;
            --kh-info: #3b82f6;
            --kh-info-bg: #eff6ff;

            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07), 0 2px 4px rgba(0,0,0,0.04);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.08), 0 4px 8px rgba(0,0,0,0.04);
            --radius: 12px;
            --radius-sm: 8px;
            --radius-xs: 6px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: var(--kh-bg);
            color: var(--kh-text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 13px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .page {
            padding: 20px 24px;
            max-width: 100%;
            min-height: 100vh;
        }

        /* ======= HEADER ======= */
        .app-header {
            background: linear-gradient(135deg, #0b6e3d 0%, #0ea55a 40%, #12b866 70%, #f0652f 100%);
            color: white;
            padding: 22px 28px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .app-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            pointer-events: none;
        }

        .app-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: 10%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
            pointer-events: none;
        }

        .header-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-left h1 {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.3px;
            margin-bottom: 4px;
        }

        .header-left .subtitle {
            font-size: 0.9rem;
            font-weight: 500;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            font-size: 0.72rem;
            background: rgba(255,255,255,0.18);
            backdrop-filter: blur(10px);
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-date {
            text-align: right;
            font-size: 0.78rem;
            opacity: 0.85;
            line-height: 1.6;
        }

        .header-icon {
            width: 52px;
            height: 52px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            border: 1px solid rgba(255,255,255,0.1);
        }

        /* ======= STATS CARDS ======= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--kh-card);
            border-radius: var(--radius);
            padding: 18px 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--kh-border);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .stat-card .stat-info { flex: 1; }
        .stat-card .stat-value { font-size: 1.55rem; font-weight: 800; line-height: 1.2; }
        .stat-card .stat-label { font-size: 0.72rem; color: var(--kh-text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }

        .stat-card.total .stat-icon { background: var(--kh-primary-light); color: var(--kh-primary); }
        .stat-card.total .stat-value { color: var(--kh-primary); }
        .stat-card.sudah .stat-icon { background: var(--kh-success-bg); color: var(--kh-success); }
        .stat-card.sudah .stat-value { color: var(--kh-success); }
        .stat-card.belum .stat-icon { background: var(--kh-danger-bg); color: var(--kh-danger); }
        .stat-card.belum .stat-value { color: var(--kh-danger); }
        .stat-card.biaya .stat-icon { background: var(--kh-info-bg); color: var(--kh-info); }
        .stat-card.biaya .stat-value { color: var(--kh-info); font-size: 1.2rem; }

        .stat-card .stat-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            border-radius: 0 0 var(--radius) var(--radius);
            transition: width 0.8s ease;
        }

        .stat-card.total .stat-bar { background: var(--kh-primary); width: 100%; }
        .stat-card.sudah .stat-bar { background: var(--kh-success); }
        .stat-card.belum .stat-bar { background: var(--kh-danger); }
        .stat-card.biaya .stat-bar { background: var(--kh-info); width: 100%; }

        /* ======= FILTER ======= */
        .filter-card {
            background: var(--kh-card);
            border: 1px solid var(--kh-border);
            border-radius: var(--radius);
            padding: 18px 22px;
            margin-bottom: 18px;
            box-shadow: var(--shadow-sm);
        }

        .filter-card .filter-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--kh-text-muted);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-row {
            display: flex;
            align-items: flex-end;
            gap: 14px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 0.72rem;
            font-weight: 600;
            color: #3d5a4c;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1.5px solid var(--kh-border);
            border-radius: var(--radius-xs);
            font-size: 12.5px;
            font-family: 'Inter', sans-serif;
            color: var(--kh-text);
            background: #fafbfc;
            transition: all 0.2s ease;
            outline: none;
            min-width: 150px;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: var(--kh-primary);
            background: white;
            box-shadow: 0 0 0 3px var(--kh-primary-glow);
        }

        .filter-group input[type="search"] {
            min-width: 220px;
        }

        .btn-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border: none;
            border-radius: var(--radius-xs);
            font-size: 12.5px;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--kh-primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--kh-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(14, 165, 90, 0.3);
        }

        .btn-excel {
            background: linear-gradient(135deg, #217346, #2e9957);
            color: white;
        }
        .btn-excel:hover {
            background: linear-gradient(135deg, #1a5c38, #247d47);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(33, 115, 70, 0.3);
            color: white;
        }

        .btn-reset {
            background: #f1f5f9;
            color: var(--kh-text-muted);
            border: 1px solid var(--kh-border);
        }
        .btn-reset:hover {
            background: #e2e8f0;
            color: var(--kh-text);
        }

        /* ======= TABLE ======= */
        .table-card {
            background: var(--kh-card);
            border: 1px solid var(--kh-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--kh-border);
            background: #fafbfc;
        }

        .table-toolbar .data-count {
            font-size: 0.82rem;
            color: var(--kh-text-muted);
        }

        .table-toolbar .data-count strong {
            color: var(--kh-primary);
            font-weight: 700;
        }

        .table-wrap {
            overflow: auto;
            max-height: 62vh;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background: linear-gradient(180deg, #f0faf5, #e5f5ed);
            color: #1a5c38;
            font-weight: 700;
            font-size: 11.5px;
            padding: 12px 14px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid #b8e0cc;
            text-align: left;
        }

        thead th.text-center { text-align: center; }
        thead th.text-end { text-align: right; }

        tbody td {
            padding: 10px 14px;
            font-size: 12.5px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f3;
            transition: background 0.15s ease;
        }

        tbody tr:nth-child(even) td {
            background: #fafcfb;
        }

        tbody tr:hover td {
            background: #eef7f2 !important;
        }

        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .fw-600 { font-weight: 600; }

        /* ======= BADGES ======= */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        .badge-success {
            background: var(--kh-success-bg);
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .badge-danger {
            background: var(--kh-danger-bg);
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .badge-info {
            background: var(--kh-info-bg);
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .badge-muted {
            background: #f8fafc;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
        }

        .badge i { font-size: 10px; }

        /* ======= FOOTER ======= */
        .table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-top: 1px solid var(--kh-border);
            background: #fafbfc;
            font-size: 12px;
        }

        .table-footer .total-biaya {
            font-weight: 700;
            color: var(--kh-primary);
            font-size: 1rem;
        }

        .page-footer {
            text-align: center;
            padding: 16px;
            color: var(--kh-text-muted);
            font-size: 11px;
        }

        /* ======= PROGRESS BAR for Ekspertisi ======= */
        .progress-mini {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
        }

        .progress-bar-track {
            flex: 1;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 1s ease;
        }

        .progress-bar-fill.green { background: linear-gradient(90deg, #10b981, #34d399); }

        .progress-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--kh-text-muted);
            min-width: 36px;
            text-align: right;
        }

        /* ======= EMPTY STATE ======= */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 14px;
        }

        .empty-state p {
            color: var(--kh-text-muted);
            font-size: 0.9rem;
        }

        /* ======= ANIMATIONS ======= */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-in {
            animation: fadeInUp 0.4s ease forwards;
        }

        .animate-delay-1 { animation-delay: 0.05s; }
        .animate-delay-2 { animation-delay: 0.1s; }
        .animate-delay-3 { animation-delay: 0.15s; }
        .animate-delay-4 { animation-delay: 0.2s; }

        /* ======= RESPONSIVE ======= */
        @media (max-width: 768px) {
            .page { padding: 12px; }
            .header-content { flex-direction: column; align-items: flex-start; }
            .header-right { display: none; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .filter-row { flex-direction: column; }
            .filter-group input,
            .filter-group select { min-width: 100%; }
        }

        /* ======= SCROLLBAR ======= */
        .table-wrap::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-wrap::-webkit-scrollbar-track { background: #f1f5f9; }
        .table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .table-wrap::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
    <?php endif; ?>
</head>
<body>
<div class="page">
    <?php if ($action !== 'export'): ?>
    <!-- HEADER -->
    <div class="app-header animate-in">
        <div class="header-content">
            <div class="header-left">
                <h1><i class="fa-solid fa-x-ray"></i> Pemeriksaan Radiologi</h1>
                <div class="subtitle">RS Kartika Husada Jatiasih</div>
                <div class="header-badge">
                    <i class="fa-solid fa-link"></i> Terintegrasi SIMRS Khanza
                </div>
            </div>
            <div class="header-right">
                <div class="header-date">
                    <div><i class="fa-regular fa-calendar"></i> <?= date('d F Y') ?></div>
                    <div><i class="fa-regular fa-clock"></i> <span id="live-clock"><?= date('H:i:s') ?></span></div>
                </div>
                <div class="header-icon">
                    <i class="fa-solid fa-hospital"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- STATISTICS -->
    <div class="stats-grid">
        <div class="stat-card total animate-in animate-delay-1">
            <div class="stat-icon"><i class="fa-solid fa-clipboard-list"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalData, 0, ',', '.') ?></div>
                <div class="stat-label">Total Pemeriksaan</div>
            </div>
            <div class="stat-bar"></div>
        </div>
        <div class="stat-card sudah animate-in animate-delay-2">
            <div class="stat-icon"><i class="fa-solid fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalSudah, 0, ',', '.') ?></div>
                <div class="stat-label">Sudah Dibaca</div>
                <?php if ($totalData > 0): ?>
                <div class="progress-mini">
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill green" style="width: <?= round($totalSudah / $totalData * 100) ?>%"></div>
                    </div>
                    <span class="progress-label"><?= round($totalSudah / $totalData * 100) ?>%</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="stat-bar" style="width: <?= $totalData > 0 ? round($totalSudah / $totalData * 100) : 0 ?>%"></div>
        </div>
        <div class="stat-card belum animate-in animate-delay-3">
            <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($totalBelum, 0, ',', '.') ?></div>
                <div class="stat-label">Belum Dibaca</div>
                <?php if ($totalData > 0): ?>
                <div class="progress-mini">
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" style="width: <?= round($totalBelum / $totalData * 100) ?>%; background: linear-gradient(90deg, #ef4444, #f87171);"></div>
                    </div>
                    <span class="progress-label"><?= round($totalBelum / $totalData * 100) ?>%</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="stat-bar" style="width: <?= $totalData > 0 ? round($totalBelum / $totalData * 100) : 0 ?>%"></div>
        </div>
        <div class="stat-card biaya animate-in animate-delay-4">
            <div class="stat-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-value">Rp <?= number_format($totalBiaya, 0, ',', '.') ?></div>
                <div class="stat-label">Total Biaya</div>
            </div>
            <div class="stat-bar"></div>
        </div>
    </div>

    <!-- FILTER -->
    <div class="filter-card animate-in">
        <div class="filter-title">
            <i class="fa-solid fa-sliders"></i> Filter Data
        </div>
        <form method="GET" id="filterForm">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="tgl_awal">Tanggal Awal</label>
                    <input type="date" id="tgl_awal" name="tgl_awal" value="<?= $escape($tglAwal) ?>">
                </div>
                <div class="filter-group">
                    <label for="tgl_akhir">Tanggal Akhir</label>
                    <input type="date" id="tgl_akhir" name="tgl_akhir" value="<?= $escape($tglAkhir) ?>">
                </div>
                <div class="filter-group">
                    <label for="filter_ekspertisi">Status Ekspertisi</label>
                    <select id="filter_ekspertisi" name="filter_ekspertisi">
                        <option value="">— Semua —</option>
                        <option value="sudah" <?= $filterEks === 'sudah' ? 'selected' : '' ?>>Sudah Dibaca</option>
                        <option value="belum" <?= $filterEks === 'belum' ? 'selected' : '' ?>>Belum Dibaca</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="cari">Pencarian</label>
                    <input type="search" id="cari" name="cari" value="<?= $escape($cari) ?>" placeholder="No.Rawat / RM / Pasien / Tindakan / Dokter">
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
                    <a href="?" class="btn btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                    <button type="submit" name="action" value="export" class="btn btn-excel"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($action === 'export'): ?>
    <!-- EXPORT HEADER -->
    <table width="100%">
        <tr><td colspan="16" align="center" style="font-size:14px;font-weight:bold;">REKAPITULASI PEMERIKSAAN RADIOLOGI</td></tr>
        <tr><td colspan="16" align="center" style="font-size:12px;font-weight:bold;">RS KARTIKA HUSADA JATIASIH</td></tr>
        <tr><td colspan="16" align="center">Periode: <?= date('d-m-Y', strtotime($tglAwal)) ?> s/d <?= date('d-m-Y', strtotime($tglAkhir)) ?></td></tr>
        <tr><td colspan="16">&nbsp;</td></tr>
    </table>
    <?php endif; ?>

    <!-- TABLE -->
    <div class="table-card">
        <?php if ($action !== 'export'): ?>
        <div class="table-toolbar">
            <div class="data-count">
                <i class="fa-solid fa-database"></i>
                Menampilkan <strong><?= number_format($totalData, 0, ',', '.') ?></strong> data pemeriksaan
                <?php if ($filterEks !== ''): ?>
                    — filter: <span class="badge <?= $filterEks === 'sudah' ? 'badge-success' : 'badge-danger' ?>"><?= $filterEks === 'sudah' ? 'Sudah Dibaca' : 'Belum Dibaca' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table <?= $action === 'export' ? 'border="1"' : '' ?>>
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
                        <th class="text-center">Tanggal</th>
                        <th class="text-center">Jam</th>
                        <th class="text-end">Biaya</th>
                        <th class="text-center">Foto</th>
                        <th class="text-center">Hasil</th>
                        <th class="text-center" style="<?= $action !== 'export' ? 'min-width:130px;' : '' ?>">Ekspertisi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($totalData > 0): ?>
                    <?php $no = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="fw-600"><?= $escape($row['no_rawat']) ?></td>
                        <td><?= $escape($row['no_rkm_medis']) ?></td>
                        <td class="fw-600"><?= $escape($row['nm_pasien']) ?></td>
                        <td><?= $escape($row['nm_perawatan']) ?></td>
                        <td><?= $escape($row['jenis_bayar']) ?></td>
                        <td><?= $escape($row['dokter_rad']) ?></td>
                        <td><?= $escape($row['petugas_rad']) ?></td>
                        <td><?= $escape($row['dokter_perujuk']) ?></td>
                        <td class="text-center"><?= $escape($row['tgl_periksa']) ?></td>
                        <td class="text-center"><?= $escape($row['jam']) ?></td>
                        <td class="text-end"><?= number_format((float)$row['biaya'], 0, ',', '.') ?></td>
                        <td class="text-center">
                            <?php if ($action === 'export'): ?>
                                <?= $row['status_foto'] === 'ada' ? 'Ada' : '-' ?>
                            <?php else: ?>
                                <?php if ($row['status_foto'] === 'ada'): ?>
                                    <span class="badge badge-info"><i class="fa-solid fa-image"></i> Ada</span>
                                <?php else: ?>
                                    <span class="badge badge-muted"><i class="fa-regular fa-image"></i> -</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($action === 'export'): ?>
                                <?= $row['status_hasil'] === 'ada' ? 'Ada' : '-' ?>
                            <?php else: ?>
                                <?php if ($row['status_hasil'] === 'ada'): ?>
                                    <span class="badge badge-info"><i class="fa-solid fa-file-medical"></i> Ada</span>
                                <?php else: ?>
                                    <span class="badge badge-muted"><i class="fa-regular fa-file"></i> -</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($action === 'export'): ?>
                                <?= $row['status_ekspertisi'] ?>
                            <?php else: ?>
                                <?php if ($row['status_ekspertisi'] === 'Sudah Dibaca'): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check-circle"></i> Sudah Dibaca</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fa-solid fa-exclamation-circle"></i> Belum Dibaca</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if ($action !== 'export'): ?>
                    <tr>
                        <td colspan="15">
                            <div class="empty-state">
                                <i class="fa-solid fa-x-ray"></i>
                                <p>Tidak ada data pemeriksaan radiologi pada periode ini.</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr><td colspan="15" align="center">Tidak ada data.</td></tr>
                    <?php endif; ?>
                <?php endif; ?>
                </tbody>
                <?php if ($totalData > 0): ?>
                <tfoot>
                    <tr style="<?= $action === 'export' ? 'background-color:#e8f5e9;font-weight:bold;' : '' ?>">
                        <td colspan="11" class="text-end fw-600" style="padding: 12px 14px;">
                            <?php if ($action !== 'export'): ?>
                                <strong>TOTAL BIAYA</strong>
                            <?php else: ?>
                                TOTAL BIAYA
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-600" style="padding: 12px 14px; color: var(--kh-primary); font-size: 13.5px;">
                            Rp <?= number_format($totalBiaya, 0, ',', '.') ?>
                        </td>
                        <td colspan="3" class="text-center" style="padding: 12px 14px;">
                            <?php if ($action !== 'export'): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> <?= $totalSudah ?></span>
                                <span class="badge badge-danger" style="margin-left: 4px;"><i class="fa-solid fa-clock"></i> <?= $totalBelum ?></span>
                            <?php else: ?>
                                Sudah: <?= $totalSudah ?> | Belum: <?= $totalBelum ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($action !== 'export' && $totalData > 0): ?>
        <div class="table-footer">
            <div>
                <span style="color: var(--kh-text-muted);">Foto tersedia:</span> <strong style="color: var(--kh-info);"><?= $totalFoto ?></strong> &nbsp;|&nbsp;
                <span style="color: var(--kh-text-muted);">Hasil tersedia:</span> <strong style="color: var(--kh-info);"><?= $totalHasil ?></strong>
            </div>
            <div class="total-biaya">
                Total: Rp <?= number_format($totalBiaya, 0, ',', '.') ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($action !== 'export'): ?>
    <div class="page-footer">
        &copy; <?= date('Y') ?> RS Kartika Husada Jatiasih — Terintegrasi dengan SIMRS Khanza
    </div>

    <script>
        // Live Clock
        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            const el = document.getElementById('live-clock');
            if (el) el.textContent = h + ':' + m + ':' + s;
        }
        setInterval(updateClock, 1000);

        // Animate stat bars on load
        document.addEventListener('DOMContentLoaded', function() {
            const bars = document.querySelectorAll('.stat-bar');
            bars.forEach(function(bar) {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(function() {
                    bar.style.width = width || '100%';
                }, 300);
            });

            const progressFills = document.querySelectorAll('.progress-bar-fill');
            progressFills.forEach(function(fill) {
                const width = fill.style.width;
                fill.style.width = '0%';
                setTimeout(function() {
                    fill.style.width = width;
                }, 500);
            });
        });
    </script>
    <?php endif; ?>
</div>
</body>
</html>
<?php
mysqli_stmt_close($stmt);
mysqli_close($koneksi);
?>
