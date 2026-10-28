<?php
/* RS ASSALAM - Dashboard Monitoring RME Perawat Rawat Inap (Dynamic Compliance)
   Update: May 2026
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
$tgl_masuk_awal   = $_GET['tgl_masuk_awal']  ?? date('Y-m-01');
$tgl_masuk_akhir  = $_GET['tgl_masuk_akhir'] ?? date('Y-m-d');
$tgl_pulang_awal  = $_GET['tgl_pulang_awal']  ?? '';
$tgl_pulang_akhir = $_GET['tgl_pulang_akhir'] ?? '';
$kd_dokter        = $_GET['kd_dokter'] ?? '';
$kd_bangsal       = $_GET['kd_bangsal'] ?? '';
$keyword          = $_GET['keyword']   ?? '';
$action           = $_GET['action']    ?? '';

/* ================= LOGIKA EXPORT EXCEL ================= */
$isExcel = ($action == 'excel' || $action == 'export');
if ($isExcel) {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_RME_Perawat_Ranap_".date('YmdHis').".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
}

// 3. LOGIKA FILTER WHERE
$where = ["r.status_lanjut='Ranap'"];
$params = [];
$types = "";

$where[] = "ki.tgl_masuk BETWEEN ? AND ?";
$params[] = $tgl_masuk_awal; $params[] = $tgl_masuk_akhir;
$types .= "ss";

if ($tgl_pulang_awal && $tgl_pulang_akhir) {
    $where[] = "ki.tgl_keluar BETWEEN ? AND ?";
    $params[] = $tgl_pulang_awal; $params[] = $tgl_pulang_akhir;
    $types .= "ss";
}

if ($kd_dokter) {
    $where[] = "r.no_rawat IN (SELECT no_rawat FROM dpjp_ranap WHERE kd_dokter = ?)";
    $params[] = $kd_dokter;
    $types .= "s";
}

if ($kd_bangsal) {
    $where[] = "k.kd_bangsal = ?";
    $params[] = $kd_bangsal;
    $types .= "s";
}

if ($keyword) {
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rkm_medis LIKE ? OR r.no_rawat LIKE ?)";
    $lk = "%$keyword%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "sss";
}

$where_sql = implode(" AND ", $where);

