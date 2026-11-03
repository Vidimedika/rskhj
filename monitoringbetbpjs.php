<?php
/**
 * Dashboard Monitoring Harian Penggunaan Bed - RS ASSALAM
 * Filter: KHUSUS JENIS BAYAR BPJS + Pencarian Pasien + Filter Status
 * Desain: Profesional Medical Theme (Hijau & Merah)
 */

$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   = "db-sim";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Koneksi gagal: " . $conn->connect_error); }

$tgl_awal   = $_GET['tgl_awal']   ?? date('Y-m-01');
$tgl_akhir  = $_GET['tgl_akhir']  ?? date('Y-m-d');
$keyword    = $_GET['keyword']    ?? '';
$status_bed = $_GET['status_bed'] ?? 'semua';
$action     = $_GET['action']     ?? '';

$search_param = "%" . $keyword . "%";

$sql = "SELECT * FROM (
            SELECT 
                ki.tgl_masuk, ki.jam_masuk, ki.kd_kamar, b.nm_bangsal, ki.no_rawat, p.nm_pasien, r.no_rkm_medis,
                ki.tgl_keluar, ki.jam_keluar, ki.stts_pulang, pj.png_jawab,
                (SELECT d.nm_dokter FROM dpjp_ranap dr JOIN dokter d ON dr.kd_dokter=d.kd_dokter WHERE dr.no_rawat=r.no_rawat LIMIT 1) as dokter_dpjp,
                (SELECT COUNT(*) FROM kamar_inap ki2 
                 WHERE ki2.kd_kamar = ki.kd_kamar 
                 AND ki2.no_rawat != ki.no_rawat
                 AND (
                    CONCAT(ki2.tgl_masuk, ' ', ki2.jam_masuk) 
                    BETWEEN CONCAT(ki.tgl_masuk, ' ', ki.jam_masuk) 
                    AND CONCAT(IF(ki.tgl_keluar='0000-00-00', CURDATE(), ki.tgl_keluar), ' ', IF(ki.jam_keluar='00:00:00', '23:59:59', ki.jam_keluar))
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
            AND (p.nm_pasien LIKE ? OR r.no_rkm_medis LIKE ?)
        ) as main_query WHERE 1=1";

if ($status_bed == 'irisan') {
    $sql .= " AND jumlah_bentrok > 0";
} elseif ($status_bed == 'ok') {
    $sql .= " AND jumlah_bentrok = 0";
}

$sql .= " ORDER BY tgl_masuk ASC, nm_bangsal ASC, kd_kamar ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ssss", $tgl_awal, $tgl_akhir, $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result();

// Jika mode Excel, kirim header download dan langsung cetak judul teks murni + tabel
if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_Bed_BPJS_".date('Ymd').".xls");
    
    // Judul tulisan khusus keluaran file Excel agar rapi di spreadsheet
    echo "<h3>MONITORING BED & PASIEN (KHUSUS BPJS) - RS ASSALAM</h3>";
    echo "<p>Periode: ".date('d-m-Y', strtotime($tgl_awal))." s/d ".date('d-m-Y', strtotime($tgl_akhir))."</p><br>";
}
?>

