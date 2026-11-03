<?php
/* Dashboard Monitoring Rajal & Tindakan Operasi - RS ASSALAM
    Developer: Vidi Medika
    Copyright: © Vidi Medika Konsultan IT | Smart IT for Health
*/

// 1. KONEKSI DATABASE
$host = "192.168.5.52:3382"; $user = "clientkhanza"; $pass = "Client1453"; $db = "db-sim";
$koneksi = mysqli_connect($host, $user, $pass, $db);
if (!$koneksi) { die("Koneksi gagal : " . mysqli_connect_error()); }
/* ================= FILTER & SANITASI ================= */
$tgl_awal   = isset($_GET['tgl_awal']) ? mysqli_real_escape_string($koneksi, $_GET['tgl_awal']) : date('Y-m-01');
$tgl_akhir  = isset($_GET['tgl_akhir']) ? mysqli_real_escape_string($koneksi, $_GET['tgl_akhir']) : date('Y-m-d');
$cari       = isset($_GET['cari']) ? mysqli_real_escape_string($koneksi, $_GET['cari']) : "";
$poli       = isset($_GET['poli']) ? mysqli_real_escape_string($koneksi, $_GET['poli']) : "";
$stts_p     = isset($_GET['stts_p']) ? mysqli_real_escape_string($koneksi, $_GET['stts_p']) : "";
$cara_bayar = isset($_GET['cara_bayar']) ? mysqli_real_escape_string($koneksi, $_GET['cara_bayar']) : "";
$perujuk    = isset($_GET['perujuk']) ? mysqli_real_escape_string($koneksi, $_GET['perujuk']) : ""; // Filter Perujuk

function getCachedOptions($sql, $field) {
    global $koneksi;
    $res = mysqli_query($koneksi, $sql);
    $data = [];
    if($res){
        while($row = mysqli_fetch_assoc($res)) $data[] = $row[$field];
    }
    return $data;
}

$opt_poli    = getCachedOptions("SELECT nm_poli FROM poliklinik WHERE status='1' ORDER BY nm_poli", "nm_poli");
$opt_penjab  = getCachedOptions("SELECT png_jawab FROM penjab WHERE status='1' ORDER BY png_jawab", "png_jawab");
$opt_perujuk = getCachedOptions("SELECT DISTINCT nmppkrujukan FROM bridging_sep WHERE nmppkrujukan <> '' ORDER BY nmppkrujukan", "nmppkrujukan");
$opt_status  = ['Belum', 'Sudah', 'Batal', 'Berkas Diterima', 'Dirujuk', 'Meninggal', 'Dirawat'];

/* ================= OPTIMIZED QUERY ENGINE ================= */
$where = "WHERE r.tgl_registrasi BETWEEN '$tgl_awal' AND '$tgl_akhir'";
if($cari)       $where .= " AND (p.nm_pasien LIKE '%$cari%' OR p.no_rkm_medis = '$cari' OR s.no_sep = '$cari')";
if($poli)       $where .= " AND po.nm_poli = '$poli'";
if($stts_p)     $where .= " AND r.stts = '$stts_p'";
if($cara_bayar) $where .= " AND pj.png_jawab = '$cara_bayar'";
if($perujuk)    $where .= " AND s.nmppkrujukan = '$perujuk'"; // Kondisi Filter Perujuk

