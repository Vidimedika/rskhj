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
$tgl_awal   = $_GET['tgl_awal']   ?? date('Y-m-01');
$tgl_akhir  = $_GET['tgl_akhir']  ?? date('Y-m-d');
$kd_poli    = $_GET['kd_poli']    ?? ''; 
$kd_dokter  = $_GET['kd_dokter']  ?? '';
$search     = $_GET['search']     ?? ''; 
$action     = $_GET['action']     ?? '';

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

if ($search) { 
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rawat LIKE ? OR r.no_rkm_medis LIKE ? OR bs.no_sep LIKE ? OR bs.no_kartu LIKE ? OR ni.no_nota LIKE ?)"; 
    $lk = "%$search%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "ssssss"; 
}

$where_sql = implode(" AND ", $where);

// OPTIMASI QUERY: Mencegah duplikasi dengan GROUP BY r.no_rawat, EXISTS(), dan Subquery Terindeks
$sql = "SELECT 
            r.no_rawat, r.no_rkm_medis, p.nm_pasien, pol.nm_poli,
            IFNULL(bs.no_sep, '-') as no_sep, 
            IFNULL(bs.no_kartu, '-') as no_kartu,
            IFNULL(bs.asal_rujukan, '-') as asal_rujukan, 
            pj.png_jawab, 
            IFNULL(bs.klsrawat, '-') as kelas, 
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
        LEFT JOIN (
            SELECT no_rawat, MAX(no_sep) as no_sep, MAX(no_kartu) as no_kartu, MAX(asal_rujukan) as asal_rujukan, MAX(klsrawat) as klsrawat
            FROM bridging_sep 
            GROUP BY no_rawat
        ) bs ON r.no_rawat = bs.no_rawat
        LEFT JOIN nota_jalan ni ON r.no_rawat = ni.no_rawat
        WHERE $where_sql
        GROUP BY r.no_rawat
        ORDER BY r.tgl_registrasi DESC, r.jam_reg DESC";

$stmt = $conn->prepare($sql);
if(!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$data_pasien = [];
$stats = ['total' => 0, 'lengkap' => 0, 'belum' => 0];

while ($row = $result->fetch_assoc()) {
    $isIGD = (stripos($row['nm_poli'], 'IGD') !== false);
    $skor = $isIGD ? ($row['f_medis_igd'] + $row['f_triase'] + $row['f_resume']) : $row['f_resume'];
    $row['persen'] = round(($skor / ($isIGD ? 3 : 1)) * 100);
    $stats['total']++;
    ($row['persen'] >= 100) ? $stats['lengkap']++ : $stats['belum']++;
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
                    <label class="label-filter">PENCARIAN GLOBAL</label>
                    <input type="text" name="search" value="<?=$search?>" placeholder="Nama/Nota/RM/Rawat/SEP/Kartu" class="search-input">
                </div>
                <button type="submit" style="background:#0a7c3b; color:white; padding: 7px 15px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">CARI DATA</button>
                <a href="?action=excel&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>&kd_poli=<?=$kd_poli?>&kd_dokter=<?=$kd_dokter?>&search=<?=$search?>" style="background:#2e7d32; color:white; padding: 7px 15px; text-decoration:none; border-radius:4px; font-weight:bold;">EXPORT EXCEL</a>
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
                <?php $no=1; foreach($data_pasien as $r): ?>
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
</div>
<div style="text-align:center; font-size:12px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. All Rights Reserved. Smart IT For Health.
</div>
</body>
</html>
