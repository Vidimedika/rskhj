<?php
/* RS ASSALAM - Dashboard Monitoring Berkas Klaim (Optimized + Smart Export)
   Update: Jan 2026
   Developer: Vidi Medika Konsultan IT
*/

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
$berdasarkan = $_GET['berdasarkan'] ?? 'masuk'; // Default filter ke Tanggal Masuk
$kd_dokter   = $_GET['kd_dokter']   ?? '';
$keyword     = $_GET['keyword']     ?? ''; 
$action      = $_GET['action']      ?? ''; 
$limit       = 20;
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset      = ($page - 1) * $limit;

// 3. LOGIKA QUERY (PERBAIKAN: Ambil data kamar_inap & kunjungan dengan tanggal paling terbaru untuk mencegah duplikasi)
$where = [
    "r.status_lanjut='Ranap'",
    "r.stts <> 'Batal'",
    "r.kd_pj IN ('025', '143', '208', '380', '494', '495', '496')"
];
$params = [];
$types = "";

// LOGIKA FILTER TANGGAL
if ($berdasarkan == 'pulang') {
    $where[] = "ki.tgl_keluar BETWEEN ? AND ?";
} else {
    $where[] = "ki.tgl_masuk BETWEEN ? AND ?";
}
$params[] = $tgl_awal; $params[] = $tgl_akhir;
$types .= "ss";

if ($kd_dokter) {
    $where[] = "r.no_rawat IN (SELECT no_rawat FROM dpjp_ranap WHERE kd_dokter = ?)";
    $params[] = $kd_dokter;
    $types .= "s";
}

if ($keyword) {
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rkm_medis LIKE ? OR r.no_rawat LIKE ? OR EXISTS(SELECT 1 FROM bridging_sep bs_s WHERE bs_s.no_rawat = r.no_rawat AND bs_s.no_sep LIKE ?))";
    $like_keyword = "%$keyword%";
    $params[] = $like_keyword; $params[] = $like_keyword; $params[] = $like_keyword; $params[] = $like_keyword;
    $types .= "ssss";
}

$where_sql = implode(" AND ", $where);

// TAHAP 1: Query ringan untuk mengambil daftar no_rawat unik per pasien (tanggal terbaru) & hitung total halaman
$sql_list = "SELECT r.no_rawat, r.no_rkm_medis
             FROM reg_periksa r
             INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
             INNER JOIN (
                 SELECT 
                     k_in.no_rawat,
                     MIN(k_in.tgl_masuk) as tgl_masuk,
                     SUBSTRING_INDEX(GROUP_CONCAT(k_in.jam_masuk ORDER BY k_in.tgl_masuk ASC, k_in.jam_masuk ASC SEPARATOR '|||'), '|||', 1) as jam_masuk,
                     SUBSTRING_INDEX(GROUP_CONCAT(k_in.tgl_keluar ORDER BY (k_in.stts_pulang = 'Pindah Kamar') ASC, k_in.tgl_masuk DESC, k_in.jam_masuk DESC SEPARATOR '|||'), '|||', 1) as tgl_keluar
                 FROM kamar_inap k_in
                 WHERE IFNULL(k_in.diagnosa_awal, '') NOT LIKE '%batal%'
                   AND IFNULL(k_in.diagnosa_akhir, '') NOT LIKE '%batal%'
                 GROUP BY k_in.no_rawat
                 HAVING NOT (SUM(k_in.stts_pulang <> 'Pindah Kamar') = 0 AND SUM(k_in.lama) = 0 AND SUM(k_in.ttl_biaya) = 0)
             ) ki ON r.no_rawat = ki.no_rawat
             WHERE $where_sql
             GROUP BY r.no_rawat
             ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC";

$stmt_list = $conn->prepare($sql_list);
if (!empty($params)) $stmt_list->bind_param($types, ...$params);
$stmt_list->execute();
$res_list = $stmt_list->get_result();

$valid_rawats = [];
$seen_rm = [];
while ($r_list = $res_list->fetch_assoc()) {
    $rm = $r_list['no_rkm_medis'];
    if (isset($seen_rm[$rm])) continue;
    $seen_rm[$rm] = true;
    $valid_rawats[] = $r_list['no_rawat'];
}
$stmt_list->close();

