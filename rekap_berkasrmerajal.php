<?php
/* RS KARTIKA HUSADA JATIASIH- Dashboard Monitoring Klaim Berkas Claim (Highly Optimized)
   Update: Jan 2026
   Developer: Vidi Medika Konsultan IT
*/

// 1. KONEKSI DATABASE
$host = "192.168.10.29";
$user = "root";
$pass = "1nternet";
$db   = "rskhj";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// 2. TANGKAP FILTER
$tgl_awal    = $_GET['tgl_awal']    ?? date('Y-m-01');
$tgl_akhir   = $_GET['tgl_akhir']   ?? date('Y-m-d');
$kd_poli     = $_GET['kd_poli']     ?? ''; 
$kd_dokter   = $_GET['kd_dokter']   ?? '';
$stts_resume = $_GET['stts_resume'] ?? '';
$search      = $_GET['search']      ?? ''; 
$action      = $_GET['action']      ?? '';
$limit       = 20;
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset      = ($page - 1) * $limit;

// 3. LOGIKA QUERY
// Filter Jenis Bayar BPJS Kesehatan sesuai Master Penanggung (kecuali 087: BPJS KETENAGAKERJAAN & NON: NON BPJS)
$where = [
    "r.status_lanjut='Ralan'",
    "r.stts <> 'Batal'",
    "r.kd_pj IN ('025', '143', '208', '380', '494', '495', '496')"
];
$params = [];
$types = "";

$where[] = "r.tgl_registrasi BETWEEN ? AND ?";
$params[] = $tgl_awal; $params[] = $tgl_akhir;
$types .= "ss";

if ($kd_poli) { $where[] = "r.kd_poli = ?"; $params[] = $kd_poli; $types .= "s"; }
if ($kd_dokter) { $where[] = "r.kd_dokter = ?"; $params[] = $kd_dokter; $types .= "s"; }
if ($stts_resume === 'ada') {
    $where[] = "EXISTS(SELECT 1 FROM resume_pasien rp WHERE rp.no_rawat = r.no_rawat)";
} elseif ($stts_resume === 'kosong') {
    $where[] = "NOT EXISTS(SELECT 1 FROM resume_pasien rp WHERE rp.no_rawat = r.no_rawat)";
}

if ($search) { 
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rawat LIKE ? OR r.no_rkm_medis LIKE ? OR EXISTS(SELECT 1 FROM bridging_sep bs_s WHERE bs_s.no_rawat = r.no_rawat AND (bs_s.no_sep LIKE ? OR bs_s.no_kartu LIKE ?)) OR ni.no_nota LIKE ?)"; 
    $lk = "%$search%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "ssssss"; 
}

$where_sql = implode(" AND ", $where);

// HITUNG STATISTIK TOTAL & PAGINASI
$stats = ['total' => 0, 'lengkap' => 0, 'belum' => 0];
$sql_stats = "SELECT 
                COUNT(*) AS total,
                SUM(is_lengkap) AS lengkap
              FROM (
                  SELECT 
                    r.no_rawat,
                    CASE 
                        WHEN pol.nm_poli LIKE '%IGD%' THEN 
                            (EXISTS(SELECT 1 FROM penilaian_medis_igd WHERE no_rawat = r.no_rawat) 
                             AND EXISTS(SELECT 1 FROM data_triase_igd WHERE no_rawat = r.no_rawat) 
                             AND EXISTS(SELECT 1 FROM resume_pasien WHERE no_rawat = r.no_rawat))
                        ELSE 
                            EXISTS(SELECT 1 FROM resume_pasien WHERE no_rawat = r.no_rawat)
                    END AS is_lengkap
                  FROM reg_periksa r
                  INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
                  INNER JOIN poliklinik pol ON r.kd_poli = pol.kd_poli
                  LEFT JOIN nota_jalan ni ON r.no_rawat = ni.no_rawat
                  WHERE $where_sql
                  GROUP BY r.no_rawat
              ) AS stat_sub";
