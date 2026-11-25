<?php
date_default_timezone_set('Asia/Jakarta');
/**
 * ============================================================================
 * DASHBOARD MONITORING RESUME MEDIS ELEKTRONIK (RME) - RAJAL & RANAP
 * RS KARTIKA HUSADA JATIASIH - SMART IT FOR HEALTH
 * ============================================================================
 * Sesuai Arahan Pak Gun:
 * 1. Rawat Jalan: tabel resume_pasien relasi dengan reg_periksa, filter penjab
 *    (Bisa Semua Jaminan, BPJS, maupun jaminan lainnya).
 * 2. Rawat Inap: tabel resume_pasien_ranap di-join dengan kamar_inap pada kolom
 *    tanggal pulang (tgl_keluar) & filter jaminan/penjab.
 * 3. Terintegrasi 2 modul tab: Rawat Jalan (Rajal) & Rawat Inap (Ranap).
 * 4. Metrik Kepatuhan Resume (Sudah Terisi vs Belum Terisi), Visual Chart.js,
 *    Smart Pagination, Instant Search, Detail Modal & Export Excel.
 */

// ----------------------------------------------------------------------------
// 1. KONEKSI DATABASE (PDO MULTI-HOST FALLBACK)
// ----------------------------------------------------------------------------
$db_configs = [
    ['host' => '192.168.10.29', 'db' => 'rskhj', 'user' => 'root', 'pass' => '1nternet'],
    ['host' => 'localhost',     'db' => 'rskhj', 'user' => 'root', 'pass' => '1nternet'],
    ['host' => 'localhost',     'db' => 'sik',   'user' => 'root', 'pass' => ''],
];

$conn = null;
$conn_error = '';

foreach ($db_configs as $cfg) {
    try {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8mb4";
        $conn = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
        try { $conn->exec("SET time_zone = '+07:00'"); } catch (Exception $tz_e) {}
        break;
    } catch (PDOException $e) {
        $conn_error = $e->getMessage();
    }
}

