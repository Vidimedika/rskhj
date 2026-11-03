<?php
/* RS ASSALAM - Dashboard Monitoring & Supervisi Berkas Keperawatan RME
   Update: 2026
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

// 2. DEKLARASI HELPER FUNCTION (Harus di atas sebelum dipanggil)
function checkTableExists($conn, $tableName) {
    $check = $conn->query("SHOW TABLES LIKE '$tableName'");
    return ($check && $check->num_rows > 0);
}

// 3. PEMANGGILAN FUNGSI (Aman dipanggil di sini)
$has_rj_dewasa  = checkTableExists($conn, 'penilaian_risiko_jatuh_dewasa');
$has_rj_anak    = checkTableExists($conn, 'penilaian_risiko_jatuh_anak');
$has_rj_lansia  = checkTableExists($conn, 'penilaian_risiko_jatuh_lansia');

$has_ews_dewasa = checkTableExists($conn, 'pemantauan_ews_dewasa');
$has_pews_anak  = checkTableExists($conn, 'pemantauan_pews_anak');
$has_ews_lansia = checkTableExists($conn, 'pemantauan_ews_lansia');

$has_obs_igd    = checkTableExists($conn, 'catatan_observasi_igd');
$has_pp_pulang  = checkTableExists($conn, 'perencanaan_pemulangan');

// 2. TANGKAP FILTER
$tgl_masuk_awal   = $_GET['tgl_masuk_awal']   ?? date('Y-m-01');
$tgl_masuk_akhir  = $_GET['tgl_masuk_akhir']  ?? date('Y-m-d');
$tgl_pulang_awal  = $_GET['tgl_pulang_awal']  ?? '';
$tgl_pulang_akhir = $_GET['tgl_pulang_akhir'] ?? '';
$kd_dokter        = $_GET['kd_dokter']        ?? '';
$keyword          = $_GET['keyword']          ?? '';
$action           = $_GET['action']           ?? '';

// 3. LOGIKA QUERY
$where = ["r.status_lanjut='Ranap'", "pj.png_jawab LIKE '%BPJS%'"];
$params = [];
$types = "";

// Filter Tanggal Masuk
$where[] = "ki.tgl_masuk BETWEEN ? AND ?";
$params[] = $tgl_masuk_awal; 
$params[] = $tgl_masuk_akhir;
$types .= "ss";

// Filter Tanggal Pulang (Kondisional)
if (!empty($tgl_pulang_awal) && !empty($tgl_pulang_akhir)) {
    $where[] = "ki.tgl_keluar BETWEEN ? AND ?";
    $params[] = $tgl_pulang_awal; 
    $params[] = $tgl_pulang_akhir;
    $types .= "ss";
}

// Filter Dokter DPJP
if (!empty($kd_dokter)) {
    $where[] = "r.no_rawat IN (SELECT no_rawat FROM dpjp_ranap WHERE kd_dokter = ?)";
    $params[] = $kd_dokter;
    $types .= "s";
}

// Filter Pencarian Pasien
if (!empty($keyword)) {
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rkm_medis LIKE ? OR r.no_rawat LIKE ? OR bs.no_sep LIKE ?)";
    $lk = "%$keyword%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "ssss";
}

$where_sql = implode(" AND ", $where);

// Konstruksi Klausa SQL Aman untuk Tabel Opsional
$sql_obs_igd   = $has_obs_igd   ? "IF(obs_igd.no_rawat IS NULL, 0, 1)" : "0";
$sql_pp_pulang = $has_pp_pulang ? "IF(pp.no_rawat IS NULL, 0, 1)"      : "0";

// Ekspresi SQL Risiko Jatuh
$rj_conditions = [];
if ($has_rj_dewasa) $rj_conditions[] = "rj_d.no_rawat IS NOT NULL";
if ($has_rj_anak)   $rj_conditions[] = "rj_a.no_rawat IS NOT NULL";
if ($has_rj_lansia) $rj_conditions[] = "rj_l.no_rawat IS NOT NULL";
$sql_risiko_jatuh = count($rj_conditions) > 0 ? "IF(" . implode(" OR ", $rj_conditions) . ", 1, 0)" : "0";

// Ekspresi SQL EWS/PEWS
$ews_conditions = [];
if ($has_ews_dewasa) $ews_conditions[] = "ews_d.no_rawat IS NOT NULL";
if ($has_pews_anak)  $ews_conditions[] = "ews_a.no_rawat IS NOT NULL";
if ($has_ews_lansia) $ews_conditions[] = "ews_l.no_rawat IS NOT NULL";
$sql_ews_pews = count($ews_conditions) > 0 ? "IF(" . implode(" OR ", $ews_conditions) . ", 1, 0)" : "0";

// Ambil Data Utama
$sql = "SELECT 
            r.no_rawat, r.no_rkm_medis, p.nm_pasien, bs.no_sep, 
            b.nm_bangsal, ki.tgl_masuk, ki.tgl_keluar,
            (SELECT d.nm_dokter FROM dpjp_ranap dr JOIN dokter d ON dr.kd_dokter = d.kd_dokter WHERE dr.no_rawat = r.no_rawat LIMIT 1) as dokter_dpjp,
            
            /* 10 Poin Supervisi Keperawatan */
            IF(pak.no_rawat IS NULL, 0, 1) as f_awal_kep_ugd,
            $sql_obs_igd as f_obs_ugd,
            IF(tp.no_rawat IS NULL, 0, 1) as f_transfer_ruang,
            IF(par.no_rawat IS NULL, 0, 1) as f_awal_kep_umum,
            IF(pakk.no_rawat IS NULL, 0, 1) as f_awal_kep_kandungan,
            $sql_pp_pulang as f_perencanaan_pulang,
            $sql_risiko_jatuh as f_risiko_jatuh,
            IF(cp.no_rawat IS NULL, 0, 1) as f_catatan_keperawatan,
            $sql_ews_pews as f_ews_pews,
            IF(prn.no_rawat IS NOT NULL OR pra.no_rawat IS NOT NULL, 1, 0) as f_soapie,

            /* Berkas Penunjang/Klaim */
            IF(dt.no_rawat IS NULL, 0, 1) as f_triase,
            IF(pr.no_rawat IS NULL, 0, 1) as f_spr,
            IF(pb.no_rkm_medis IS NULL, 0, 1) as f_skl,
            IF(pl.no_rawat IS NULL, 0, 1) as f_lab,
            IF(prd.no_rawat IS NULL, 0, 1) as f_radiologi,
            IF(kie.no_rawat IS NULL, 0, 1) as f_kie

        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
        INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
        INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
        INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
        LEFT JOIN bridging_sep bs ON r.no_rawat = bs.no_rawat

        /* Join Berkas Supervisi Keperawatan Utama */
        LEFT JOIN penilaian_awal_keperawatan_igd pak ON r.no_rawat = pak.no_rawat
        " . ($has_obs_igd ? "LEFT JOIN catatan_observasi_igd obs_igd ON r.no_rawat = obs_igd.no_rawat" : "") . "
        LEFT JOIN transfer_pasien_antar_ruang tp ON r.no_rawat = tp.no_rawat
        LEFT JOIN penilaian_awal_keperawatan_ranap par ON r.no_rawat = par.no_rawat
        LEFT JOIN penilaian_awal_keperawatan_kebidanan pakk ON r.no_rawat = pakk.no_rawat
        " . ($has_pp_pulang ? "LEFT JOIN perencanaan_pemulangan pp ON r.no_rawat = pp.no_rawat" : "") . "
        
        /* Join Risiko Jatuh */
        " . ($has_rj_dewasa ? "LEFT JOIN penilaian_risiko_jatuh_dewasa rj_d ON r.no_rawat = rj_d.no_rawat" : "") . "
        " . ($has_rj_anak   ? "LEFT JOIN penilaian_risiko_jatuh_anak rj_a ON r.no_rawat = rj_a.no_rawat" : "") . "
        " . ($has_rj_lansia ? "LEFT JOIN penilaian_risiko_jatuh_lansia rj_l ON r.no_rawat = rj_l.no_rawat" : "") . "
        
        /* Join Catatan Perawatan */
        LEFT JOIN catatan_perawatan cp ON r.no_rawat = cp.no_rawat

        /* Join EWS/PEWS */
        " . ($has_ews_dewasa ? "LEFT JOIN pemantauan_ews_dewasa ews_d ON r.no_rawat = ews_d.no_rawat" : "") . "
        " . ($has_pews_anak  ? "LEFT JOIN pemantauan_pews_anak ews_a ON r.no_rawat = ews_a.no_rawat" : "") . "
        " . ($has_ews_lansia ? "LEFT JOIN pemantauan_ews_lansia ews_l ON r.no_rawat = ews_l.no_rawat" : "") . "

        /* Join SOAPIE & Penunjang */
        LEFT JOIN pemeriksaan_ranap prn ON r.no_rawat = prn.no_rawat
        LEFT JOIN pemeriksaan_ralan pra ON r.no_rawat = pra.no_rawat
        LEFT JOIN data_triase_igd dt ON r.no_rawat = dt.no_rawat
        LEFT JOIN permintaan_ranap pr ON r.no_rawat = pr.no_rawat
        LEFT JOIN pasien_bayi pb ON r.no_rkm_medis = pb.no_rkm_medis
        LEFT JOIN periksa_lab pl ON r.no_rawat = pl.no_rawat
        LEFT JOIN periksa_radiologi prd ON r.no_rawat = prd.no_rawat
        LEFT JOIN pelaksanaan_informasi_edukasi kie ON r.no_rawat = kie.no_rawat

        WHERE $where_sql
        GROUP BY r.no_rawat
        ORDER BY ki.tgl_masuk DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("<div style='padding:20px; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:5px; margin:20px; font-family:sans-serif;'>
            <h3>Query SQL Error!</h3>
            <p><b>Pesan Error Database:</b> " . htmlspecialchars($conn->error) . "</p>
         </div>");
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// List Dokter Filter
$list_dokter = $conn->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status = '1' ORDER BY nm_dokter ASC");

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_Supervisi_Keperawatan_".date('Ymd').".xls");
}

