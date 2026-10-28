<?php
/**
 * -----------------------------------------------------------------------------
 * DASHBOARD MONITORING BOOKING REGISTRASI & INTEGRASI SAUNG WA API
 * RS ASSALAM / RS KARTIKA HUSADA - SMART IT FOR HEALTH
 * -----------------------------------------------------------------------------
 */

// 1. KONEKSI DATABASE (PDO)
$host = "192.168.10.29";
$db   = "rskhj";
$user = "root";
$pass = "1nternet";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div style='padding:20px; color:red; font-family:sans-serif;'><h3>Koneksi Database Gagal:</h3> " . htmlspecialchars($e->getMessage()) . "</div>");
}

// 2. KONFIGURASI API SAUNG WA
$saungwa_app_key  = "37fef3fd-fdda-46fe-9759-04ac995d3d5f"; 
$saungwa_auth_key = "EWhw07nquml0NYpWifuoDdzfNDPY1L3r8cFVILNAtX38dNvrUO";

// FUNGSI FORMAT TANGGAL INDONESIA
function bookingTglIndo($tanggal) {
    if (empty($tanggal) || $tanggal == '0000-00-00') return "-";
    $hari = array(1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu');
    $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
    
    $split = explode('-', $tanggal);
    if (count($split) !== 3) return $tanggal;
    
    $tgl_indo = $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
    $num_hari = date('N', strtotime($tanggal));
    return $hari[$num_hari] . ", " . $tgl_indo;
}

// FUNGSI SANITASI NOMOR WHATSAPP
function bookingFormatNomorWA($nomor) {
    $nomor = preg_replace('/[^0-9]/', '', (string)$nomor);
    if (empty($nomor)) return '';

    if (substr($nomor, 0, 2) === '08') {
        $nomor = '628' . substr($nomor, 2);
    } elseif (substr($nomor, 0, 1) === '8') {
        $nomor = '628' . substr($nomor, 1);
    }

    return $nomor;
}

// FUNGSI EKSEKUSI API SAUNG WA
function bookingKirimPesanSaungWA($appKey, $authKey, $nomorRaw, $pesan) {
    $nomor = bookingFormatNomorWA($nomorRaw);

    if (empty($nomor) || strlen($nomor) < 10 || substr($nomor, 0, 3) !== '628') {
        return [
            'status' => false, 
            'message' => "Format nomor telepon tidak valid: '" . htmlspecialchars($nomorRaw) . "'."
        ];
    }

    $payload = array(
        'appkey'  => trim($appKey),
        'authkey' => trim($authKey),
        'to'      => trim($nomor),
        'message' => $pesan
    );

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://app.saungwa.com/api/create-message',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return ['status' => false, 'message' => "cURL Error: " . $err];
    }

    $resData = json_decode($response, true);

    if (isset($resData['status']) && ($resData['status'] === true || $resData['status'] === 'true' || $resData['status'] == 200)) {
        return ['status' => true, 'response' => $response];
    } else {
        $msg = isset($resData['message']) ? $resData['message'] : (isset($resData['msg']) ? $resData['msg'] : $response);
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        return ['status' => false, 'message' => $msg];
    }
}

// 3. AMBIL TEMPLATE PESAN WA DARI DATABASE
$template_wa_db = "";
try {
    $stmt_wa = $conn->prepare("SELECT pesan_booking FROM set_wa LIMIT 1");
    $stmt_wa->execute();
    $res_wa = $stmt_wa->fetch();
    if ($res_wa && !empty($res_wa['pesan_booking'])) {
        $template_wa_db = $res_wa['pesan_booking'];
    }
} catch (Exception $e) {
    $template_wa_db = "";
}