// 4. OPTIMIZED QUERY
$sql = "SELECT 
            r.no_rawat, r.no_rkm_medis, p.nm_pasien, 
            IFNULL(bs.klsrawat, '-') as kelas, 
            b.nm_bangsal, ki.tgl_masuk, ki.tgl_keluar,
            (SELECT GROUP_CONCAT(d.nm_dokter SEPARATOR ' | ') FROM dpjp_ranap dr JOIN dokter d ON dr.kd_dokter = d.kd_dokter WHERE dr.no_rawat = r.no_rawat) as daftar_dpjp,
            
            -- Indikator Tambahan: Awal Keperawatan IGD
            IF((SELECT COUNT(no_rawat) FROM penilaian_awal_keperawatan_igd WHERE no_rawat = r.no_rawat) > 0, 1, 0) as f_awal_keperawatan_igd,

            -- Indikator Tambahan: SOAP Rajal (Rawat Jalan)
            IF((SELECT COUNT(no_rawat) FROM pemeriksaan_ralan WHERE no_rawat = r.no_rawat) > 0, 1, 0) as f_soap_rajal,

            -- Indikator Wajib 1: Awal Keperawatan Ranap
            IF(
                (SELECT COUNT(no_rawat) FROM penilaian_awal_keperawatan_ranap WHERE no_rawat = r.no_rawat) > 0 OR
                (SELECT COUNT(no_rawat) FROM penilaian_awal_keperawatan_kebidanan_ranap WHERE no_rawat = r.no_rawat) > 0 OR
                (SELECT COUNT(no_rawat) FROM penilaian_awal_keperawatan_ranap_bayi WHERE no_rawat = r.no_rawat) > 0, 
                1, 0
            ) as f_awal_keperawatan,
            
            -- Indikator Wajib 2: SOAP Ranap Keperawatan
            IF((SELECT COUNT(no_rawat) FROM pemeriksaan_ranap WHERE no_rawat = r.no_rawat) > 0, 1, 0) as f_soap_ranap,
            
            -- Indikator Wajib 3: Lanjutan Resiko Jatuh
            IF(
                (SELECT COUNT(no_rawat) FROM penilaian_lanjutan_resiko_jatuh_dewasa WHERE no_rawat = r.no_rawat) > 0 OR
                (SELECT COUNT(no_rawat) FROM penilaian_lanjutan_resiko_jatuh_anak WHERE no_rawat = r.no_rawat) > 0, 
                1, 0
            ) as f_resiko_jatuh,

            -- Indikator Wajib 4: Pemantauan PEWS (Dewasa atau Anak)
            IF(
                (SELECT COUNT(no_rawat) FROM pemantauan_pews_dewasa WHERE no_rawat = r.no_rawat) > 0 OR
                (SELECT COUNT(no_rawat) FROM pemantauan_pews_anak WHERE no_rawat = r.no_rawat) > 0,
                1, 0
            ) as f_pews,

            -- Indikator Wajib 5: CPPT Keperawatan
            IF((SELECT COUNT(no_rawat) FROM catatan_keperawatan_ranap WHERE no_rawat = r.no_rawat) > 0, 1, 0) as f_cppt_perawat,

            -- Indikator Wajib 6: Perencanaan Pemulangan
            IF((SELECT COUNT(no_rawat) FROM perencanaan_pemulangan WHERE no_rawat = r.no_rawat) > 0, 1, 0) as f_discharge_planning,

            -- Indikator Opsional 7: Transfer Antar Ruangan
            IF((SELECT COUNT(no_rawat) FROM transfer_pasien_antar_ruang WHERE no_rawat = r.no_rawat) > 0, 1, 0) as f_transfer_ruang,

            -- Indikator Opsional 8: KIE (Informasi & Edukasi)
            IF((SELECT COUNT(no_rawat) FROM pelaksanaan_informasi_edukasi WHERE no_rawat = r.no_rawat) > 0, 1, 0) as f_kie

        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
        INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
        INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
        LEFT JOIN bridging_sep bs ON r.no_rawat = bs.no_rawat
        WHERE $where_sql
        GROUP BY r.no_rawat
        ORDER BY ki.tgl_masuk DESC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("<div style='color:red; padding:20px; background:#f8d7da; font-family:sans-serif; border-radius:5px;'>
            <h3>Konfigurasi SQL Error!</h3>
            <p>Ada ketidaksesuaian nama kolom database. Silakan laporkan detail error di bawah ini:</p>
            <code><strong>Error:</strong> " . $conn->error . "</code>
         </div>");
}

