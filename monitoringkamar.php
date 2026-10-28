<?php
/**
 * Dashboard Monitoring Kamar & Mutasi - RS ASSALAM
 * Filter: KHUSUS JENIS BAYAR BPJS
 * Logika: Deteksi Irisan / Overlap Berbasis Bed Spesifik (100% Identik dengan bed.php)
 */

// Konfigurasi Database
$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   = "db-sim";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Koneksi gagal: " . $conn->connect_error); }

// Inisialisasi Parameter & Proteksi XSS Dasar
$tgl_masuk_awal  = $_GET['tgl_masuk_awal']  ?? date('Y-m-01');
$tgl_masuk_akhir = $_GET['tgl_masuk_akhir'] ?? date('Y-m-d');
$keyword          = $_GET['keyword']          ?? '';
$action           = $_GET['action']           ?? '';

$where = ["r.status_lanjut='Ranap'", "pj.png_jawab LIKE '%BPJS%'"];
$params = [];
$types = "";

// Filter tanggal registrasi
$where[] = "r.tgl_registrasi BETWEEN ? AND ?";
$params[] = $tgl_masuk_awal; 
$params[] = $tgl_masuk_akhir;
$types .= "ss";

if ($keyword) {
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rkm_medis LIKE ? OR r.no_rawat LIKE ?)";
    $lk = "%$keyword%";
    array_push($params, $lk, $lk, $lk);
    $types .= "sss";
}

$where_sql = implode(" AND ", $where);

// QUERY UTAMA: Menggunakan kondisi BETWEEN pada subquery (Sama dengan bed.php)
$sql = "SELECT 
            r.no_rawat, r.no_rkm_medis, p.nm_pasien, bs.no_sep, pj.png_jawab, 
            b.nm_bangsal, ki.kd_kamar, ki.tgl_masuk, ki.jam_masuk, ki.tgl_keluar, 
            ki.jam_keluar, ki.stts_pulang, ki.diagnosa_awal, d.nm_dokter as dokter_dpjp,
            IF(rr.no_rawat IS NULL, 0, 1) as f_resume,
            IF(pl.no_rawat IS NULL, 0, 1) as f_lab,
            IF(prd.no_rawat IS NULL, 0, 1) as f_radiologi,
            (SELECT COUNT(*) FROM kamar_inap ki2 
             WHERE ki2.kd_kamar = ki.kd_kamar 
             AND ki2.no_rawat != r.no_rawat
             AND (
                CONCAT(ki2.tgl_masuk, ' ', ki2.jam_masuk) 
                BETWEEN CONCAT(ki.tgl_masuk, ' ', ki.jam_masuk) 
                AND CONCAT(IF(ki.tgl_keluar='0000-00-00', CURDATE(), ki.tgl_keluar), ' ', IF(ki.jam_keluar='00:00:00', '23:59:59', ki.jam_keluar))
             )
            ) as jumlah_bentrok
        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
        INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
        INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
        INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
        LEFT JOIN bridging_sep bs ON r.no_rawat = bs.no_rawat
        LEFT JOIN dpjp_ranap dr ON r.no_rawat = dr.no_rawat
        LEFT JOIN dokter d ON dr.kd_dokter = d.kd_dokter
        LEFT JOIN resume_pasien_ranap rr ON r.no_rawat = rr.no_rawat
        LEFT JOIN periksa_lab pl ON r.no_rawat = pl.no_rawat
        LEFT JOIN periksa_radiologi prd ON r.no_rawat = prd.no_rawat
        WHERE $where_sql
        GROUP BY ki.no_rawat, ki.tgl_masuk, ki.jam_masuk, ki.kd_kamar
        ORDER BY r.no_rawat DESC, ki.tgl_masuk ASC, ki.jam_masuk ASC";

$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

// STEP 1: Pengelompokan baris mutasi per no_rawat
$data_rawat = [];
while ($row = $result->fetch_assoc()) {
    $nr = $row['no_rawat'];
    $data_rawat[$nr][] = $row;
}

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_Kamar_BPJS_".date('Ymd').".xls");
}

