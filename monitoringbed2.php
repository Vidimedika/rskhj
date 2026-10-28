<<<<<<< HEAD
<?php
/**
 * Dashboard Monitoring Harian Penggunaan Bed - RS ASSALAM
 * 
 */

$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   = "db-sim";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Koneksi gagal: " . $conn->connect_error); }

$tgl_awal  = $_GET['tgl_awal']  ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$status_ket = $_GET['status_ket'] ?? 'semua'; // Default menampilkan semua data
$action    = $_GET['action']    ?? '';

// SQL dasar menggunakan subquery di luar (Derived Table / CTE) agar bisa memfilter alias `jumlah_bentrok` lewat WHERE
$sql = "SELECT * FROM (
            SELECT 
                ki.tgl_masuk, ki.jam_masuk, ki.kd_kamar, b.nm_bangsal, ki.no_rawat, p.nm_pasien, r.no_rkm_medis,
                ki.tgl_keluar, ki.jam_keluar, ki.stts_pulang, pj.png_jawab,
                (SELECT d.nm_dokter FROM dpjp_ranap dr JOIN dokter d ON dr.kd_dokter=d.kd_dokter WHERE dr.no_rawat=r.no_rawat LIMIT 1) as dokter_dpjp,
                (SELECT COUNT(*) FROM kamar_inap ki2 
                 WHERE ki2.kd_kamar = ki.kd_kamar 
                 AND ki2.no_rawat != ki.no_rawat
                 AND (
                    (ki2.tgl_masuk BETWEEN ki.tgl_masuk AND IF(ki.tgl_keluar='0000-00-00', CURDATE(), ki.tgl_keluar))
                 )
                ) as jumlah_bentrok
            FROM kamar_inap ki
            INNER JOIN reg_periksa r ON ki.no_rawat = r.no_rawat
            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
            INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
            INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
            INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
            WHERE (ki.tgl_masuk BETWEEN ? AND ?)
            AND pj.png_jawab LIKE '%BPJS%'
        ) as monitoring_bed WHERE 1=1";

// Menambahkan kondisi filter berdasarkan pilihan Keterangan
if ($status_ket == 'bentrok') {
    $sql .= " AND jumlah_bentrok > 0";
} elseif ($status_ket == 'ok') {
    $sql .= " AND jumlah_bentrok = 0";
}

