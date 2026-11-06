<?php
/* RS KARTIKA HUSADA JATIASIH - Dashboard Monitoring Kunjungan IGD
   Update: Jan 2026
   Developer: Vidi Medika Konsultan IT
*/
session_start();

/* ================= KONEKSI DATABASE ================= */
$host = "192.168.10.29";
$user = "root";
$pass = "1nternet";
$db   = "rskhj";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

/* ================= LOGIKA EXPORT EXCEL ================= */
$isExport = isset($_GET['action']) && $_GET['action'] == 'export';
if ($isExport) {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_IGD_".date('YmdHis').".xls");
}

/* ================= AMBIL DATA MASTER ================= */
$list_dokter = $conn->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter ASC");
$list_penjab = $conn->query("SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab ASC");

/* ================= FILTER DATA ================= */
$tgl_awal    = $_GET['tgl_awal'] ?? date('Y-m-d'); 
$tgl_akhir   = $_GET['tgl_akhir'] ?? date('Y-m-d');
$dokter      = $_GET['dokter'] ?? "";
$cara_bayar  = $_GET['cara_bayar'] ?? "";
$status_pasien = $_GET['status_pasien'] ?? ""; 
$per_halaman = 15;
$halaman = max(1, (int)($_GET['halaman'] ?? 1));
$offset = ($halaman - 1) * $per_halaman;

/* ================= QUERY UTAMA (OPTIMIZED) ================= */
$where = " AND (po.nm_poli LIKE '%IGD%' OR po.kd_poli IN ('IGDK','IGD'))"; 
$params = [$tgl_awal, $tgl_akhir];
$types  = "ss";

if ($dokter != "") { $where .= " AND d.kd_dokter=?"; $params[] = $dokter; $types .= "s"; }
if ($cara_bayar != "") { $where .= " AND pj.kd_pj=?"; $params[] = $cara_bayar; $types .= "s"; }
if ($status_pasien != "") { $where .= " AND r.status_lanjut=?"; $params[] = $status_pasien; $types .= "s"; }

$sql = "
SELECT 
    r.no_rawat, r.no_rkm_medis, p.nm_pasien, d.nm_dokter, po.nm_poli, pj.png_jawab,
    r.status_lanjut, r.jam_reg, r.tgl_registrasi, s.no_sep, 
    MAX(pmi.diagnosis) as diagnosa,
    COUNT(DISTINCT pmi.no_rawat) as ada_igd,
    COUNT(DISTINCT dti.no_rawat) as ada_triase,
    COUNT(DISTINCT rp.no_rawat) as ada_resume,
    COUNT(DISTINCT pl.no_rawat) as ada_lab,
    COUNT(DISTINCT prad.no_rawat) as ada_rad,
    COUNT(DISTINCT bdp.no_rawat) as ada_berkas,
    COUNT(DISTINCT pran.no_rawat) as ada_spr
FROM reg_periksa r
JOIN pasien p ON r.no_rkm_medis=p.no_rkm_medis
JOIN dokter d ON r.kd_dokter=d.kd_dokter
JOIN poliklinik po ON r.kd_poli=po.kd_poli
JOIN penjab pj ON r.kd_pj=pj.kd_pj
LEFT JOIN bridging_sep s ON r.no_rawat=s.no_rawat
LEFT JOIN penilaian_medis_igd pmi ON r.no_rawat=pmi.no_rawat
LEFT JOIN data_triase_igd dti ON r.no_rawat=dti.no_rawat
LEFT JOIN resume_pasien rp ON r.no_rawat=rp.no_rawat
LEFT JOIN periksa_lab pl ON r.no_rawat=pl.no_rawat
LEFT JOIN periksa_radiologi prad ON r.no_rawat=prad.no_rawat
LEFT JOIN berkas_digital_perawatan bdp ON r.no_rawat=bdp.no_rawat
LEFT JOIN permintaan_ranap pran ON r.no_rawat=pran.no_rawat
WHERE r.tgl_registrasi BETWEEN ? AND ? 
    $where 
GROUP BY r.no_rawat
ORDER BY r.tgl_registrasi DESC, r.jam_reg DESC"; 

