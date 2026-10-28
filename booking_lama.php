<?php
// 1. KONEKSI DATABASE
$db_hostname = "192.168.10.29";
$db_username = "root";
$db_password = "1nternet";
$db_name     = "rskhj";

try {
    $conn = new PDO("mysql:host=$db_hostname;dbname=$db_name", $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage());
}

// 2. LOGIKA FILTER
$tgl_booking_awal  = $_GET['tgl_booking_awal'] ?? date('Y-m-01');
$tgl_booking_akhir = $_GET['tgl_booking_akhir'] ?? date('Y-m-d');
$tgl_periksa_awal  = $_GET['tgl_periksa_awal'] ?? '';
$tgl_periksa_akhir = $_GET['tgl_periksa_akhir'] ?? '';
$filter_asal       = $_GET['filter_asal'] ?? ''; 
$filter_hadir      = $_GET['filter_hadir'] ?? ''; 
$filter_sumber     = $_GET['filter_sumber'] ?? ''; 

$cond_b = [];
$cond_sk = [];
$params = [];

// Filter Periode Booking / Terbit Surat
if (!empty($tgl_booking_awal) && !empty($tgl_booking_akhir)) {
    $cond_b[]  = "b.tanggal_booking BETWEEN ? AND ?";
    $cond_sk[] = "sk.tgl_surat BETWEEN ? AND ?";
    $params['b_tgl1']  = $tgl_booking_awal;
    $params['b_tgl2']  = $tgl_booking_akhir;
    $params['sk_tgl1'] = $tgl_booking_awal;
    $params['sk_tgl2'] = $tgl_booking_akhir;
}

// Filter Periode Rencana Periksa / Tgl Rencana Kontrol
if (!empty($tgl_periksa_awal) && !empty($tgl_periksa_akhir)) {
    $cond_b[]  = "b.tanggal_periksa BETWEEN ? AND ?";
    $cond_sk[] = "sk.tgl_rencana BETWEEN ? AND ?";
    $params['b_prk1']  = $tgl_periksa_awal;
    $params['b_prk2']  = $tgl_periksa_akhir;
    $params['sk_prk1'] = $tgl_periksa_awal;
    $params['sk_prk2'] = $tgl_periksa_akhir;
}

$where_b  = count($cond_b) > 0 ? " WHERE " . implode(" AND ", $cond_b) : "";
$where_sk = count($cond_sk) > 0 ? " WHERE " . implode(" AND ", $cond_sk) : "";

// Filter HAVING
$having = [];
if ($filter_hadir === 'hadir') {
    $having[] = "hadir IS NOT NULL";
} elseif ($filter_hadir === 'tidak') {
    $having[] = "hadir IS NULL";
}

if ($filter_asal === 'rajal') {
    $having[] = "cek_rajal > 0";
} elseif ($filter_asal === 'ranap') {
    $having[] = "cek_rajal = 0";
}

if ($filter_sumber === 'booking') {
    $having[] = "sumber_data = 'Booking Registrasi'";
} elseif ($filter_sumber === 'skdp') {
    $having[] = "sumber_data = 'Surat Kontrol BPJS'";
}

$having_sql = count($having) > 0 ? " HAVING " . implode(" AND ", $having) : "";