$stmt_stats = $conn->prepare($sql_stats);
if (!empty($params)) $stmt_stats->bind_param($types, ...$params);
$stmt_stats->execute();
if ($row_stats = $stmt_stats->get_result()->fetch_assoc()) {
    $stats['total']   = (int)($row_stats['total'] ?? 0);
    $stats['lengkap'] = (int)($row_stats['lengkap'] ?? 0);
    $stats['belum']   = $stats['total'] - $stats['lengkap'];
}
$stmt_stats->close();

$total_pages = max(1, (int)ceil($stats['total'] / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// OPTIMASI QUERY: Hanya mengeksekusi subquery pada 20 baris yang tampil (atau semua saat export Excel)
$limit_sql = ($action == 'excel') ? "" : "LIMIT $limit OFFSET $offset";
$sql = "SELECT 
            r.no_rawat, r.no_rkm_medis, p.nm_pasien, pol.nm_poli,
            IFNULL((SELECT MAX(bs.no_sep) FROM bridging_sep bs WHERE bs.no_rawat = r.no_rawat), '-') as no_sep, 
            IFNULL((SELECT MAX(bs.no_kartu) FROM bridging_sep bs WHERE bs.no_rawat = r.no_rawat), '-') as no_kartu,
            IFNULL((SELECT MAX(bs.asal_rujukan) FROM bridging_sep bs WHERE bs.no_rawat = r.no_rawat), '-') as asal_rujukan, 
            pj.png_jawab, 
            IFNULL((SELECT MAX(bs.klsrawat) FROM bridging_sep bs WHERE bs.no_rawat = r.no_rawat), '-') as kelas, 
            r.tgl_registrasi, 
            MAX(ni.no_nota) as no_nota, 
            IFNULL((SELECT SUM(b.totalbiaya) FROM billing b WHERE b.no_rawat = r.no_rawat), 0) as total_billing,
            MAX(ni.tanggal) as tgl_pulang,
            d.nm_dokter as dokter_dpjp,
            IFNULL((SELECT GROUP_CONCAT(DISTINCT mbd.nama SEPARATOR ', ') 
                    FROM berkas_digital_perawatan bdp 
                    JOIN master_berkas_digital mbd ON bdp.kode = mbd.kode 
                    WHERE bdp.no_rawat = r.no_rawat), '-') as list_berkas,
            EXISTS(SELECT 1 FROM penilaian_medis_igd WHERE no_rawat = r.no_rawat) as f_medis_igd,
            EXISTS(SELECT 1 FROM data_triase_igd WHERE no_rawat = r.no_rawat) as f_triase,
            EXISTS(SELECT 1 FROM resume_pasien WHERE no_rawat = r.no_rawat) as f_resume,
            EXISTS(SELECT 1 FROM periksa_lab WHERE no_rawat = r.no_rawat) as f_lab,
            EXISTS(SELECT 1 FROM periksa_radiologi WHERE no_rawat = r.no_rawat) as f_radiologi
        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
        INNER JOIN dokter d ON r.kd_dokter = d.kd_dokter
        INNER JOIN poliklinik pol ON r.kd_poli = pol.kd_poli
        LEFT JOIN nota_jalan ni ON r.no_rawat = ni.no_rawat
        WHERE $where_sql
        GROUP BY r.no_rawat
        ORDER BY r.tgl_registrasi DESC, r.jam_reg DESC
        $limit_sql";

$stmt = $conn->prepare($sql);
if(!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$data_pasien = [];
while ($row = $result->fetch_assoc()) {
    $isIGD = (stripos($row['nm_poli'], 'IGD') !== false);
    $skor = $isIGD ? ($row['f_medis_igd'] + $row['f_triase'] + $row['f_resume']) : $row['f_resume'];
    $row['persen'] = round(($skor / ($isIGD ? 3 : 1)) * 100);
    $data_pasien[] = $row;
}

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_Klaim_BPJS_Ralan_".date('Ymd').".xls");
}

function renderStatus($val, $isExcel) {
    if($isExcel) return "<td>" . ($val ? "Ada" : "-") . "</td>";
    $style = $val ? "background:#e8f5e9; color:#0a7c3b; font-weight:bold" : "background:#fbe9e7; color:#c62828";
    return "<td style='$style'>".($val ? 'Ada' : '-')."</td>";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Klaim - RS KARTIKA HUSADA JATIASIH</title>
    <?php if ($action != 'excel'): ?>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f4f3; margin: 0; padding: 20px; font-size: 11px; }
        .container { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-top: 5px solid #0a7c3b; }
        .header-title { text-align: center; margin-bottom: 25px; }
        .filter-box { background: #f9f9f9; padding: 20px; border-radius: 10px; margin-bottom: 25px; border: 1px solid #e0e0e0; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
        .card { padding: 15px; border-radius: 10px; text-align: center; color: white; }
        .bg-total { background: #2e7d32; }
        .bg-lengkap { background: #0a7c3b; }
        .bg-belum { background: #d32f2f; } 
        .bg-persen { background: #f9a825; }
        .card-val { font-size: 20px; font-weight: bold; display: block; }
        .scroll-area { overflow: auto; max-height: 550px; border: 1px solid #eee; }
        table { width: 100%; border-collapse: collapse; min-width: 2600px; }
        th { background: #0a7c3b; color: white; padding: 12px 8px; position: sticky; top: 0; z-index: 2; border: 1px solid #08632f; }
        td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: center; }
        .badge { padding: 5px 10px; border-radius: 20px; color: white; font-weight: bold; font-size: 10px; }
        .label-filter { display:block; font-weight:bold; margin-bottom:5px; font-size: 10px; color: #555; }
        .search-input { width: 220px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; border-left: 3px solid #0a7c3b; }
        .pagination-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding: 10px 5px; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: 12px; color: #555; font-weight: bold; }
        .pagination-links { display: flex; gap: 6px; align-items: center; }
        .page-btn { padding: 6px 12px; border: 1px solid #0a7c3b; border-radius: 5px; text-decoration: none; color: #0a7c3b; font-weight: bold; font-size: 11px; background: #fff; transition: 0.2s; }
        .page-btn:hover { background: #e8f5e9; }
        .page-btn.active { background: #0a7c3b; color: #fff; }
        .page-btn.disabled { border-color: #ccc; color: #aaa; pointer-events: none; background: #f5f5f5; }
    </style>
    <?php endif; ?>
</head>
<body>

<div class="container">
    <?php if($action == 'excel'): ?>
        <table>
            <tr><th colspan="20" style="font-size: 16px; color: #0a7c3b;">RS KARTIKA HUSADA JATIASIH</th></tr>
            <tr><th colspan="20" style="font-size: 14px; color: #0a7c3b;">DASHBOARD MONITORING BERKAS KLAIM BPJS RAWAT JALAN</th></tr>
            <tr><th colspan="20">Periode Registrasi: <?= $tgl_awal ?> s/d <?= $tgl_akhir ?></th></tr>
        </table>
    <?php else: ?>
        <div class="header-title">
            <h1 style="color: #0a7c3b; margin: 0; letter-spacing: 1px;">RS KARTIKA HUSADA JATIASIH</h1>
            <h1 style="color: #0a7c3b; margin: 0; letter-spacing: 1px;">DASHBOARD MONITORING BERKAS KLAIM BPJS RAWAT JALAN</h1>
        </div>

        <div class="filter-box">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; justify-content: center;">
                <div>
                    <label class="label-filter">PERIODE TGL REGISTRASI.</label>
                    <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> 
                    <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
                </div>
                <div>
                    <label class="label-filter">POLIKLINIK</label>
                    <select name="kd_poli" style="width: 150px;">
                        <option value="">- Semua Poli -</option>
                        <?php
                        $polis = $conn->query("SELECT kd_poli, nm_poli FROM poliklinik WHERE status='1' ORDER BY nm_poli");
                        while($p = $polis->fetch_assoc()) echo "<option value='{$p['kd_poli']}' ".($kd_poli==$p['kd_poli']?'selected':'').">{$p['nm_poli']}</option>";
                        ?>
                    </select>
                </div>
                <div>
                    <label class="label-filter">DOKTER DPJP</label>
                    <select name="kd_dokter" style="width: 180px;">
                        <option value="">- Semua Dokter -</option>
                        <?php
                        $docs = $conn->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter");
                        while($d = $docs->fetch_assoc()) echo "<option value='{$d['kd_dokter']}' ".($kd_dokter==$d['kd_dokter']?'selected':'').">{$d['nm_dokter']}</option>";
                        ?>
                    </select>
                </div>
                <div>
                    <label class="label-filter">STATUS RESUME</label>
                    <select name="stts_resume" style="width: 130px;">
                        <option value="">- Semua -</option>
                        <option value="ada" <?= $stts_resume === 'ada' ? 'selected' : '' ?>>Ada</option>
                        <option value="kosong" <?= $stts_resume === 'kosong' ? 'selected' : '' ?>>- (Belum Ada)</option>
                    </select>
                </div>
                <div>
                    <label class="label-filter">PENCARIAN GLOBAL</label>
                    <input type="text" name="search" value="<?=$search?>" placeholder="Nama/Nota/RM/Rawat/SEP/Kartu" class="search-input">
                </div>
                <button type="submit" style="background:#0a7c3b; color:white; padding: 7px 15px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">CARI DATA</button>
                <a href="?action=excel&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>&kd_poli=<?=$kd_poli?>&kd_dokter=<?=$kd_dokter?>&stts_resume=<?=$stts_resume?>&search=<?=$search?>" style="background:#2e7d32; color:white; padding: 7px 15px; text-decoration:none; border-radius:4px; font-weight:bold;">EXPORT EXCEL</a>
                <a href="?" style="text-decoration:none; font-size:11px; color:red; padding-bottom: 7px;">Reset</a>
            </form>
        </div>

        <div class="stats-grid">
            <div class="card bg-total"><span class="card-val"><?=$stats['total']?></span> Pasien</div>
            <div class="card bg-lengkap"><span class="card-val"><?=$stats['lengkap']?></span> Lengkap</div>
            <div class="card bg-belum"><span class="card-val"><?=$stats['belum']?></span> Belum</div>
            <div class="card bg-persen"><span class="card-val"><?= ($stats['total'] > 0) ? round(($stats['lengkap']/$stats['total'])*100) : 0 ?>%</span> Progres</div>
        </div>
    <?php endif; ?>

    <div class="<?= ($action != 'excel') ? 'scroll-area' : '' ?>">
        <table border="<?= ($action == 'excel') ? '1' : '0' ?>">
            <thead>
                <tr>
                    <th>No</th>
                    <th>No. Rawat</th>
                    <th>No. RM</th>
                    <th>Nama Pasien</th>
                    <th>No. Kartu</th>
                    <th>Tgl Masuk</th>
                    <th>Tgl Keluar (Nota)</th>
                    <th>No. Nota</th>
                    <th>Billing</th>
                    <th>Dokter</th>
                    <th>Poli/Unit</th>
                    <th>SEP</th>
                    <th>Awal Medis IGD</th>
                    <th>Triase</th>
                    <th>Resume</th>
                    <th>Lab</th>
                    <th>Rad</th>
                    <th style="width: 250px;">Berkas Digital</th>
                    <th>%</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = ($action == 'excel') ? 1 : ($offset + 1); foreach($data_pasien as $r): ?>
                <tr>
                    <td><?=$no++?></td>
                    <td style="font-family: monospace;"><?= ($action == 'excel' ? "'" : "") . $r['no_rawat'] ?></td>
                    <td style="font-family: monospace;"><?= ($action == 'excel' ? "'" : "") . $r['no_rkm_medis'] ?></td>
                    <td style="text-align: left; font-weight: bold;"><?=strtoupper($r['nm_pasien'])?></td>
                    <td><?= ($action == 'excel' ? "'" : "") . ($r['no_kartu'] ?: '-') ?></td>
                    <td><?=date('d/m/Y', strtotime($r['tgl_registrasi']))?></td>
                    <td style="color:#0a7c3b; font-weight:bold;">
                        <?= ($r['tgl_pulang'] && $r['tgl_pulang'] != '0000-00-00') ? date('d/m/Y', strtotime($r['tgl_pulang'])) : '-' ?>
                    </td>
                    <td style="font-weight:bold; color:#2980b9;"><?=$r['no_nota'] ?: '-'?></td>
                    <td style="text-align: right; font-weight:bold; color:#d32f2f;">
                        <?= $action == 'excel' ? $r['total_billing'] : 'Rp ' . number_format($r['total_billing'], 0, ',', '.') ?>
                    </td>
                    <td style="text-align: left;"><?=$r['dokter_dpjp']?></td>
                    <td style="font-weight: bold;"><?=$r['nm_poli']?></td>
                    <td><?= ($action == 'excel' ? "'" : "") . ($r['no_sep'] ?: '-') ?></td>
                    
                    <?= renderStatus($r['f_medis_igd'], ($action=='excel')) ?>
                    <?= renderStatus($r['f_triase'], ($action=='excel')) ?>
                    <?= renderStatus($r['f_resume'], ($action=='excel')) ?>
                    <?= renderStatus($r['f_lab'], ($action=='excel')) ?>
                    <?= renderStatus($r['f_radiologi'], ($action=='excel')) ?>

                    <td style="text-align: left; font-size: 8px;"><?= $r['list_berkas'] ?: '-' ?></td>
                    <td style="font-weight: bold;"><?=$r['persen']?>%</td>
                    <td>
                        <?php if($action != 'excel'): ?>
                            <span class="badge" style="background: <?=$r['persen']>=100 ? '#0a7c3b':'#d32f2f'?>">
                                <?=$r['persen']>=100 ? 'LENGKAP':'BELUM'?>
                            </span>
                        <?php else: echo ($r['persen']>=100 ? 'LENGKAP':'BELUM'); endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($action != 'excel' && $stats['total'] > 0): 
        $base_query = [
            'tgl_awal'    => $tgl_awal,
            'tgl_akhir'   => $tgl_akhir,
            'kd_poli'     => $kd_poli,
            'kd_dokter'   => $kd_dokter,
            'stts_resume' => $stts_resume,
            'search'      => $search
        ];
        $start_item = $offset + 1;
        $end_item   = min($offset + $limit, $stats['total']);
    ?>
    <div class="pagination-bar">
        <div class="pagination-info">
            Menampilkan <?= $start_item ?> - <?= $end_item ?> dari <?= $stats['total'] ?> pasien (Halaman <?= $page ?> dari <?= $total_pages ?>)
        </div>
        <div class="pagination-links">
            <a class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($base_query, ['page' => $page - 1])) ?>">&laquo; Prev</a>
            <?php
            $start_p = max(1, $page - 2);
            $end_p   = min($total_pages, $page + 2);
            for ($p = $start_p; $p <= $end_p; $p++):
            ?>
                <a class="page-btn <?= ($p == $page) ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($base_query, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($base_query, ['page' => $page + 1])) ?>">Next &raquo;</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<div style="text-align:center; font-size:12px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. All Rights Reserved. Smart IT For Health.
</div>
</body>
</html>