function status_render($val, $isExcel) {
    if ($isExcel) return $val ? "Ada" : "-";
    return $val ? "<td class='status-ada'>Ada</td>" : "<td class='status-tidak'>-</td>";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring Kamar BPJS - RS ASSALAM</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 11px; background: #f0f2f5; margin: 20px; }
        .card { background: #fff; padding: 15px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; }
        th { background: #1565c0; color: white; padding: 12px 8px; border: 1px solid #0d47a1; position: sticky; top: 0; }
        td { padding: 8px; border: 1px solid #e0e0e0; vertical-align: middle; }
        .bentrok { background-color: #ffebee !important; color: #d32f2f !important; font-weight: bold; }
        .status-ada { background: #e8f5e9; color: #2e7d32; font-weight: bold; text-align: center; }
        .status-tidak { background: #ffebee; color: #c62828; text-align: center; }
        .str { mso-number-format: "\@"; }
        .filter-section { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
        .badge-danger { background: #d32f2f; color: white; padding: 2px 5px; border-radius: 3px; font-size: 9px; display: inline-block; margin-top: 3px; }
    </style>
</head>
<body>

<?php if ($action != 'excel'): ?>
<div class="card">
    <h2 style="color:#1565c0; margin-top:0;">RS ASSALAM - Monitoring Kamar Inap (Khusus BPJS)</h2>
    <form method="GET" class="filter-section">
        <input type="date" name="tgl_masuk_awal" value="<?=htmlspecialchars($tgl_masuk_awal, ENT_QUOTES, 'UTF-8')?>">
        <span>s/d</span>
        <input type="date" name="tgl_masuk_akhir" value="<?=htmlspecialchars($tgl_masuk_akhir, ENT_QUOTES, 'UTF-8')?>">
        <input type="text" name="keyword" placeholder="No.Rawat / RM / Nama Pasien..." value="<?=htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8')?>">
        <button type="submit" style="background:#1565c0; color:white; border:none; padding:8px 15px; cursor:pointer; border-radius:4px;">Filter Data</button>
        <a href="?<?=http_build_query(array_merge($_GET, ['action'=>'excel']))?>" style="text-decoration:none; background:#b71c1c; color:white; padding:8px 15px; border-radius:4px;">Export Excel</a>
    </form>
    <div style="overflow-x:auto;">
<?php endif; ?>

    <table border="1">
        <thead>
            <tr>
                <th>No</th>
                <th>No. Rawat</th>
                <th>No. RM</th>
                <th>Nama Pasien</th>
                <th>No. SEP</th>
                <th>Penjamin</th>
                <th>Dokter DPJP</th>
                <th>Diagnosa Awal</th>
                <th>Unit / Kamar (Bed)</th>
                <th>Tgl Masuk</th>
                <th>Jam</th>
                <th>Tgl Keluar</th>
                <th>Jam</th>
                <th>Status Pulang</th>
                <th>Resume</th>
                <th>Lab</th>
                <th>Rad</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 0;
            foreach ($data_rawat as $no_rawat => $rows):
                $i++;
                foreach ($rows as $index => $r):
                    $is_bentrok = ($r['jumlah_bentrok'] > 0);
                    $style = $is_bentrok ? ($action == 'excel' ? "bgcolor='#FFEEEE' style='color:#D32F2F; font-weight:bold;'" : "class='bentrok'") : "";
            ?>
                <tr <?=$style?>>
                    <?php if ($index === 0): ?>
                        <td align="center" rowspan="<?=count($rows)?>"><?=$i?></td>
                        <td class="str" rowspan="<?=count($rows)?>"><?=$r['no_rawat']?></td>
                        <td class="str" rowspan="<?=count($rows)?>"><?=$r['no_rkm_medis']?></td>
                        <td rowspan="<?=count($rows)?>"><?=strtoupper($r['nm_pasien'])?></td>
                        <td class="str" rowspan="<?=count($rows)?>"><?=$r['no_sep'] ?: '-'?></td>
                        <td rowspan="<?=count($rows)?>"><?=$r['png_jawab']?></td>
                        <td rowspan="<?=count($rows)?>"><?=$r['dokter_dpjp'] ?: '-'?></td>
                        <td rowspan="<?=count($rows)?>"><?=$r['diagnosa_awal'] ?: '-'?></td>
                    <?php endif; ?>
                    
                    <td>
                        <?=$r['nm_bangsal']?> (<strong><?=$r['kd_kamar']?></strong>)
                        <?php if($is_bentrok && $action != 'excel'): ?>
                            <br><span class="badge-danger">IRISAN (OVERLAP BED)</span>
                        <?php endif; ?>
                    </td>
                    <td align="center"><?=date('d-m-Y', strtotime($r['tgl_masuk']))?></td>
                    <td align="center"><?=$r['jam_masuk']?></td>
                    <td align="center"><?=($r['tgl_keluar'] != '0000-00-00') ? date('d-m-Y', strtotime($r['tgl_keluar'])) : 'Masih Dirawat'?></td>
                    <td align="center"><?=($r['jam_keluar'] != '00:00:00') ? $r['jam_keluar'] : '-'?></td>
                    <td align="center"><?=$r['stts_pulang'] ?: '-'?></td>
                    
                    <?php if ($action == 'excel'): ?>
                        <td align="center"><?=status_render($r['f_resume'], true)?></td>
                        <td align="center"><?=status_render($r['f_lab'], true)?></td>
                        <td align="center"><?=status_render($r['f_radiologi'], true)?></td>
                    <?php else: ?>
                        <?=status_render($r['f_resume'], false)?>
                        <?=status_render($r['f_lab'], false)?>
                        <?=status_render($r['f_radiologi'], false)?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endforeach; ?>
        </tbody>
    </table>

<?php if ($action != 'excel'): ?>
    </div>
</div>
<?php endif; ?>
<div style="text-align:center; font-size:11px; color:#888; margin-top:15px; padding-bottom:20px;">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b> - Sistem Monitoring Realtime Overlap Bed & Kamar Terintegrasi dengan SIMRS KHANZA
</div>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