// 3. QUERY UTAMA
$sql = "SELECT * FROM (
    -- Sumber 1: Tabel booking_registrasi
    SELECT 
        b.tanggal_booking AS tgl_transaksi,
        b.jam_booking AS jam_transaksi,
        b.no_rkm_medis,
        p.nm_pasien,
        p.no_tlp,
        b.tanggal_periksa AS tgl_rencana_periksa,
        d.nm_dokter,
        pl.nm_poli,
        pj.png_jawab,
        '-' AS no_surat_kontrol,
        'Booking Registrasi' AS sumber_data,
        rp.no_rkm_medis AS hadir,
        (SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis = b.no_rkm_medis AND tgl_registrasi = b.tanggal_booking) AS cek_rajal
    FROM booking_registrasi b
    JOIN pasien p ON b.no_rkm_medis = p.no_rkm_medis
    JOIN dokter d ON b.kd_dokter = d.kd_dokter
    JOIN poliklinik pl ON b.kd_poli = pl.kd_poli
    JOIN penjab pj ON b.kd_pj = pj.kd_pj
    LEFT JOIN reg_periksa rp ON b.no_rkm_medis = rp.no_rkm_medis AND b.tanggal_periksa = rp.tgl_registrasi
    $where_b

    UNION ALL

    -- Sumber 2: Tabel bridging_surat_kontrol_bpjs (Memakai kd_dokter_bpjs & maping_dokter_dpjpvclaim)
    SELECT 
        sk.tgl_surat AS tgl_transaksi,
        '00:00:00' AS jam_transaksi,
        sep.nomr AS no_rkm_medis,
        p.nm_pasien,
        p.no_tlp,
        sk.tgl_rencana AS tgl_rencana_periksa,
        COALESCE(d.nm_dokter, sk.nm_dokter_bpjs) AS nm_dokter,
        COALESCE(pl.nm_poli, sk.nm_poli_bpjs) AS nm_poli,
        pj.png_jawab,
        sk.no_surat AS no_surat_kontrol,
        'Surat Kontrol BPJS' AS sumber_data,
        rp.no_rkm_medis AS hadir,
        (SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis = sep.nomr AND tgl_registrasi = sk.tgl_surat) AS cek_rajal
    FROM bridging_surat_kontrol_bpjs sk
    JOIN bridging_sep sep ON sk.no_sep = sep.no_sep
    JOIN pasien p ON sep.nomr = p.no_rkm_medis
    LEFT JOIN maping_dokter_dpjpvclaim map ON sk.kd_dokter_bpjs = map.kd_dokter_bpjs
    LEFT JOIN dokter d ON map.kd_dokter = d.kd_dokter
    LEFT JOIN maping_poli_bpjs map_p ON sk.kd_poli_bpjs = map_p.kd_poli_bpjs
    LEFT JOIN poliklinik pl ON map_p.kd_poli_rs = pl.kd_poli
    LEFT JOIN penjab pj ON p.kd_pj = pj.kd_pj
    LEFT JOIN reg_periksa rp ON sep.nomr = rp.no_rkm_medis AND sk.tgl_rencana = rp.tgl_registrasi
    $where_sk
) AS gabungan
$having_sql
ORDER BY tgl_transaksi DESC, jam_transaksi DESC";

$stmt = $conn->prepare($sql);

// Binding parameter secara aman
$bind_index = 1;
if (isset($params['b_tgl1']))  { $stmt->bindValue($bind_index++, $params['b_tgl1']); $stmt->bindValue($bind_index++, $params['b_tgl2']); }
if (isset($params['b_prk1']))  { $stmt->bindValue($bind_index++, $params['b_prk1']); $stmt->bindValue($bind_index++, $params['b_prk2']); }
if (isset($params['sk_tgl1'])) { $stmt->bindValue($bind_index++, $params['sk_tgl1']); $stmt->bindValue($bind_index++, $params['sk_tgl2']); }
if (isset($params['sk_prk1'])) { $stmt->bindValue($bind_index++, $params['sk_prk1']); $stmt->bindValue($bind_index++, $params['sk_prk2']); }

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. LOGIKA EXPORT CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Data_Booking_GABUNGAN_'.date('Ymd_His').'.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Penambahan kolom jenis_bayar di header CSV
    fputcsv($output, ['phone', 'name', 'norm', 'jenis_bayar', 'tgl_rencana_periksa', 'dokter', 'poli', 'no_surat_kontrol', 'sumber_data']);
    
    foreach ($rows as $row) {
        $phone = trim($row['no_tlp']);
        
        if (substr($phone, 0, 2) === '08') {
            $phone = '628' . substr($phone, 2);
        } elseif (substr($phone, 0, 1) === '8') {
            $phone = '628' . substr($phone, 1);
        }

        // Penambahan data png_jawab di baris CSV
        fputcsv($output, [
            $phone,
            $row['nm_pasien'],
            $row['no_rkm_medis'],
            $row['png_jawab'],
            $row['tgl_rencana_periksa'],
            $row['nm_dokter'],
            $row['nm_poli'],
            $row['no_surat_kontrol'],
            $row['sumber_data']
        ]);
    }
    fclose($output);
    exit;
}

// STATISTIK UNTUK TAMPILAN
$jml_tampil  = count($rows);
$jml_hadir   = 0;
$jml_booking = 0;
$jml_skdp    = 0;

