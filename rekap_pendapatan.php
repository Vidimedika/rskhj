<?php
/* RS ASSALAM - Dashboard Monitoring Pendapatan
   Update: Jan 2026
   Developer: Vidi Medika Konsultan IT
*/


// 1. KONEKSI DATABASE
 $db_hostname    = "192.168.10.28";
    $db_username    = "root";
    $db_password    = "1nternet";
    $db_name        = "rskhj";

$conn = new mysqli($host,$user,$pass,$db);
if($conn->connect_error) die("Koneksi gagal : ".$conn->connect_error);
/* ================== HELPER ================== */
function bukaquery2($sql){
    global $conn;
    return $conn->query($sql);
}

function getOne($sql){
    $q = bukaquery2($sql);
    if($q && $d = $q->fetch_row()){
        return $d[0];
    }
    return "";
}

function getAngka($sql){
    $q = bukaquery2($sql);
    if($q && $d = $q->fetch_row()){
        return floatval($d[0]);
    }
    return 0;
}

/* ================== FILTER ================== */
$tgl_awal  = $_GET['tgl_awal']  ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$jam_awal  = $_GET['jam_awal']  ?? "00:00";
$jam_akhir = $_GET['jam_akhir'] ?? "23:59";

$datetime_awal  = "$tgl_awal $jam_awal:00";
$datetime_akhir = "$tgl_akhir $jam_akhir:59";