if (!$conn) {
    die("
    <div style='max-width:600px;margin:50px auto;padding:24px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-family:Segoe UI,sans-serif;color:#991b1b;'>
        <h3 style='margin-top:0;'><i class='fa-solid fa-triangle-exclamation'></i> Koneksi Database Gagal</h3>
        <p>Tidak dapat terhubung ke server database SIMRS Khanza.</p>
        <code style='display:block;background:#fff;padding:10px;border-radius:6px;border:1px solid #fee2e2;'>{$conn_error}</code>
        <p style='margin-top:15px;font-size:13px;color:#7f1d1d;'>Pastikan jaringan atau server MySQL di <b>192.168.10.29</b> atau <b>localhost</b> aktif.</p>
    </div>");
}

// ----------------------------------------------------------------------------
// 2. HELPER FUNCTIONS
// ----------------------------------------------------------------------------
function tglIndo($tanggal, $show_day = true) {
    if (empty($tanggal) || $tanggal === '0000-00-00' || $tanggal === '-') return '-';
    $hari = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $split = explode('-', $tanggal);
    if (count($split) !== 3) return $tanggal;
    $tgl_indo = (int)$split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
    if ($show_day) {
        $num_hari = date('N', strtotime($tanggal));
        return $hari[$num_hari] . ', ' . $tgl_indo;
    }
    return $tgl_indo;
}

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

// ----------------------------------------------------------------------------
// 3. PARAMETER & FILTER REQUEST
// ----------------------------------------------------------------------------
$today          = date('Y-m-d');
$tab            = $_GET['tab'] ?? 'rajal'; // 'rajal' atau 'ranap'
$tgl_awal       = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir      = $_GET['tgl_akhir'] ?? date('Y-m-d');
$basis_tgl      = $_GET['basis_tgl'] ?? ($tab === 'ranap' ? 'pulang' : 'registrasi'); // 'pulang' atau 'masuk' untuk ranap
$status_resume  = $_GET['status_resume'] ?? 'semua'; // 'semua', 'lengkap', 'belum'
$status_periksa = $_GET['status_periksa'] ?? 'bukan_batal'; // 'bukan_batal', 'semua', 'Sudah', 'Belum', 'Batal', 'Dirawat', 'Dirujuk'
$status_pulang  = $_GET['status_pulang'] ?? 'pulang_valid'; // Sesuai arahan Mas Amri: default 'pulang_valid' (kecuali '-' dan 'Pindah kamar')
$filter_pj      = $_GET['filter_pj'] ?? 'semua'; // 'semua', 'bpjs', atau kd_pj spesifik
$kd_poli        = $_GET['kd_poli'] ?? '';
$kd_bangsal     = $_GET['kd_bangsal'] ?? '';
$kd_dokter      = $_GET['kd_dokter'] ?? '';
$keyword        = trim($_GET['keyword'] ?? '');
$auto_refresh   = (int)($_GET['refresh'] ?? 0);

// Preset shortcut tanggal cepat
if (isset($_GET['preset'])) {
    switch ($_GET['preset']) {
        case 'today':
            $tgl_awal = $tgl_akhir = $today;
            break;
        case 'yesterday':
            $tgl_awal = $tgl_akhir = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'last7':
            $tgl_awal = date('Y-m-d', strtotime('-6 days'));
            $tgl_akhir = $today;
            break;
        case 'this_month':
            $tgl_awal = date('Y-m-01');
            $tgl_akhir = date('Y-m-d');
            break;
        case 'last_month':
            $tgl_awal = date('Y-m-01', strtotime('first day of last month'));
            $tgl_akhir = date('Y-m-t', strtotime('last day of last month'));
            break;
    }
}

// ----------------------------------------------------------------------------
// 4. DROPDOWN MASTER DATA
// ----------------------------------------------------------------------------
// Daftar Penjab / Jaminan
$list_penjab = $conn->query("SELECT kd_pj, png_jawab FROM penjab WHERE status = '1' ORDER BY png_jawab ASC")->fetchAll();

// Daftar Poliklinik (untuk Rajal)
$list_poli = $conn->query("
    SELECT kd_poli, nm_poli 
    FROM poliklinik 
    WHERE status = '1' 
      AND nm_poli NOT LIKE '%Alkes BPJS%'
    ORDER BY nm_poli ASC
")->fetchAll();

// Daftar Bangsal (untuk Ranap)
$list_bangsal = $conn->query("
    SELECT kd_bangsal, nm_bangsal 
    FROM bangsal 
    WHERE status = '1' 
    ORDER BY nm_bangsal ASC
")->fetchAll();

// Daftar Dokter
$list_dokter = $conn->query("
    SELECT kd_dokter, nm_dokter 
    FROM dokter 
    WHERE status = '1' 
    ORDER BY nm_dokter ASC
")->fetchAll();

// ----------------------------------------------------------------------------
// 5. QUERY UTAMA BERDASARKAN TAB (RAJAL vs RANAP)
// ----------------------------------------------------------------------------
$conditions = [];
$params     = [];

if ($tab === 'ranap') {
    // =========================================================================
    // MODUL RAWAT INAP (RANAP)
    // =========================================================================
    // Filter status lanjut ranap
    $conditions[] = "r.status_lanjut = 'Ranap'";

    // Filter Status Pasien / Registrasi (Sesuai arahan: jangan masukkan pasien batal)
    if ($status_periksa === 'bukan_batal') {
        $conditions[] = "(r.stts NOT IN ('Batal', 'BATAL', 'batal') AND r.stts IS NOT NULL)";
    } elseif ($status_periksa === 'Batal') {
        $conditions[] = "r.stts IN ('Batal', 'BATAL', 'batal')";
    } elseif ($status_periksa !== 'semua' && !empty($status_periksa)) {
        $conditions[] = "r.stts = ?";
        $params[] = $status_periksa;
    }

    // Filter Status Pulang Ranap (Sesuai arahan spesifik Mas Amri: '-' dan 'Pindah Kamar' jangan diambil)
    if ($status_pulang === 'pulang_valid') {
        $conditions[] = "(ki.stts_pulang NOT IN ('-', 'Pindah Kamar', '') AND ki.stts_pulang IS NOT NULL AND ki.tgl_keluar IS NOT NULL AND ki.tgl_keluar != '0000-00-00')";
    } elseif ($status_pulang === 'Atas Persetujuan Dokter') {
        $conditions[] = "ki.stts_pulang IN ('Atas Persetujuan Dokter', 'Sembuh', 'Membaik')";
    } elseif ($status_pulang === 'Rujuk') {
        $conditions[] = "ki.stts_pulang = 'Rujuk'";
    } elseif ($status_pulang === 'Meninggal') {
        $conditions[] = "ki.stts_pulang IN ('Meninggal', '+')";
    } elseif ($status_pulang === 'Atas Permintaan Sendiri') {
        $conditions[] = "ki.stts_pulang IN ('Atas Permintaan Sendiri', 'APS', 'Pulang Paksa')";
    } elseif ($status_pulang !== 'semua' && !empty($status_pulang)) {
        $conditions[] = "ki.stts_pulang = ?";
        $params[] = $status_pulang;
    }

    // Filter Tanggal (Berdasarkan Tanggal Pulang atau Tanggal Masuk)
    if ($basis_tgl === 'masuk') {
        if (!empty($tgl_awal) && !empty($tgl_akhir)) {
            $conditions[] = "ki.tgl_masuk BETWEEN ? AND ?";
            $params[] = $tgl_awal;
            $params[] = $tgl_akhir;
        }
    } else {
        // Default: Tanggal Pulang (tgl_keluar di kamar_inap) - Sesuai pesan Pak Gun
        if (!empty($tgl_awal) && !empty($tgl_akhir)) {
            $conditions[] = "ki.tgl_keluar BETWEEN ? AND ?";
            $params[] = $tgl_awal;
            $params[] = $tgl_akhir;
        }
    }

    // Filter Penjab / Jaminan
    if ($filter_pj === 'bpjs') {
        $conditions[] = "pj.png_jawab LIKE '%BPJS%'";
    } elseif ($filter_pj !== 'semua' && !empty($filter_pj)) {
        $conditions[] = "r.kd_pj = ?";
        $params[] = $filter_pj;
    }

    // Filter Bangsal
    if (!empty($kd_bangsal)) {
        $conditions[] = "ki.kd_bangsal = ?";
        $params[] = $kd_bangsal;
    }

    // Filter Dokter DPJP
    if (!empty($kd_dokter)) {
        $conditions[] = "(r.no_rawat IN (SELECT drp.no_rawat FROM dpjp_ranap drp WHERE drp.kd_dokter = ?) OR r.kd_dokter = ?)";
        $params[] = $kd_dokter;
        $params[] = $kd_dokter;
    }

    // Filter Kata Kunci
    if (!empty($keyword)) {
        $conditions[] = "(r.no_rawat LIKE ? OR r.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ? OR sep.no_sep LIKE ?)";
        $kw = "%" . $keyword . "%";
        for ($i = 0; $i < 4; $i++) {
            $params[] = $kw;
        }
    }

    $where_sql = count($conditions) > 0 ? " WHERE " . implode(" AND ", $conditions) : "";

    $sql = "SELECT 
                r.no_rawat,
                r.no_rkm_medis,
                p.nm_pasien,
                COALESCE(p.no_tlp, '') AS no_tlp,
                COALESCE(p.jk, '-') AS jk,
                r.tgl_registrasi,
                r.stts AS stts_periksa,
                pj.png_jawab AS jaminan,
                ki.tgl_masuk,
                ki.jam_masuk,
                ki.tgl_keluar,
                ki.jam_keluar,
                ki.nm_bangsal,
                ki.stts_pulang,
                DATEDIFF(IF(ki.tgl_keluar IS NULL OR ki.tgl_keluar = '0000-00-00', CURDATE(), ki.tgl_keluar), ki.tgl_masuk) AS lama_inap,
                COALESCE(sep.no_sep, '-') AS no_sep,
                COALESCE(
                    (SELECT GROUP_CONCAT(DISTINCT d.nm_dokter SEPARATOR ' | ') 
                     FROM dpjp_ranap dr 
                     JOIN dokter d ON dr.kd_dokter = d.kd_dokter 
                     WHERE dr.no_rawat = r.no_rawat),
                    d_reg.nm_dokter,
                    '-'
                ) AS dpjp,
                IF(rs.no_rawat IS NOT NULL, 'Lengkap', 'Belum Diisi') AS status_resume,
                rs.diagnosa_utama,
                rs.kd_diagnosa_utama,
                rs.diagnosa_sekunder,
                rs.prosedur_utama,
                rs.cara_keluar,
                rs.keadaan,
                rs.obat_pulang
            FROM reg_periksa r
            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
            INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
            INNER JOIN (
                SELECT 
                    k_in.no_rawat,
                    MIN(k_in.tgl_masuk) as tgl_masuk,
                    MIN(k_in.jam_masuk) as jam_masuk,
                    MAX(k_in.tgl_keluar) as tgl_keluar,
                    MAX(k_in.jam_keluar) as jam_keluar,
                    SUBSTRING_INDEX(GROUP_CONCAT(b.kd_bangsal ORDER BY k_in.tgl_masuk DESC, k_in.jam_masuk DESC SEPARATOR '|'), '|', 1) as kd_bangsal,
                    GROUP_CONCAT(DISTINCT b.nm_bangsal SEPARATOR ' / ') as nm_bangsal,
                    SUBSTRING_INDEX(GROUP_CONCAT(k_in.stts_pulang ORDER BY k_in.tgl_masuk DESC, k_in.jam_masuk DESC SEPARATOR '|'), '|', 1) as stts_pulang
                FROM kamar_inap k_in
                INNER JOIN kamar k ON k_in.kd_kamar = k.kd_kamar
                INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
                GROUP BY k_in.no_rawat
            ) ki ON r.no_rawat = ki.no_rawat
            LEFT JOIN resume_pasien_ranap rs ON r.no_rawat = rs.no_rawat
            LEFT JOIN dokter d_reg ON r.kd_dokter = d_reg.kd_dokter
            LEFT JOIN (
                SELECT no_rawat, MAX(no_sep) as no_sep FROM bridging_sep GROUP BY no_rawat
            ) sep ON r.no_rawat = sep.no_rawat
            $where_sql
            ORDER BY ki.tgl_keluar DESC, ki.tgl_masuk DESC, r.no_rawat DESC";

} else {
    // =========================================================================
    // MODUL RAWAT JALAN (RAJAL)
    // =========================================================================
    // Filter status lanjut rajal
    $conditions[] = "r.status_lanjut = 'Ralan'";
    $conditions[] = "pol.nm_poli NOT LIKE '%Alkes BPJS%'";

    // Filter Status Pasien / Registrasi (Sesuai arahan: jangan masukkan pasien batal)
    if ($status_periksa === 'bukan_batal') {
        $conditions[] = "(r.stts NOT IN ('Batal', 'BATAL', 'batal') AND r.stts IS NOT NULL)";
    } elseif ($status_periksa === 'Batal') {
        $conditions[] = "r.stts IN ('Batal', 'BATAL', 'batal')";
    } elseif ($status_periksa !== 'semua' && !empty($status_periksa)) {
        $conditions[] = "r.stts = ?";
        $params[] = $status_periksa;
    }

    // Filter Tanggal Registrasi Rajal
    if (!empty($tgl_awal) && !empty($tgl_akhir)) {
        $conditions[] = "r.tgl_registrasi BETWEEN ? AND ?";
        $params[] = $tgl_awal;
        $params[] = $tgl_akhir;
    }

    // Filter Penjab / Jaminan
    if ($filter_pj === 'bpjs') {
        $conditions[] = "pj.png_jawab LIKE '%BPJS%'";
    } elseif ($filter_pj !== 'semua' && !empty($filter_pj)) {
        $conditions[] = "r.kd_pj = ?";
        $params[] = $filter_pj;
    }

    // Filter Poliklinik
    if (!empty($kd_poli)) {
        $conditions[] = "r.kd_poli = ?";
        $params[] = $kd_poli;
    }

    // Filter Dokter
    if (!empty($kd_dokter)) {
        $conditions[] = "r.kd_dokter = ?";
        $params[] = $kd_dokter;
    }

    // Filter Kata Kunci
    if (!empty($keyword)) {
        $conditions[] = "(r.no_rawat LIKE ? OR r.no_rkm_medis LIKE ? OR p.nm_pasien LIKE ? OR d.nm_dokter LIKE ? OR pol.nm_poli LIKE ? OR sep.no_sep LIKE ?)";
        $kw = "%" . $keyword . "%";
        for ($i = 0; $i < 6; $i++) {
            $params[] = $kw;
        }
    }

    $where_sql = count($conditions) > 0 ? " WHERE " . implode(" AND ", $conditions) : "";

    $sql = "SELECT 
                r.no_rawat,
                r.no_rkm_medis,
                p.nm_pasien,
                COALESCE(p.no_tlp, '') AS no_tlp,
                COALESCE(p.jk, '-') AS jk,
                r.tgl_registrasi,
                r.jam_reg,
                r.stts AS stts_periksa,
                pol.nm_poli,
                d.nm_dokter AS dpjp,
                pj.png_jawab AS jaminan,
                COALESCE(sep.no_sep, '-') AS no_sep,
                IF(rs.no_rawat IS NOT NULL, 'Lengkap', 'Belum Diisi') AS status_resume,
                rs.diagnosa_utama,
                rs.kd_diagnosa_utama,
                rs.diagnosa_sekunder,
                rs.prosedur_utama,
                rs.kondisi_pulang,
                rs.obat_pulang
            FROM reg_periksa r
            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
            INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
            INNER JOIN dokter d ON r.kd_dokter = d.kd_dokter
            INNER JOIN poliklinik pol ON r.kd_poli = pol.kd_poli
            LEFT JOIN resume_pasien rs ON r.no_rawat = rs.no_rawat
            LEFT JOIN (
                SELECT no_rawat, MAX(no_sep) as no_sep FROM bridging_sep GROUP BY no_rawat
            ) sep ON r.no_rawat = sep.no_rawat
            $where_sql
            ORDER BY r.tgl_registrasi DESC, r.jam_reg DESC, r.no_rawat DESC";
}

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$raw_rows = $stmt->fetchAll();

// ----------------------------------------------------------------------------
// 6. METRIK STATISTIK & ANALITIK
// ----------------------------------------------------------------------------
$total_pasien  = 0;
$total_lengkap = 0;
$total_belum   = 0;
$total_bpjs    = 0;
$total_nonbpjs = 0;

$chart_trend  = [];
$chart_unit   = []; // Poli (Rajal) atau Bangsal (Ranap)
$chart_dokter = [];

foreach ($raw_rows as $row) {
    $total_pasien++;
    $is_lengkap = ($row['status_resume'] === 'Lengkap');
    $is_bpjs    = (stripos($row['jaminan'], 'BPJS') !== false);

    if ($is_lengkap) {
        $total_lengkap++;
    } else {
        $total_belum++;
    }

    if ($is_bpjs) {
        $total_bpjs++;
    } else {
        $total_nonbpjs++;
    }

    // Trend Harian
    $tgl_trend = ($tab === 'ranap') ? ($row['tgl_keluar'] ?: $row['tgl_masuk']) : $row['tgl_registrasi'];
    if (!empty($tgl_trend) && $tgl_trend !== '0000-00-00') {
        if (!isset($chart_trend[$tgl_trend])) {
            $chart_trend[$tgl_trend] = ['total' => 0, 'lengkap' => 0, 'belum' => 0];
        }
        $chart_trend[$tgl_trend]['total']++;
        if ($is_lengkap) {
            $chart_trend[$tgl_trend]['lengkap']++;
        } else {
            $chart_trend[$tgl_trend]['belum']++;
        }
    }

    // Unit Breakdown (Poli / Bangsal)
    $unit_name = ($tab === 'ranap') ? ($row['nm_bangsal'] ?: 'Belum Ada Kamar') : ($row['nm_poli'] ?: 'Lainnya');
    if (!isset($chart_unit[$unit_name])) {
        $chart_unit[$unit_name] = ['total' => 0, 'lengkap' => 0, 'belum' => 0];
    }
    $chart_unit[$unit_name]['total']++;
    if ($is_lengkap) $chart_unit[$unit_name]['lengkap']++;
    else $chart_unit[$unit_name]['belum']++;

    // Dokter Breakdown
    $dok_name = !empty($row['dpjp']) ? trim($row['dpjp']) : 'Belum Ditentukan';
    // Potong jika multiple dpjp
    $dok_first = explode('|', $dok_name)[0];
    $dok_clean = trim($dok_first);
    if (!isset($chart_dokter[$dok_clean])) {
        $chart_dokter[$dok_clean] = ['total' => 0, 'lengkap' => 0, 'belum' => 0];
    }
    $chart_dokter[$dok_clean]['total']++;
    if ($is_lengkap) $chart_dokter[$dok_clean]['lengkap']++;
    else $chart_dokter[$dok_clean]['belum']++;
}

// Persentase Kepatuhan
$persen_lengkap = ($total_pasien > 0) ? round(($total_lengkap / $total_pasien) * 100, 1) : 0;
$persen_belum   = ($total_pasien > 0) ? round(($total_belum / $total_pasien) * 100, 1) : 0;

// Filter baris data tabel sesuai status resume
$rows = [];
foreach ($raw_rows as $row) {
    if ($status_resume === 'lengkap' && $row['status_resume'] !== 'Lengkap') continue;
    if ($status_resume === 'belum' && $row['status_resume'] !== 'Belum Diisi') continue;
    $rows[] = $row;
}

ksort($chart_trend);
uasort($chart_unit, function($a, $b) { return $b['total'] <=> $a['total']; });
uasort($chart_dokter, function($a, $b) { return $b['belum'] <=> $a['belum']; }); // Urutkan dokter dengan resume belum terisi terbanyak

// ----------------------------------------------------------------------------
// 7. EXPORT EXCEL (.xls)
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    $judul_export = ($tab === 'ranap') ? "Monitoring_Resume_RANAP_" : "Monitoring_Resume_RAJAL_";
    $filename = $judul_export . date('Ymd_His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Cache-Control: max-age=0");

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8">';
    echo '<style>th { background-color: #047857; color: #ffffff; font-weight: bold; padding: 8px; border: 1px solid #999; } td { padding: 6px; border: 1px solid #ccc; }</style>';
    echo '</head><body>';
    echo '<h3>DASHBOARD MONITORING KELENGKAPAN RESUME MEDIS (' . strtoupper($tab) . ')</h3>';
    echo '<p><b>RS KARTIKA HUSADA JATIASIH</b><br>';
    echo 'Periode: ' . htmlspecialchars($tgl_awal) . ' s/d ' . htmlspecialchars($tgl_akhir) . '<br>';
    echo '<b>Total Pasien:</b> ' . number_format($total_pasien,0,',','.') . ' | ';
    echo '<b>Resume Lengkap:</b> ' . number_format($total_lengkap,0,',','.') . ' (' . $persen_lengkap . '%) | ';
    echo '<b>Resume Belum Diisi:</b> ' . number_format($total_belum,0,',','.') . ' (' . $persen_belum . '%)</p>';
    
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>No</th><th>No. Rawat</th><th>No. RM</th><th>Nama Pasien</th><th>Jaminan</th><th>No. SEP</th>';
    if ($tab === 'ranap') {
        echo '<th>Tgl. Masuk</th><th>Tgl. Pulang</th><th>Lama (Hari)</th><th>Status Pulang</th><th>Bangsal/Kamar</th><th>DPJP Ranap</th>';
    } else {
        echo '<th>Tgl. Registrasi</th><th>Poliklinik</th><th>Dokter DPJP</th><th>Status Reg</th>';
    }
    echo '<th>Status Resume</th><th>Diagnosa Utama</th><th>Prosedur/Tindakan</th><th>Obat Pulang</th>';
    echo '</tr></thead><tbody>';

    $no_exp = 1;
    foreach ($rows as $r) {
        $is_ok = ($r['status_resume'] === 'Lengkap');
        $bg_status = $is_ok ? '#dcfce7' : '#fee2e2';
        echo '<tr>';
        echo '<td align="center">' . $no_exp++ . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_rawat']) . '</td>';
        echo '<td align="center" style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_rkm_medis']) . '</td>';
        echo '<td>' . htmlspecialchars($r['nm_pasien']) . '</td>';
        echo '<td>' . htmlspecialchars($r['jaminan']) . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_sep']) . '</td>';
        if ($tab === 'ranap') {
            echo '<td align="center">' . htmlspecialchars($r['tgl_masuk']) . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['tgl_keluar'] ?: '-') . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['lama_inap'] ?? 0) . '</td>';
            echo '<td>' . htmlspecialchars($r['stts_pulang'] ?: '-') . '</td>';
            echo '<td>' . htmlspecialchars($r['nm_bangsal']) . '</td>';
            echo '<td>' . htmlspecialchars($r['dpjp']) . '</td>';
        } else {
            echo '<td align="center">' . htmlspecialchars($r['tgl_registrasi']) . '</td>';
            echo '<td>' . htmlspecialchars($r['nm_poli']) . '</td>';
            echo '<td>' . htmlspecialchars($r['dpjp']) . '</td>';
            echo '<td align="center">' . htmlspecialchars($r['stts_periksa'] ?? 'Sudah') . '</td>';
        }
        echo '<td align="center" style="background:' . $bg_status . '; font-weight:bold;">' . htmlspecialchars($r['status_resume']) . '</td>';
        echo '<td>' . htmlspecialchars($r['diagnosa_utama'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['prosedur_utama'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['obat_pulang'] ?: '-') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Monitoring Resume Medis (<?= strtoupper($tab) ?>) - RS Kartika Husada Jatiasih</title>
    
    <!-- Google Fonts & Font Awesome 6 -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <?php if ($auto_refresh > 0): ?>
    <meta http-equiv="refresh" content="<?= $auto_refresh ?>">
    <?php endif; ?>

    <style>
        :root {
            --primary: #047857;
            --primary-dark: #065f46;
            --primary-light: #ecfdf5;
            --accent: #0284c7;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --bg-body: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            font-size: 13px;
            line-height: 1.5;
            padding: 16px;
        }

        .dashboard-container {
            max-width: 1480px;
            margin: 0 auto;
        }

        /* Top Brand Header */
        .brand-header {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .brand-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .brand-logo {
            height: 52px;
            width: auto;
            object-fit: contain;
            border-radius: 6px;
        }
        .brand-titles h1 {
            font-size: 19px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.3px;
            text-transform: uppercase;
        }
        .brand-titles p {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .brand-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .clock-badge {
            background: #f1f5f9;
            padding: 6px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            color: #334155;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e2e8f0;
        }
        .clock-badge i { color: var(--primary); }

        /* Navigation Tabs: Rajal vs Ranap */
        .nav-tabs-wrapper {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            overflow-x: auto;
            padding-bottom: 4px;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            background: #ffffff;
            color: #475569;
            border: 1px solid var(--border-color);
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .tab-btn:hover {
            background: #f1f5f9;
            color: var(--primary);
            border-color: #cbd5e1;
        }
        .tab-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(4, 120, 87, 0.2);
        }
        .tab-btn .badge-pill {
            background: rgba(255,255,255,0.25);
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
        }
        .tab-btn:not(.active) .badge-pill {
            background: #e2e8f0;
            color: #475569;
        }

        /* Metric Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .metric-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(0,0,0,0.05);
        }
        .metric-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }
        .card-total::before   { background: #3b82f6; }
        .card-lengkap::before { background: var(--success); }
        .card-belum::before   { background: var(--danger); }
        .card-rate::before    { background: var(--primary); }
        .card-bpjs::before    { background: var(--accent); }

        .metric-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .metric-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .metric-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .icon-total   { background: #eff6ff; color: #2563eb; }
        .icon-lengkap { background: #dcfce7; color: var(--success); }
        .icon-belum   { background: #fee2e2; color: var(--danger); }
        .icon-rate    { background: #ecfdf5; color: var(--primary); }
        .icon-bpjs    { background: #e0f2fe; color: var(--accent); }

        .metric-value {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.1;
            margin-bottom: 4px;
        }
        .metric-sub {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Filter Section */
        .filter-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 18px 22px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 18px;
        }
        .filter-presets {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-presets span {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-right: 4px;
        }
        .btn-preset {
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            transition: all 0.15s;
        }
        .btn-preset:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #a7f3d0;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            align-items: flex-end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
            position: relative;
        }
        .form-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #475569;
            letter-spacing: 0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }
        .form-control, .form-select {
            height: 38px;
            padding: 7px 11px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 12.5px;
            background: #ffffff;
            color: #1e293b;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            width: 100%;
            box-sizing: border-box;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(4, 120, 87, 0.12);
        }

        /* Custom Searchable Select (Search di dalam Select Box) */
        .searchable-select-wrap {
            position: relative;
            width: 100%;
        }
        .searchable-select-trigger {
            height: 38px;
            padding: 7px 11px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 12.5px;
            background: #ffffff;
            color: #1e293b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            box-sizing: border-box;
        }
        .searchable-select-trigger:hover {
            border-color: #94a3b8;
        }
        .searchable-select-wrap.active .searchable-select-trigger {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(4, 120, 87, 0.12);
        }
        .searchable-select-trigger .selected-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 8px;
            font-weight: 500;
        }
        .searchable-select-trigger .chevron-icon {
            font-size: 11px;
            color: #64748b;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }
        .searchable-select-wrap.active .chevron-icon {
            transform: rotate(180deg);
        }
        .searchable-select-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            width: 100%;
            min-width: 260px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            z-index: 999;
            display: none;
            overflow: hidden;
            animation: fadeInSelect 0.15s ease-out;
        }
        @keyframes fadeInSelect {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .searchable-select-wrap.active .searchable-select-dropdown {
            display: block;
        }
        .searchable-select-search {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            background: #f8fafc;
            position: relative;
        }
        .searchable-select-search input {
            width: 100%;
            padding: 6px 10px 6px 28px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            background: #ffffff;
            box-sizing: border-box;
        }
        .searchable-select-search input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(4, 120, 87, 0.15);
        }
        .searchable-select-search .search-icon {
            position: absolute;
            left: 19px;
            top: 17px;
            font-size: 11px;
            color: #94a3b8;
        }
        .searchable-select-options {
            max-height: 220px;
            overflow-y: auto;
            padding: 4px 0;
        }
        .searchable-select-option {
            padding: 8px 12px;
            font-size: 12px;
            color: #1e293b;
            cursor: pointer;
            transition: background 0.1s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .searchable-select-option:hover {
            background: #f1f5f9;
            color: var(--primary);
        }
        .searchable-select-option.selected {
            background: #ecfdf5;
            color: var(--primary);
            font-weight: 700;
        }
        .searchable-select-option.hidden {
            display: none;
        }
        .searchable-select-empty {
            padding: 14px 12px;
            text-align: center;
            font-size: 11.5px;
            color: #94a3b8;
            display: none;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            grid-column: span 2;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-danger  { background: var(--danger); color: #fff; }
        .btn-danger:hover  { background: #b91c1c; }
        .btn-excel   { background: #15803d; color: #fff; }
        .btn-excel:hover   { background: #166534; }
        .btn-reset   { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .btn-reset:hover   { background: #e2e8f0; }

        /* Visual Analytics Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 16px;
            margin-bottom: 18px;
        }
        @media (max-width: 992px) {
            .charts-grid { grid-template-columns: 1fr; }
            .filter-actions { grid-column: span 1; }
        }
        .chart-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 18px 22px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            flex-direction: column;
        }
        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
        }
        .chart-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-title i { color: var(--primary); }
        .chart-container {
            position: relative;
            width: 100%;
            min-height: 240px;
            max-height: 290px;
        }

        /* Data Table Section */
        .table-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .table-header {
            padding: 16px 22px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .table-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .table-search {
            position: relative;
            max-width: 320px;
            width: 100%;
        }
        .table-search input {
            width: 100%;
            padding: 7px 12px 7px 32px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 12px;
        }
        .table-search i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        table.custom-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        table.custom-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.3px;
            padding: 10px 14px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
            white-space: nowrap;
        }
        table.custom-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #334155;
        }
        table.custom-table tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }
        .badge-lengkap { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-belum   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-bpjs    { background: #e0f2fe; color: #0369a1; }
        .badge-umum    { background: #fef3c7; color: #b45309; }

        .btn-view-resume {
            padding: 4px 9px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-view-resume:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #a7f3d0;
        }

        /* Table Pagination Styling */
        .table-footer {
            padding: 14px 22px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            background: #ffffff;
        }
        .pagination-info {
            font-size: 12px;
            color: #64748b;
        }
        .pagination-info b {
            color: #1e293b;
        }
        .pagination-nav {
            display: flex;
            align-items: center;
            gap: 4px;
            list-style: none;
            margin: 0;
            padding: 0;
            flex-wrap: wrap;
        }
        .page-link-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .page-link-btn:hover:not(:disabled) {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #a7f3d0;
        }
        .page-link-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(4, 120, 87, 0.25);
        }
        .page-link-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            background: #f8fafc;
        }
        .page-ellipsis {
            padding: 0 5px;
            color: #94a3b8;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
        }

        /* Modal Preview Resume */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
            border: 1px solid var(--border-color);
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-body {
            padding: 20px;
        }
        .detail-row {
            margin-bottom: 12px;
        }
        .detail-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 2px;
        }
        .detail-val {
            font-size: 13px;
            color: #1e293b;
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        /* Print Media Style */
        @media print {
            body { background: #fff; padding: 0; font-size: 11px; }
            .brand-header, .filter-box, .nav-tabs-wrapper, .filter-presets, .table-search, .btn-action, .table-footer { display: none !important; }
            .metric-card { box-shadow: none; border: 1px solid #ccc; }
            .chart-card { break-inside: avoid; border: 1px solid #ccc; margin-bottom: 15px; }
            .table-card { border: none; box-shadow: none; }
            table.custom-table th, table.custom-table td { border: 1px solid #000; padding: 4px 6px; }
            #resumeTable tbody tr { display: table-row !important; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">

    <!-- 1. HEADER BRANDING & LOGO -->
    <header class="brand-header">
        <div class="brand-left">
            <img src="file_20250905231623.logorskhj.jpg" alt="Logo RS Kartika Husada" class="brand-logo" onerror="this.style.display='none'; document.getElementById('alt-logo').style.display='flex';">
            <div id="alt-logo" style="display:none; width:48px; height:48px; border-radius:8px; background:var(--primary); color:#fff; align-items:center; justify-content:center; font-size:20px;">
                <i class="fa-solid fa-hospital"></i>
            </div>
            <div class="brand-titles">
                <h1>Dashboard Monitoring Resume Medis Elektronik (RME)</h1>
                <p>RS Kartika Husada Jatiasih &bull; Monitoring Pengisian Resume DPJP (Rawat Jalan & Rawat Inap)</p>
            </div>
        </div>

        <div class="brand-right">
            <div class="clock-badge">
                <i class="fa-regular fa-clock"></i>
                <span id="live-clock"><?= date('H:i:s') ?> WIB</span>
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:11px; font-weight:700; color:#64748b;"><i class="fa-solid fa-arrows-rotate"></i> Refresh:</label>
                <select class="form-select" style="width:auto; padding:4px 8px; font-size:11.5px;" onchange="window.location.href=updateUrlParam('refresh', this.value)">
                    <option value="0"   <?= $auto_refresh == 0 ? 'selected' : '' ?>>Nonaktif</option>
                    <option value="30"  <?= $auto_refresh == 30 ? 'selected' : '' ?>>30 Detik</option>
                    <option value="60"  <?= $auto_refresh == 60 ? 'selected' : '' ?>>1 Menit</option>
                    <option value="300" <?= $auto_refresh == 300 ? 'selected' : '' ?>>5 Menit</option>
                </select>
            </div>
        </div>
    </header>

    <!-- 2. NAVIGASI TAB MODUL TERPADU (RAJAL VS RANAP) -->
    <nav class="nav-tabs-wrapper">
        <a class="tab-btn <?= $tab === 'rajal' ? 'active' : '' ?>" href="?tab=rajal&tgl_awal=<?= urlencode($tgl_awal) ?>&tgl_akhir=<?= urlencode($tgl_akhir) ?>&filter_pj=<?= urlencode($filter_pj) ?>">
            <i class="fa-solid fa-stethoscope"></i> Resume Rawat Jalan (Rajal)
            <span class="badge-pill"><?= $tab === 'rajal' ? number_format($total_pasien, 0, ',', '.') : 'Rajal' ?></span>
        </a>
        <a class="tab-btn <?= $tab === 'ranap' ? 'active' : '' ?>" href="?tab=ranap&tgl_awal=<?= urlencode($tgl_awal) ?>&tgl_akhir=<?= urlencode($tgl_akhir) ?>&filter_pj=<?= urlencode($filter_pj) ?>">
            <i class="fa-solid fa-bed-pulse"></i> Resume Rawat Inap (Ranap)
            <span class="badge-pill"><?= $tab === 'ranap' ? number_format($total_pasien, 0, ',', '.') : 'Ranap' ?></span>
        </a>
        <div style="margin-left:auto; display:flex; gap:8px;">
            <a class="tab-btn" href="dashboard_ekspedisi_ranap.php">
                <i class="fa-solid fa-book-medical"></i> Buku Ekspedisi Ranap
            </a>
            <a class="tab-btn" href="dashboard_suratkontrol.php">
                <i class="fa-solid fa-file-circle-check"></i> Surat Kontrol & SEP
            </a>
        </div>
    </nav>

    <!-- 3. KARTU METRIK STATISTIK RESUME -->
    <div class="metrics-grid">
        <div class="metric-card card-total">
            <div class="metric-header">
                <span class="metric-title">Total Pasien (<?= strtoupper($tab) ?>)</span>
                <div class="metric-icon icon-total"><i class="fa-solid fa-users"></i></div>
            </div>
            <div class="metric-value"><?= number_format($total_pasien, 0, ',', '.') ?></div>
            <div class="metric-sub">
                <?php if ($tab === 'ranap'): ?>
                    <i class="fa-regular fa-calendar-check"></i> Pasien Pulang / Inap
                <?php else: ?>
                    <i class="fa-regular fa-calendar-check"></i> Registrasi Rawat Jalan
                <?php endif; ?>
            </div>
        </div>

        <div class="metric-card card-lengkap">
            <div class="metric-header">
                <span class="metric-title">Resume Lengkap / Ada</span>
                <div class="metric-icon icon-lengkap"><i class="fa-solid fa-circle-check"></i></div>
            </div>
            <div class="metric-value" style="color:var(--success);"><?= number_format($total_lengkap, 0, ',', '.') ?></div>
            <div class="metric-sub"><b style="color:var(--success);"><?= $persen_lengkap ?>%</b> telah diisi dokter DPJP</div>
        </div>

        <div class="metric-card card-belum">
            <div class="metric-header">
                <span class="metric-title" style="color:#b91c1c;">Resume Belum Diisi</span>
                <div class="metric-icon icon-belum"><i class="fa-solid fa-triangle-exclamation"></i></div>
            </div>
            <div class="metric-value" style="color:var(--danger);"><?= number_format($total_belum, 0, ',', '.') ?></div>
            <div class="metric-sub"><b style="color:var(--danger);"><?= $persen_belum ?>%</b> belum terisi oleh dokter</div>
        </div>

        <div class="metric-card card-rate">
            <div class="metric-header">
                <span class="metric-title">Tingkat Kepatuhan DPJP</span>
                <div class="metric-icon icon-rate"><i class="fa-solid fa-chart-pie"></i></div>
            </div>
            <div class="metric-value" style="color:var(--primary);"><?= $persen_lengkap ?>%</div>
            <div class="metric-sub">
                <div style="background:#e2e8f0; border-radius:999px; height:6px; overflow:hidden; margin-top:4px;">
                    <div style="background:var(--primary); width:<?= min(100, $persen_lengkap) ?>%; height:100%;"></div>
                </div>
            </div>
        </div>

        <div class="metric-card card-bpjs">
            <div class="metric-header">
                <span class="metric-title">Proporsi Jaminan Pasien</span>
                <div class="metric-icon icon-bpjs"><i class="fa-solid fa-id-card"></i></div>
            </div>
            <div class="metric-value" style="font-size:20px; margin-top:4px;">
                <span style="color:#0284c7;"><?= number_format($total_bpjs, 0, ',', '.') ?></span> 
                <span style="color:#cbd5e1; font-weight:400;">/</span> 
                <span style="color:#b45309;"><?= number_format($total_nonbpjs, 0, ',', '.') ?></span>
            </div>
            <div class="metric-sub"><b><?= $total_bpjs ?></b> BPJS &bull; <b><?= $total_nonbpjs ?></b> Umum/Asuransi</div>
        </div>
    </div>

    <!-- 4. FORM FILTER & PENCARIAN -->
    <div class="filter-box">
        <div class="filter-presets">
            <span><i class="fa-solid fa-bolt"></i> Shortcut:</span>
            <a href="?tab=<?= $tab ?>&preset=today&filter_pj=<?= urlencode($filter_pj) ?>" class="btn-preset">Hari Ini</a>
            <a href="?tab=<?= $tab ?>&preset=yesterday&filter_pj=<?= urlencode($filter_pj) ?>" class="btn-preset">Kemarin</a>
            <a href="?tab=<?= $tab ?>&preset=last7&filter_pj=<?= urlencode($filter_pj) ?>" class="btn-preset">7 Hari Terakhir</a>
            <a href="?tab=<?= $tab ?>&preset=this_month&filter_pj=<?= urlencode($filter_pj) ?>" class="btn-preset">Bulan Ini</a>
            <a href="?tab=<?= $tab ?>&preset=last_month&filter_pj=<?= urlencode($filter_pj) ?>" class="btn-preset">Bulan Kemarin</a>
        </div>

        <form method="get" action="">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            
            <div class="filter-grid">
                <?php if ($tab === 'ranap'): ?>
                <div class="form-group">
                    <label class="form-label">Basis Tanggal Ranap</label>
                    <select name="basis_tgl" class="form-select" style="font-weight:700; color:#0f766e;">
                        <option value="pulang" <?= $basis_tgl === 'pulang' ? 'selected' : '' ?>>Tanggal Pulang (Keluar)</option>
                        <option value="masuk" <?= $basis_tgl === 'masuk' ? 'selected' : '' ?>>Tanggal Masuk Ranap</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label"><?= $tab === 'ranap' ? ($basis_tgl === 'masuk' ? 'Tgl. Masuk Mulai' : 'Tgl. Pulang Mulai') : 'Tgl. Registrasi Mulai' ?></label>
                    <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tgl_awal) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= $tab === 'ranap' ? ($basis_tgl === 'masuk' ? 'Tgl. Masuk Selesai' : 'Tgl. Pulang Selesai') : 'Tgl. Registrasi Selesai' ?></label>
                    <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tgl_akhir) ?>" required>
                </div>

                <!-- Filter Jaminan / Penjab (Search di dalam Select Box) -->
                <?php
                $current_pj_label = '-- Semua Jaminan --';
                if ($filter_pj === 'bpjs') {
                    $current_pj_label = '⭐ Jaminan BPJS Saja';
                } else {
                    foreach ($list_penjab as $pj) {
                        if ($filter_pj === $pj['kd_pj']) {
                            $current_pj_label = $pj['png_jawab'];
                            break;
                        }
                    }
                }
                ?>
                <div class="form-group" style="min-width:210px;">
                    <label class="form-label"><i class="fa-solid fa-shield-halved text-emerald-600"></i> Jaminan / Penjab</label>
                    <div class="searchable-select-wrap" id="wrapPenjab">
                        <input type="hidden" name="filter_pj" id="valPenjab" value="<?= htmlspecialchars($filter_pj) ?>">
                        
                        <div class="searchable-select-trigger" id="triggerPenjab" onclick="togglePenjabDropdown(event)">
                            <span class="selected-text" id="labelPenjab"><?= htmlspecialchars($current_pj_label) ?></span>
                            <i class="fa-solid fa-chevron-down chevron-icon"></i>
                        </div>

                        <div class="searchable-select-dropdown" id="dropdownPenjab" onclick="event.stopPropagation()">
                            <div class="searchable-select-search">
                                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                                <input type="text" id="searchInSelect" placeholder="Ketik cari nama jaminan..." onkeyup="filterInSelectOptions(this.value)" autocomplete="off">
                            </div>
                            <div class="searchable-select-options" id="optionsPenjabList">
                                <div class="searchable-select-option <?= $filter_pj === 'semua' ? 'selected' : '' ?>" data-val="semua" onclick="selectPenjabOption('semua', '-- Semua Jaminan --')">
                                    <span>-- Semua Jaminan --</span>
                                    <?php if ($filter_pj === 'semua'): ?><i class="fa-solid fa-check text-xs"></i><?php endif; ?>
                                </div>
                                <div class="searchable-select-option <?= $filter_pj === 'bpjs' ? 'selected' : '' ?>" data-val="bpjs" onclick="selectPenjabOption('bpjs', '⭐ Jaminan BPJS Saja')">
                                    <span style="color:#0284c7; font-weight:600;">⭐ Jaminan BPJS Saja</span>
                                    <?php if ($filter_pj === 'bpjs'): ?><i class="fa-solid fa-check text-xs"></i><?php endif; ?>
                                </div>
                                <?php foreach ($list_penjab as $pj): 
                                    $is_sel = ($filter_pj === $pj['kd_pj']);
                                ?>
                                <div class="searchable-select-option <?= $is_sel ? 'selected' : '' ?>" data-val="<?= htmlspecialchars($pj['kd_pj']) ?>" onclick="selectPenjabOption('<?= htmlspecialchars($pj['kd_pj']) ?>', '<?= htmlspecialchars(addslashes($pj['png_jawab'])) ?>')">
                                    <span><?= htmlspecialchars($pj['png_jawab']) ?></span>
                                    <?php if ($is_sel): ?><i class="fa-solid fa-check text-xs"></i><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <div class="searchable-select-empty" id="emptyPenjabSearch">
                                    Tidak ada jaminan yang cocok
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Status Pasien (Registrasi) -->
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-user-check text-blue-600"></i> Status Pasien</label>
                    <select name="status_periksa" class="form-select" style="font-weight:600;">
                        <option value="bukan_batal" <?= $status_periksa === 'bukan_batal' ? 'selected' : '' ?>>⭐ Aktif / Bukan Batal (Default)</option>
                        <option value="semua" <?= $status_periksa === 'semua' ? 'selected' : '' ?>>-- Semua Status (Termasuk Batal) --</option>
                        <option value="Sudah" <?= $status_periksa === 'Sudah' ? 'selected' : '' ?>>Sudah Diperiksa</option>
                        <option value="Belum" <?= $status_periksa === 'Belum' ? 'selected' : '' ?>>Belum Diperiksa</option>
                        <option value="Dirawat" <?= $status_periksa === 'Dirawat' ? 'selected' : '' ?>>Dirawat</option>
                        <option value="Dirujuk" <?= $status_periksa === 'Dirujuk' ? 'selected' : '' ?>>Dirujuk</option>
                        <option value="Batal" <?= $status_periksa === 'Batal' ? 'selected' : '' ?>>❌ Khusus Pasien Batal</option>
                    </select>
                </div>

                <?php if ($tab === 'ranap'): ?>
                <!-- Filter Status Pulang Ranap (Sesuai Arahan Mas Amri) -->
                <div class="form-group">
                    <label class="form-label"><i class="fa-solid fa-door-open text-amber-600"></i> Status Pulang Ranap</label>
                    <select name="status_pulang" class="form-select" style="font-weight:600; color:#b45309;">
                        <option value="pulang_valid" <?= $status_pulang === 'pulang_valid' ? 'selected' : '' ?>>⭐ Pasien Pulang (Kecuali '-' & Pindah Kamar)</option>
                        <option value="Atas Persetujuan Dokter" <?= $status_pulang === 'Atas Persetujuan Dokter' ? 'selected' : '' ?>>1. Atas Persetujuan Dokter</option>
                        <option value="Rujuk" <?= $status_pulang === 'Rujuk' ? 'selected' : '' ?>>2. Rujuk</option>
                        <option value="Meninggal" <?= $status_pulang === 'Meninggal' ? 'selected' : '' ?>>3. Meninggal</option>
                        <option value="Atas Permintaan Sendiri" <?= $status_pulang === 'Atas Permintaan Sendiri' ? 'selected' : '' ?>>4. Atas Permintaan Sendiri (APS)</option>
                        <option value="semua" <?= $status_pulang === 'semua' ? 'selected' : '' ?>>-- Semua Status Pulang (Termasuk Masih Dirawat) --</option>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($tab === 'ranap'): ?>
                <div class="form-group">
                    <label class="form-label">Bangsal / Ruang Rawat</label>
                    <select name="kd_bangsal" class="form-select">
                        <option value="">-- Semua Bangsal --</option>
                        <?php foreach ($list_bangsal as $bg): ?>
                        <option value="<?= htmlspecialchars($bg['kd_bangsal']) ?>" <?= $kd_bangsal === $bg['kd_bangsal'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bg['nm_bangsal']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label class="form-label">Poliklinik Tujuan</label>
                    <select name="kd_poli" class="form-select">
                        <option value="">-- Semua Poliklinik --</option>
                        <?php foreach ($list_poli as $lp): ?>
                        <option value="<?= htmlspecialchars($lp['kd_poli']) ?>" <?= $kd_poli === $lp['kd_poli'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lp['nm_poli']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Dokter DPJP</label>
                    <select name="kd_dokter" class="form-select">
                        <option value="">-- Semua Dokter --</option>
                        <?php foreach ($list_dokter as $ld): ?>
                        <option value="<?= htmlspecialchars($ld['kd_dokter']) ?>" <?= $kd_dokter === $ld['kd_dokter'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ld['nm_dokter']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Status Resume</label>
                    <select name="status_resume" class="form-select">
                        <option value="semua" <?= $status_resume === 'semua' ? 'selected' : '' ?>>-- Semua Status --</option>
                        <option value="lengkap" <?= $status_resume === 'lengkap' ? 'selected' : '' ?>>✅ Lengkap / Sudah Diisi</option>
                        <option value="belum" <?= $status_resume === 'belum' ? 'selected' : '' ?>>⚠️ Belum Diisi Dokter</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Kata Kunci</label>
                    <input type="text" name="keyword" class="form-control" placeholder="No. Rawat / RM / Nama / SEP..." value="<?= htmlspecialchars($keyword) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-action btn-primary">
                        <i class="fa-solid fa-filter"></i> Terapkan Filter
                    </button>
                    <?php if ($status_resume !== 'belum'): ?>
                    <a href="?tab=<?= $tab ?>&status_resume=belum&tgl_awal=<?= urlencode($tgl_awal) ?>&tgl_akhir=<?= urlencode($tgl_akhir) ?>&filter_pj=<?= urlencode($filter_pj) ?>" class="btn-action btn-danger" title="Langsung tampilkan hanya pasien yang resume-nya belum diisi">
                        <i class="fa-solid fa-triangle-exclamation"></i> Lihat Belum Resume (<?= number_format($total_belum, 0, ',', '.') ?>)
                    </a>
                    <?php endif; ?>
                    <a href="?tab=<?= $tab ?>" class="btn-action btn-reset">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                    <?php
                    $export_params = $_GET;
                    $export_params['action'] = 'export_excel';
                    $export_url = '?' . http_build_query($export_params);
                    ?>
                    <a href="<?= htmlspecialchars($export_url) ?>" class="btn-action btn-excel">
                        <i class="fa-solid fa-file-excel"></i> Export Excel
                    </a>
                    <button type="button" class="btn-action btn-reset" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Cetak
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- 5. ANALITIK GRAFIK & STATISTIK KOMPARASI VISUAL -->
    <div class="charts-grid">
        <!-- Grafik Tren Harian -->
        <div class="chart-card">
            <div class="chart-header">
                <span class="chart-title">
                    <i class="fa-solid fa-chart-column"></i> Tren Harian Kepatuhan Resume Medis (<?= strtoupper($tab) ?>)
                </span>
                <span style="font-size:11px; color:var(--text-muted);">
                    <i class="fa-regular fa-calendar"></i> Berdasarkan Tanggal <?= $tab === 'ranap' ? 'Pulang/Keluar' : 'Registrasi' ?>
                </span>
            </div>
            <div class="chart-container">
                <canvas id="chartTrendResume"></canvas>
            </div>
        </div>

        <!-- Grafik Donut Status Kepatuhan -->
        <div class="chart-card">
            <div class="chart-header">
                <span class="chart-title"><i class="fa-solid fa-chart-pie"></i> Proporsi Kelengkapan Resume</span>
                <span style="font-size:11px; font-weight:700; color:var(--primary);"><?= number_format($total_pasien, 0, ',', '.') ?> Data</span>
            </div>
            <div class="chart-container" style="display:flex; align-items:center; justify-content:center;">
                <canvas id="chartDonutResume" style="max-height:240px;"></canvas>
            </div>
        </div>
    </div>

    <div class="charts-grid" style="grid-template-columns: 1fr 1fr;">
        <!-- Grafik Per Unit (Poli / Bangsal) -->
        <div class="chart-card">
            <div class="chart-header">
                <span class="chart-title">
                    <i class="fa-solid fa-hospital"></i> 7 <?= $tab === 'ranap' ? 'Bangsal Rawat Inap' : 'Poliklinik Rawat Jalan' ?> Terbanyak
                </span>
            </div>
            <div class="chart-container">
                <canvas id="chartTopUnit"></canvas>
            </div>
        </div>

        <!-- Grafik Dokter DPJP dengan Resume Belum Diisi -->
        <div class="chart-card">
            <div class="chart-header">
                <span class="chart-title">
                    <i class="fa-solid fa-user-doctor"></i> 7 Dokter DPJP dengan Resume Belum Terisi Terbanyak
                </span>
            </div>
            <div class="chart-container">
                <canvas id="chartTopDokter"></canvas>
            </div>
        </div>
    </div>

    <!-- 6. TABEL DATA MONITORING RESUME -->
    <div class="table-card">
        <div class="table-header">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <span class="table-title">
                    <i class="fa-solid fa-table-list"></i> Data Monitoring Resume Medis <?= strtoupper($tab) ?>
                    <span class="badge" style="background:#f1f5f9; color:#475569; font-size:12px; font-weight:700;">
                        <span id="badgeTotalPasien"><?= count($rows) ?></span> Pasien
                    </span>
                </span>
                
                <div style="display:flex; align-items:center; gap:6px; font-size:12px; color:#64748b; margin-left:6px;">
                    <label for="pageSizeSelect" style="font-weight:600;"><i class="fa-solid fa-list-ol"></i> Tampilkan:</label>
                    <select id="pageSizeSelect" class="form-select" style="width:auto; padding:4px 8px; font-size:12px; font-weight:600;" onchange="changePageSize(this.value)">
                        <option value="10">10 data</option>
                        <option value="25" selected>25 data</option>
                        <option value="50">50 data</option>
                        <option value="100">100 data</option>
                        <option value="250">250 data</option>
                        <option value="all">Semua</option>
                    </select>
                </div>
            </div>

            <div class="table-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="liveTableSearch" placeholder="Cari di tabel (Nama, No RM, SEP, DPJP)..." onkeyup="handleLiveSearch()">
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-table" id="resumeTable">
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;">No</th>
                        <th>No. Rawat & RM</th>
                        <th>Nama Pasien</th>
                        <th>Jaminan</th>
                        <th>No. SEP</th>
                        <?php if ($tab === 'ranap'): ?>
                            <th>Tgl. Masuk</th>
                            <th>Tgl. Pulang</th>
                            <th style="text-align:center;">Lama</th>
                            <th style="text-align:center;">Status Pulang</th>
                            <th>Bangsal / Kamar</th>
                            <th>DPJP Ranap</th>
                        <?php else: ?>
                            <th>Tgl. Registrasi</th>
                            <th>Poliklinik</th>
                            <th>Dokter DPJP</th>
                            <th style="text-align:center;">Status Reg</th>
                        <?php endif; ?>
                        <th style="text-align:center;">Status Resume</th>
                        <th>Diagnosa Utama</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?= $tab === 'ranap' ? '13' : '11' ?>">
                            <div style="text-align:center; padding:40px; color:#64748b;">
                                <i class="fa-regular fa-folder-open" style="font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
                                <h4 style="font-size:15px; color:#334155; margin-bottom:6px;">Tidak Ada Data Ditemukan</h4>
                                <p style="font-size:12px;">Silakan sesuaikan filter tanggal atau filter jaminan di atas.</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($rows as $r): 
                            $is_ok = ($r['status_resume'] === 'Lengkap');
                            $is_bpjs = (stripos($r['jaminan'], 'BPJS') !== false);
                            $wa_number = formatNomorWA($r['no_tlp']);

                            $detail_data = htmlspecialchars(json_encode([
                                'no_rawat'       => $r['no_rawat'],
                                'no_rkm_medis'   => $r['no_rkm_medis'],
                                'nm_pasien'      => $r['nm_pasien'],
                                'dpjp'           => $r['dpjp'],
                                'unit'           => $tab === 'ranap' ? $r['nm_bangsal'] : $r['nm_poli'],
                                'status_resume'  => $r['status_resume'],
                                'diagnosa_utama' => $r['diagnosa_utama'] ?? '-',
                                'kd_diagnosa'    => $r['kd_diagnosa_utama'] ?? '-',
                                'diagnosa_sek'   => $r['diagnosa_sekunder'] ?? '-',
                                'prosedur'       => $r['prosedur_utama'] ?? '-',
                                'kondisi'        => $tab === 'ranap' ? ($r['keadaan'] ?? '-') : ($r['kondisi_pulang'] ?? '-'),
                                'obat_pulang'    => $r['obat_pulang'] ?? '-'
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td style="text-align:center; font-weight:600; color:#64748b;"><?= $no++ ?></td>
                            <td>
                                <div style="font-weight:700; color:#0f172a; font-family:monospace;"><?= htmlspecialchars($r['no_rawat']) ?></div>
                                <div style="font-size:11px; color:#64748b;">RM: <span style="font-family:monospace; font-weight:700;"><?= htmlspecialchars($r['no_rkm_medis']) ?></span></div>
                            </td>
                            <td>
                                <div style="font-weight:700; color:#0f172a;"><?= htmlspecialchars($r['nm_pasien']) ?></div>
                                <div style="font-size:11px; color:#64748b;">JK: <?= htmlspecialchars($r['jk'] === 'L' ? 'Laki-laki' : ($r['jk'] === 'P' ? 'Perempuan' : '-')) ?></div>
                            </td>
                            <td>
                                <?php if ($is_bpjs): ?>
                                    <span class="badge badge-bpjs"><i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($r['jaminan']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-umum"><?= htmlspecialchars($r['jaminan']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-family:monospace; font-size:11px; color:#475569;"><?= htmlspecialchars($r['no_sep']) ?></div>
                            </td>

                            <?php if ($tab === 'ranap'): ?>
                                <td style="font-size:11.5px;"><?= htmlspecialchars(tglIndo($r['tgl_masuk'], false)) ?></td>
                                <td>
                                    <?php if (!empty($r['tgl_keluar']) && $r['tgl_keluar'] !== '0000-00-00'): ?>
                                        <div style="font-weight:700; color:#0f172a; font-size:11.5px;"><?= htmlspecialchars(tglIndo($r['tgl_keluar'], false)) ?></div>
                                        <div style="font-size:10px; color:#059669;"><i class="fa-solid fa-check"></i> Sudah Pulang</div>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fef3c7; color:#b45309; font-size:10px;"><i class="fa-solid fa-bed"></i> Masih Dirawat</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center; font-weight:700; color:#334155;"><?= htmlspecialchars($r['lama_inap'] ?? 0) ?> hr</td>
                                <td style="text-align:center;">
                                    <?php 
                                        $sp = $r['stts_pulang'] ?? '-';
                                        $sp_color = '#334155'; $sp_bg = '#f1f5f9';
                                        if (in_array($sp, ['Atas Persetujuan Dokter', 'Sembuh', 'Membaik'])) {
                                            $sp_color = '#15803d'; $sp_bg = '#dcfce7';
                                        } elseif ($sp === 'Rujuk') {
                                            $sp_color = '#0369a1'; $sp_bg = '#e0f2fe';
                                        } elseif (in_array($sp, ['Meninggal', '+'])) {
                                            $sp_color = '#991b1b'; $sp_bg = '#fee2e2';
                                        } elseif (in_array($sp, ['Atas Permintaan Sendiri', 'APS', 'Pulang Paksa'])) {
                                            $sp_color = '#c2410c'; $sp_bg = '#ffedd5';
                                        }
                                    ?>
                                    <span class="badge" style="background:<?= $sp_bg ?>; color:<?= $sp_color ?>; font-size:10.5px; font-weight:600;">
                                        <?= htmlspecialchars($sp) ?>
                                    </span>
                                </td>
                                <td><span style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($r['nm_bangsal']) ?></span></td>
                                <td><div style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($r['dpjp']) ?></div></td>
                            <?php else: ?>
                                <td>
                                    <div style="font-weight:600; color:#0f172a; font-size:11.5px;"><?= htmlspecialchars(tglIndo($r['tgl_registrasi'], false)) ?></div>
                                    <div style="font-size:10px; color:#64748b;"><?= htmlspecialchars($r['jam_reg'] ?? '') ?> WIB</div>
                                </td>
                                <td><span style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($r['nm_poli']) ?></span></td>
                                <td><div style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($r['dpjp']) ?></div></td>
                                <td style="text-align:center;">
                                    <?php 
                                        $st = $r['stts_periksa'] ?? 'Belum';
                                        $st_bg = '#f1f5f9'; $st_cl = '#475569';
                                        if ($st === 'Sudah') { $st_bg = '#dcfce7'; $st_cl = '#166534'; }
                                        elseif ($st === 'Belum') { $st_bg = '#fef3c7'; $st_cl = '#b45309'; }
                                        elseif ($st === 'Batal') { $st_bg = '#fee2e2'; $st_cl = '#991b1b'; }
                                    ?>
                                    <span class="badge" style="background:<?= $st_bg ?>; color:<?= $st_cl ?>; font-size:10.5px; font-weight:600;">
                                        <?= htmlspecialchars($st) ?>
                                    </span>
                                </td>
                            <?php endif; ?>

                            <td style="text-align:center;">
                                <?php if ($is_ok): ?>
                                    <span class="badge badge-lengkap"><i class="fa-solid fa-circle-check"></i> Lengkap</span>
                                <?php else: ?>
                                    <span class="badge badge-belum"><i class="fa-solid fa-triangle-exclamation"></i> Belum Diisi</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($r['diagnosa_utama'])): ?>
                                    <div style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($r['diagnosa_utama']) ?></div>
                                    <?php if (!empty($r['kd_diagnosa_utama'])): ?>
                                        <small style="color:#0284c7; font-family:monospace;">[<?= htmlspecialchars($r['kd_diagnosa_utama']) ?>]</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-style:italic;">-</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:center;">
                                <button type="button" class="btn-view-resume" onclick="viewDetailResume(this)" data-resume='<?= $detail_data ?>'>
                                    <i class="fa-solid fa-eye"></i> Detail
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION & INFO CONTROLS -->
        <div class="table-footer">
            <div class="pagination-info" id="paginationInfo">
                Menampilkan <b id="infoStart">0</b> s/d <b id="infoEnd">0</b> dari <b id="infoFiltered">0</b> data
                <span id="infoTotalWrapper" style="color:#94a3b8;">(Total <b><?= count($rows) ?></b> pasien dalam filter)</span>
            </div>
            <nav aria-label="Navigasi Halaman Tabel">
                <ul class="pagination-nav" id="paginationNav">
                    <!-- Tombol pagination digenerate otomatis via JavaScript -->
                </ul>
            </nav>
        </div>
    </div>

    <!-- FOOTER COPYRIGHT -->
    <footer style="text-align:center; font-size:12px; color:#64748b; margin-top:28px; margin-bottom:12px; padding-top:16px; border-top:1px solid #e2e8f0; font-weight:600;">
        &copy; <?= date('Y'); ?> IT RS Kartika Husada Jatiasih - Iqbal Alyansyah
    </footer>

</div>

<!-- 7. MODAL DETAIL RESUME MEDIS -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box">
        <div class="modal-header">
            <h4 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">
                <i class="fa-solid fa-file-waveform" style="color:var(--primary);"></i> Rincian Resume Medis Elektronik
            </h4>
            <button type="button" onclick="closeModal()" style="border:none; background:none; font-size:18px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:16px; background:#f1f5f9; padding:12px; border-radius:8px;">
                <div>
                    <div class="detail-label">No. Rawat</div>
                    <b id="m_no_rawat" style="font-family:monospace; color:#0f172a;">-</b>
                </div>
                <div>
                    <div class="detail-label">No. RM & Nama Pasien</div>
                    <b id="m_pasien" style="color:#0f172a;">-</b>
                </div>
                <div>
                    <div class="detail-label">Dokter DPJP</div>
                    <b id="m_dpjp" style="color:#0f172a;">-</b>
                </div>
                <div>
                    <div class="detail-label"><?= $tab === 'ranap' ? 'Bangsal' : 'Poliklinik' ?></div>
                    <b id="m_unit" style="color:#0f172a;">-</b>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Status Resume</div>
                <div id="m_status_resume" style="font-weight:700;">-</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Diagnosa Utama (ICD-10)</div>
                <div class="detail-val" id="m_diagnosa_utama">-</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Diagnosa Sekunder</div>
                <div class="detail-val" id="m_diagnosa_sek">-</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Prosedur / Tindakan (ICD-9 CM)</div>
                <div class="detail-val" id="m_prosedur">-</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Kondisi / Keadaan Pulang</div>
                <div class="detail-val" id="m_kondisi">-</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Terapi Obat Pulang</div>
                <div class="detail-val" id="m_obat">-</div>
            </div>
        </div>
        <div style="padding:14px 20px; border-top:1px solid #f1f5f9; text-align:right;">
            <button type="button" class="btn-action btn-reset" onclick="closeModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- JAVASCRIPT: CHART.JS & CLIENT-SIDE INTERACTIVITY -->
<script>
// Jam Digital Real-Time (Asia/Jakarta - WIB)
function updateClock() {
    try {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-GB', {
            timeZone: 'Asia/Jakarta',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
        const clockEl = document.getElementById('live-clock');
        if (clockEl) clockEl.textContent = `${timeStr} WIB`;
    } catch (e) {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        const clockEl = document.getElementById('live-clock');
        if (clockEl) clockEl.textContent = `${hours}:${minutes}:${seconds} WIB`;
    }
}
updateClock();
setInterval(updateClock, 1000);

// Helper URL parameter update
function updateUrlParam(key, value) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, value);
    return url.toString();
}

// Modal Detail Resume
function viewDetailResume(btn) {
    const data = JSON.parse(btn.getAttribute('data-resume') || '{}');
    document.getElementById('m_no_rawat').textContent = data.no_rawat || '-';
    document.getElementById('m_pasien').textContent = (data.no_rkm_medis || '') + ' - ' + (data.nm_pasien || '');
    document.getElementById('m_dpjp').textContent = data.dpjp || '-';
    document.getElementById('m_unit').textContent = data.unit || '-';
    
    const stEl = document.getElementById('m_status_resume');
    if (data.status_resume === 'Lengkap') {
        stEl.innerHTML = '<span class="badge badge-lengkap"><i class="fa-solid fa-circle-check"></i> Lengkap / Terisi</span>';
    } else {
        stEl.innerHTML = '<span class="badge badge-belum"><i class="fa-solid fa-triangle-exclamation"></i> Belum Diisi Dokter</span>';
    }

    document.getElementById('m_diagnosa_utama').textContent = (data.diagnosa_utama && data.diagnosa_utama !== '-') ? `${data.diagnosa_utama} [${data.kd_diagnosa || ''}]` : 'Belum diisi';
    document.getElementById('m_diagnosa_sek').textContent = data.diagnosa_sek || '-';
    document.getElementById('m_prosedur').textContent = data.prosedur || '-';
    document.getElementById('m_kondisi').textContent = data.kondisi || '-';
    document.getElementById('m_obat').textContent = data.obat_pulang || '-';

    document.getElementById('detailModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('detailModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('detailModal');
    if (event.target === modal) {
        closeModal();
    }
};

// ============================================================================
// CLIENT-SIDE SEARCH & SMART PAGINATION CONTROLLER
// ============================================================================
let allTableRows = [];
let matchingRows = [];
let currentPage = 1;
let pageSize = 25;

function initPagination() {
    const tableBody = document.querySelector("#resumeTable tbody");
    if (!tableBody) return;
    
    const rows = Array.from(tableBody.querySelectorAll("tr"));
    allTableRows = rows.filter(r => !r.id.startsWith("emptySearchResult") && r.cells.length > 1);
    matchingRows = [...allTableRows];
    
    renderPagination();
}

function handleLiveSearch() {
    const query = (document.getElementById("liveTableSearch")?.value || "").trim().toUpperCase();
    
    if (!query) {
        matchingRows = [...allTableRows];
    } else {
        matchingRows = allTableRows.filter(row => {
            const text = (row.textContent || row.innerText || "").toUpperCase();
            return text.indexOf(query) > -1;
        });
    }
    
    currentPage = 1;
    renderPagination();
}

function changePageSize(val) {
    if (val === 'all') {
        pageSize = matchingRows.length || 999999;
    } else {
        pageSize = parseInt(val, 10) || 25;
    }
    currentPage = 1;
    renderPagination();
}

function goToPage(p) {
    currentPage = p;
    renderPagination();
    const tableCard = document.querySelector(".table-card");
    if (tableCard && window.scrollY > tableCard.offsetTop + 100) {
        tableCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function renderPagination() {
    const totalMatching = matchingRows.length;
    const totalPages = Math.max(1, Math.ceil(totalMatching / pageSize));
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;
    
    const startIdx = (currentPage - 1) * pageSize;
    const endIdx = Math.min(startIdx + pageSize, totalMatching);
    
    allTableRows.forEach(r => r.style.display = "none");
    
    for (let i = startIdx; i < endIdx; i++) {
        const row = matchingRows[i];
        row.style.display = "";
        const noCell = row.cells[0];
        if (noCell) noCell.textContent = i + 1;
    }
    
    let emptyRow = document.getElementById("emptySearchResultRow");
    if (totalMatching === 0 && allTableRows.length > 0) {
        if (!emptyRow) {
            emptyRow = document.createElement("tr");
            emptyRow.id = "emptySearchResultRow";
            const colCount = <?= $tab === 'ranap' ? 13 : 11 ?>;
            emptyRow.innerHTML = `
                <td colspan="${colCount}" style="text-align:center; padding:36px; color:#64748b;">
                    <i class="fa-solid fa-magnifying-glass" style="font-size:26px; color:#cbd5e1; margin-bottom:10px; display:block;"></i>
                    <b style="font-size:13px; color:#334155;">Tidak ada data pasien yang cocok dengan pencarian Anda.</b>
                </td>`;
            document.querySelector("#resumeTable tbody")?.appendChild(emptyRow);
        }
        emptyRow.style.display = "";
    } else if (emptyRow) {
        emptyRow.style.display = "none";
    }
    
    const infoStart = document.getElementById("infoStart");
    const infoEnd = document.getElementById("infoEnd");
    const infoFiltered = document.getElementById("infoFiltered");
    if (infoStart) infoStart.textContent = totalMatching > 0 ? (startIdx + 1).toLocaleString('id-ID') : 0;
    if (infoEnd) infoEnd.textContent = endIdx.toLocaleString('id-ID');
    if (infoFiltered) infoFiltered.textContent = totalMatching.toLocaleString('id-ID');
    
    const badgeTotal = document.getElementById("badgeTotalPasien");
    if (badgeTotal) badgeTotal.textContent = totalMatching.toLocaleString('id-ID');
    
    renderPageButtons(totalPages);
}

function renderPageButtons(totalPages) {
    const nav = document.getElementById("paginationNav");
    if (!nav) return;
    nav.innerHTML = "";
    
    if (totalPages <= 1) return;
    
    nav.appendChild(createPageBtn('<i class="fa-solid fa-angles-left"></i>', 1, currentPage === 1, false, "Halaman Pertama"));
    nav.appendChild(createPageBtn('<i class="fa-solid fa-angle-left"></i>', currentPage - 1, currentPage === 1, false, "Halaman Sebelumnya"));
    
    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    if (currentPage <= 3) {
        endPage = Math.min(totalPages, maxVisible);
    } else if (currentPage >= totalPages - 2) {
        startPage = Math.max(1, totalPages - maxVisible + 1);
    }
    
    if (startPage > 1) {
        nav.appendChild(createPageBtn("1", 1, false, false));
        if (startPage > 2) nav.appendChild(createEllipsis());
    }
    
    for (let p = startPage; p <= endPage; p++) {
        nav.appendChild(createPageBtn(String(p), p, false, p === currentPage));
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) nav.appendChild(createEllipsis());
        nav.appendChild(createPageBtn(String(totalPages), totalPages, false, false));
    }
    
    nav.appendChild(createPageBtn('<i class="fa-solid fa-angle-right"></i>', currentPage + 1, currentPage === totalPages, false, "Halaman Berikutnya"));
    nav.appendChild(createPageBtn('<i class="fa-solid fa-angles-right"></i>', totalPages, currentPage === totalPages, false, "Halaman Terakhir"));
}

function createPageBtn(label, targetPage, disabled, isActive, title = "") {
    const li = document.createElement("li");
    li.className = "page-item";
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `page-link-btn ${isActive ? 'active' : ''}`;
    btn.innerHTML = label;
    if (title) btn.title = title;
    if (disabled) {
        btn.disabled = true;
    } else {
        btn.onclick = () => goToPage(targetPage);
    }
    li.appendChild(btn);
    return li;
}

function createEllipsis() {
    const li = document.createElement("li");
    li.className = "page-ellipsis";
    li.innerHTML = "&hellip;";
    return li;
}

document.addEventListener("DOMContentLoaded", initPagination);
if (document.readyState === "complete" || document.readyState === "interactive") {
    initPagination();
}

// ----------------------------------------------------------------------------
// SEARCHABLE DROPDOWN JAMINAN / PENJAB (SEARCH DI DALAM SELECT)
// ----------------------------------------------------------------------------
function togglePenjabDropdown(event) {
    if (event) event.stopPropagation();
    const wrap = document.getElementById('wrapPenjab');
    if (!wrap) return;
    const isOpen = wrap.classList.contains('active');
    
    if (isOpen) {
        wrap.classList.remove('active');
    } else {
        wrap.classList.add('active');
        const inputSearch = document.getElementById('searchInSelect');
        if (inputSearch) {
            inputSearch.value = '';
            filterInSelectOptions('');
            setTimeout(() => inputSearch.focus(), 50);
        }
        const selectedOpt = wrap.querySelector('.searchable-select-option.selected');
        if (selectedOpt) {
            selectedOpt.scrollIntoView({ block: 'nearest' });
        }
    }
}

function selectPenjabOption(val, text) {
    const hiddenVal = document.getElementById('valPenjab');
    const labelSpan = document.getElementById('labelPenjab');
    if (hiddenVal) hiddenVal.value = val;
    if (labelSpan) labelSpan.textContent = text;
    
    const options = document.querySelectorAll('#optionsPenjabList .searchable-select-option');
    options.forEach(opt => {
        if (opt.getAttribute('data-val') === val) {
            opt.classList.add('selected');
            if (!opt.querySelector('.fa-check')) {
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-check text-xs';
                opt.appendChild(icon);
            }
        } else {
            opt.classList.remove('selected');
            const icon = opt.querySelector('.fa-check');
            if (icon) icon.remove();
        }
    });
    
    const wrap = document.getElementById('wrapPenjab');
    if (wrap) wrap.classList.remove('active');
}

function filterInSelectOptions(query) {
    const q = (query || '').toLowerCase().trim();
    const options = document.querySelectorAll('#optionsPenjabList .searchable-select-option');
    let visibleCount = 0;
    
    options.forEach(opt => {
        const text = opt.textContent.toLowerCase();
        if (q === '' || text.includes(q)) {
            opt.classList.remove('hidden');
            visibleCount++;
        } else {
            opt.classList.add('hidden');
        }
    });
    
    const emptyNotice = document.getElementById('emptyPenjabSearch');
    if (emptyNotice) {
        emptyNotice.style.display = (visibleCount === 0) ? 'block' : 'none';
    }
}

// Klik di luar dropdown untuk menutup
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('wrapPenjab');
    if (wrap && !wrap.contains(e.target)) {
        wrap.classList.remove('active');
    }
});

// Kontrol keyboard pada input search di dalam select
document.getElementById('searchInSelect')?.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const wrap = document.getElementById('wrapPenjab');
        if (wrap) wrap.classList.remove('active');
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const firstVisible = document.querySelector('#optionsPenjabList .searchable-select-option:not(.hidden)');
        if (firstVisible) {
            firstVisible.click();
        }
    }
});

// ----------------------------------------------------------------------------
// INISIALISASI CHART.JS
// ----------------------------------------------------------------------------
<?php
$trend_labels  = [];
$trend_total   = [];
$trend_lengkap = [];
$trend_belum   = [];

foreach ($chart_trend as $tgl => $val) {
    $trend_labels[]  = date('d/m', strtotime($tgl));
    $trend_total[]   = $val['total'];
    $trend_lengkap[] = $val['lengkap'];
    $trend_belum[]   = $val['belum'];
}

$unit_labels  = [];
$unit_lengkap = [];
$unit_belum   = [];
$cnt_u = 0;
foreach ($chart_unit as $uname => $uval) {
    if ($cnt_u++ >= 7) break;
    $unit_labels[]  = $uname;
    $unit_lengkap[] = $uval['lengkap'];
    $unit_belum[]   = $uval['belum'];
}

$dok_labels  = [];
$dok_lengkap = [];
$dok_belum   = [];
$cnt_d = 0;
foreach ($chart_dokter as $dname => $dval) {
    if ($cnt_d++ >= 7) break;
    $dok_labels[]  = $dname;
    $dok_lengkap[] = $dval['lengkap'];
    $dok_belum[]   = $dval['belum'];
}
?>

// 1. Chart Trend Harian
const ctxTrend = document.getElementById('chartTrendResume').getContext('2d');
new Chart(ctxTrend, {
    type: 'bar',
    data: {
        labels: <?= json_encode($trend_labels) ?>,
        datasets: [
            {
                label: 'Resume Lengkap',
                data: <?= json_encode($trend_lengkap) ?>,
                backgroundColor: 'rgba(22, 163, 74, 0.85)',
                borderRadius: 4
            },
            {
                label: 'Belum Diisi',
                data: <?= json_encode($trend_belum) ?>,
                backgroundColor: 'rgba(220, 38, 38, 0.85)',
                borderRadius: 4
            },
            {
                label: 'Total Pasien',
                data: <?= json_encode($trend_total) ?>,
                type: 'line',
                borderColor: '#0284c7',
                backgroundColor: 'rgba(2, 132, 199, 0.1)',
                borderWidth: 2,
                tension: 0.3,
                fill: false,
                pointRadius: 3
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans', size: 11 } } },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            x: { stacked: true, grid: { display: false } },
            y: { beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});

// 2. Chart Donut Status Kepatuhan Resume
const ctxDonut = document.getElementById('chartDonutResume').getContext('2d');
new Chart(ctxDonut, {
    type: 'doughnut',
    data: {
        labels: ['Resume Lengkap', 'Belum Diisi'],
        datasets: [{
            data: [<?= $total_lengkap ?>, <?= $total_belum ?>],
            backgroundColor: ['#16a34a', '#dc2626'],
            borderWidth: 3,
            borderColor: '#ffffff',
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans', size: 12 } } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = <?= max(1, $total_pasien) ?>;
                        const val = context.raw || 0;
                        const pct = ((val / total) * 100).toFixed(1);
                        return ` ${context.label}: ${val.toLocaleString('id-ID')} (${pct}%)`;
                    }
                }
            }
        },
        cutout: '68%'
    }
});

// 3. Chart Top Unit (Poli / Bangsal)
const ctxUnit = document.getElementById('chartTopUnit').getContext('2d');
new Chart(ctxUnit, {
    type: 'bar',
    data: {
        labels: <?= json_encode($unit_labels) ?>,
        datasets: [
            {
                label: 'Lengkap',
                data: <?= json_encode($unit_lengkap) ?>,
                backgroundColor: 'rgba(22, 163, 74, 0.85)',
                borderRadius: 4
            },
            {
                label: 'Belum Diisi',
                data: <?= json_encode($unit_belum) ?>,
                backgroundColor: 'rgba(220, 38, 38, 0.85)',
                borderRadius: 4
            }
        ]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans', size: 11 } } }
        },
        scales: {
            x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
            y: { stacked: true, grid: { display: false } }
        }
    }
});

// 4. Chart Top Dokter DPJP (Dengan Resume Belum Diisi)
const ctxDokter = document.getElementById('chartTopDokter').getContext('2d');
new Chart(ctxDokter, {
    type: 'bar',
    data: {
        labels: <?= json_encode($dok_labels) ?>,
        datasets: [
            {
                label: 'Belum Diisi',
                data: <?= json_encode($dok_belum) ?>,
                backgroundColor: 'rgba(220, 38, 38, 0.85)',
                borderRadius: 4
            },
            {
                label: 'Sudah Lengkap',
                data: <?= json_encode($dok_lengkap) ?>,
                backgroundColor: 'rgba(2, 132, 199, 0.85)',
                borderRadius: 4
            }
        ]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans', size: 11 } } }
        },
        scales: {
            x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
            y: { stacked: true, grid: { display: false } }
        }
    }
});
</script>

</body>
</html>