$sql = "SELECT 
            r.tgl_registrasi, r.no_rawat, r.stts, 
            p.no_rkm_medis, p.nm_pasien, 
            po.nm_poli, d.nm_dokter, pj.png_jawab,
            s.no_sep, s.nmppkrujukan, s.klsrawat,
            GROUP_CONCAT(DISTINCT lo.diagnosa_preop SEPARATOR ' | ') as pre_op,
            GROUP_CONCAT(DISTINCT lo.diagnosa_postop SEPARATOR ' | ') as post_op,
            GROUP_CONCAT(DISTINCT d2.nm_dokter SEPARATOR ', ') as dpjp_nama
        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON r.kd_dokter = d.kd_dokter
        INNER JOIN poliklinik po ON r.kd_poli = po.kd_poli
        INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
        LEFT JOIN bridging_sep s ON r.no_rawat = s.no_rawat
        LEFT JOIN laporan_operasi lo ON r.no_rawat = lo.no_rawat
        LEFT JOIN dpjp_ranap dr ON r.no_rawat = dr.no_rawat
        LEFT JOIN dokter d2 ON dr.kd_dokter = d2.kd_dokter
        $where
        GROUP BY r.no_rawat
        ORDER BY r.tgl_registrasi DESC, r.jam_reg DESC";

$res = mysqli_query($koneksi, $sql);
$total_data = ($res) ? mysqli_num_rows($res) : 0;