/* ================== MASTER AKUN BAYAR ================== */
$akunbayar = [];
$qakun = bukaquery2("
    SELECT r.kd_rek, r.nm_rek
    FROM rekening r
    WHERE r.kd_rek IN (SELECT kd_rek FROM akun_bayar GROUP BY kd_rek)
    ORDER BY r.nm_rek
");
while($d = $qakun->fetch_assoc()){
    $akunbayar[] = $d;
}

/* ================== DETEKSI JENIS TRANSAKSI ================== */
function deteksiTransaksi($no){
    if(getOne("SELECT no_nota FROM nota_inap WHERE no_rawat='$no'")!="")
        return "RI";
    if(getOne("SELECT no_nota FROM nota_jalan WHERE no_rawat='$no'")!="")
        return "RJ";
    if(getOne("SELECT nota_jual FROM penjualan WHERE nota_jual='$no'")!="")
        return "JUAL";
    if(getOne("SELECT no_deposit FROM deposit WHERE no_deposit='$no'")!="")
        return "DEP";
    if(getOne("SELECT no_masuk FROM pemasukan_lain WHERE no_masuk='$no'")!="")
        return "LAIN";
    return "";
}

/* ================== HITUNG PER AKUN ================== */
function bayarPerAkun($tipe,$no,$kd){
    switch($tipe){
        case 'RI':
            return getAngka("
                SELECT SUM(dni.besar_bayar)
                FROM detail_nota_inap dni
                JOIN akun_bayar ab ON dni.nama_bayar=ab.nama_bayar
                WHERE dni.no_rawat='$no'
                AND ab.kd_rek='$kd'
            ");
        case 'RJ':
            return getAngka("
                SELECT SUM(dnj.besar_bayar)
                FROM detail_nota_jalan dnj
                JOIN akun_bayar ab ON dnj.nama_bayar=ab.nama_bayar
                WHERE dnj.no_rawat='$no'
                AND ab.kd_rek='$kd'
            ");
        case 'JUAL ':
            return getAngka("
                SELECT SUM(dj.total)
                FROM detailjual dj
                JOIN penjualan pj ON pj.nota_jual=dj.nota_jual
                JOIN akun_bayar ab ON pj.nama_bayar=ab.nama_bayar
                WHERE pj.nota_jual='$no'
                AND ab.kd_rek='$kd'
            ");
        case 'DEP':
            return getAngka("
                SELECT SUM(d.besar_deposit)
                FROM deposit d
                JOIN akun_bayar ab ON d.nama_bayar=ab.nama_bayar
                WHERE d.no_deposit='$no'
                AND ab.kd_rek='$kd'
            ");
        case 'LAIN':
            return getAngka("
                SELECT SUM(pl.besar)
                FROM pemasukan_lain pl
                JOIN kategori_pemasukan_lain kp 
                    ON kp.kode_kategori=pl.kode_kategori
                WHERE pl.no_masuk='$no'
                AND kp.kd_rek2='$kd'
            ");
    }
    return 0;
}

/* ================== DATA PEMBAYARAN ================== */
$sql = "
SELECT 
    no_nota,
    tgl_bayar,
    nama_pasien,
    jumlah_bayar,
    petugas
FROM tagihan_sadewa
WHERE tgl_bayar BETWEEN '$datetime_awal' AND '$datetime_akhir'
ORDER BY tgl_bayar,no_nota
";
$data = bukaquery2($sql);

/* ================== EXPORT EXCEL ================== */
if(isset($_GET['export']) && $_GET['export']=="excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=rekap_pembayaran.xls");
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Rekap Pembayaran Per Akun Bayar</title>
<style>
body{font-family:Arial;font-size:13px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #000;padding:5px}
th{background:#0a8f3c;color:#fff;text-align:center;}
.right{text-align:right}
.filter input,.filter select{margin:2px;padding:3px}
</style>
</head>
<body>

<h2 style="text-align:center;color:#0a8f3c">
RS KARTIKA HUSADA JATIASIH<br>
REKAP PEMBAYARAN PASIEN
</h2>

<form method="get" class="filter">
Tanggal :
<input type="date" name="tgl_awal" value="<?= $tgl_awal ?>">
s/d
<input type="date" name="tgl_akhir" value="<?= $tgl_akhir ?>">
Jam :
<input type="time" name="jam_awal" value="<?= $jam_awal ?>">
-
<input type="time" name="jam_akhir" value="<?= $jam_akhir ?>">
<button type="submit">Tampilkan</button>
<button type="submit" name="export" value="excel">Export Excel</button>
</form>

<br>

<table>
<tr>
<th>No</th><th>Tgl</th><th>No Nota</th><th>Pasien</th>
<th>Jenis</th><th>Total</th><th>Petugas</th><th>Rincian Akun</th>
</tr>

<?php
$no=1; 
$grand=0; 
$totalAkun=[];
while($r = $data->fetch_assoc()):
    $jenis = deteksiTransaksi($r['no_nota']);
    if($jenis=="") continue;

    $grand += $r['jumlah_bayar'];
?>
<tr>
<td><?= $no++ ?></td>
<td><?= $r['tgl_bayar'] ?></td>
<td><?= $r['no_nota'] ?></td>
<td><?= $r['nama_pasien'] ?></td>
<td><?= $jenis ?></td>
<td class="right"><?= number_format($r['jumlah_bayar'],0,',','.') ?></td>
<td><?= $r['petugas'] ?></td>
<td>
<table width="100%">
<?php foreach($akunbayar as $a):
    $nilai = bayarPerAkun($jenis,$r['no_nota'],$a['kd_rek']);
    if($nilai>0):
        $totalAkun[$a['nm_rek']] = ($totalAkun[$a['nm_rek']] ?? 0) + $nilai;
?>
<tr>
<td><?= $a['nm_rek'] ?></td>
<td class="right"><?= number_format($nilai,0,',','.') ?></td>
</tr>
<?php endif; endforeach; ?>
</table>
</td>
</tr>
<?php endwhile; ?>
</table>

<br>

<h3 style="color:#0a8f3c">RINGKASAN PER AKUN</h3>
<table width="40%">
<?php foreach($totalAkun as $nm=>$ttl): ?>
<tr>
<td><?= $nm ?></td>
<td class="right"><?= number_format($ttl,0,',','.') ?></td>
</tr>
<?php endforeach; ?>
<tr>
<th>GRAND TOTAL</th>
<th class="right"><?= number_format($grand,0,',','.') ?></th>
</tr>
</table>

<div style="
    text-align:center;
    font-size:12px;
    color:#555;
    margin:15px 0;
    border-top:1px solid #ddd;
    padding-top:8px
">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. All Rights Reserved.
</div>

</body>
</html>