if (empty($template_wa_db)) {
    $template_wa_db = "Assalamualaikum Bapak/Ibu,\n\nBagaimana kabarnya hari ini?\nSemoga Bapak/Ibu selalu dalam keadaan sehat dan dalam lindungan Allah SWT.\n\nKami informasikan kembali jadwal kontrol Anda di RS Kartika Husada Jatiasih sebagai berikut:\n\n🧑‍🦼 Nama: {name}\n📑 No. RM: {norm}\n📅 Tgl Kontrol: {tanggal}\n👨‍⚕️ Dokter: {dokter}\n🏥 Poliklinik: {poli}\n\nMohon konfirmasi kehadiran Anda dengan membalas pesan ini:\n👉 *AKAN KONTROL*\n\n--- *INFO PENDAFTARAN* ---\n1️⃣ *Asuransi/Umum*\nKirimkan foto surat kontrol melalui WhatsApp ini.\n\n2️⃣ *BPJS Kesehatan*\nWajib mendaftar melalui Aplikasi Mobile JKN pada link berikut:\n👉 https://play.google.com/store/apps/details?id=app.bpjs.mobile&hl=id\n\nTerima kasih atas perhatian dan kepercayaan Anda kepada RS Kartika Husada Jatiasih.\n\nWassalamualaikum warahmatullahi wabarakatuh.\n\n*RS Kartika Husada Jatiasih*\n*SEHAT*\n*Sinergi Empati Hangat Amanah Tanggap*";
}

// 4. LOGIKA FILTER DASHBOARD
$tgl_booking_awal  = $_GET['tgl_booking_awal'] ?? date('Y-m-01');
$tgl_booking_akhir = $_GET['tgl_booking_akhir'] ?? date('Y-m-d');
$tgl_periksa_awal  = $_GET['tgl_periksa_awal'] ?? '';
$tgl_periksa_akhir = $_GET['tgl_periksa_akhir'] ?? '';
$filter_asal       = $_GET['filter_asal'] ?? ''; 
$filter_hadir      = $_GET['filter_hadir'] ?? ''; 

$conditions = [];
$params = [];
$conditions[] = "NOT EXISTS (
    SELECT 1 FROM reg_periksa rp_batal
    WHERE rp_batal.no_rkm_medis = b.no_rkm_medis
      AND rp_batal.tgl_registrasi = COALESCE(b.tanggal_periksa, sk_sub.tgl_rencana_max, b.tanggal_booking)
      AND rp_batal.stts = 'Batal'
)";

if (!empty($tgl_booking_awal) && !empty($tgl_booking_akhir)) {
    $conditions[] = "b.tanggal_booking BETWEEN ? AND ?";
    $params[] = $tgl_booking_awal;
    $params[] = $tgl_booking_akhir;
}

if (!empty($tgl_periksa_awal) && !empty($tgl_periksa_akhir)) {
    $conditions[] = "COALESCE(b.tanggal_periksa, sk_sub.tgl_rencana_max) BETWEEN ? AND ?";
    $params[] = $tgl_periksa_awal;
    $params[] = $tgl_periksa_akhir;
}

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

$where_sql  = count($conditions) > 0 ? " WHERE " . implode(" AND ", $conditions) : "";
$having_sql = count($having) > 0 ? " HAVING " . implode(" AND ", $having) : "";