$sql .= " ORDER BY tgl_masuk ASC, nm_bangsal ASC, kd_kamar ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $tgl_awal, $tgl_akhir);
$stmt->execute();
$result = $stmt->get_result();

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_Bed_BPJS_".date('Ymd').".xls");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Monitoring Bed BPJS - RS ASSALAM</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10px; margin: 0; background-color: #f4f7f6; }
        .container { padding: 15px; }
        .header-card { background: #01579b; color: white; padding: 15px; border-radius: 8px 8px 0 0; }
        .filter-box { background: white; padding: 10px; margin-bottom: 15px; border-radius: 0 0 8px 8px; border: 1px solid #ddd; }
        table { border-collapse: collapse; width: 100%; background: white; }
        th { background: #0277bd; color: white; padding: 8px; border: 1px solid #01579b; text-transform: uppercase; }
        td { padding: 6px; border: 1px solid #eee; }
        .tgl-row { background: #e1f5fe; font-weight: bold; font-size: 12px; color: #01579b; }
        .bentrok { background-color: #ffebee !important; color: #d32f2f !important; font-weight: bold; }
        .badge { padding: 2px 5px; border-radius: 3px; font-size: 9px; color: white; display: inline-block; }
        .badge-info { background: #0288d1; }
        .badge-danger { background: #d32f2f; }
        .badge-success { background: #4caf50; }
        .str { mso-number-format: "\@"; } 
    </style>
</head>
<body>

<div class="container">
    <div class="header-card">
        <h2 style="margin:0;">MONITORING BED & PASIEN (KHUSUS BPJS)</h2>
        <p style="margin:5px 0; opacity: 0.8;">RS ASSALAM - Laporan Berdasarkan Penjamin BPJS Kesehatan</p>
    </div>

    <?php if ($action != 'excel'): ?>
    <div class="filter-box">
        <form method="GET">
            <strong>Periode Masuk:</strong> 
            <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> s/d 
            <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
            
            <strong style="margin-left: 15px;">Keterangan:</strong>
            <select name="status_ket" style="padding: 3px 8px; border-radius: 4px; border: 1px solid #ccc;">
                <option value="semua" <?=($status_ket == 'semua') ? 'selected' : ''?>>- Semua -</option>
                <option value="ok" <?=($status_ket == 'ok') ? 'selected' : ''?>>OK (Normal)</option>
                <option value="bentrok" <?=($status_ket == 'bentrok') ? 'selected' : ''?>>IRISAN (Overlap Bed)</option>
            </select>

            <button type="submit" style="background:#01579b; color:white; border:none; padding:5px 12px; border-radius:4px; cursor:pointer; margin-left: 10px;">Filter BPJS</button>
            <a href="?action=excel&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>&status_ket=<?=$status_ket?>" style="background:#b71c1c; color:white; padding:6px 12px; border-radius:4px; text-decoration:none; font-size:10px; margin-left:10px;">Export Excel</a>
        </form>
    </div>
    <?php endif; ?>

    <table border="1">
        <thead>
            <tr>
                <th>No</th>
                <th>Kamar/Bed</th>
                <th>Nama Pasien</th>
                <th>No. RM</th>
                <th>No. Rawat</th>
                <th>Jenis Bayar</th>
                <th>Dokter DPJP</th>
                <th>Tgl Masuk</th>
                <th>Jam</th>
                <th>Tgl Keluar</th>
                <th>Jam</th>
                <th>Status</th>
                <th>Ket</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            $current_date = "";
            if ($result->num_rows > 0):
                while ($row = $result->fetch_assoc()): 
                    if ($row['tgl_masuk'] != $current_date) {
                        $current_date = $row['tgl_masuk'];
                        echo "<tr class='tgl-row'><td colspan='13'>📅 Tanggal Masuk: " . date('d-m-Y', strtotime($current_date)) . "</td></tr>";
                        $no = 1;
                    }

                    $is_bentrok = ($row['jumlah_bentrok'] > 0);
                    $row_class = $is_bentrok ? "class='bentrok'" : "";
                    $style_excel = ($action == 'excel' && $is_bentrok) ? "bgcolor='#FF0000'" : "";
                ?>
                <tr <?=$row_class?> <?=$style_excel?>>
                    <td align="center"><?=$no++?></td>
                    <td><strong><?=$row['kd_kamar']?></strong><br><small><?=$row['nm_bangsal']?></small></td>
                    <td><?=strtoupper($row['nm_pasien'])?></td>
                    <td align="center" class="str"><?=$row['no_rkm_medis']?></td>
                    <td align="center" class="str"><?=$row['no_rawat']?></td>
                    <td align="left"><?=$row['png_jawab']?></td>
                    <td align="left"><?=$row['dokter_dpjp'] ?: '-'?></td>
                    <td align="center"><?=date('d-m-Y', strtotime($row['tgl_masuk']))?></td>
                    <td align="center"><?=$row['jam_masuk']?></td>
                    <td align="center">
                        <?=($row['tgl_keluar'] != '0000-00-00') ? date('d-m-Y', strtotime($row['tgl_keluar'])) : '<span class="badge badge-info">Masih Dirawat</span>'?>
                    </td>
                    <td align="center">
                        <?=($row['jam_keluar'] != '00:00:00') ? $row['jam_keluar'] : '-'?>
                    </td>
                    <td align="center"><?=$row['stts_pulang']?></td>
                    <td align="center">
                        <?php if($is_bentrok): ?>
                            <span class="badge badge-danger">IRISAN (OVERLAP BED)</span>
                        <?php else: ?>
                            <span class="badge badge-success">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; 
            else: ?>
                <tr><td colspan="13" align="center" style="padding:20px;">Data pasien BPJS dengan kriteria tersebut tidak ditemukan pada periode ini.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="text-align:center; padding: 20px; color:#888; font-size: 10px;">
        © 2026 <b>VIDI MEDIKA IT HEALTHCARE CONSULTANT</b> - Sistem Monitoring Bed Terintegrasi dengan SIMRS Khanza    </div>
</div>

</body>
</html>
=======
<?php
/**
 * Dashboard Monitoring Harian Penggunaan Bed - RS ASSALAM
 * 
 */

$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   = "db-sim";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Koneksi gagal: " . $conn->connect_error); }

$tgl_awal  = $_GET['tgl_awal']  ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$status_ket = $_GET['status_ket'] ?? 'semua'; // Default menampilkan semua data
$action    = $_GET['action']    ?? '';

// SQL dasar menggunakan subquery di luar (Derived Table / CTE) agar bisa memfilter alias `jumlah_bentrok` lewat WHERE
$sql = "SELECT * FROM (
            SELECT 
                ki.tgl_masuk, ki.jam_masuk, ki.kd_kamar, b.nm_bangsal, ki.no_rawat, p.nm_pasien, r.no_rkm_medis,
                ki.tgl_keluar, ki.jam_keluar, ki.stts_pulang, pj.png_jawab,
                (SELECT d.nm_dokter FROM dpjp_ranap dr JOIN dokter d ON dr.kd_dokter=d.kd_dokter WHERE dr.no_rawat=r.no_rawat LIMIT 1) as dokter_dpjp,
                (SELECT COUNT(*) FROM kamar_inap ki2 
                 WHERE ki2.kd_kamar = ki.kd_kamar 
                 AND ki2.no_rawat != ki.no_rawat
                 AND (
                    (ki2.tgl_masuk BETWEEN ki.tgl_masuk AND IF(ki.tgl_keluar='0000-00-00', CURDATE(), ki.tgl_keluar))
                 )
                ) as jumlah_bentrok
            FROM kamar_inap ki
            INNER JOIN reg_periksa r ON ki.no_rawat = r.no_rawat
            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
            INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
            INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
            INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
            WHERE (ki.tgl_masuk BETWEEN ? AND ?)
            AND pj.png_jawab LIKE '%BPJS%'
        ) as monitoring_bed WHERE 1=1";

// Menambahkan kondisi filter berdasarkan pilihan Keterangan
if ($status_ket == 'bentrok') {
    $sql .= " AND jumlah_bentrok > 0";
} elseif ($status_ket == 'ok') {
    $sql .= " AND jumlah_bentrok = 0";
}

$sql .= " ORDER BY tgl_masuk ASC, nm_bangsal ASC, kd_kamar ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $tgl_awal, $tgl_akhir);
$stmt->execute();
$result = $stmt->get_result();

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_Bed_BPJS_".date('Ymd').".xls");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Monitoring Bed BPJS - RS ASSALAM</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10px; margin: 0; background-color: #f4f7f6; }
        .container { padding: 15px; }
        .header-card { background: #01579b; color: white; padding: 15px; border-radius: 8px 8px 0 0; }
        .filter-box { background: white; padding: 10px; margin-bottom: 15px; border-radius: 0 0 8px 8px; border: 1px solid #ddd; }
        table { border-collapse: collapse; width: 100%; background: white; }
        th { background: #0277bd; color: white; padding: 8px; border: 1px solid #01579b; text-transform: uppercase; }
        td { padding: 6px; border: 1px solid #eee; }
        .tgl-row { background: #e1f5fe; font-weight: bold; font-size: 12px; color: #01579b; }
        .bentrok { background-color: #ffebee !important; color: #d32f2f !important; font-weight: bold; }
        .badge { padding: 2px 5px; border-radius: 3px; font-size: 9px; color: white; display: inline-block; }
        .badge-info { background: #0288d1; }
        .badge-danger { background: #d32f2f; }
        .badge-success { background: #4caf50; }
        .str { mso-number-format: "\@"; } 
    </style>
</head>
<body>

<div class="container">
    <div class="header-card">
        <h2 style="margin:0;">MONITORING BED & PASIEN (KHUSUS BPJS)</h2>
        <p style="margin:5px 0; opacity: 0.8;">RS ASSALAM - Laporan Berdasarkan Penjamin BPJS Kesehatan</p>
    </div>

    <?php if ($action != 'excel'): ?>
    <div class="filter-box">
        <form method="GET">
            <strong>Periode Masuk:</strong> 
            <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> s/d 
            <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
            
            <strong style="margin-left: 15px;">Keterangan:</strong>
            <select name="status_ket" style="padding: 3px 8px; border-radius: 4px; border: 1px solid #ccc;">
                <option value="semua" <?=($status_ket == 'semua') ? 'selected' : ''?>>- Semua -</option>
                <option value="ok" <?=($status_ket == 'ok') ? 'selected' : ''?>>OK (Normal)</option>
                <option value="bentrok" <?=($status_ket == 'bentrok') ? 'selected' : ''?>>IRISAN (Overlap Bed)</option>
            </select>

            <button type="submit" style="background:#01579b; color:white; border:none; padding:5px 12px; border-radius:4px; cursor:pointer; margin-left: 10px;">Filter BPJS</button>
            <a href="?action=excel&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>&status_ket=<?=$status_ket?>" style="background:#b71c1c; color:white; padding:6px 12px; border-radius:4px; text-decoration:none; font-size:10px; margin-left:10px;">Export Excel</a>
        </form>
    </div>
    <?php endif; ?>

    <table border="1">
        <thead>
            <tr>
                <th>No</th>
                <th>Kamar/Bed</th>
                <th>Nama Pasien</th>
                <th>No. RM</th>
                <th>No. Rawat</th>
                <th>Jenis Bayar</th>
                <th>Dokter DPJP</th>
                <th>Tgl Masuk</th>
                <th>Jam</th>
                <th>Tgl Keluar</th>
                <th>Jam</th>
                <th>Status</th>
                <th>Ket</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            $current_date = "";
            if ($result->num_rows > 0):
                while ($row = $result->fetch_assoc()): 
                    if ($row['tgl_masuk'] != $current_date) {
                        $current_date = $row['tgl_masuk'];
                        echo "<tr class='tgl-row'><td colspan='13'>📅 Tanggal Masuk: " . date('d-m-Y', strtotime($current_date)) . "</td></tr>";
                        $no = 1;
                    }

                    $is_bentrok = ($row['jumlah_bentrok'] > 0);
                    $row_class = $is_bentrok ? "class='bentrok'" : "";
                    $style_excel = ($action == 'excel' && $is_bentrok) ? "bgcolor='#FF0000'" : "";
                ?>
                <tr <?=$row_class?> <?=$style_excel?>>
                    <td align="center"><?=$no++?></td>
                    <td><strong><?=$row['kd_kamar']?></strong><br><small><?=$row['nm_bangsal']?></small></td>
                    <td><?=strtoupper($row['nm_pasien'])?></td>
                    <td align="center" class="str"><?=$row['no_rkm_medis']?></td>
                    <td align="center" class="str"><?=$row['no_rawat']?></td>
                    <td align="left"><?=$row['png_jawab']?></td>
                    <td align="left"><?=$row['dokter_dpjp'] ?: '-'?></td>
                    <td align="center"><?=date('d-m-Y', strtotime($row['tgl_masuk']))?></td>
                    <td align="center"><?=$row['jam_masuk']?></td>
                    <td align="center">
                        <?=($row['tgl_keluar'] != '0000-00-00') ? date('d-m-Y', strtotime($row['tgl_keluar'])) : '<span class="badge badge-info">Masih Dirawat</span>'?>
                    </td>
                    <td align="center">
                        <?=($row['jam_keluar'] != '00:00:00') ? $row['jam_keluar'] : '-'?>
                    </td>
                    <td align="center"><?=$row['stts_pulang']?></td>
                    <td align="center">
                        <?php if($is_bentrok): ?>
                            <span class="badge badge-danger">IRISAN (OVERLAP BED)</span>
                        <?php else: ?>
                            <span class="badge badge-success">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; 
            else: ?>
                <tr><td colspan="13" align="center" style="padding:20px;">Data pasien BPJS dengan kriteria tersebut tidak ditemukan pada periode ini.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="text-align:center; padding: 20px; color:#888; font-size: 10px;">
        © 2026 <b>VIDI MEDIKA IT HEALTHCARE CONSULTANT</b> - Sistem Monitoring Bed Terintegrasi dengan SIMRS Khanza    </div>
</div>

</body>
</html>
>>>>>>> origin/master