function status_cek($val, $isExcel = false) {
    if ($isExcel) return $val ? "Ada" : "-";
    return $val ? "<td style='background:#d4edda; color:#155724; font-weight:bold'>Ada</td>" : "<td style='background:#f8d7da; color:#721c24'>-</td>";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring Berkas Supervisi Keperawatan - RS ASSALAM</title>
    <?php if ($action != 'excel'): ?>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; font-size: 10px; }
        .container { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #0a7c3b; text-transform: uppercase; margin: 5px 0; }
        .filter { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
        .filter-row { display: flex; justify-content: center; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .f-group { display: flex; flex-direction: column; gap: 4px; }
        .f-group label { font-weight: bold; font-size: 9px; color: #555; }
        input, select, button { padding: 6px; border-radius: 4px; border: 1px solid #ccc; font-size: 11px; }
        .btn-cari { background: #0a7c3b; color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 15px; }
        .btn-excel { background: #1d6f42; color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 15px; text-decoration: none; border-radius: 4px; display: inline-block; }
        .scroll-area { max-height: 600px; overflow: auto; border: 1px solid #ddd; margin-top: 10px; }
        table { border-collapse: collapse; width: 100%; min-width: 3200px; } 
        thead th { position: sticky; top: 0; background: #0a7c3b; color: white; padding: 10px 4px; z-index: 10; border: 1px solid #08632f; text-align: center; }
        td { padding: 6px 4px; border: 1px solid #eee; text-align: center; vertical-align: top; }
        .norm { font-weight: bold; font-family: monospace; color: #333; }
        .badge { padding: 4px 8px; border-radius: 10px; color: white; font-weight: bold; display: inline-block; }
    </style>
    <?php endif; ?>
</head>
<body>

<div class="container">
    <?php if ($action != 'excel'): ?>
    <h2>RS ASSALAM</h2>
    <h2>DASHBOARD MONITORING & SUPERVISI BERKAS KEPERAWATAN RME</h2>

    <div class="filter">
        <form method="GET">
            <div class="filter-row">
                <div class="f-group">
                    <label>TANGGAL MASUK</label>
                    <div><input type="date" name="tgl_masuk_awal" value="<?=$tgl_masuk_awal?>"> s/d <input type="date" name="tgl_masuk_akhir" value="<?=$tgl_masuk_akhir?>"></div>
                </div>
                <div class="f-group">
                    <label>TANGGAL PULANG</label>
                    <div><input type="date" name="tgl_pulang_awal" value="<?=$tgl_pulang_awal?>"> s/d <input type="date" name="tgl_pulang_akhir" value="<?=$tgl_pulang_akhir?>"></div>
                </div>
                <div class="f-group">
                    <label>DOKTER DPJP</label>
                    <select name="kd_dokter" style="width: 200px;">
                        <option value="">-- Semua Dokter --</option>
                        <?php while($d = $list_dokter->fetch_assoc()): ?>
                            <option value="<?=$d['kd_dokter']?>" <?=$kd_dokter == $d['kd_dokter'] ? 'selected':''?>><?=$d['nm_dokter']?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="f-group">
                    <label>CARI PASIEN</label>
                    <input type="text" name="keyword" value="<?=$keyword?>" placeholder="Nama/RM/SEP" style="width:130px">
                </div>
                <button type="submit" class="btn-cari">FILTER</button>
                <a href="?action=excel&tgl_masuk_awal=<?=$tgl_masuk_awal?>&tgl_masuk_akhir=<?=$tgl_masuk_akhir?>&tgl_pulang_awal=<?=$tgl_pulang_awal?>&tgl_pulang_akhir=<?=$tgl_pulang_akhir?>&kd_dokter=<?=$kd_dokter?>&keyword=<?=$keyword?>" class="btn-excel">EXCEL</a>
            </div>
        </form>
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
                    <th>Dokter DPJP</th>
                    <th>Bangsal</th>
                    <th>Tgl Masuk</th>
                    <th>Tgl Pulang</th>
                    <th style="background:#0d47a1">NO. SEP</th>
                    
                    <!-- Header 10 Poin Supervisi Keperawatan Utama -->
                    <th style="background:#0277bd">1. Awal Kep. UGD</th>
                    <th style="background:#0277bd">2. Obs. UGD</th>
                    <th style="background:#0277bd">3. Transfer Ruang</th>
                    <th style="background:#0277bd">4. Awal Kep. Umum</th>
                    <th style="background:#0277bd">5. Awal Kep. Kebidanan</th>
                    <th style="background:#0277bd">6. Plan Pemulangan</th>
                    <th style="background:#0277bd">7. Risti Jatuh</th>
                    <th style="background:#0277bd">8. Catatan Kep.</th>
                    <th style="background:#0277bd">9. EWS/PEWS</th>
                    <th style="background:#0277bd">10. SOAPIE</th>

                    <!-- Header Penunjang Lainnya -->
                    <th>Triase</th>
                    <th>SPR</th>
                    <th>SKL</th>
                    <th>Lab</th>
                    <th>Rad</th>
                    <th>KIE</th>
                    <th>%</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                while ($r = $result->fetch_assoc()):
                    // Skor Khusus Supervisi Keperawatan (10 Poin)
                    $skor_supervisi = $r['f_awal_kep_ugd'] + $r['f_obs_ugd'] + $r['f_transfer_ruang'] + 
                                     $r['f_awal_kep_umum'] + $r['f_awal_kep_kandungan'] + $r['f_perencanaan_pulang'] + 
                                     $r['f_risiko_jatuh'] + $r['f_catatan_keperawatan'] + $r['f_ews_pews'] + $r['f_soapie'];
                    
                    // Total Keseluruhan (10 Supervisi + 6 Penunjang = 16)
                    $skor_total = $skor_supervisi + $r['f_triase'] + $r['f_spr'] + $r['f_skl'] + $r['f_lab'] + $r['f_radiologi'] + $r['f_kie'];
                    
                    $persen = round(($skor_total / 16) * 100); 
                ?>
                <tr>
                    <td><?=$no++?></td>
                    <td class="norm">='<?=$r['no_rawat']?>'</td>
                    <td class="norm">='<?=$r['no_rkm_medis']?>'</td>
                    <td style="text-align:left"><?=$r['nm_pasien']?></td>
                    <td style="text-align:left"><?=$r['dokter_dpjp'] ?: '-'?></td>
                    <td><?=$r['nm_bangsal']?></td>
                    <td><?=date('d/m/Y', strtotime($r['tgl_masuk']))?></td>
                    <td><?=($r['tgl_keluar'] && $r['tgl_keluar'] != '0000-00-00') ? date('d/m/Y', strtotime($r['tgl_keluar'])) : "Rawat"?></td>
                    <td><?=$r['no_sep'] ?: '-'?></td>
                    
                    <!-- 10 Kolom Supervisi Keperawatan -->
                    <?=status_cek($r['f_awal_kep_ugd'], ($action == 'excel'))?>
                    <?=status_cek($r['f_obs_ugd'], ($action == 'excel'))?>
                    <?=status_cek($r['f_transfer_ruang'], ($action == 'excel'))?>
                    <?=status_cek($r['f_awal_kep_umum'], ($action == 'excel'))?>
                    <?=status_cek($r['f_awal_kep_kandungan'], ($action == 'excel'))?>
                    <?=status_cek($r['f_perencanaan_pulang'], ($action == 'excel'))?>
                    <?=status_cek($r['f_risiko_jatuh'], ($action == 'excel'))?>
                    <?=status_cek($r['f_catatan_keperawatan'], ($action == 'excel'))?>
                    <?=status_cek($r['f_ews_pews'], ($action == 'excel'))?>
                    <?=status_cek($r['f_soapie'], ($action == 'excel'))?>

                    <!-- Kolom Penunjang Lainnya -->
                    <?=status_cek($r['f_triase'], ($action == 'excel'))?>
                    <?=status_cek($r['f_spr'], ($action == 'excel'))?>
                    <?=status_cek($r['f_skl'], ($action == 'excel'))?>
                    <?=status_cek($r['f_lab'], ($action == 'excel'))?>
                    <?=status_cek($r['f_radiologi'], ($action == 'excel'))?>
                    <?=status_cek($r['f_kie'], ($action == 'excel'))?>

                    <td><b><?=$persen?>%</b></td>
                    <td>
                        <?php if ($action != 'excel'): ?>
                        <span class="badge" style="background: <?=$persen>=80 ? '#28a745':'#dc3545'?>">
                            <?=$persen>=80 ? 'SIAP':'BELUM'?>
                        </span>
                        <?php else: echo ($persen>=80 ? 'SIAP':'BELUM'); endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<div style="text-align:center; font-size:11px; color:#888; margin-top:15px;">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b> - Sistem Informasi Monitoring & Supervisi RME v2.0
</div>
</body>
</html>