if(!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

function status_cek($val, $isExcel = false) {
    if($isExcel) {
        return $val ? "<td bgcolor='#D4EDDA' style='color:#155724; text-align:center; font-weight:bold;'>Lengkap</td>" : "<td bgcolor='#F8D7DA' style='color:#721C24; text-align:center;'>-</td>";
    }
    return $val ? "<td style='background:#d4edda; color:#155724; font-weight:bold'>Lengkap</td>" : "<td style='background:#f8d7da; color:#721c24'>-</td>";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring RME Perawat  - RS Kartika Husada Jatiasih</title>
    <?php if (!$isExcel): ?>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; font-size: 11px; }
        .container { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #1e4620; text-transform: uppercase; margin: 5px 0; }
        h3 { text-align: center; color: #555; margin: 5px 0 20px 0; font-size: 14px; }
        .filter { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
        .filter-row { display: flex; justify-content: center; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .f-group { display: flex; flex-direction: column; gap: 4px; }
        .f-group label { font-weight: bold; font-size: 10px; color: #555; }
        input, select, button { padding: 8px; border-radius: 4px; border: 1px solid #ccc; font-size: 11px; }
        .btn-cari { background: #1e4620; color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 15px; }
        .btn-excel { background: #1d6f42; color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 15px; text-decoration: none; border-radius: 4px; display: inline-block; }
        .scroll-area { max-height: 600px; overflow: auto; border: 1px solid #ddd; margin-top: 10px; }
        table { border-collapse: collapse; width: 100%; min-width: 2700px; }
        thead th { position: sticky; top: 0; background: #1e4620; color: white; padding: 12px 5px; z-index: 10; border: 1px solid #143216; text-align: center; }
        td { padding: 10px 5px; border: 1px solid #eee; text-align: center; vertical-align: middle; }
        .norm { font-weight: bold; font-family: monospace; color: #333; }
        .badge { padding: 4px 8px; border-radius: 10px; color: white; font-weight: bold; font-size: 10px; }
    </style>
    <?php else: ?>
    <style>
        .str { mso-number-format:"\@"; } /* Mengunci data string agar No. RM / No. Rawat tidak corrupt */
        thead th { background-color: #1E4620; color: #FFFFFF; font-weight: bold; text-align: center; }
        td { vertical-align: middle; }
    </style>
    <?php endif; ?>
</head>
<body>

<div class="container">
    <?php if (!$isExcel): ?>
    <h2>RS KARTIKA HUSADA JATIASIH</h2>
    <h3>MONITORING RME PERAWAT </h3>
    <div class="filter">
        <form method="GET">
            <div class="filter-row">
                <div class="f-group">
                    <label>TANGGAL MASUK</label>
                    <div>
                        <input type="date" name="tgl_masuk_awal" value="<?=$tgl_masuk_awal?>"> s/d 
                        <input type="date" name="tgl_masuk_akhir" value="<?=$tgl_masuk_akhir?>">
                    </div>
                </div>
                
                <div class="f-group">
                    <label>TANGGAL PULANG (OPSIONAL)</label>
                    <div>
                        <input type="date" name="tgl_pulang_awal" value="<?=$tgl_pulang_awal?>"> s/d 
                        <input type="date" name="tgl_pulang_akhir" value="<?=$tgl_pulang_akhir?>">
                    </div>
                </div>

                <div class="f-group">
                    <label>DOKTER DPJP UTAMA</label>
                    <select name="kd_dokter">
                        <option value="">- Semua Dokter -</option>
                        <?php
                        $docs = $conn->query("SELECT DISTINCT d.kd_dokter, d.nm_dokter
                            FROM dokter d
                            INNER JOIN dpjp_ranap dr ON d.kd_dokter = dr.kd_dokter
                            INNER JOIN reg_periksa r ON dr.no_rawat = r.no_rawat
                            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
                            INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
                            INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
                            WHERE d.status = '1'
                              AND r.status_lanjut = 'Ranap'
                              AND ki.tgl_masuk BETWEEN '" . $conn->real_escape_string($tgl_masuk_awal) . "' AND '" . $conn->real_escape_string($tgl_masuk_akhir) . "'
                              " . (($tgl_pulang_awal && $tgl_pulang_akhir)
                                  ? "AND ki.tgl_keluar BETWEEN '" . $conn->real_escape_string($tgl_pulang_awal) . "' AND '" . $conn->real_escape_string($tgl_pulang_akhir) . "' "
                                  : "") . "
                              " . ($kd_bangsal
                                  ? "AND k.kd_bangsal = '" . $conn->real_escape_string($kd_bangsal) . "' "
                                  : "") . "
                              " . ($keyword
                                  ? "AND (p.nm_pasien LIKE '%" . $conn->real_escape_string($keyword) . "%'
                                         OR r.no_rkm_medis LIKE '%" . $conn->real_escape_string($keyword) . "%'
                                         OR r.no_rawat LIKE '%" . $conn->real_escape_string($keyword) . "%') "
                                  : "") . "
                            ORDER BY d.nm_dokter");
                        while($d = $docs->fetch_assoc()) {
                            $s = ($kd_dokter == $d['kd_dokter']) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($d['kd_dokter'], ENT_QUOTES, 'UTF-8') . '" ' . $s . '>' . htmlspecialchars($d['nm_dokter'], ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="f-group">
                    <label>NURSE STATION / BANGSAL</label>
                    <select name="kd_bangsal">
                        <option value="">- Semua Nurse Station -</option>
                        <?php
                        $bangsalList = $conn->query("SELECT DISTINCT b.kd_bangsal, b.nm_bangsal
                            FROM bangsal b
                            INNER JOIN kamar k ON b.kd_bangsal = k.kd_bangsal
                            INNER JOIN kamar_inap ki ON k.kd_kamar = ki.kd_kamar
                            INNER JOIN reg_periksa r ON ki.no_rawat = r.no_rawat
                            INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
                            WHERE r.status_lanjut = 'Ranap'
                              AND ki.tgl_masuk BETWEEN '" . $conn->real_escape_string($tgl_masuk_awal) . "' AND '" . $conn->real_escape_string($tgl_masuk_akhir) . "'
                              " . (($tgl_pulang_awal && $tgl_pulang_akhir)
                                  ? "AND ki.tgl_keluar BETWEEN '" . $conn->real_escape_string($tgl_pulang_awal) . "' AND '" . $conn->real_escape_string($tgl_pulang_akhir) . "' "
                                  : "") . "
                              " . ($kd_dokter
                                  ? "AND r.no_rawat IN (SELECT no_rawat FROM dpjp_ranap WHERE kd_dokter = '" . $conn->real_escape_string($kd_dokter) . "') "
                                  : "") . "
                              " . ($keyword
                                  ? "AND (p.nm_pasien LIKE '%" . $conn->real_escape_string($keyword) . "%' OR r.no_rkm_medis LIKE '%" . $conn->real_escape_string($keyword) . "%' OR r.no_rawat LIKE '%" . $conn->real_escape_string($keyword) . "%') "
                                  : "") . "
                            ORDER BY b.nm_bangsal");
                        while($b = $bangsalList->fetch_assoc()) {
                            $selected = ($kd_bangsal == $b['kd_bangsal']) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($b['kd_bangsal'], ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>' . htmlspecialchars($b['nm_bangsal'], ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="f-group">
                    <label>CARI PASIEN</label>
                    <input type="text" name="keyword" value="<?=$keyword?>" placeholder="Nama / No. RM / No. Rawat" style="width:180px">
                </div>

                <button type="submit" class="btn-cari">FILTER DATA</button>
                <a href="?action=excel&tgl_masuk_awal=<?=$tgl_masuk_awal?>&tgl_masuk_akhir=<?=$tgl_masuk_akhir?>&tgl_pulang_awal=<?=$tgl_pulang_awal?>&tgl_pulang_akhir=<?=$tgl_pulang_akhir?>&kd_dokter=<?=$kd_dokter?>&kd_bangsal=<?=$kd_bangsal?>&keyword=<?=$keyword?>" class="btn-excel">EXPORT EXCEL</a>
                <div style="font-size:14px; font-weight:bold; color:#1e4620; padding:8px 10px;">
                    Jumlah Pasien: <?=number_format($result->num_rows, 0, ',', '.') ?>
                </div>
                <a href="?" style="text-decoration:none; font-size:11px; color:red; margin-left:5px;">Reset</a>
            </div>
        </form>
    </div>
    <?php else: ?>
    <table border="0">
        <tr>
            <td colspan="21" style="font-size:16px; font-weight:bold; text-align:center;">RS KARTIKA HUSADA JATIASIH</td>
        </tr>
        <tr>
            <td colspan="21" style="font-size:13px; font-weight:bold; text-align:center; color:#555;">DASHBOARD MONITORING RME PERAWAT RAWAT INAP</td>
        </tr>
        <tr>
            <td colspan="21" style="font-size:11px; text-align:center;">Periode Masuk: <?=$tgl_masuk_awal?> s/d <?=$tgl_masuk_akhir?></td>
        </tr>
        <tr><td colspan="21"></td></tr>
    </table>
    <?php endif; ?>

    <div class="<?= (!$isExcel) ? 'scroll-area' : '' ?>">
        <table border="1" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th width="40">No</th>
                    <th width="140">No. Rawat</th>
                    <th width="90">No. RM</th>
                    <th width="220">Nama Pasien</th>
                    <th width="70">Kelas</th>
                    <th width="200">Bangsal / Kamar</th>
                    <th width="100">Tgl Masuk</th>
                    <th width="100">Tgl Pulang</th>
                    <th width="250">DPJP Utama</th>
                    <th width="140">Awal Keperawatan IGD</th>
                    <th width="120">SOAP Rajal</th>
                    <th width="140">Awal Keperawatan Ranap</th>
                    <th width="120">SOAP Ranap</th>
                    <th width="120">Resiko Jatuh</th>
                    <th width="130">Pemantauan PEWS /EWS/MEOWS</th>
                    <th width="130">CPPT Perawat</th>
                    <th width="150">Perencanaan Pemulangan</th>
                    <th width="160">Transfer Ruangan (Opsional)</th> 
                    <th width="140">KIE / Edukasi (Opsional)</th> 
                    <th width="90">% Kelengkapan</th>
                    <th width="100">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                while ($r = $result->fetch_assoc()):
                    // Ditambahkan f_awal_keperawatan_igd & f_soap_rajal ke skor wajib (sekarang total 8 indikator wajib)
                    $skor_wajib = $r['f_awal_keperawatan_igd'] + $r['f_soap_rajal'] + $r['f_awal_keperawatan'] + $r['f_soap_ranap'] + $r['f_resiko_jatuh'] + $r['f_pews'] + $r['f_cppt_perawat'] + $r['f_discharge_planning'];
                    
                    $total_skor = $skor_wajib;
                    $pembagi_dinamis = 8;

                    if ($r['f_transfer_ruang'] > 0) {
                        $total_skor += 1;
                        $pembagi_dinamis += 1;
                    }
                    if ($r['f_kie'] > 0) {
                        $total_skor += 1;
                        $pembagi_dinamis += 1;
                    }

                    $persen = round(($total_skor / $pembagi_dinamis) * 100); 
                ?>
                <tr>
                    <td style="text-align:center;"><?=$no++?></td>
                    <td class="str" style="text-align:center; mso-number-format:'\@';"><?=$r['no_rawat']?></td>
                    <td class="str" style="text-align:center; mso-number-format:'\@';"><?=$r['no_rkm_medis']?></td>
                    <td style="text-align:left;"><?=$r['nm_pasien']?></td>
                    <td style="text-align:center;"><?=$r['kelas']?></td>
                    <td style="text-align:left;"><?=$r['nm_bangsal']?></td>
                    <td style="text-align:center;"><?=date('d/m/Y', strtotime($r['tgl_masuk']))?></td>
                    <td style="text-align:center;"><?=($r['tgl_keluar'] && $r['tgl_keluar'] != '0000-00-00') ? date('d/m/Y', strtotime($r['tgl_keluar'])) : "Masih di rawat"?></td>
                    <td style="text-align:left; font-size:10px;"><?=$r['daftar_dpjp'] ?: '-'?></td>
                    
                    <?=status_cek($r['f_awal_keperawatan_igd'], $isExcel)?>
                    <?=status_cek($r['f_soap_rajal'], $isExcel)?>
                    <?=status_cek($r['f_awal_keperawatan'], $isExcel)?>
                    <?=status_cek($r['f_soap_ranap'], $isExcel)?>
                    <?=status_cek($r['f_resiko_jatuh'], $isExcel)?>
                    <?=status_cek($r['f_pews'], $isExcel)?>
                    <?=status_cek($r['f_cppt_perawat'], $isExcel)?>
                    <?=status_cek($r['f_discharge_planning'], $isExcel)?>

                    <?=status_cek($r['f_transfer_ruang'], $isExcel)?>
                    <?=status_cek($r['f_kie'], $isExcel)?>

                    <td style="text-align:center; font-weight:bold; mso-number-format:'0%';"><?=$persen?>%</td>
                    <td style="text-align:center; font-weight:bold;">
                        <?php if(!$isExcel): ?>
                        <span class="badge" style="background: <?=$persen == 100 ? '#28a745':'#dc3545'?>">
                            <?=$persen == 100 ? 'LENGKAP':'BELUM'?>
                        </span>
                        <?php else: 
                            echo $persen == 100 ? "LENGKAP" : "BELUM";
                        endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($result->num_rows == 0): ?>
                <tr>
                    <td colspan="21" style="padding:20px; font-weight:bold; color:red; text-align:center;">Data tidak ditemukan berdasarkan filter pilihan.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$isExcel): ?>
<div style="text-align:center; font-size:12px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. All Rights Reserved.
</div>
<?php endif; ?>

</body>
</html>