<?php if ($action != 'excel'): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Monitoring Bed BPJS - RS ASSALAM</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 11px; margin: 0; background-color: #f1f5f4; color: #333; }
        .container { padding: 20px; max-width: 1600px; margin: 0 auto; }
        .header-card { background: linear-gradient(135deg, #00796b 0%, #004d40 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .header-card h2 { margin: 0; font-size: 18px; font-weight: 600; letter-spacing: 0.5px; }
        .header-card p { margin: 6px 0 0 0; opacity: 0.85; font-size: 11px; }
        .filter-box { background: white; padding: 15px 20px; margin-bottom: 20px; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: flex; flex-wrap: wrap; align-items: flex-end; gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; margin-bottom: 6px; color: #004d40; font-size: 11px; }
        .form-control { padding: 7px 10px; border: 1px solid #b2dfdb; border-radius: 5px; font-size: 11px; color: #333; background-color: #fafafa; transition: all 0.2s; height: 32px; box-sizing: border-box; }
        .form-control:focus { border-color: #00796b; background-color: #fff; outline: none; box-shadow: 0 0 0 2px rgba(0,121,107,0.15); }
        .btn { border: none; padding: 0 16px; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; height: 32px; transition: all 0.2s; box-sizing: border-box; }
        .btn-primary { background-color: #00796b; color: white; box-shadow: 0 2px 4px rgba(0,121,107,0.2); }
        .btn-primary:hover { background-color: #00695c; transform: translateY(-1px); }
        .btn-danger { background-color: #c62828; color: white; box-shadow: 0 2px 4px rgba(198,40,40,0.2); }
        .btn-danger:hover { background-color: #b71c1c; transform: translateY(-1px); }
        table { border-collapse: collapse; width: 100%; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.04); border: 1px solid #e0e0e0; }
        th { background-color: #00695c; color: white; padding: 10px 8px; font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: 0.3px; border: 1px solid #004d40; }
        td { padding: 8px; border: 1px solid #eeeeee; vertical-align: middle; }
        tr:nth-child(even) { background-color: #f9fbfb; }
        tr:hover { background-color: #f2f7f6; }
        .tgl-row { background-color: #e0f2f1 !important; font-weight: bold; font-size: 11px; color: #004d40; }
        .tgl-row td { padding: 10px; border-top: 2px solid #b2dfdb; border-bottom: 2px solid #b2dfdb; }
        .bentrok { background-color: #ffebee !important; color: #b71c1c !important; }
        .bentrok:hover { background-color: #ffcdd2 !important; }
        .bentrok small { color: #c62828 !important; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 9px; font-weight: bold; color: white; display: inline-block; text-transform: uppercase; letter-spacing: 0.3px; }
        .badge-info { background-color: #0097a7; }
        .badge-danger { background-color: #d32f2f; box-shadow: 0 2px 4px rgba(211,47,47,0.2); }
        .badge-success { background-color: #2e7d32; box-shadow: 0 2px 4px rgba(46,125,50,0.2); }
        .str { mso-number-format: "\@"; } 
        .footer-text { text-align: center; padding: 25px; color: #757575; font-size: 10px; letter-spacing: 0.5px; }
    </style>
</head>
<body>

<div class="container">
    <!-- Header Dashboard Web -->
    <div class="header-card">
        <h2>MONITORING BED & PASIEN (KHUSUS BPJS) - RS ASSALAM</h2>
        <p>Sistem Kendali Mutu & Verifikasi Akurasi Irisan Berbasis Detik</p>
    </div>

    <!-- Filter Form -->
    <div class="filter-box">
        <form method="GET" style="display: flex; flex-wrap: wrap; gap: 15px; width: 100%; align-items: flex-end;">
            <div class="form-group">
                <label>Periode Masuk</label>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <input type="date" name="tgl_awal" class="form-control" value="<?=$tgl_awal?>">
                    <span style="color: #666; font-size:11px;">s/d</span>
                    <input type="date" name="tgl_akhir" class="form-control" value="<?=$tgl_akhir?>">
                </div>
            </div>

            <div class="form-group">
                <label>Cari Nama Pasien / No. RM</label>
                <input type="text" name="keyword" class="form-control" placeholder="Ketik nama atau rekam medis..." value="<?=htmlspecialchars($keyword)?>" style="width: 220px;">
            </div>

            <div class="form-group">
                <label>Status Keterangan Bed</label>
                <select name="status_bed" class="form-control" style="width: 160px;">
                    <option value="semua" <?=$status_bed == 'semua' ? 'selected' : ''?>>-- Semua Status --</option>
                    <option value="ok" <?=$status_bed == 'ok' ? 'selected' : ''?>>OK (Normal)</option>
                    <option value="irisan" <?=$status_bed == 'irisan' ? 'selected' : ''?>>IRISAN (Overlap)</option>
                </select>
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">Tampilkan BPJS</button>
                <a href="?action=excel&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>&keyword=<?=urlencode($keyword)?>&status_bed=<?=$status_bed?>" class="btn btn-danger">Export Excel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

    <!-- Data Table (Berlaku untuk tampilan Web & Excel) -->
    <table border="1">
        <thead>
            <tr>
                <th width="3%">No</th>
                <th width="12%">Kamar/Bed</th>
                <th width="15%">Nama Pasien</th>
                <th width="7%">No. RM</th>
                <th width="11%">No. Rawat</th>
                <th width="12%">Jenis Bayar</th>
                <th width="14%">Dokter DPJP</th>
                <th width="7%">Tgl Masuk</th>
                <th width="5%">Jam</th>
                <th width="7%">Tgl Keluar</th>
                <th width="5%">Jam</th>
                <th width="6%">Status</th>
                <th width="6%">Ket</th>
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
                    $row_class = ($is_bentrok && $action != 'excel') ? "class='bentrok'" : "";
                ?>
                <tr <?=$row_class?>>
                    <td align="center"><?=$no++?></td>
                    <td>
                        <strong style="color: #004d40;"><?=$row['kd_kamar']?></strong><br>
                        <small style="color: #555; font-size: 9px;"><?=$row['nm_bangsal']?></small>
                    </td>
                    <td><b><?=strtoupper($row['nm_pasien'])?></b></td>
                    <td align="center" class="str"><?=$row['no_rkm_medis']?></td>
                    <td align="center" class="str"><?=$row['no_rawat']?></td>
                    <td align="left"><?=$row['png_jawab']?></td>
                    <td align="left" style="font-size: 10px;"><?=$row['dokter_dpjp'] ?: '-'?></td>
                    <td align="center"><?=date('d-m-Y', strtotime($row['tgl_masuk']))?></td>
                    <td align="center"><?=$row['jam_masuk']?></td>
                    <td align="center">
                        <?=($row['tgl_keluar'] != '0000-00-00') ? date('d-m-Y', strtotime($row['tgl_keluar'])) : 'Masih Dirawat'?>
                    </td>
                    <td align="center">
                        <?=($row['jam_keluar'] != '00:00:00') ? $row['jam_keluar'] : '-'?>
                    </td>
                    <td align="center"><?=$row['stts_pulang']?></td>
                    <td align="center">
                        <?=$is_bentrok ? 'IRISAN' : 'OK'?>
                    </td>
                </tr>
                <?php endwhile; 
            else: ?>
                <tr><td colspan="13" align="center" style="padding:30px; font-size:12px; color:#777;">Data BPJS tidak ditemukan berdasarkan filter/pencarian ini.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

<?php if ($action != 'excel'): ?>
    <!-- Footer Dashboard Web -->
    <div class="footer-text">
        © 2026 <b>VIDI MEDIKA IT HEALTHCARE CONSULTANT</b> — Integrasi Sistem Informasi Manajemen RS (SIMRS) Khanza
    </div>
</div>

</body>
</html>
<?php endif; ?>