function getStatusBadge($stts) {
    $map = ['Belum' => '#bdc3c7', 'Sudah' => '#27ae60', 'Batal' => '#e74c3c', 'Dirujuk' => '#f39c12', 'Dirawat' => '#2980b9'];
    $color = $map[$stts] ?? '#34495e';
    return "<span class='badge' style='background:$color; color:#fff;'>$stts</span>";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring RS ASSALAM</title>
    <style>
        :root { --assalam-green: #008148; --assalam-red: #ED1C24; --assalam-light: #f1f8f4; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f4f4; margin: 0; padding: 15px; font-size: 11px; color: #333; }
        .container { background: #fff; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); border-top: 6px solid var(--assalam-green); padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        .title-text h1 { color: var(--assalam-green); margin: 0; font-size: 24px; text-transform: uppercase; }
        .counter-box { background: var(--assalam-green); color: white; padding: 10px 25px; border-radius: 5px; text-align: center; }
        .filter-section { background: var(--assalam-light); padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 8px; border-left: 4px solid var(--assalam-red); }
        .table-wrap { overflow-x: auto; max-height: 600px; border: 1px solid #eee; }
        table { width: 100%; border-collapse: collapse; min-width: 2500px; }
        th { background: var(--assalam-green); color: white; padding: 12px 10px; position: sticky; top: 0; z-index: 5; text-align: left; border-right: 1px solid #00703e; white-space: nowrap; }
        td { padding: 10px; border-bottom: 1px solid #eee; border-right: 1px solid #f9f9f9; vertical-align: top; }
        .badge { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; }
        .sep-code { color: #0056b3; font-weight: bold; font-family: monospace; }
        tr:hover { background: #f1f1f1; }
        select, input { padding: 6px; border: 1px solid #ccc; border-radius: 4px; max-width: 200px; }
    </style>
</head>
<body>

<div class="container" id="reportArea">
    <div class="header">
        <div class="title-text">
            <h1>Rumah Sakit Assalam</h1>
            <h1 style="color:var(--assalam-red); font-weight:bold;">Monitoring Kunjungan & Laporan Operasi</h1>
        </div>
        <div class="counter-box">
            <small>TOTAL DATA</small>
            <div style="font-size: 26px; font-weight: bold;"><?= number_format($total_data) ?></div>
        </div>
    </div>

    <form method="GET">
        <div class="filter-section">
            <input type="date" name="tgl_awal" value="<?= $tgl_awal ?>">
            <input type="date" name="tgl_akhir" value="<?= $tgl_akhir ?>">
            <input type="text" name="cari" placeholder="Cari Nama/RM/SEP..." value="<?= $cari ?>">
            
            <select name="cara_bayar">
                <option value="">- Semua Cara Bayar -</option>
                <?php foreach($opt_penjab as $pj) echo "<option ".($pj==$cara_bayar?'selected':'').">$pj</option>"; ?>
            </select>

            <select name="perujuk">
                <option value=""> Semua Perujuk </option>
                <?php foreach($opt_perujuk as $pr) echo "<option ".($pr==$perujuk?'selected':'').">$pr</option>"; ?>
            </select>

            <select name="poli">
                <option value=""> Poliklinik </option>
                <?php foreach($opt_poli as $p) echo "<option ".($p==$poli?'selected':'').">$p</option>"; ?>
            </select>

            <select name="stts_p">
                <option value=""> Status </option>
                <?php foreach($opt_status as $s) echo "<option ".($s==$stts_p?'selected':'').">$s</option>"; ?>
            </select>

            <button type="submit" style="background:var(--assalam-green); color:white; border:none; padding:8px 15px; cursor:pointer; font-weight:bold; border-radius:4px;">TAMPILKAN</button>
            <button type="button" onclick="exportToExcel()" style="background:var(--assalam-red); color:white; border:none; padding:8px 15px; cursor:pointer; font-weight:bold; border-radius:4px;">EXCEL</button>
        </div>
    </form>

    <div class="table-wrap">
        <table id="mainTable">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tgl. Registrasi</th>
                    <th>No. RM</th>
                    <th>Nama Pasien</th>
                    <th>Poliklinik</th>
                    <th>Dokter</th>
                    <th>Cara Bayar</th>
                    <th>No. SEP BPJS</th>
                    <th>Perujuk</th>
                    <th>Kelas</th>
                    <th>Diagnosa Pre-Op</th>
                    <th>Diagnosa Post-Op</th>
                    <th>DPJP Ranap</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                if($res) {
                    while($r = mysqli_fetch_assoc($res)) {
                        echo "<tr>
                                <td>".$i++."</td>
                                <td>".$r['tgl_registrasi']."</td>
                                <td><b>".$r['no_rkm_medis']."</b></td>
                                <td>".strtoupper($r['nm_pasien'])."</td>
                                <td>".$r['nm_poli']."</td>
                                <td>".$r['nm_dokter']."</td>
                                <td><small>".$r['png_jawab']."</small></td>
                                <td class='sep-code'>".($r['no_sep'] ?: '-')."</td>
                                <td><small>".($r['nmppkrujukan'] ?: '-')."</small></td>
                                <td><center>".($r['klsrawat'] ?: '-')."</center></td>
                                <td><small>".($r['pre_op'] ?: '-')."</small></td>
                                <td><small>".($r['post_op'] ?: '-')."</small></td>
                                <td style='font-weight:bold; color:#2c3e50;'>".($r['dpjp_nama'] ?: '-')."</td>
                                <td>".getStatusBadge($r['stts'])."</td>
                              </tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function exportToExcel() {
    let table = document.getElementById("mainTable");
    let html = table.outerHTML;

    let style = `<style>
        table { border-collapse: collapse; width: 100%; font-family: sans-serif; }
        th { background-color: #008148; color: #ffffff; border: 1px solid #000000; padding: 5px; }
        td { border: 1px solid #000000; padding: 5px; vertical-align: top; }
    </style>`;

    let template = `<html xmlns:o="urn:schemas-microsoft-com:office:office" 
                          xmlns:x="urn:schemas-microsoft-com:office:excel" 
                          xmlns="http://www.w3.org/TR/REC-html40">
                    <head><meta charset="utf-8">${style}</head>
                    <body>
                        <h3>LAPORAN MONITORING RS ASSALAM</h3>
                        <p>Periode: <?= $tgl_awal ?> s/d <?= $tgl_akhir ?></p>
                        ${html}
                    </body></html>`;

    let blob = new Blob([template], { type: "application/vnd.ms-excel" });
    let a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "Laporan_Marketing_Assalam_<?= date('Ymd_His') ?>.xls";
    a.click();
}
</script>

<p style="text-align:center; color:#888; margin-top:30px; padding-bottom:30px;">
    &copy; Vidi Medika Konsultan IT | Smart IT for Health
</p>
</body>
</html>
