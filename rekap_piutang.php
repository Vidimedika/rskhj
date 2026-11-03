<?php
/* RS ASSALAM - Dashboard Monitoring Pendapatan Piutang
   Update: Jan 2026
   Developer: Vidi Medika Konsultan IT
*/


// 1. KONEKSI DATABASE
$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   ="db-sim";

$conn = new mysqli($host,$user,$pass,$db);
if($conn->connect_error) die("Koneksi gagal : ".$conn->connect_error);
/* ================== FILTER & SECURITY ================== */
$tgl_awal    = isset($_GET['tgl_awal'])  ? $conn->real_escape_string($_GET['tgl_awal'])  : date('Y-m-01');
$tgl_akhir   = isset($_GET['tgl_akhir']) ? $conn->real_escape_string($_GET['tgl_akhir']) : date('Y-m-d');
$berdasarkan = $_GET['berdasarkan'] ?? 'masuk';
$status      = isset($_GET['status'])    ? $conn->real_escape_string($_GET['status'])    : '';
$akun        = isset($_GET['akun'])      ? $conn->real_escape_string($_GET['akun'])      : '';
$cari        = isset($_GET['cari'])      ? $conn->real_escape_string($_GET['cari'])      : '';

/* ================== EXPORT EXCEL ================== */
if(isset($_GET['export']) && $_GET['export']=="excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=rekap_piutang_pasien.xls");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Piutang & Ekses - RS ASSALAM</title>
    <style>
        body{font-family:Arial,sans-serif;font-size:11px;margin:20px;line-height:1.4}
        table{border-collapse:collapse;width:100%;margin-bottom:20px}
        th,td{border:1px solid #000;padding:5px}
        th{background:#0a8f3c;color:#fff;text-transform:uppercase}
        .center{text-align:center}
        .right{text-align:right}
        .filter-box{background:#f9f9f9;padding:15px;margin-bottom:20px;border:1px solid #ddd;border-radius:4px}
        .flex-container {display: flex; gap: 20px; align-items: flex-start;}
        .flex-item {flex: 1;}
        tr:nth-child(even) {background-color: #f2f2f2;}
        @media print{.filter-box{display:none}}
    </style>
</head>
<body>

<h2 class="center" style="color:#0a8f3c; margin-bottom:5px">RS ASSALAM</h2>
<h3 class="center" style="margin-top:0">REKAP PIUTANG PASIEN & EKSES</h3>

<div class="filter-box">
<form method="get">
    <b>Filter:</b> 
    <select name="berdasarkan">
        <option value="masuk" <?=$berdasarkan=='masuk'?'selected':''?>>Tgl Masuk</option>
        <option value="pulang" <?=$berdasarkan=='pulang'?'selected':''?>>Tgl Pulang</option>
    </select>
    <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> s.d 
    <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
    
    Status: 
    <select name="status">
        <option value="">Semua</option>
        <option value="Ralan" <?=$status=='Ralan'?'selected':''?>>Ralan</option>
        <option value="Ranap" <?=$status=='Ranap'?'selected':''?>>Ranap</option>
    </select>

    Akun: 
    <select name="akun">
        <option value="">Semua Akun Piutang</option>
        <?php
        $qakun=$conn->query("SELECT DISTINCT nama_bayar FROM detail_piutang_pasien WHERE nama_bayar!='' ORDER BY nama_bayar");
        while($a=$qakun->fetch_assoc()) echo "<option value='{$a['nama_bayar']}' ".($akun==$a['nama_bayar']?'selected':'').">{$a['nama_bayar']}</option>";
        ?>
    </select>

    <input type="text" name="cari" value="<?=$cari?>" placeholder="Nama / No RM / No Rawat" style="width:150px">
    <button type="submit" style="background:#0a8f3c; color:#fff; border:none; padding:4px 10px; cursor:pointer">Tampilkan</button>
    <button type="submit" name="export" value="excel" style="background:#217346; color:#fff; border:none; padding:4px 10px; cursor:pointer">Excel</button>
</form>
</div>

<?php
/* ================== OPTIMIZED QUERY ================== */
$sql = "
SELECT
    rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.status_lanjut, ps.nm_pasien,
    pp.sisapiutang, pp.uangmuka, 
    pj.png_jawab as jenis_bayar,
    dpp.nama_bayar as akun_piutang,
    IF(rp.status_lanjut='Ralan', nj.no_nota, ni.no_nota) as no_nota,
    IF(rp.status_lanjut='Ralan', nj.tanggal, ki.tgl_keluar) as tgl_pulang,
    COALESCE(dnj.nama_bayar, dni.nama_bayar) as akun_pembayaran
FROM piutang_pasien pp
INNER JOIN reg_periksa rp ON rp.no_rawat = pp.no_rawat
INNER JOIN pasien ps ON ps.no_rkm_medis = rp.no_rkm_medis
INNER JOIN penjab pj ON rp.kd_pj = pj.kd_pj
LEFT JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat
LEFT JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat
LEFT JOIN (SELECT no_rawat, MAX(tgl_keluar) as tgl_keluar FROM kamar_inap GROUP BY no_rawat) ki ON ki.no_rawat = rp.no_rawat
LEFT JOIN detail_piutang_pasien dpp ON dpp.no_rawat = rp.no_rawat
LEFT JOIN (SELECT no_rawat, MAX(nama_bayar) as nama_bayar FROM detail_nota_jalan GROUP BY no_rawat) dnj ON dnj.no_rawat = rp.no_rawat
LEFT JOIN (SELECT no_rawat, MAX(nama_bayar) as nama_bayar FROM detail_nota_inap GROUP BY no_rawat) dni ON dni.no_rawat = rp.no_rawat
WHERE 1=1 ";

if($berdasarkan == 'pulang'){
    $sql .= " AND ( (rp.status_lanjut='Ralan' AND nj.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir') 
               OR (rp.status_lanjut='Ranap' AND ki.tgl_keluar BETWEEN '$tgl_awal' AND '$tgl_akhir') )";
} else {
    $sql .= " AND rp.tgl_registrasi BETWEEN '$tgl_awal' AND '$tgl_akhir'";
}

if($status != '') $sql .= " AND rp.status_lanjut = '$status'";
if($akun   != '') $sql .= " AND dpp.nama_bayar = '$akun'";
if($cari   != '') $sql .= " AND (ps.nm_pasien LIKE '%$cari%' OR rp.no_rkm_medis = '$cari' OR rp.no_rawat = '$cari')";

$sql .= " GROUP BY rp.no_rawat ORDER BY rp.tgl_registrasi ASC";
$q = $conn->query($sql);

$sumPiutang = 0; $sumEkses = 0; 
$ringkasanPiutang = []; $ringkasanBayar = [];
?>

<table>
<thead>
    <tr>
        <th>No</th>
        <th>No. RM</th>
        <th>Nama Pasien</th>
        <th>No. Rawat</th>
        <th>Tgl Masuk</th>
        <th>Tgl Pulang</th>
        <th>Status</th>
        <th>No. Nota</th>
        <th>Jenis Bayar</th>
        <th>Akun Piutang</th>
        <th>Total Piutang</th>
        <th>Akun Bayar</th>
        <th>Ekses (Uang Muka)</th>
    </tr>
</thead>
<tbody>
<?php 
if($q && $q->num_rows > 0):
    $no = 1;
    while($r = $q->fetch_assoc()):
        $akun_p_nm = $r['akun_piutang'] ?: 'TANPA AKUN';
        $akun_b_nm = $r['akun_pembayaran'] ?: '-';

        $sumPiutang += $r['sisapiutang'];
        $sumEkses   += $r['uangmuka'];

        $ringkasanPiutang[$akun_p_nm] = ($ringkasanPiutang[$akun_p_nm] ?? 0) + $r['sisapiutang'];
        $ringkasanBayar[$akun_b_nm]   = ($ringkasanBayar[$akun_b_nm] ?? 0) + $r['uangmuka'];
?>
    <tr>
        <td class="center"><?=$no++?></td>
        <td class="center"><?=$r['no_rkm_medis']?></td>
        <td><?=$r['nm_pasien']?></td>
        <td class="center"><?=$r['no_rawat']?></td>
        <td class="center"><?=date('d-m-Y', strtotime($r['tgl_registrasi']))?></td>
        <td class="center"><?=$r['tgl_pulang'] ? date('d-m-Y', strtotime($r['tgl_pulang'])) : '-'?></td>
        <td class="center"><?=$r['status_lanjut']?></td>
        <td class="center"><?=$r['no_nota'] ?: '-'?></td>
        <td><?=$r['jenis_bayar']?></td>
        <td><?=$akun_p_nm?></td>
        <td class="right"><?=number_format($r['sisapiutang'], 0, ',', '.')?></td>
        <td><?=$akun_b_nm?></td>
        <td class="right"><?=number_format($r['uangmuka'], 0, ',', '.')?></td>
    </tr>
<?php endwhile; else: ?>
    <tr><td colspan="13" class="center">Data tidak ditemukan untuk periode ini.</td></tr>
<?php endif; ?>
</tbody>
<tfoot>
    <tr style="background:#eee; font-weight:bold">
        <td colspan="10" class="right">TOTAL KESELURUHAN</td>
        <td class="right"><?=number_format($sumPiutang, 0, ',', '.')?></td>
        <td></td>
        <td class="right"><?=number_format($sumEkses, 0, ',', '.')?></td>
    </tr>
</tfoot>
</table>

<div class="flex-container">
    <div class="flex-item">
        <h3>Ringkasan Per Akun Piutang</h3>
        <table>
            <thead>
                <tr><th>No</th><th>Akun Piutang</th><th>Total Sisa Piutang</th></tr>
            </thead>
            <tbody>
            <?php $i=1; ksort($ringkasanPiutang); foreach($ringkasanPiutang as $a=>$t): ?>
                <tr><td class="center"><?=$i++?></td><td><?=$a?></td><td class="right"><?=number_format($t, 0, ',', '.')?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="flex-item">
        <h3>Ringkasan Per Akun Bayar (Ekses)</h3>jual 
        <table>
            <thead>
                <tr><th>No</th><th>Akun Bayar</th><th>Total Ekses</th></tr>
            </thead>
            <tbody>
            <?php $j=1; ksort($ringkasanBayar); foreach($ringkasanBayar as $ab=>$tb): ?>
                <tr><td class="center"><?=$j++?></td><td><?=$ab?></td><td class="right"><?=number_format($tb, 0, ',', '.')?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="text-align:center; font-size:12px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. All Rights Reserved.
</div>

</body>
</html>