$total_data  = count($valid_rawats);
$total_pages = max(1, (int)ceil($total_data / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

$target_rawats = ($action == 'excel') ? $valid_rawats : array_slice($valid_rawats, $offset, $limit);

// TAHAP 2: Hanya mengeksekusi subquery detail (Billing, SEP, Berkas, Pemeriksaan) pada 20 baris yang tampil
$data_ranap = [];
if (!empty($target_rawats)) {
    $placeholders = implode(',', array_fill(0, count($target_rawats), '?'));
    $sql = "SELECT 
                r.no_rawat, r.no_rkm_medis, p.nm_pasien, 
                IFNULL((SELECT SUBSTRING_INDEX(GROUP_CONCAT(bs.no_sep ORDER BY bs.jnspelayanan ASC, bs.tglsep DESC, bs.no_sep DESC SEPARATOR '|||'), '|||', 1) FROM bridging_sep bs WHERE bs.no_rawat = r.no_rawat), '-') as no_sep, 
                IFNULL((SELECT SUBSTRING_INDEX(GROUP_CONCAT(bs.no_kartu ORDER BY bs.jnspelayanan ASC, bs.tglsep DESC, bs.no_sep DESC SEPARATOR '|||'), '|||', 1) FROM bridging_sep bs WHERE bs.no_rawat = r.no_rawat), '-') as no_kartu,
                IFNULL((SELECT SUBSTRING_INDEX(GROUP_CONCAT(bs.klsrawat ORDER BY bs.jnspelayanan ASC, bs.tglsep DESC, bs.no_sep DESC SEPARATOR '|||'), '|||', 1) FROM bridging_sep bs WHERE bs.no_rawat = r.no_rawat), '-') as kelas, 
                ki.nm_bangsal, ki.tgl_masuk, ki.jam_masuk, ki.tgl_keluar, ki.jam_keluar, ki.diagnosa_awal, ki.diagnosa_akhir,
                MAX(ni.no_nota) as no_nota,
                IFNULL((SELECT SUM(b.totalbiaya) FROM billing b WHERE b.no_rawat = r.no_rawat), 0) as total_billing,
                IFNULL(d_poly.nm_dokter, '-') as dr_poliklinik,
                (SELECT GROUP_CONCAT(DISTINCT d.nm_dokter SEPARATOR ' | ') 
                 FROM dpjp_ranap dr 
                 JOIN dokter d ON dr.kd_dokter = d.kd_dokter 
                 WHERE dr.no_rawat = r.no_rawat) as daftar_dpjp,
                IFNULL((SELECT GROUP_CONCAT(DISTINCT mbd.nama SEPARATOR ', ') 
                        FROM berkas_digital_perawatan bdp 
                        INNER JOIN master_berkas_digital mbd ON bdp.kode = mbd.kode 
                        WHERE bdp.no_rawat = r.no_rawat), '-') as list_berkas,
                EXISTS(SELECT 1 FROM penilaian_medis_igd WHERE no_rawat = r.no_rawat) as f_medis_igd,
                EXISTS(SELECT 1 FROM data_triase_igd WHERE no_rawat = r.no_rawat) as f_triase,
                EXISTS(SELECT 1 FROM permintaan_ranap WHERE no_rawat = r.no_rawat) as f_spr,
                EXISTS(SELECT 1 FROM pasien_bayi WHERE no_rkm_medis = r.no_rkm_medis) as f_skl,
                EXISTS(SELECT 1 FROM resume_pasien_ranap WHERE no_rawat = r.no_rawat) as f_resume,
                EXISTS(SELECT 1 FROM laporan_operasi WHERE no_rawat = r.no_rawat) as f_operasi,
                EXISTS(SELECT 1 FROM periksa_lab WHERE no_rawat = r.no_rawat) as f_lab,
                EXISTS(SELECT 1 FROM periksa_radiologi WHERE no_rawat = r.no_rawat) as f_radiologi
            FROM reg_periksa r
            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
            INNER JOIN (
                SELECT 
                    k_in.no_rawat,
                    MIN(k_in.tgl_masuk) as tgl_masuk,
                    SUBSTRING_INDEX(GROUP_CONCAT(k_in.jam_masuk ORDER BY k_in.tgl_masuk ASC, k_in.jam_masuk ASC SEPARATOR '|||'), '|||', 1) as jam_masuk,
                    SUBSTRING_INDEX(GROUP_CONCAT(k_in.tgl_keluar ORDER BY (k_in.stts_pulang = 'Pindah Kamar') ASC, k_in.tgl_masuk DESC, k_in.jam_masuk DESC SEPARATOR '|||'), '|||', 1) as tgl_keluar,
                    SUBSTRING_INDEX(GROUP_CONCAT(k_in.jam_keluar ORDER BY (k_in.stts_pulang = 'Pindah Kamar') ASC, k_in.tgl_masuk DESC, k_in.jam_masuk DESC SEPARATOR '|||'), '|||', 1) as jam_keluar,
                    SUBSTRING_INDEX(GROUP_CONCAT(b.nm_bangsal ORDER BY (k_in.stts_pulang = 'Pindah Kamar') ASC, k_in.tgl_masuk DESC, k_in.jam_masuk DESC SEPARATOR '|||'), '|||', 1) as nm_bangsal,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(TRIM(k_in.diagnosa_awal), '-') ORDER BY k_in.tgl_masuk ASC, k_in.jam_masuk ASC SEPARATOR '|||'), '|||', 1) as diagnosa_awal,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(TRIM(k_in.diagnosa_akhir), '-') ORDER BY (k_in.stts_pulang = 'Pindah Kamar') ASC, k_in.tgl_masuk DESC, k_in.jam_masuk DESC SEPARATOR '|||'), '|||', 1) as diagnosa_akhir
                FROM kamar_inap k_in
                INNER JOIN kamar k ON k_in.kd_kamar = k.kd_kamar
                INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
                WHERE k_in.no_rawat IN ($placeholders)
                  AND IFNULL(k_in.diagnosa_awal, '') NOT LIKE '%batal%'
                  AND IFNULL(k_in.diagnosa_akhir, '') NOT LIKE '%batal%'
                GROUP BY k_in.no_rawat
            ) ki ON r.no_rawat = ki.no_rawat
            LEFT JOIN dokter d_poly ON r.kd_dokter = d_poly.kd_dokter
            LEFT JOIN nota_inap ni ON r.no_rawat = ni.no_rawat
            WHERE r.no_rawat IN ($placeholders)
            GROUP BY r.no_rawat
            ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC";

    $stmt = $conn->prepare($sql);
    $bind_types = str_repeat('s', count($target_rawats) * 2);
    $bind_params = array_merge($target_rawats, $target_rawats);
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $data_ranap[] = $row;
    }
    $stmt->close();
}