// Ambil satu baris ekstra agar tombol Next hanya muncul jika masih ada data.
if (!$isExport) {
    $sql .= " LIMIT " . ($per_halaman + 1) . " OFFSET " . $offset;
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Error SQL Prepare: " . $conn->error);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* Rekap kelengkapan berkas per dokter untuk seluruh hasil filter */
$sql_rekap_dokter = "
SELECT d.kd_dokter, d.nm_dokter,
       COUNT(DISTINCT r.no_rawat) AS total_kunjungan,
       COUNT(DISTINCT CASE WHEN dti.no_rawat IS NOT NULL THEN r.no_rawat END) AS triase_ada,
       COUNT(DISTINCT CASE WHEN pmi.no_rawat IS NOT NULL THEN r.no_rawat END) AS medis_ada,
       COUNT(DISTINCT CASE WHEN rp.no_rawat IS NOT NULL THEN r.no_rawat END) AS resume_ada,
       COUNT(DISTINCT CASE WHEN pran.no_rawat IS NOT NULL THEN r.no_rawat END) AS spr_ada
FROM reg_periksa r
JOIN dokter d ON r.kd_dokter=d.kd_dokter
JOIN poliklinik po ON r.kd_poli=po.kd_poli
JOIN penjab pj ON r.kd_pj=pj.kd_pj
LEFT JOIN data_triase_igd dti ON r.no_rawat=dti.no_rawat
LEFT JOIN penilaian_medis_igd pmi ON r.no_rawat=pmi.no_rawat
LEFT JOIN resume_pasien rp ON r.no_rawat=rp.no_rawat
LEFT JOIN permintaan_ranap pran ON r.no_rawat=pran.no_rawat
WHERE r.tgl_registrasi BETWEEN ? AND ? $where
GROUP BY d.kd_dokter, d.nm_dokter
ORDER BY d.nm_dokter";
$stmt_rekap_dokter = $conn->prepare($sql_rekap_dokter);
$stmt_rekap_dokter->bind_param($types, ...$params);
$stmt_rekap_dokter->execute();
$rekap_dokter = $stmt_rekap_dokter->get_result();

$komponen = [
    "SEP" => "no_sep", "Medis IGD" => "ada_igd", "Triase" => "ada_triase",
    "Resume" => "ada_resume", "SPR/Ranap" => "ada_spr", "Lab" => "ada_lab",
    "Rad" => "ada_rad", "Berkas Digital" => "ada_berkas"
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <?php if (!$isExport): ?>
    <meta http-equiv="refresh" content="60"> 
    <?php endif; ?>
    <title>Monitoring IGD - RS KARTIKA HUSADA JATIASIH</title>
    <style>
        :root {
            --hijau-rs: #0a7c3b;
            --hijau-muda: #e8f5e9;
            --merah-rs: #d32f2f;
            --merah-muda: #ffebee;
        }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; background: #f4f6f8; padding: 15px; margin: 0; }
        h2 { text-align: center; color: var(--hijau-rs); margin: 2px; text-transform: uppercase; }
        .filter-box { background: white; padding: 15px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .btn-filter { background: var(--hijau-rs); color: white; border: none; padding: 6px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-excel { background: #1d6f42; color: white; border: none; padding: 6px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-reset { background: #666; color: white; border: none; padding: 6px 15px; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .scroll { overflow: auto; background: white; border: 1px solid #ccc; max-height: 650px; border-radius: 8px; }
        table { border-collapse: collapse; width: 100%; white-space: nowrap; }
        th { background: var(--hijau-rs); color: white; padding: 10px; border: 1px solid #08632f; }
        <?php if (!$isExport): ?>
        th { position: sticky; top: 0; z-index: 10; }
        <?php endif; ?>
        td { border: 1px solid #eee; padding: 8px 6px; text-align: center; }
        /* Teks diagnosa diset rata kiri di CSS juga untuk opsi tambahan */
        td.txt-left { text-align: left !important; }
        .lengkap { background-color: var(--hijau-muda) !important; color: #1b5e20 !important; font-weight: bold; }
        .kurang { background-color: var(--merah-muda) !important; color: #b71c1c !important; font-weight: bold; }
        .na-kolom { background-color: #f5f5f5; color: #aaa; }
        .stts-ranap { background: #673ab7; color: white; padding: 2px 5px; border-radius: 3px; font-weight: bold; }
        .stts-ralan { background: #0288d1; color: white; padding: 2px 5px; border-radius: 3px; font-weight: bold; }
        .footer-row { background: #eee; font-weight: bold; color: #333; }
    </style>
</head>
<body>

<?php if (!$isExport): ?>
<h2>RS KARTIKA HUSADA JATIASIH</h2>
<h2 style="font-size:14px; margin-bottom:15px;">Monitoring Kunjungan IGD</h2>

<form method="get" class="filter-box">
    Periode: <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> s/d 
    <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
    
    Dokter: 
    <select name="dokter">
        <option value=""> Semua Dokter </option>
        <?php while($d = $list_dokter->fetch_assoc()) echo "<option value='{$d['kd_dokter']}' ".($dokter==$d['kd_dokter']?'selected':'').">{$d['nm_dokter']}</option>"; ?>
    </select>

    Cara Bayar:
    <select name="cara_bayar">
        <option value=""> Semua Cara Bayar </option>
        <?php while($pj = $list_penjab->fetch_assoc()) echo "<option value='{$pj['kd_pj']}' ".($cara_bayar==$pj['kd_pj']?'selected':'').">{$pj['png_jawab']}</option>"; ?>
    </select>

    Status:
    <select name="status_pasien">
        <option value=""> Semua Status </option>
        <option value="Ralan" <?=$status_pasien=='Ralan'?'selected':''?>>Ralan (Pulang)</option>
        <option value="Ranap" <?=$status_pasien=='Ranap'?'selected':''?>>Ranap (Rawat Inap)</option>
    </select>

    <button type="submit" class="btn-filter">Filter Data</button>
    <a href="?action=export&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>&dokter=<?=$dokter?>&cara_bayar=<?=$cara_bayar?>&status_pasien=<?=$status_pasien?>" class="btn-excel">Export Excel</a>
    <a href="?" class="btn-reset">Reset</a>
</form>
<?php else: ?>
    <center><h3>LAPORAN MONITORING IGD RS KARTIKA HUSADA JATIASIH<br>Periode: <?=$tgl_awal?> s/d <?=$tgl_akhir?></h3></center>
<?php endif; ?>

<div class="scroll">
    <table border="<?=$isExport ? '1' : '0'?>">
        <thead>
            <tr>
                <th>No</th><th>Tanggal</th><th>Jam</th><th>No. Rawat</th><th>No. RM</th>
                <th>Nama Pasien</th><th>Jenis Bayar</th><th>Status</th><th>Dokter</th>
                <th>Diagnosa</th>
                <?php foreach($komponen as $k=>$v) echo "<th>$k</th>"; ?>
                <th>%</th><th>Hasil</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $no = 1; $grand_siap = 0; $grand_belum = 0;
        $hit = array_fill_keys(array_values($komponen), 0);

        $has_next = false;
        while($row = $res->fetch_assoc()){
            if (!$isExport && $no > $per_halaman) {
                $has_next = true;
                break;
            }
            $is_bpjs = (stripos($row['png_jawab'], 'BPJS') !== false);
            $sep_ada = !empty($row['no_sep']) ? 1 : 0;
            
            $pembagi = $is_bpjs ? 4 : 3;
            $poin = ($is_bpjs ? $sep_ada : 0) + $row['ada_igd'] + $row['ada_triase'] + ($row['status_lanjut'] == "Ranap" ? $row['ada_spr'] : $row['ada_resume']);
            
            $persen = round(($poin / $pembagi) * 100);
            $siap = ($persen >= 100);
            if($siap) $grand_siap++; else $grand_belum++;

            if($is_bpjs && $sep_ada) $hit['no_sep']++;
            foreach(['ada_igd','ada_triase','ada_resume','ada_lab','ada_rad','ada_berkas','ada_spr'] as $k) if($row[$k]) $hit[$k]++;

            $stts_class = ($row['status_lanjut'] == "Ranap") ? "stts-ranap" : "stts-ralan";
            $text_diagnosa = !empty(trim($row['diagnosa'] ?? '')) ? htmlspecialchars($row['diagnosa']) : '-';

            echo "<tr>
                <td>$no</td>
                <td>{$row['tgl_registrasi']}</td>
                <td>".substr($row['jam_reg'],0,8)."</td>
                <td style='mso-number-format:\"\@\";'>{$row['no_rawat']}</td>
                <td style='mso-number-format:\"\@\";'>{$row['no_rkm_medis']}</td>
                <td align='left'>{$row['nm_pasien']}</td>
                <td align='left'>{$row['png_jawab']}</td>
                <td><span class='".(!$isExport ? $stts_class : "")."'>".strtoupper($row['status_lanjut'])."</span></td>
                <td align='left'>{$row['nm_dokter']}</td>
                <td align='left' class='txt-left'>{$text_diagnosa}</td>";
            
            foreach($komponen as $lbl => $key){
                if($key == "no_sep" && !$is_bpjs) {
                    echo "<td class='na-kolom'>-</td>";
                } else {
                    $val = ($key == "no_sep") ? $sep_ada : $row[$key];
                    $c = $val ? 'lengkap' : 'kurang';
                    echo "<td class='".(!$isExport ? $c : "")."'>".($val ? 'Ada' : '-')."</td>";
                }
            }
            echo "<td>$persen%</td>
                  <td class='".(!$isExport ? ($siap ? 'lengkap' : 'kurang') : "")."'>".($siap ? 'SIAP' : 'BELUM')."</td>
            </tr>";
            $no++;
        }
        $total = $no - 1;
        ?>
        </tbody>
        <tfoot>
            <tr class="footer-row">
                <td colspan="10" align="right">TOTAL BERKAS ADA (LENGKAP):</td>
                <?php foreach($komponen as $k=>$v) echo "<td>".$hit[$v]."</td>"; ?>
                <td colspan="2"><?=$grand_siap?> PASIEN SIAP</td>
            </tr>
            <tr class="footer-row">
                <td colspan="10" align="right">TOTAL BERKAS TIDAK ADA (KURANG):</td>
                <?php foreach($komponen as $k=>$v) echo "<td>".($total - $hit[$v])."</td>"; ?>
                <td colspan="2"><?=$grand_belum?> PASIEN KURANG</td>
            </tr>
        </tfoot>
    </table>
</div>

<?php if (!$isExport): ?>
<div style="text-align:center; margin:12px 0;">
    <?php
    $query_filter = http_build_query([
        'tgl_awal' => $tgl_awal,
        'tgl_akhir' => $tgl_akhir,
        'dokter' => $dokter,
        'cara_bayar' => $cara_bayar,
        'status_pasien' => $status_pasien
    ]);
    if ($halaman > 1):
    ?>
        <a class="btn-reset" href="?<?=$query_filter?>&halaman=<?=$halaman - 1?>">Previous</a>
    <?php endif; ?>
    <span style="margin:0 10px;">Halaman <?=$halaman?></span>
    <?php if ($has_next): ?>
        <a class="btn-filter" style="display:inline-block; text-decoration:none;" href="?<?=$query_filter?>&halaman=<?=$halaman + 1?>">Next</a>
    <?php endif; ?>
</div>
<h3 style="margin:18px 0 8px; color:#0a7c3b;">Rekap Berkas per Dokter</h3>
<div class="scroll">
    <table>
        <thead>
            <tr>
                <th>Nama Dokter</th><th>Total Kunjungan</th>
                <th>Triase Ada</th><th>Triase Belum</th>
                <th>Medis IGD Ada</th><th>Medis IGD Belum</th>
                <th>Resume Ada</th><th>Resume Belum</th>
                <th>SPR Ada</th><th>SPR Belum</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($rekap = $rekap_dokter->fetch_assoc()): ?>
            <tr>
                <td style="text-align:left;"><?=htmlspecialchars($rekap['nm_dokter'])?></td>
                <td><?=$rekap['total_kunjungan']?></td>
                <td class="lengkap"><?=$rekap['triase_ada']?></td>
                <td class="kurang"><?=$rekap['total_kunjungan'] - $rekap['triase_ada']?></td>
                <td class="lengkap"><?=$rekap['medis_ada']?></td>
                <td class="kurang"><?=$rekap['total_kunjungan'] - $rekap['medis_ada']?></td>
                <td class="lengkap"><?=$rekap['resume_ada']?></td>
                <td class="kurang"><?=$rekap['total_kunjungan'] - $rekap['resume_ada']?></td>
                <td class="lengkap"><?=$rekap['spr_ada']?></td>
                <td class="kurang"><?=$rekap['total_kunjungan'] - $rekap['spr_ada']?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<div style="text-align:center; font-size:10px; color:#888; margin-top:20px;">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b> - Smart IT For Health 
</div>
<?php endif; ?>

</body>
</html>