// 5. QUERY UTAMA FIX RELASI SURAT KONTROL BPJS
$sql = "SELECT 
            b.tanggal_booking,
            b.jam_booking,
            b.no_rkm_medis,
            b.kd_dokter,
            b.kd_poli,
            b.kd_pj,
            COALESCE(b.tanggal_periksa, sk_sub.tgl_rencana_max) AS tgl_periksa_utama,
            p.nm_pasien, 
            COALESCE(p.no_tlp, '') AS no_tlp, 
            d.nm_dokter, 
            pl.nm_poli, 
            pj.png_jawab, 
            rp.no_rkm_medis AS hadir,
            (SELECT COUNT(*) FROM reg_periksa WHERE no_rkm_medis = b.no_rkm_medis AND tgl_registrasi = b.tanggal_booking) AS cek_rajal
        FROM booking_registrasi b
        INNER JOIN pasien p ON b.no_rkm_medis = p.no_rkm_medis 
        LEFT JOIN (
            SELECT 
                sep.nomr, 
                MAX(sk.tgl_rencana) AS tgl_rencana_max
            FROM bridging_surat_kontrol_bpjs sk
            INNER JOIN bridging_sep sep ON sk.no_sep = sep.no_sep
            GROUP BY sep.nomr
        ) sk_sub ON b.no_rkm_medis = sk_sub.nomr
        INNER JOIN dokter d ON b.kd_dokter = d.kd_dokter 
        INNER JOIN poliklinik pl ON b.kd_poli = pl.kd_poli
        INNER JOIN penjab pj ON b.kd_pj = pj.kd_pj
        LEFT JOIN reg_periksa rp ON b.no_rkm_medis = rp.no_rkm_medis 
            AND COALESCE(b.tanggal_periksa, sk_sub.tgl_rencana_max) = rp.tgl_registrasi
        $where_sql
        GROUP BY 
            b.no_rkm_medis, 
            b.tanggal_booking, 
            b.jam_booking, 
            b.kd_dokter, 
            b.kd_poli,
            b.kd_pj,
            b.tanggal_periksa,
            sk_sub.tgl_rencana_max,
            p.nm_pasien,
            p.no_tlp,
            d.nm_dokter,
            pl.nm_poli,
            pj.png_jawab,
            rp.no_rkm_medis
        $having_sql
        ORDER BY b.tanggal_booking DESC, b.jam_booking DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// 6. ENDPOINT AJAX BROADCAST VIA JS QUEUE