foreach($rows as $r) {
    if(!empty($r['hadir'])) $jml_hadir++;
    if($r['sumber_data'] === 'Booking Registrasi') $jml_booking++;
    if($r['sumber_data'] === 'Surat Kontrol BPJS') $jml_skdp++;
}
$jml_tidak = $jml_tampil - $jml_hadir;
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Data Booking  RS Kartika Husada Jatiasih </title>
<style>
    body{font-family:Arial, sans-serif;font-size:12px;background:#f4f6f8;margin:0;padding:20px}
    h2{text-align:center;margin:2px;color:#0a7c3b;text-transform: uppercase; letter-spacing: 1px;}
    .container {background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);}
    
    .summary-container { display: flex; gap: 10px; margin-bottom: 15px; }
    .stat-box { flex: 1; padding: 10px; border-radius: 5px; border: 1px solid #ddd; text-align: center; background: #fff; }
    .stat-box small { font-size: 10px; color: #777; font-weight: bold; text-transform: uppercase; }
    .stat-box div { font-size: 18px; font-weight: bold; color: #333; }
    .box-total { border-left: 5px solid #0a7c3b; }
    .box-hadir { border-left: 5px solid #28a745; }
    .box-tidak { border-left: 5px solid #dc3545; }
    .box-sumber { border-left: 5px solid #0d6efd; }

    table{border-collapse:collapse;width:100%;background:#fff; margin-bottom: 10px;}
    th,td{border:1px solid #999;padding:6px;text-align:center}
    th{background:#0a7c3b;color:#fff;font-weight: bold;text-transform: uppercase; font-size: 11px; position: sticky; top: 0;}
    
    .hadir{background:#c6efce;color:#006100;font-weight:bold}
    .tidak-hadir{background:#ffc7ce;color:#9c0006;font-weight:bold}
    .rajal{color: #0d6efd; font-weight: bold; background: #e7f1ff;} 
    .ranap{color: #fd7e14; font-weight: bold; background: #fff3e0;} 
    
    .badge-booking { background: #e2e3e5; color: #383d41; padding: 3px 6px; border-radius: 3px; font-weight: bold; }
    .badge-skdp { background: #cff4fc; color: #055160; padding: 3px 6px; border-radius: 3px; font-weight: bold; }

    .scroll{max-height:500px;overflow:auto;margin-top:10px; border: 1px solid #ccc;}
    
    form{text-align:left;margin:15px 0;background: #e9ecef; padding: 15px; border-radius: 5px; border: 1px solid #ddd;}
    .filter-group { display: inline-block; margin-right: 15px; margin-bottom: 10px; }
    label { font-weight: bold; display: block; margin-bottom: 4px; color: #444; }
    input[type="date"], select { padding: 5px; border: 1px solid #ccc; border-radius: 4px; background: #fff;}
    
    .btn-submit{background: #0a7c3b; color: white; border: none; padding: 7px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;}
    .btn-export{background: #217346; color: white; border: none; padding: 7px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block;}
    .btn-reset{background: #6c757d; color: white; text-decoration: none; padding: 7px 15px; border-radius: 4px; font-weight: bold;}
    
    .footer{text-align:center;font-size:11px;color:#777;margin-top:20px; border-top: 1px solid #eee; padding-top: 10px;}
</style>
</head>
<body>

<div class="container">
    <h2>RS Kartika Husada Jatiasih</h2>
    <h2>Dashboard Monitoring Booking</h2>

    <form method="get" action="">
        <div class="filter-group">
            <label>Periode Booking / Terbit Surat</label>
            <input type="date" name="tgl_booking_awal" value="<?=$tgl_booking_awal?>"> s/d 
            <input type="date" name="tgl_booking_akhir" value="<?=$tgl_booking_akhir?>">
        </div>

        <div class="filter-group">
            <label>Periode Rencana Periksa</label>
            <input type="date" name="tgl_periksa_awal" value="<?=$tgl_periksa_awal?>"> s/d 
            <input type="date" name="tgl_periksa_akhir" value="<?=$tgl_periksa_akhir?>">
        </div>

        <div class="filter-group">
            <label>Sumber Data</label>
            <select name="filter_sumber">
                <option value=""> Semua Sumber </option>
                <option value="booking" <?=$filter_sumber=='booking'?'selected':''?>>Booking Registrasi</option>
                <option value="skdp" <?=$filter_sumber=='skdp'?'selected':''?>>Surat Kontrol BPJS</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Asal Pasien</label>
            <select name="filter_asal">
                <option value=""> Semua </option>
                <option value="rajal" <?=$filter_asal=='rajal'?'selected':''?>>RAJAL </option>
                <option value="ranap" <?=$filter_asal=='ranap'?'selected':''?>>RANAP </option>
            </select>
        </div>

        <div class="filter-group">
            <label>Status Hadir</label>
            <select name="filter_hadir">
                <option value=""> Semua </option>
                <option value="hadir" <?=$filter_hadir=='hadir'?'selected':''?>>HADIR</option>
                <option value="tidak" <?=$filter_hadir=='tidak'?'selected':''?>>TIDAK HADIR</option>
            </select>
        </div>

        <div class="filter-group" style="display: block;">
            <button type="submit" class="btn-submit">Tampilkan Data</button>
            <button type="submit" name="action" value="export_csv" class="btn-export">Export CSV (WA List)</button>
            <a href="?" class="btn-reset">Reset Filter</a>
        </div>
    </form>

    <div class="summary-container">
        <div class="stat-box box-total">
            <small>Total Pasien </small>
            <div><?= number_format($jml_tampil, 0, ',', '.') ?></div>
        </div>
        <div class="stat-box box-sumber">
            <small>Booking / Surat Kontrol</small>
            <div><?= $jml_booking ?> / <?= $jml_skdp ?></div>
        </div>
        <div class="stat-box box-hadir">
            <small>Total Hadir</small>
            <div style="color: #28a745;"><?= number_format($jml_hadir, 0, ',', '.') ?></div>
        </div>
        <div class="stat-box box-tidak">
            <small>Total Tidak Hadir</small>
            <div style="color: #dc3545;"><?= number_format($jml_tidak, 0, ',', '.') ?></div>
        </div>
    </div>

    <div class="scroll">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Sumber Data</th>
                    <th>Tgl. Transaksi</th>
                    <th>No. RM</th>
                    <th>Nama Pasien</th>
                    <th>No. Tlp</th>
                    <th>Jenis Bayar</th>
                    <th>Asal Pasien</th>
                    <th>Tgl. Rencana Periksa</th>
                    <th>Dokter Spesialis</th>
                    <th>Poliklinik</th>
                    <th>No. Surat Kontrol</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                if ($jml_tampil > 0) {
                    foreach ($rows as $row) {
                        $is_hadir = !empty($row['hadir']);
                        $status_text = $is_hadir ? 'HADIR' : 'TIDAK HADIR';
                        $class_hadir = $is_hadir ? 'hadir' : 'tidak-hadir';
                        $asal_pasien = ($row['cek_rajal'] > 0) ? 'RAJAL' : 'RANAP';
                        $class_asal = ($row['cek_rajal'] > 0) ? 'rajal' : 'ranap';
                        $badge_sumber = ($row['sumber_data'] === 'Booking Registrasi') ? 'badge-booking' : 'badge-skdp';

                        echo "<tr>
                            <td>$no</td>
                            <td><span class='$badge_sumber'>{$row['sumber_data']}</span></td>
                            <td>{$row['tgl_transaksi']}</td>
                            <td><b>{$row['no_rkm_medis']}</b></td>
                            <td style='text-align:left'>{$row['nm_pasien']}</td>
                            <td>{$row['no_tlp']}</td>
                            <td style='text-align:left'>{$row['png_jawab']}</td>
                            <td class='$class_asal'>$asal_pasien</td>
                            <td><b>{$row['tgl_rencana_periksa']}</b></td>
                            <td style='text-align:left'>{$row['nm_dokter']}</td>
                            <td>{$row['nm_poli']}</td>
                            <td><b>{$row['no_surat_kontrol']}</b></td>
                            <td class='$class_hadir'>$status_text</td>
                        </tr>";
                        $no++;
                    }
                } else {
                    echo "<tr><td colspan='13' style='padding:30px; color:#999;'>Data tidak ditemukan.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        © 2026 VIDI MEDIKA KONSULTAN IT. All Rights Reserved. <br>
        <i>Smart IT For Health</i>
    </div>
</div>

</body>
</html>