// 4. LOGIKA EXPORT
if ($action == 'excel') {
    $filename = "Monitoring_Klaim_" . date('Ymd_His') . ".xls";
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=$filename");
    header("Pragma: no-cache");
    header("Expires: 0");
}

function render_status_td($val, $isExcel = false) {
    $text = $val ? "Ada" : "-";
    if ($isExcel) {
        return "<td>$text</td>";
    } else {
        $bg = $val ? "#d4edda" : "#f8d7da";
        $color = $val ? "#155724" : "#721c24";
        $weight = $val ? "bold" : "normal";
        return "<td style='background:$bg; color:$color; font-weight:$weight'>$text</td>";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring Berkas Klaim - RS KARTIKA HUSADA JATIASIH</title>
    <?php if($action != 'excel'): ?>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; font-size: 10px; }
        .container { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #0a7c3b; text-transform: uppercase; margin-bottom: 5px; }
        .filter { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
        .filter-row { display: flex; justify-content: center; gap: 10px; align-items: center; flex-wrap: wrap; }
        input, select, button { padding: 8px; border-radius: 4px; border: 1px solid #ccc; font-size: 11px; }
        .btn-cari { background: #0a7c3b; color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 20px; }
        .btn-excel { background: #1d6f42; color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 20px; text-decoration: none; border-radius: 4px; }
        .scroll-area { max-height: 600px; overflow: auto; border: 1px solid #ddd; margin-top: 10px; }
        table { border-collapse: collapse; width: 100%; min-width: 3500px; }
        thead th { position: sticky; top: 0; background: #0a7c3b; color: white; padding: 12px 5px; z-index: 10; border: 1px solid #08632f; }
        td { padding: 10px 5px; border: 1px solid #eee; text-align: center; vertical-align: top; }
        .norm { font-weight: bold; font-family: monospace; font-size: 11px; }
    </style>
    <?php endif; ?>
</head>
<body>

<div class="container">
    <?php if($action != 'excel'): ?>
    <h2>RS KARTIKA HUSADA JATIASIH</h2>
    <h2>DASHBOARD MONITORING BERKAS KLAIM BPJS RAWAT INAP</h2>

    <div class="filter">
        <form method="GET">
            <div class="filter-row">
                <b>Filter Berdasarkan:</b>
                <select name="berdasarkan">
                    <option value="masuk" <?= ($berdasarkan == 'masuk' ? 'selected' : '') ?>>Tanggal Masuk</option>
                    <option value="pulang" <?= ($berdasarkan == 'pulang' ? 'selected' : '') ?>>Tanggal Pulang</option>
                </select>
                <b>Periode:</b>
                <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> s/d 
                <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
                <b>Cari:</b>
                <input type="text" name="keyword" value="<?=$keyword?>" placeholder="Nama/RM/SEP" style="width: 150px;">
                <b>Dokter:</b>
                <select name="kd_dokter">
                    <option value="">- Semua Dokter -</option>
                    <?php
                    $docs = $conn->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status = '1' ORDER BY nm_dokter");
                    while($d = $docs->fetch_assoc()) {
                        $s = ($kd_dokter == $d['kd_dokter']) ? 'selected' : '';
                        echo "<option value='{$d['kd_dokter']}' $s>{$d['nm_dokter']}</option>";
                    }
                    ?>
                </select>
                <button type="submit" class="btn-cari">CARI</button>
                <a href="?<?= http_build_query(array_merge($_GET, ['action' => 'excel'])) ?>" class="btn-excel">EXPORT EXCEL</a>
                <a href="?" style="text-decoration:none; font-size:11px; color:red;">Reset</a>
            </div>
        </form>
    </div>
    <?php else: ?>
        <h3>DATA MONITORING BERKAS KLAIM BPJS (Filter: Tanggal <?=ucfirst($berdasarkan)?>, Periode: <?=$tgl_awal?> s/d <?=$tgl_akhir?>)</h3>
    <?php endif; ?>

    <div class="scroll-area">
        <table border="<?= ($action == 'excel') ? '1' : '0' ?>">
            <thead>
                <tr style="<?= ($action == 'excel') ? 'background-color:#0a7c3b; color:white;' : '' ?>">
                    <th>No</th>
                    <th>No. Rawat</th>
                    <th>No. RM</th>
                    <th>No. Kartu</th>
                    <th>Nama Pasien</th>
                    <th>Kelas</th>
                    <th>Bangsal</th>
                    <th>Diag Awal</th>
                    <th>Diag Akhir</th>
                    <th>Tgl Masuk</th>
                    <th>Jam Masuk</th>
                    <th>Tgl Pulang</th>
                    <th>Jam Pulang</th>
                    <th>No. Nota</th>
                    <th>Billing</th>
                    <th>Dokter IGD / Poli</th>
                    <th>DPJP</th>
                    <th>SEP</th>
                    <th>Awal Medis</th>
                    <th>Triase</th>
                    <th>SPR</th>
                    <th>SKL</th>
                    <th>Resume</th>
                    <th>Operasi</th>
                    <th>Lab</th>
                    <th>Radiologi</th>
                    <th>Berkas Digital</th>
                    <th>%</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $isExcel = ($action == 'excel');
                $no = $isExcel ? 1 : ($offset + 1);
                foreach ($data_ranap as $r):
                    $skor = $r['f_medis_igd'] + $r['f_triase'] + $r['f_spr'] + $r['f_skl'] + $r['f_resume'] + $r['f_operasi'] + $r['f_lab'] + $r['f_radiologi'];
                    $persen = round(($skor / 8) * 100); 
                ?>
                <tr>
                    <td><?=$no++?></td>
                    <td class="norm"><?= ($isExcel ? "'" : "") . $r['no_rawat'] ?></td>
                    <td class="norm"><?= ($isExcel ? "'" : "") . $r['no_rkm_medis'] ?></td>
                    <td class="norm"><?= ($isExcel ? "'" : "") . $r['no_kartu'] ?></td>
                    <td style="text-align:left"><?=$r['nm_pasien']?></td>
                    <td><?=$r['kelas']?></td>
                    <td style="text-align:left"><?=$r['nm_bangsal']?></td>
                    <td style="text-align:left"><?=($r['diagnosa_awal'] ?: '-')?></td>
                    <td style="text-align:left"><?=($r['diagnosa_akhir'] ?: '-')?></td>
                    <td><?=$r['tgl_masuk']?></td>
                    <td><?=$r['jam_masuk']?></td>
                    <td><?=($r['tgl_keluar'] && $r['tgl_keluar'] != '0000-00-00') ? $r['tgl_keluar'] : "Rawat"?></td>
                    <td><?=($r['tgl_keluar'] && $r['tgl_keluar'] != '0000-00-00') ? $r['jam_keluar'] : "-" ?></td>
                    <td><?=$r['no_nota'] ?: '-'?></td>
                    <td style="text-align: right; font-weight:bold; color:#d32f2f;">
                        <?= $isExcel ? $r['total_billing'] : 'Rp ' . number_format($r['total_billing'], 0, ',', '.') ?>
                    </td>
                    <td style="text-align:left"><?=$r['dr_poliklinik']?></td>
                    <td style="text-align:left"><?= strip_tags($r['daftar_dpjp'] ?? '-') ?></td>
                    <td><?= ($isExcel ? "'" : "") . ($r['no_sep'] ?: '-') ?></td>
                    <?= render_status_td($r['f_medis_igd'], $isExcel) ?>
                    <?= render_status_td($r['f_triase'], $isExcel) ?>
                    <?= render_status_td($r['f_spr'], $isExcel) ?>
                    <?= render_status_td($r['f_skl'], $isExcel) ?>
                    <?= render_status_td($r['f_resume'], $isExcel) ?>
                    <?= render_status_td($r['f_operasi'], $isExcel) ?>
                    <?= render_status_td($r['f_lab'], $isExcel) ?>
                    <?= render_status_td($r['f_radiologi'], $isExcel) ?>
                    <td style="text-align:left"><?=$r['list_berkas']?></td>
                    <td><b><?=$persen?>%</b></td>
                    <td><?=$persen>=85 ? 'SIAP Claim':'BELUM Lengkap'?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if($action != 'excel'): ?>
    <?php
        $query_prev = $_GET;
        $query_prev['page'] = max(1, $page - 1);
        $query_next = $_GET;
        $query_next['page'] = min($total_pages, $page + 1);
        $start_row = $total_data > 0 ? ($offset + 1) : 0;
        $end_row   = min($offset + $limit, $total_data);
    ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; padding:10px 15px; background:#f8f9fa; border:1px solid #ddd; border-radius:8px; font-size:12px;">
        <div>
            Menampilkan <b><?= $start_row ?> - <?= $end_row ?></b> dari <b><?= number_format($total_data, 0, ',', '.') ?></b> data (Halaman <b><?= $page ?></b> dari <b><?= $total_pages ?></b>)
        </div>
        <div style="display:flex; gap:8px;">
            <?php if ($page > 1): ?>
                <a href="?<?= htmlspecialchars(http_build_query($query_prev)) ?>" style="padding:7px 16px; background:#0a7c3b; color:white; text-decoration:none; border-radius:5px; font-weight:bold;">&laquo; Prev</a>
            <?php else: ?>
                <span style="padding:7px 16px; background:#e0e0e0; color:#999; border-radius:5px; font-weight:bold;">&laquo; Prev</span>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?<?= htmlspecialchars(http_build_query($query_next)) ?>" style="padding:7px 16px; background:#0a7c3b; color:white; text-decoration:none; border-radius:5px; font-weight:bold;">Next &raquo;</a>
            <?php else: ?>
                <span style="padding:7px 16px; background:#e0e0e0; color:#999; border-radius:5px; font-weight:bold;">Next &raquo;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<div style="text-align:center; font-size:12px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. All Rights Reserved. Smart IT For Health. 
</div>
</body>
</html>