if (isset($_GET['action']) && $_GET['action'] === 'ajax_send_wa') {
    header('Content-Type: application/json');
    $norm_target = $_GET['no_rkm_medis'] ?? '';
    $tgl_target  = $_GET['tanggal_periksa'] ?? '';

    $stmt_single = $conn->prepare("
        SELECT b.no_rkm_medis, p.nm_pasien, COALESCE(p.no_tlp, '') AS no_tlp, d.nm_dokter, pl.nm_poli
        FROM booking_registrasi b
        INNER JOIN pasien p ON b.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON b.kd_dokter = d.kd_dokter
        INNER JOIN poliklinik pl ON b.kd_poli = pl.kd_poli
        WHERE b.no_rkm_medis = ? LIMIT 1
    ");
    $stmt_single->execute([$norm_target]);
    $row_target = $stmt_single->fetch();

    if ($row_target) {
        $tgl_periksa_formatted = bookingTglIndo($tgl_target);
        $search_tags = ['{name}', '{norm}', '{tanggal}', '{dokter}', '{poli}'];
        $replace_val = [$row_target['nm_pasien'], $row_target['no_rkm_medis'], $tgl_periksa_formatted, $row_target['nm_dokter'], $row_target['nm_poli']];
        $msg_wa = str_replace($search_tags, $replace_val, $template_wa_db);

        $api_res = bookingKirimPesanSaungWA($saungwa_app_key, $saungwa_auth_key, $row_target['no_tlp'], $msg_wa);
        echo json_encode($api_res);
        exit;
    }

    echo json_encode(['status' => false, 'message' => 'Data pasien tidak ditemukan']);
    exit;
}

// 7. ACTION: KIRIM WA DIRECT VIA HTTP GET
$notif_msg = "";
if (isset($_GET['action']) && $_GET['action'] === 'send_api_wa') {
    $norm_target = $_GET['no_rkm_medis'] ?? '';
    $tgl_target  = $_GET['tanggal_periksa'] ?? '';

    $stmt_single = $conn->prepare("
        SELECT b.no_rkm_medis, p.nm_pasien, COALESCE(p.no_tlp, '') AS no_tlp, d.nm_dokter, pl.nm_poli
        FROM booking_registrasi b
        INNER JOIN pasien p ON b.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON b.kd_dokter = d.kd_dokter
        INNER JOIN poliklinik pl ON b.kd_poli = pl.kd_poli
        WHERE b.no_rkm_medis = ? LIMIT 1
    ");
    $stmt_single->execute([$norm_target]);
    $row_target = $stmt_single->fetch();

    if ($row_target) {
        $tgl_periksa_formatted = bookingTglIndo($tgl_target);
        $search_tags = ['{name}', '{norm}', '{tanggal}', '{dokter}', '{poli}'];
        $replace_val = [$row_target['nm_pasien'], $row_target['no_rkm_medis'], $tgl_periksa_formatted, $row_target['nm_dokter'], $row_target['nm_poli']];
        $msg_wa = str_replace($search_tags, $replace_val, $template_wa_db);

        $api_res = bookingKirimPesanSaungWA($saungwa_app_key, $saungwa_auth_key, $row_target['no_tlp'], $msg_wa);
        if ($api_res['status']) {
            $notif_msg = "<div class='alert alert-success'><i class='fa-solid fa-circle-check'></i> Pesan WA berhasil dikirim ke <b>" . htmlspecialchars($row_target['nm_pasien']) . "</b> (" . htmlspecialchars(bookingFormatNomorWA($row_target['no_tlp'])) . ") via API.</div>";
        } else {
            $notif_msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> <b>Gagal Kirim WA:</b> " . htmlspecialchars($api_res['message']) . "</div>";
        }
    }
}

// 8. EXPORT CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Data_SaungWA_'.date('Ymd_His').'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['name', 'number', 'tanggal', 'dokter', 'poli']);
    foreach ($rows as $row) {
        fputcsv($output, [$row['nm_pasien'], bookingFormatNomorWA($row['no_tlp']), bookingTglIndo($row['tgl_periksa_utama']), $row['nm_dokter'], $row['nm_poli']], ",", '"'); 
    }
    fclose($output);
    exit;
}

$jml_tampil = count($rows);
$jml_hadir  = 0;
foreach($rows as $r) {
    if(!empty($r['hadir'])) $jml_hadir++;
}
$jml_tidak = $jml_tampil - $jml_hadir;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Booking Registrasi Pasien</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { background: #ffffff; padding: 24px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); max-width: 1400px; margin: 0 auto; }
        h2 { text-align: center; margin: 4px 0; color: #0a7c3b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
        h4 { text-align: center; margin: 0 0 20px 0; color: #555; font-weight: 400; font-size: 14px; }
        .page-tabs { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin: 0 0 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 12px; }
        .page-tab { display: inline-flex; align-items: center; gap: 7px; padding: 9px 15px; border: 1px solid #ced4da; border-radius: 6px; color: #495057; background: #f8f9fa; text-decoration: none; font-weight: 600; }
        .page-tab:hover { background: #e9ecef; }
        .page-tab.active { color: #fff; background: #0a7c3b; border-color: #0a7c3b; }
        
        .summary-container { display: flex; gap: 15px; margin-bottom: 20px; }
        .stat-box { flex: 1; padding: 15px; border-radius: 8px; border: 1px solid #e1e4e8; text-align: center; background: #fafbfc; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .stat-box small { font-size: 11px; color: #6a737d; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-box div { font-size: 22px; font-weight: 700; margin-top: 5px; color: #24292e; }
        .box-total { border-left: 5px solid #0a7c3b; }
        .box-hadir { border-left: 5px solid #2e7d32; background: #f1f8e9; }
        .box-hadir div { color: #2e7d32; }
        .box-tidak { border-left: 5px solid #c62828; background: #ffebee; }
        .box-tidak div { color: #c62828; }
        
        form { background: #f8f9fa; padding: 18px; border-radius: 8px; border: 1px solid #e9ecef; margin-bottom: 20px; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-weight: 600; font-size: 11px; text-transform: uppercase; color: #495057; }
        input[type="date"], select { padding: 7px 10px; border: 1px solid #ced4da; border-radius: 5px; background: #fff; font-size: 12px; outline: none; }
        
        .btn-group { display: flex; gap: 8px; align-items: flex-end; margin-left: auto; flex-wrap: wrap; }
        .btn { padding: 8px 16px; border-radius: 5px; font-weight: 600; font-size: 12px; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-submit { background: #0a7c3b; color: white; }
        .btn-submit:hover { background: #08632f; }
        .btn-bulk { background: #d97706; color: white; }
        .btn-bulk:hover { background: #b45309; }
        .btn-export { background: #217346; color: white; }
        .btn-export:hover { background: #195835; }
        .btn-reset { background: #6c757d; color: white; }
        .btn-reset:hover { background: #5a6268; }
        
        .table-responsive { max-height: 550px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 6px; }
        table { border-collapse: collapse; width: 100%; background: #fff; }
        th, td { border: 1px solid #e9ecef; padding: 9px 12px; text-align: center; }
        th { background: #0a7c3b; color: #fff; font-weight: 600; text-transform: uppercase; font-size: 11px; position: sticky; top: 0; z-index: 10; letter-spacing: 0.3px; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        tr:hover { background-color: #f1f3f5; }
        
        .hadir { background: #d4edda; color: #155724; font-weight: 700; border-radius: 4px; padding: 4px 8px; }
        .tidak-hadir { background: #f8d7da; color: #721c24; font-weight: 700; border-radius: 4px; padding: 4px 8px; }
        .rajal { color: #0d6efd; font-weight: 700; background: #e7f1ff; border-radius: 4px; padding: 3px 6px; } 
        .ranap { color: #fd7e14; font-weight: 700; background: #fff3e0; border-radius: 4px; padding: 3px 6px; } 
        
        .btn-wa { background: #25d366; color: white; padding: 5px 9px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px; }
        
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .cell-nowrap { white-space: nowrap; }
        .badge-hadir { font-size: 11px; padding: 4px 8px; border-radius: 4px; font-weight: bold; background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; display: inline-block; }
        .badge-absent { font-size: 11px; padding: 4px 8px; border-radius: 4px; font-weight: bold; background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; display: inline-block; }
        .badge-asal-rajal { color: #0d6efd; font-weight: 700; background: #e7f1ff; border-radius: 4px; padding: 3px 6px; display: inline-block; }
        .badge-asal-ranap { color: #fd7e14; font-weight: 700; background: #fff3e0; border-radius: 4px; padding: 3px 6px; display: inline-block; }

        .footer { text-align: center; font-size: 11px; color: #6c757d; margin-top: 25px; border-top: 1px solid #dee2e6; padding-top: 15px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Monitoring Booking Registrasi Pasien</h2>
    <h4>TerIntegrasi dengan SIMRS Khanza</h4>

    <nav class="page-tabs" aria-label="Menu booking pasien">
        <a class="page-tab" href="bookingkontrol.php"><i class="fa-solid fa-id-card"></i> Pasien Booking Kontrol BPJS</a>
        <span class="page-tab active" aria-current="page"><i class="fa-solid fa-user"></i> Pasien Booking Kontrol Non-SKDP</span>
        <a class="page-tab" href="bookingkontrol.php?view=monthly"><i class="fa-solid fa-calendar-days"></i> Booking 1 Bulan</a>
    </nav>

    <?= $notif_msg ?>

    <div class="summary-container">
        <div class="stat-box box-total"><small>Total Pasien Booking</small><div><?= number_format($jml_tampil, 0, ',', '.') ?></div></div>
        <div class="stat-box box-hadir"><small>Pasien Hadir</small><div><?= number_format($jml_hadir, 0, ',', '.') ?></div></div>
        <div class="stat-box box-tidak"><small>Pasien Tidak Hadir</small><div><?= number_format($jml_tidak, 0, ',', '.') ?></div></div>
    </div>

    <form method="get" action="">
        <?php if (($_GET['view'] ?? '') === 'non_skdp'): ?>
            <input type="hidden" name="view" value="non_skdp">
        <?php endif; ?>
        <div class="filter-row">
            <div class="filter-group">
                <label>Periode Booking</label>
                <div>
                    <input type="date" name="tgl_booking_awal" value="<?= htmlspecialchars($tgl_booking_awal) ?>"> s/d 
                    <input type="date" name="tgl_booking_akhir" value="<?= htmlspecialchars($tgl_booking_akhir) ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Periode Periksa</label>
                <div>
                    <input type="date" name="tgl_periksa_awal" value="<?= htmlspecialchars($tgl_periksa_awal) ?>"> s/d 
                    <input type="date" name="tgl_periksa_akhir" value="<?= htmlspecialchars($tgl_periksa_akhir) ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Asal Pasien</label>
                <select name="filter_asal">
                    <option value="">-- Semua --</option>
                    <option value="rajal" <?= $filter_asal === 'rajal' ? 'selected' : '' ?>>RAJAL</option>
                    <option value="ranap" <?= $filter_asal === 'ranap' ? 'selected' : '' ?>>RANAP</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Status Hadir</label>
                <select name="filter_hadir">
                    <option value="">-- Semua --</option>
                    <option value="hadir" <?= $filter_hadir === 'hadir' ? 'selected' : '' ?>>HADIR</option>
                    <option value="tidak" <?= $filter_hadir === 'tidak' ? 'selected' : '' ?>>TIDAK HADIR</option>
                </select>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-submit"><i class="fa-solid fa-filter"></i> Tampilkan</button>
                <button type="submit" name="action" value="export_csv" class="btn btn-export"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                <a href="<?= (($_GET['view'] ?? '') === 'non_skdp') ? 'bookingkontrol.php?view=non_skdp' : '?' ?>" class="btn btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">No</th>
                    <th>Tgl. Booking</th>
                    <th>No. RM</th>
                    <th>Nama Pasien</th>
                    <th>No. Tlp / HP</th>
                    <th>Asal Pasien</th>
                    <th>Tgl. Periksa</th>
                    <th>Dokter Spesialis</th>
                    <th>Poliklinik</th>
                    <th>Jenis Bayar</th>
                    <th>Status</th>
                    <th style="width: 170px;">Aksi WhatsApp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="12" style="padding: 20px; color: #777;">Data booking registrasi tidak ditemukan untuk periode filter ini.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    $no = 1;
                    $query_params = $_GET;
                    unset($query_params['action'], $query_params['no_rkm_medis'], $query_params['tanggal_periksa']);

                    foreach ($rows as $row):
                        $is_hadir = !empty($row['hadir']);
                        $status_text = $is_hadir ? 'HADIR' : 'TIDAK HADIR';
                        $class_hadir = $is_hadir ? 'hadir' : 'tidak-hadir';
                        $asal_pasien = ($row['cek_rajal'] > 0) ? 'RAJAL' : 'RANAP';
                        $class_asal = ($row['cek_rajal'] > 0) ? 'rajal' : 'ranap';

                        $phone_clean = bookingFormatNomorWA($row['no_tlp']);
                        $tgl_periksa_formatted = bookingTglIndo($row['tgl_periksa_utama']);

                        $search_tags = ['{name}', '{norm}', '{tanggal}', '{dokter}', '{poli}'];
                        $replace_val = [$row['nm_pasien'], $row['no_rkm_medis'], $tgl_periksa_formatted, $row['nm_dokter'], $row['nm_poli']];
                        $msg_wa = str_replace($search_tags, $replace_val, $template_wa_db);

                        $link_wa_manual = "https://api.whatsapp.com/send?phone=" . $phone_clean . "&text=" . urlencode($msg_wa);

                    ?>
                    <tr id="row-<?= htmlspecialchars($row['no_rkm_medis']) ?>-<?= htmlspecialchars($row['tgl_periksa_utama']) ?>">
                        <td><?= $no++ ?></td>
                        <td class="cell-nowrap"><?= htmlspecialchars(bookingTglIndo($row['tanggal_booking'])) ?></td>
                        <td><b><?= htmlspecialchars($row['no_rkm_medis']) ?></b></td>
                        <td class="cell-nowrap" style="text-align:left; font-weight: 600;"><?= htmlspecialchars($row['nm_pasien']) ?></td>
                        <td><?= htmlspecialchars($row['no_tlp']) ?></td>
                        <td><span class="<?= $row['cek_rajal'] > 0 ? 'badge-asal-rajal' : 'badge-asal-ranap' ?>"><?= $asal_pasien ?></span></td>
                        <td class="cell-nowrap"><b><?= htmlspecialchars($tgl_periksa_formatted) ?></b></td>
                        <td class="cell-nowrap" style="text-align:left"><?= htmlspecialchars($row['nm_dokter']) ?></td>
                        <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                        <td><?= htmlspecialchars($row['png_jawab']) ?></td>
                        <td><span class="<?= $is_hadir ? 'badge-hadir' : 'badge-absent' ?>"><?= $status_text ?></span></td>
                        <td style="white-space: nowrap;">
                            <a href="<?= htmlspecialchars($link_wa_manual) ?>" target="_blank" class="btn-wa" title="Buka dan Kirim Manual di Web WhatsApp">
                                <i class="fa-brands fa-whatsapp"></i> Manual
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        © 2026 PT VIDI MEDIKA PRATAMA. All Rights Reserved. <br>
        <i>Smart IT for Health</i>
    </div>
</div>

<script>
const queueData = [
    <?php foreach ($rows as $r): ?>
    {
        norm: "<?= addslashes($r['no_rkm_medis']) ?>",
        tgl: "<?= addslashes($r['tgl_periksa_utama']) ?>",
        nama: "<?= addslashes($r['nm_pasien']) ?>"
    },
    <?php endforeach; ?>
];

let currentIndex = 0;
const delayMs = 5000;

function startBulkSend() {
    if (queueData.length === 0) {
        alert("Tidak ada data pasien yang siap dikirim!");
        return;
    }

    if (!confirm(`Apakah Anda yakin ingin mengirim WA ke ${queueData.length} pasien secara berurutan dengan jeda 5 Detik per nomor?`)) {
        return;
    }

    document.getElementById('btnSendAll').disabled = true;
    document.getElementById('progressContainer').style.display = 'block';
    currentIndex = 0;
    processNextQueue();
}

function processNextQueue() {
    if (currentIndex >= queueData.length) {
        document.getElementById('progressStatus').innerHTML = "<i class='fa-solid fa-circle-check' style='color:green;'></i> Pengiriman selesai!";
        document.getElementById('btnSendAll').disabled = false;
        alert("Semua pesan dalam antrean berhasil diproses.");
        return;
    }

    const currentItem = queueData[currentIndex];
    const total = queueData.length;
    const countDisplay = currentIndex + 1;

    document.getElementById('progressStatus').innerHTML = `<i class="fa-solid fa-paper-plane fa-spin"></i> Mengirim ke: <b>${currentItem.nama}</b> (${countDisplay}/${total})...`;
    document.getElementById('progressText').innerText = `${countDisplay} / ${total}`;
    
    const percent = Math.round((countDisplay / total) * 100);
    document.getElementById('progressBarFill').style.width = `${percent}%`;

    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('action', 'ajax_send_wa');
    urlParams.set('no_rkm_medis', currentItem.norm);
    urlParams.set('tanggal_periksa', currentItem.tgl);

    fetch(`?${urlParams.toString()}`)
        .then(response => response.json())
        .then(data => {
            const logBox = document.getElementById('progressLog');
            const timeNow = new Date().toLocaleTimeString('id-ID');
            if (data.status) {
                logBox.innerHTML = `<div>[${timeNow}] <span style="color:green;">[OK]</span> Pesan terkirim ke ${currentItem.nama} (${currentItem.norm})</div>` + logBox.innerHTML;
            } else {
                logBox.innerHTML = `<div>[${timeNow}] <span style="color:red;">[GAGAL]</span> ${currentItem.nama}: ${data.message}</div>` + logBox.innerHTML;
            }
        })
        .catch(err => {
            const logBox = document.getElementById('progressLog');
            const timeNow = new Date().toLocaleTimeString('id-ID');
            logBox.innerHTML = `<div>[${timeNow}] <span style="color:red;">[ERROR]</span> Koneksi terputus saat mengirim ke ${currentItem.nama}</div>` + logBox.innerHTML;
        })
        .finally(() => {
            currentIndex++;
            if (currentIndex < queueData.length) {
                setTimeout(processNextQueue, delayMs);
            } else {
                processNextQueue();
            }
        });
}
</script>

</body>
</html>
