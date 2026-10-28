<?php
// Tampilkan dashboard booking non-SKDP melalui halaman ini saat tab dipilih.
if (($_GET['view'] ?? '') === 'non_skdp') {
    require __DIR__ . '/booking.php';
    exit;
}
if (($_GET['view'] ?? '') === 'monthly') {
    require __DIR__ . '/bookingbulanan.php';
    exit;
}

/**
 * -----------------------------------------------------------------------------
 * DASHBOARD MONITORING SURAT KONTROL BPJS & INTEGRASI SAUNG WA API
 * PT VIDI MEDIKA PRATAMA - SMART IT FOR HEALTH
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
function tglIndo($tanggal) {
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
function formatNomorWA($nomor) {
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
function kirimPesanSaungWA($appKey, $authKey, $nomorRaw, $pesan) {
    $nomor = formatNomorWA($nomorRaw);

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
    $template_wa_db = "Assalamualaikum Bapak/Ibu,\n\nBagaimana kabarnya hari ini?\nSemoga Bapak/Ibu selalu dalam keadaan sehat dan dalam lindungan Allah SWT.\n\nKami informasikan kembali jadwal kontrol Anda berdasarkan Surat Kontrol BPJS sebagai berikut:\n\n🧑‍🦼 Nama: {name}\n📑 No. RM: {norm}\n📅 Tgl Rencana Kontrol: {tanggal}\n👨‍⚕️ Dokter: {dokter}\n🏥 Poliklinik: {poli}\n\nMohon konfirmasi kehadiran Anda dengan membalas pesan ini:\n👉 *AKAN KONTROL*\n\n--- *INFO PENDAFTARAN* ---\nBPJS Kesehatan wajib mendaftar melalui Aplikasi Mobile JKN pada link berikut:\n👉 https://play.google.com/store/apps/details?id=app.bpjs.mobile&hl=id\n\nTerima kasih atas perhatian dan kepercayaan Anda.\n\nWassalamualaikum warahmatullahi wabarakatuh.";
}

// 4. LOGIKA FILTER DASHBOARD SURAT KONTROL
$tgl_awal        = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir       = $_GET['tgl_akhir'] ?? date('Y-m-d');
$tgl_terbit_awal = $_GET['tgl_terbit_awal'] ?? '';
$tgl_terbit_akhir = $_GET['tgl_terbit_akhir'] ?? '';
$kd_poli         = $_GET['kd_poli'] ?? '';
$status_kehadiran_filter = $_GET['status_kehadiran'] ?? '';
$keyword         = trim($_GET['keyword'] ?? '');

$conditions = [];
$having_conditions = [];
$params = [];
$conditions[] = "NOT EXISTS (
    SELECT 1 FROM reg_periksa rp_batal
    WHERE rp_batal.no_rkm_medis = sep.nomr
      AND rp_batal.tgl_registrasi = sk.tgl_rencana
      AND rp_batal.stts = 'Batal'
)";

if (!empty($tgl_awal) && !empty($tgl_akhir)) {
    $conditions[] = "sk.tgl_rencana BETWEEN ? AND ?";
    $params[] = $tgl_awal;
    $params[] = $tgl_akhir;
}

if (!empty($tgl_terbit_awal) && !empty($tgl_terbit_akhir)) {
    $conditions[] = "sk.tgl_surat BETWEEN ? AND ?";
    $params[] = $tgl_terbit_awal;
    $params[] = $tgl_terbit_akhir;
}

if (!empty($kd_poli)) {
    $conditions[] = "sk.kd_poli_bpjs = ?";
    $params[] = $kd_poli;
}

if (!empty($keyword)) {
    $conditions[] = "(sk.no_surat LIKE ? OR sk.no_sep LIKE ? OR sep.nomr LIKE ? OR p.nm_pasien LIKE ? OR sk.nm_dokter_bpjs LIKE ?)";
    $wildcard = "%" . $keyword . "%";
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}

// FILTER STATUS KEHADIRAN (DIINJEKSIKAN PADA CLAUSE HAVING KARENA MENGGUNAKAN ALIAS ALGORITMA IF)
if (!empty($status_kehadiran_filter)) {
    $having_conditions[] = "status_kehadiran = ?";
    $params[] = $status_kehadiran_filter;
}

$where_sql  = count($conditions) > 0 ? " WHERE " . implode(" AND ", $conditions) : "";
$having_sql = count($having_conditions) > 0 ? " HAVING " . implode(" AND ", $having_conditions) : "";

// 5. QUERY UTAMA SURAT KONTROL BPJS & CEK KEHADIRAN DI REG_PERIKSA
$sql = "SELECT 
            sk.no_surat,
            sk.no_sep,
            sk.tgl_surat,
            sk.tgl_rencana,
            sk.kd_dokter_bpjs,
            sk.nm_dokter_bpjs,
            sk.kd_poli_bpjs,
            sk.nm_poli_bpjs,
            COALESCE(sep.nomr, '-') AS no_rkm_medis,
            COALESCE(p.nm_pasien, 'Pasien Tidak Terdaftar') AS nm_pasien,
            COALESCE(p.no_tlp, '') AS no_tlp,
            (SELECT COUNT(*) FROM reg_periksa rp_asal
             WHERE rp_asal.no_rkm_medis = sep.nomr
               AND rp_asal.tgl_registrasi = sk.tgl_rencana) AS cek_rajal,
            rp.no_rawat,
            rp.stts AS status_periksa,
            IF(rp.no_rawat IS NOT NULL AND rp.stts != 'Batal', 'Hadir', 'Tidak Hadir') AS status_kehadiran
        FROM bridging_surat_kontrol_bpjs sk
        LEFT JOIN bridging_sep sep ON sk.no_sep = sep.no_sep
        LEFT JOIN pasien p ON sep.nomr = p.no_rkm_medis
        LEFT JOIN reg_periksa rp ON sep.nomr = rp.no_rkm_medis 
            AND sk.tgl_rencana = rp.tgl_registrasi 
            AND rp.stts != 'Batal'
        $where_sql
        GROUP BY sk.no_surat
        $having_sql
        ORDER BY sk.tgl_rencana DESC, sk.no_surat DESC";

// DROPDOWN FILTER POLIKLINIK
$list_poli = $conn->query("SELECT DISTINCT kd_poli_bpjs, nm_poli_bpjs FROM bridging_surat_kontrol_bpjs WHERE nm_poli_bpjs IS NOT NULL AND nm_poli_bpjs != '' ORDER BY nm_poli_bpjs ASC")->fetchAll();

// 6. ENDPOINT AJAX BROADCAST VIA JS QUEUE
if (isset($_GET['action']) && $_GET['action'] === 'ajax_send_wa') {
    header('Content-Type: application/json');
    $no_surat_target = $_GET['no_surat'] ?? '';

    $stmt_single = $conn->prepare("
        SELECT sk.no_surat, sk.tgl_rencana, sk.nm_dokter_bpjs, sk.nm_poli_bpjs, COALESCE(sep.nomr, '-') AS no_rkm_medis, COALESCE(p.nm_pasien, 'Pasien Tidak Terdaftar') AS nm_pasien, COALESCE(p.no_tlp, '') AS no_tlp
        FROM bridging_surat_kontrol_bpjs sk
        LEFT JOIN bridging_sep sep ON sk.no_sep = sep.no_sep
        LEFT JOIN pasien p ON sep.nomr = p.no_rkm_medis
        WHERE sk.no_surat = ? LIMIT 1
    ");
    $stmt_single->execute([$no_surat_target]);
    $row_target = $stmt_single->fetch();

    if ($row_target) {
        $tgl_periksa_formatted = tglIndo($row_target['tgl_rencana']);
        $search_tags = ['{name}', '{norm}', '{tanggal}', '{dokter}', '{poli}'];
        $replace_val = [$row_target['nm_pasien'], $row_target['no_rkm_medis'], $tgl_periksa_formatted, $row_target['nm_dokter_bpjs'], $row_target['nm_poli_bpjs']];
        $msg_wa = str_replace($search_tags, $replace_val, $template_wa_db);

        $api_res = kirimPesanSaungWA($saungwa_app_key, $saungwa_auth_key, $row_target['no_tlp'], $msg_wa);
        echo json_encode($api_res);
        exit;
    }

    echo json_encode(['status' => false, 'message' => 'Data surat kontrol tidak ditemukan']);
    exit;
}

// 7. ACTION: KIRIM WA DIRECT VIA HTTP GET
$notif_msg = "";
if (isset($_GET['action']) && $_GET['action'] === 'send_api_wa') {
    $no_surat_target = $_GET['no_surat'] ?? '';

    $stmt_single = $conn->prepare("
        SELECT sk.no_surat, sk.tgl_rencana, sk.nm_dokter_bpjs, sk.nm_poli_bpjs, COALESCE(sep.nomr, '-') AS no_rkm_medis, COALESCE(p.nm_pasien, 'Pasien Tidak Terdaftar') AS nm_pasien, COALESCE(p.no_tlp, '') AS no_tlp
        FROM bridging_surat_kontrol_bpjs sk
        LEFT JOIN bridging_sep sep ON sk.no_sep = sep.no_sep
        LEFT JOIN pasien p ON sep.nomr = p.no_rkm_medis
        WHERE sk.no_surat = ? LIMIT 1
    ");
    $stmt_single->execute([$no_surat_target]);
    $row_target = $stmt_single->fetch();

    if ($row_target) {
        $tgl_periksa_formatted = tglIndo($row_target['tgl_rencana']);
        $search_tags = ['{name}', '{norm}', '{tanggal}', '{dokter}', '{poli}'];
        $replace_val = [$row_target['nm_pasien'], $row_target['no_rkm_medis'], $tgl_periksa_formatted, $row_target['nm_dokter_bpjs'], $row_target['nm_poli_bpjs']];
        $msg_wa = str_replace($search_tags, $replace_val, $template_wa_db);

        $api_res = kirimPesanSaungWA($saungwa_app_key, $saungwa_auth_key, $row_target['no_tlp'], $msg_wa);
        if ($api_res['status']) {
            $notif_msg = "<div class='alert alert-success'><i class='fa-solid fa-circle-check'></i> Pesan WA berhasil dikirim ke <b>" . htmlspecialchars($row_target['nm_pasien']) . "</b> (" . htmlspecialchars(formatNomorWA($row_target['no_tlp'])) . ") via API.</div>";
        } else {
            $notif_msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> <b>Gagal Kirim WA:</b> " . htmlspecialchars($api_res['message']) . "</div>";
        }
    }
}

// 8. EXPORT EXCEL (.xls)
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    $stmt_export = $conn->prepare($sql);
    $stmt_export->execute($params);
    $rows_export = $stmt_export->fetchAll();

    $filename = "Surat_Kontrol_BPJS_" . date('Ymd_His') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Cache-Control: max-age=0");

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Data Surat Kontrol</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
    echo '<body>';
    echo '<table border="1" style="border-collapse:collapse;">';
    echo '<tr style="background-color:#0a7c3b; color:#ffffff; font-weight:bold; text-align:center;">';
    echo '<th>No</th>';
    echo '<th>No Surat Kontrol</th>';
    echo '<th>No SEP Asal</th>';
    echo '<th>Tgl. Terbit Surat Kontrol</th>';
    echo '<th>Tgl Rencana Kontrol</th>';
    echo '<th>No RM</th>';
    echo '<th>Nama Pasien</th>';
    echo '<th>No Telp</th>';
    echo '<th>Asal Pasien</th>';
    echo '<th>Dokter BPJS</th>';
    echo '<th>Poli BPJS</th>';
    echo '<th>Status Kehadiran</th>';
    echo '</tr>';

    $no_exp = 1;
    foreach ($rows_export as $r) {
        echo '<tr>';
        echo '<td style="text-align:center;">' . $no_exp++ . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_surat']) . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars($r['no_sep']) . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars(tglIndo($r['tgl_surat'])) . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars(tglIndo($r['tgl_rencana'])) . '</td>';
        echo '<td style="mso-number-format:\'\@\'; text-align:center;">' . htmlspecialchars($r['no_rkm_medis']) . '</td>';
        echo '<td>' . htmlspecialchars($r['nm_pasien']) . '</td>';
        echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars(formatNomorWA($r['no_tlp'])) . '</td>';
        echo '<td>' . (($r['cek_rajal'] > 0) ? 'RAJAL' : 'RANAP') . '</td>';
        echo '<td>' . htmlspecialchars($r['nm_dokter_bpjs'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['nm_poli_bpjs'] ?? '-') . '</td>';
        echo '<td style="text-align:center;">' . htmlspecialchars($r['status_kehadiran']) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
    exit;
}

// EKSEKUSI DATA UTAMA
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// HITUNG REKAPITULASI STATUS KEHADIRAN
$total_surat = count($rows);
$total_hadir = 0;
$total_tidak_hadir = 0;

foreach ($rows as $r) {
    if ($r['status_kehadiran'] === 'Hadir') {
        $total_hadir++;
    } else {
        $total_tidak_hadir++;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Surat Kontrol BPJS</title>
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
        .box-absent { border-left: 5px solid #c62828; background: #ffebee; }
        .box-absent div { color: #c62828; }
        
        form { background: #f8f9fa; padding: 18px; border-radius: 8px; border: 1px solid #e9ecef; margin-bottom: 20px; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-weight: 600; font-size: 11px; text-transform: uppercase; color: #495057; }
        input[type="date"], input[type="text"], select { padding: 7px 10px; border: 1px solid #ced4da; border-radius: 5px; background: #fff; font-size: 12px; outline: none; }
        
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
        .cell-nowrap { white-space: nowrap; }
        
        .badge-surat { font-weight: 700; color: #0a7c3b; background: #e8f5e9; padding: 4px 8px; border-radius: 4px; font-family: monospace; }
        .badge-sep { font-weight: 600; color: #555; background: #f0f0f0; padding: 3px 6px; border-radius: 4px; font-family: monospace; }
        .badge-hadir { font-size: 11px; padding: 4px 8px; border-radius: 4px; font-weight: bold; background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; display: inline-block; }
        .badge-absent { font-size: 11px; padding: 4px 8px; border-radius: 4px; font-weight: bold; background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; display: inline-block; }
        .badge-asal-rajal { color: #0d6efd; font-weight: 700; background: #e7f1ff; border-radius: 4px; padding: 3px 6px; display: inline-block; }
        .badge-asal-ranap { color: #fd7e14; font-weight: 700; background: #fff3e0; border-radius: 4px; padding: 3px 6px; display: inline-block; }

        .btn-wa { background: #25d366; color: white; padding: 5px 9px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px; }
        
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        

        .footer { text-align: center; font-size: 11px; color: #6c757d; margin-top: 25px; border-top: 1px solid #dee2e6; padding-top: 15px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Monitoring Surat Kontrol BPJS V-Claim</h2>
    <h4>TerIntegrasi dengan SIMRS Khanza</h4>

    <nav class="page-tabs" aria-label="Menu booking pasien">
        <span class="page-tab active" aria-current="page"><i class="fa-solid fa-id-card"></i> Pasien Booking Kontrol BPJS</span>
        <a class="page-tab" href="bookingkontrol.php?view=non_skdp"><i class="fa-solid fa-user"></i> Pasien Booking Kontrol Non-SKDP</a>
        <a class="page-tab" href="bookingkontrol.php?view=monthly"><i class="fa-solid fa-calendar-days"></i> Booking 1 Bulan</a>
    </nav>

    <?= $notif_msg ?>

    <!-- KARTU RINGKASAN REKAPITULASI STATISTIK -->
    <div class="summary-container">
        <div class="stat-box box-total">
            <small>Total Surat Kontrol</small>
            <div><?= number_format($total_surat, 0, ',', '.') ?></div>
        </div>
        <div class="stat-box box-hadir">
            <small>Pasien Hadir</small>
            <div><?= number_format($total_hadir, 0, ',', '.') ?></div>
        </div>
        <div class="stat-box box-absent">
            <small>Pasien Tidak Hadir</small>
            <div><?= number_format($total_tidak_hadir, 0, ',', '.') ?></div>
        </div>
    </div>

    <form method="get" action="">
        <div class="filter-row">
            <div class="filter-group">
                <label>Periode Rencana Kontrol</label>
                <div>
                    <input type="date" name="tgl_awal" value="<?= htmlspecialchars($tgl_awal) ?>"> s/d 
                    <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($tgl_akhir) ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Periode SKDP Terbit</label>
                <div>
                    <input type="date" name="tgl_terbit_awal" value="<?= htmlspecialchars($tgl_terbit_awal) ?>"> s/d
                    <input type="date" name="tgl_terbit_akhir" value="<?= htmlspecialchars($tgl_terbit_akhir) ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Poliklinik Tujuan</label>
                <select name="kd_poli" style="min-width: 180px;">
                    <option value="">-- Semua Poliklinik --</option>
                    <?php foreach ($list_poli as $p): ?>
                        <option value="<?= $p['kd_poli_bpjs'] ?>" <?= $kd_poli === $p['kd_poli_bpjs'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nm_poli_bpjs']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- PENAMBAHAN FILTER STATUS KEHADIRAN -->
            <div class="filter-group">
                <label>Status Kehadiran</label>
                <select name="status_kehadiran" style="min-width: 150px;">
                    <option value="">-- Semua Status --</option>
                    <option value="Hadir" <?= $status_kehadiran_filter === 'Hadir' ? 'selected' : '' ?>>Hadir</option>
                    <option value="Tidak Hadir" <?= $status_kehadiran_filter === 'Tidak Hadir' ? 'selected' : '' ?>>Tidak Hadir</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Pencarian (Keyword)</label>
                <input type="text" name="keyword" placeholder="No Surat / SEP / RM / Pasien / Dokter" value="<?= htmlspecialchars($keyword) ?>" style="width: 220px;">
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-submit"><i class="fa-solid fa-filter"></i> Tampilkan</button>
                <button type="submit" name="action" value="export_excel" class="btn btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
                <a href="bookingkontrol.php" class="btn btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">No</th>
                    <th>No. Surat Kontrol</th>
                    <th>No. SEP Asal</th>
                    <th>Tgl. Terbit Surat Kontrol</th>
                    <th>Tgl. Rencana Kontrol</th>
                    <th>No. RM</th>
                    <th>Nama Pasien</th>
                    <th>No. Telp / HP</th>
                    <th>Asal Pasien</th>
                    <th>Dokter BPJS</th>
                    <th>Poli BPJS</th>
                    <th>Status Kehadiran</th>
                    <th style="width: 170px;">Aksi WhatsApp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="13" style="padding: 20px; color: #777;">Data surat kontrol BPJS tidak ditemukan untuk kriteria filter ini.</td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    $query_params = $_GET;
                    unset($query_params['action'], $query_params['no_surat']);

                    foreach ($rows as $row): 
                        $phone_clean = formatNomorWA($row['no_tlp']);
                        $asal_pasien = ($row['cek_rajal'] > 0) ? 'RAJAL' : 'RANAP';
                        $class_asal = ($row['cek_rajal'] > 0) ? 'badge-asal-rajal' : 'badge-asal-ranap';
                        $tgl_periksa_formatted = tglIndo($row['tgl_rencana']);

                        $search_tags = ['{name}', '{norm}', '{tanggal}', '{dokter}', '{poli}'];
                        $replace_val = [$row['nm_pasien'], $row['no_rkm_medis'], $tgl_periksa_formatted, $row['nm_dokter_bpjs'], $row['nm_poli_bpjs']];
                        $msg_wa = str_replace($search_tags, $replace_val, $template_wa_db);

                        $link_wa_manual = "https://api.whatsapp.com/send?phone=" . $phone_clean . "&text=" . urlencode($msg_wa);

                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><span class="badge-surat"><?= htmlspecialchars($row['no_surat']) ?></span></td>
                            <td><span class="badge-sep"><?= htmlspecialchars($row['no_sep']) ?></span></td>
                            <td class="cell-nowrap"><?= htmlspecialchars(tglIndo($row['tgl_surat'])) ?></td>
                            <td class="cell-nowrap"><b><?= htmlspecialchars($tgl_periksa_formatted) ?></b></td>
                            <td><b><?= htmlspecialchars($row['no_rkm_medis']) ?></b></td>
                            <td class="cell-nowrap" style="text-align: left; font-weight: 600;"><?= htmlspecialchars($row['nm_pasien']) ?></td>
                            <td><?= htmlspecialchars($row['no_tlp']) ?></td>
                            <td><span class="<?= $class_asal ?>"><?= $asal_pasien ?></span></td>
                            <td style="text-align: left;"><?= htmlspecialchars($row['nm_dokter_bpjs'] ?? '-') ?></td>
                            <td style="text-align: left;"><?= htmlspecialchars($row['nm_poli_bpjs'] ?? '-') ?></td>
                            <td>
                                <?php if ($row['status_kehadiran'] === 'Hadir'): ?>
                                    <span class="badge-hadir"><i class="fa-solid fa-circle-check"></i> Hadir</span>
                                <?php else: ?>
                                    <span class="badge-absent"><i class="fa-solid fa-circle-xmark"></i> Tidak Hadir</span>
                                <?php endif; ?>
                            </td>
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

</body>
</html>
