<?php
/* RS ASSALAM - Dashboard Monitoring Biaya (Highly Optimized)
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
// 2. TANGKAP FILTER
$tgl_masuk_awal   = $_GET['tgl_masuk_awal']   ?? '';
$tgl_masuk_akhir  = $_GET['tgl_masuk_akhir']  ?? '';
$tgl_keluar_awal  = $_GET['tgl_keluar_awal']  ?? '';
$tgl_keluar_akhir = $_GET['tgl_keluar_akhir'] ?? '';
$status_pasien    = $_GET['status_pasien']    ?? 'semua';
$kd_dokter        = $_GET['kd_dokter']        ?? '';
$keyword          = $_GET['keyword']          ?? '';
$action           = $_GET['action']           ?? '';

// 3. LOGIKA WHERE CLAUSE
$where = ["r.status_lanjut='Ranap'", "pj.png_jawab LIKE '%BPJS%'"];
$params = [];
$types = "";

if (!empty($tgl_masuk_awal) && !empty($tgl_masuk_akhir)) {
    $where[] = "ki.tgl_masuk BETWEEN ? AND ?";
    $params[] = $tgl_masuk_awal; $params[] = $tgl_masuk_akhir;
    $types .= "ss";
}
if (!empty($tgl_keluar_awal) && !empty($tgl_keluar_akhir)) {
    $where[] = "ki.tgl_keluar BETWEEN ? AND ?";
    $params[] = $tgl_keluar_awal; $params[] = $tgl_keluar_akhir;
    $types .= "ss";
}
if ($status_pasien == 'ranap') {
    $where[] = "ki.tgl_keluar = '0000-00-00'";
} elseif ($status_pasien == 'pulang') {
    $where[] = "ki.tgl_keluar != '0000-00-00'";
}
if (!empty($kd_dokter)) {
    $where[] = "r.no_rawat IN (SELECT no_rawat FROM dpjp_ranap WHERE kd_dokter = ?)";
    $params[] = $kd_dokter;
    $types .= "s";
}
if (!empty($keyword)) {
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rkm_medis LIKE ? OR r.no_rawat LIKE ? OR bs.no_sep LIKE ?)";
    $lk = "%$keyword%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "ssss";
}

$where_sql = implode(" AND ", $where);

// 4. QUERY UTAMA (OPTIMALISASI: LAMA DIAMBIL DARI KOLOM LAMA)
$sql = "SELECT 
            r.no_rawat, r.no_rkm_medis, p.nm_pasien, bs.no_sep, r.biaya_reg,
            pj.png_jawab, b.nm_bangsal, ki.tgl_masuk, ki.jam_masuk, ki.tgl_keluar,
            ki.diagnosa_awal, 
            dpjp.dokter_dpjp,
            ki.lama as lama_hari,
            COALESCE(kamar.total, 0) as total_biaya_kamar,
            COALESCE(obat.total, 0) as total_biaya_obat,
            COALESCE(lab.total, 0) as total_biaya_lab,
            COALESCE(rad.total, 0) as total_biaya_radiologi,
            COALESCE(ralan.total, 0) as total_tindakan_ralan,
            COALESCE(ranap.total, 0) as total_tindakan_ranap,
            COALESCE(ops.total, 0) as total_biaya_operasi
        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
        INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
        INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
        INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
        LEFT JOIN bridging_sep bs ON r.no_rawat = bs.no_rawat
        LEFT JOIN (
            SELECT dr.no_rawat, GROUP_CONCAT(d.nm_dokter SEPARATOR '<br>') as dokter_dpjp 
            FROM dpjp_ranap dr JOIN dokter d ON dr.kd_dokter = d.kd_dokter GROUP BY dr.no_rawat
        ) dpjp ON r.no_rawat = dpjp.no_rawat
        LEFT JOIN (SELECT no_rawat, SUM(ttl_biaya) as total FROM kamar_inap GROUP BY no_rawat) kamar ON r.no_rawat = kamar.no_rawat
        LEFT JOIN (SELECT no_rawat, SUM(total) as total FROM detail_pemberian_obat GROUP BY no_rawat) obat ON r.no_rawat = obat.no_rawat
        LEFT JOIN (SELECT no_rawat, SUM(biaya) as total FROM periksa_lab GROUP BY no_rawat) lab ON r.no_rawat = lab.no_rawat
        LEFT JOIN (SELECT no_rawat, SUM(biaya) as total FROM periksa_radiologi GROUP BY no_rawat) rad ON r.no_rawat = rad.no_rawat
        LEFT JOIN (SELECT no_rawat, SUM(biaya_rawat) as total FROM rawat_jl_dr GROUP BY no_rawat) ralan ON r.no_rawat = ralan.no_rawat
        LEFT JOIN (SELECT no_rawat, SUM(biaya_rawat) as total FROM rawat_inap_dr GROUP BY no_rawat) ranap ON r.no_rawat = ranap.no_rawat
        LEFT JOIN (
            SELECT no_rawat, SUM(biayaoperator1 + biayaoperator2 + biayaoperator3 + biayaasisten_operator1 + biayaasisten_operator2 + biayaasisten_operator3 + biayainstrumen + biayadokter_anak + biayaperawaat_resusitas + biayadokter_anestesi + biayaasisten_anestesi + biayaasisten_anestesi2 + biayabidan + biayabidan2 + biayabidan3 + biayaperawat_luar + biayaalat + biayasewaok + akomodasi + bagian_rs + biaya_omloop + biaya_omloop2 + biaya_omloop3 + biaya_omloop4 + biaya_omloop5 + biayasarpras + biaya_dokter_pjanak + biaya_dokter_umum) as total 
            FROM operasi GROUP BY no_rawat
        ) ops ON r.no_rawat = ops.no_rawat
        WHERE $where_sql
        GROUP BY r.no_rawat, ki.tgl_masuk, ki.jam_masuk
        ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$list_dokter = $conn->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter ASC");

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=KENDALI MUTU KENDALI BIAYA_RS_ASSALAM.xls");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring Biling RS KARTIKA HUSADA JATIASIH</title>
    <?php if ($action != 'excel'): ?>
    <style>
        :root { --main: #2e7d32; --accent: #e65100; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f0; margin: 0; padding: 10px; font-size: 10px; color: #333; }
        .box { background: white; padding: 15px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-top: 6px solid var(--main); }
        .filter-box { background: #f1f8e9; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; justify-content: center; border: 1px solid #dcedc8; }
        .filter-box div { display: flex; flex-direction: column; }
        .filter-box label { font-weight: bold; margin-bottom: 4px; color: #333; }
        .btn-cari { background: var(--main); color: white; border: none; padding: 7px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; height: 32px; transition: 0.3s; }
        .btn-cari:hover { background: #1b5e20; }
        .btn-excel { background: #1b5e20; color: white; padding: 7px 15px; border-radius: 4px; text-decoration: none; font-weight: bold; height: 18px; line-height: 18px; }
        table { width: 100%; border-collapse: collapse; background: white; white-space: nowrap; }
        thead th { background: var(--main); color: white; padding: 8px 4px; border: 1px solid #1b5e20; position: sticky; top: 0; z-index: 10; }
        tbody td { border: 1px solid #eee; padding: 5px 4px; }
        .text-right { text-align: right; font-weight: bold; }
        .scroll { max-height: 65vh; overflow: auto; border: 1px solid #ddd; border-radius: 4px; }
        .bg-yellow { background-color: #ffff00 !important; color: #000 !important; }
        .bg-maroon { background-color: #800000 !important; color: #ffffff !important; }
        .billing-normal { background: #fff3e0; color: var(--accent); }
        .sep-box { color: #0d47a1; font-weight: bold; font-family: monospace; }
        .jam-masuk { color: #777; font-size: 9px; }
        tr:hover { background-color: #f5f5f5; }
    </style>
    <?php endif; ?>
</head>
<body>

<div class="box">
    <?php if ($action != 'excel'): ?>
    <h2 style="text-align:center; color:var(--main); margin:0 0 5px 0;">RS ASSALAM</h2>
<h2 style="text-align:center; color:var(--main); margin:0 0 5px 0;">DASHBOARD MONITORING KENDALI MUTU KENDALI BIAYA</h2>
   
    
    <form class="filter-box" method="GET">
        <div><label>Tgl Masuk</label><span><input type="date" name="tgl_masuk_awal" value="<?=$tgl_masuk_awal?>"> s/d <input type="date" name="tgl_masuk_akhir" value="<?=$tgl_masuk_akhir?>"></span></div>
        <div><label>Tgl Keluar</label><span><input type="date" name="tgl_keluar_awal" value="<?=$tgl_keluar_awal?>"> s/d <input type="date" name="tgl_keluar_akhir" value="<?=$tgl_keluar_akhir?>"></span></div>
        <div>
            <label>Dokter DPJP</label>
            <select name="kd_dokter" style="width:140px; padding:5px;">
                <option value="">-- Semua Dokter --</option>
                <?php while($dr = $list_dokter->fetch_assoc()): ?>
                    <option value="<?=$dr['kd_dokter']?>" <?=$kd_dokter == $dr['kd_dokter'] ? 'selected' : ''?>><?=$dr['nm_dokter']?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="status_pasien" style="padding:5px;">
                <option value="semua" <?= $status_pasien == 'semua' ? 'selected' : '' ?>>Semua</option>
                <option value="ranap" <?= $status_pasien == 'ranap' ? 'selected' : '' ?>>Masih Dirawat</option>
                <option value="pulang" <?= $status_pasien == 'pulang' ? 'selected' : '' ?>>Sudah Pulang</option>
            </select>
        </div>
        <div><label>Keyword</label><input type="text" name="keyword" value="<?=$keyword?>" placeholder="Nama/RM/SEP" style="padding:5px;"></div>
        <button type="submit" class="btn-cari">PROSES</button>
        <a href="?action=excel&tgl_masuk_awal=<?=$tgl_masuk_awal?>&tgl_masuk_akhir=<?=$tgl_masuk_akhir?>&tgl_keluar_awal=<?=$tgl_keluar_awal?>&tgl_keluar_akhir=<?=$tgl_keluar_akhir?>&status_pasien=<?=$status_pasien?>&kd_dokter=<?=$kd_dokter?>&keyword=<?=$keyword?>" class="btn-excel">EXCEL</a>
    </form>
    <?php endif; ?>

    <div class="scroll">
        <table border="<?= ($action == 'excel') ? '1' : '0' ?>">
            <thead>
                <tr>
                    <th>No</th>
                    <th>No. Rawat</th>
                    <th>No. RM</th>
                    <th>No. SEP</th>
                    <th>Nama Pasien</th>
                    <th>Diagnosa Awal</th>
                    <th>DPJP</th>
                    <th>Bangsal /Kamar</th>
                    <th>Tgl Masuk</th>
                    <th> Jam Masuk</th> <th>Tgl Keluar</th>
                    <th>Lama Hari Perawatan</th>
                    <th>Biaya Regis</th>
                    <th>Biaya Kamar</th>
                    <th>Biaya Obat</th>
                    <th>Biaya Lab</th>
                    <th>Biaya Radiologi</th>
                    <th>Biaya Tindakan Ralan</th>
                    <th>Biaya Tindakan Ranap</th>
                    <th>Biaya Operasi</th>
                    <th style="background:var(--accent); color:white">TOTAL BILING SEMENTARA</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $n = 1; $totals = array_fill(0, 9, 0);
                while ($r = $result->fetch_assoc()):
                    $biling = $r['biaya_reg'] + $r['total_biaya_kamar'] + $r['total_biaya_obat'] + $r['total_biaya_lab'] + $r['total_biaya_radiologi'] + $r['total_tindakan_ralan'] + $r['total_tindakan_ranap'] + $r['total_biaya_operasi'];
                    
                    // Akumulasi total untuk footer
                    $totals[0] += $r['biaya_reg']; 
                    $totals[1] += $r['total_biaya_kamar']; 
                    $totals[2] += $r['total_biaya_obat']; 
                    $totals[3] += $r['total_biaya_lab']; 
                    $totals[4] += $r['total_biaya_radiologi']; 
                    $totals[5] += $r['total_tindakan_ralan']; 
                    $totals[6] += $r['total_tindakan_ranap']; 
                    $totals[7] += $r['total_biaya_operasi']; 
                    $totals[8] += $biling;

                    $tr_class = ''; $bill_class = 'billing-normal';
                    // Kondisi warna baris berdasarkan nilai kolom lama
                    if ($r['lama_hari'] == 4) { $tr_class = 'class="bg-yellow"'; $bill_class = ''; }
                    elseif ($r['lama_hari'] >= 5) { $tr_class = 'class="bg-maroon"'; $bill_class = ''; }
                ?>
                <tr align="center" <?=$tr_class?>>
                    <td><?=$n++?></td>
                    <td>'<?=$r['no_rawat']?>'</td>
                    <td><?=$r['no_rkm_medis']?></td>
                    <td class="sep-box"><?=$r['no_sep'] ?: '-'?></td>
                    <td align="left"><?=$r['nm_pasien']?></td>
                    <td align="left" style="font-size:8px; white-space:normal; min-width:130px; color:#c62828;"><b><?=$r['diagnosa_awal'] ?: '-'?></b></td>
                    <td align="left" style="font-size:8px; line-height:1.1;"><?=$r['dokter_dpjp']?></td>
                    <td align="left"><small><?=$r['nm_bangsal']?></small></td>
                    <td><?=$r['tgl_masuk']?></td>
                    <td class="jam-masuk"><?=$r['jam_masuk']?></td> 
                    <td><?= ($r['tgl_keluar'] == '0000-00-00') ? '<b style="color:#2e7d32">RAWAT</b>' : $r['tgl_keluar'] ?></td>
                    <td><b><?=$r['lama_hari']?> Hr</b></td>
                    <td class="text-right"><?=number_format($r['biaya_reg'],0,',','.')?></td>
                    <td class="text-right"><?=number_format($r['total_biaya_kamar'],0,',','.')?></td>
                    <td class="text-right"><?=number_format($r['total_biaya_obat'],0,',','.')?></td>
                    <td class="text-right"><?=number_format($r['total_biaya_lab'],0,',','.')?></td>
                    <td class="text-right"><?=number_format($r['total_biaya_radiologi'],0,',','.')?></td>
                    <td class="text-right"><?=number_format($r['total_tindakan_ralan'],0,',','.')?></td>
                    <td class="text-right"><?=number_format($r['total_tindakan_ranap'],0,',','.')?></td>
                    <td class="text-right"><?=number_format($r['total_biaya_operasi'],0,',','.')?></td>
                    <td class="text-right <?=$bill_class?>"><?=number_format($biling,0,',','.')?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f5f5f5;">
                    <th colspan="12" align="right" style="padding:10px;">TOTAL KESELURUHAN (Rp):</th>
                    <?php foreach($totals as $key => $val): ?>
                        <th class="text-right" <?= $key == 8 ? 'style="background:var(--accent); color:white"' : '' ?>><?=number_format($val,0,',','.')?></th>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<div style="text-align:center; font-size:11px; color:#888; margin-top:15px;">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b> - Sistem Informasi Kendali Biaya v1.0
</div>
</body>
</html>
