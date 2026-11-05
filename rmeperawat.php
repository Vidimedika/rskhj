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
$default_masuk_awal  = date('Y-m-01');
$default_masuk_akhir = date('Y-m-d');

$tgl_masuk_awal   = trim($_GET['tgl_masuk_awal']  ?? '');
$tgl_masuk_akhir  = trim($_GET['tgl_masuk_akhir'] ?? '');
$tgl_pulang_awal  = trim($_GET['tgl_pulang_awal']  ?? '');
$tgl_pulang_akhir = trim($_GET['tgl_pulang_akhir'] ?? '');
$kd_dokter        = $_GET['kd_dokter'] ?? '';
$kd_bangsal       = $_GET['kd_bangsal'] ?? '';
$keyword          = $_GET['keyword']   ?? '';
$action           = $_GET['action']    ?? '';
$tab              = $_GET['tab']       ?? 'rme';
if (!in_array($tab, ['rme', 'diet'], true)) {
    $tab = 'rme';
}
$limit            = 15;
$page             = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset           = ($page - 1) * $limit;

// Jika hanya salah satu tanggal pulang yang diisi, samakan agar filter tetap jalan
if ($tgl_pulang_awal !== '' && $tgl_pulang_akhir === '') {
    $tgl_pulang_akhir = $tgl_pulang_awal;
} elseif ($tgl_pulang_akhir !== '' && $tgl_pulang_awal === '') {
    $tgl_pulang_awal = $tgl_pulang_akhir;
}

// Jika hanya salah satu tanggal masuk yang diisi, samakan
if ($tgl_masuk_awal !== '' && $tgl_masuk_akhir === '') {
    $tgl_masuk_akhir = $tgl_masuk_awal;
} elseif ($tgl_masuk_akhir !== '' && $tgl_masuk_awal === '') {
    $tgl_masuk_awal = $tgl_masuk_akhir;
}

// Jika user mengisi Tanggal Pulang sementara Tanggal Masuk masih bernilai default bawaan,
// kosongkan Tanggal Masuk agar pencarian Tanggal Pulang tidak terhalang Tanggal Masuk bulan ini
if ($tgl_pulang_awal !== '' && $tgl_pulang_akhir !== '' &&
    $tgl_masuk_awal === $default_masuk_awal && $tgl_masuk_akhir === $default_masuk_akhir) {
    $tgl_masuk_awal  = '';
    $tgl_masuk_akhir = '';
}

// Jika kedua filter tanggal kosong semua (saat pertama kali buka halaman), gunakan default Tanggal Masuk bulan ini
if ($tgl_masuk_awal === '' && $tgl_masuk_akhir === '' && $tgl_pulang_awal === '' && $tgl_pulang_akhir === '') {
    $tgl_masuk_awal  = $default_masuk_awal;
    $tgl_masuk_akhir = $default_masuk_akhir;
}

/* ================= LOGIKA EXPORT EXCEL ================= */
$isExcel = ($action == 'excel' || $action == 'export');
if ($isExcel) {
    $excel_filename = ($tab === 'diet')
        ? "Formulir_Order_Diet_Pasien_" . date('YmdHis') . ".xls"
        : "Monitoring_RME_Perawat_Ranap_" . date('YmdHis') . ".xls";
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=" . $excel_filename);
    header("Pragma: no-cache");
    header("Expires: 0");
}

// 3. LOGIKA FILTER WHERE
$where = ["r.status_lanjut='Ranap'"];
$params = [];
$types = "";

// Formulir Order Diet hanya menampilkan pasien yang belum keluar dari kamar terakhirnya.
// Filter ini berlaku juga untuk ekspor Excel saat tab diet dipilih.
if ($tab === 'diet') {
    $where[] = "EXISTS (
        SELECT 1
        FROM kamar_inap ki_terakhir
        WHERE ki_terakhir.no_rawat = r.no_rawat
          AND (ki_terakhir.tgl_masuk, ki_terakhir.jam_masuk) = (
              SELECT ki_max.tgl_masuk, ki_max.jam_masuk
              FROM kamar_inap ki_max
              WHERE ki_max.no_rawat = r.no_rawat
              ORDER BY ki_max.tgl_masuk DESC, ki_max.jam_masuk DESC
              LIMIT 1
          )
          AND (ki_terakhir.tgl_keluar IS NULL OR ki_terakhir.tgl_keluar = '0000-00-00')
    )";
}

if ($tgl_masuk_awal !== '' && $tgl_masuk_akhir !== '') {
    $where[] = "ki.tgl_masuk BETWEEN ? AND ?";
    $params[] = $tgl_masuk_awal;
    $params[] = $tgl_masuk_akhir;
    $types .= "ss";
}

if ($tgl_pulang_awal !== '' && $tgl_pulang_akhir !== '') {
    $where[] = "(ki.tgl_keluar BETWEEN ? AND ? AND ki.tgl_keluar != '0000-00-00' AND (ki.stts_pulang IS NULL OR ki.stts_pulang != 'Pindah Kamar'))";
    $params[] = $tgl_pulang_awal;
    $params[] = $tgl_pulang_akhir;
    $types .= "ss";
}

if ($kd_dokter) {
    $where[] = "r.no_rawat IN (SELECT no_rawat FROM dpjp_ranap WHERE kd_dokter = ?)";
    $params[] = $kd_dokter;
    $types .= "s";
}

$map_bangsal_sql = [
    'lt3'     => "(k.kd_kamar LIKE '%ANGGREK%' OR k.kd_kamar LIKE '%BOUGENVIL%' OR k.kd_kamar LIKE '%MAWAR%' OR k.kd_kamar LIKE '%JASMIN%')",
    'lt3_vip' => "(k.kd_kamar LIKE '%LILY%' OR k.kd_kamar LIKE '%MAGNO%')",
    'lt2'     => "(k.kd_kamar LIKE '%SAKURA%' OR k.kd_kamar LIKE '%212%' OR k.kd_kamar LIKE '%213%' OR k.kd_kamar LIKE '%214%' OR k.kd_kamar LIKE '%EDELWEIS%' OR k.kd_kamar LIKE '%TULIP%')",
    'icu'     => "(k.kd_kamar LIKE '%ICU%' OR k.kd_kamar LIKE '%NICU%' OR k.kd_kamar LIKE '%PICU%' OR k.kd_bangsal IN ('ICU', 'NICU', 'PICU'))",
    'perina'  => "(k.kd_kamar LIKE '%PERINA%' OR k.kd_bangsal = 'SAL')"
];

if ($kd_bangsal && isset($map_bangsal_sql[$kd_bangsal])) {
    $where[] = $map_bangsal_sql[$kd_bangsal];
}

if ($keyword) {
    $where[] = "(p.nm_pasien LIKE ? OR r.no_rkm_medis LIKE ? OR r.no_rawat LIKE ?)";
    $lk = "%$keyword%";
    $params[] = $lk; $params[] = $lk; $params[] = $lk;
    $types .= "sss";
}

$where_sql = implode(" AND ", $where);

// HITUNG TOTAL PASIEN & PAGINASI
$total_pasien = 0;
$sql_count = "SELECT COUNT(DISTINCT r.no_rawat) AS total
              FROM reg_periksa r
              INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
              INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
              INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
              WHERE $where_sql";
$stmt_count = $conn->prepare($sql_count);
if ($stmt_count) {
    if (!empty($types)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    if ($row_count = $stmt_count->get_result()->fetch_assoc()) {
        $total_pasien = (int)($row_count['total'] ?? 0);
    }
    $stmt_count->close();
}

$total_pages = max(1, (int)ceil($total_pasien / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}
$limit_sql = $isExcel ? "" : "LIMIT $limit OFFSET $offset";

// HITUNG REKAPITULASI PER DPJP & JENIS BAYAR
$rekap_dpjp  = [];
$rekap_bayar = [];
$sql_rekap = "SELECT 
                r.no_rawat,
                COALESCE(NULLIF(TRIM(pj.png_jawab), ''), '-') AS jenis_bayar,
                COALESCE(
                    (SELECT GROUP_CONCAT(DISTINCT d.nm_dokter ORDER BY d.nm_dokter SEPARATOR ' | ')
                     FROM dpjp_ranap dr
                     INNER JOIN dokter d ON dr.kd_dokter = d.kd_dokter
                     WHERE dr.no_rawat = r.no_rawat),
                    '- (Belum Ada DPJP)'
                ) AS nm_dpjp
              FROM reg_periksa r
              INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
              INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
              INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
              INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
              WHERE $where_sql
              GROUP BY r.no_rawat";
$stmt_rekap = $conn->prepare($sql_rekap);
if ($stmt_rekap) {
    if (!empty($types)) {
        $stmt_rekap->bind_param($types, ...$params);
    }
    $stmt_rekap->execute();
    $res_rekap = $stmt_rekap->get_result();
    while ($rr = $res_rekap->fetch_assoc()) {
        $jb   = $rr['jenis_bayar'];
        $dpjp = $rr['nm_dpjp'];

        $rekap_bayar[$jb] = ($rekap_bayar[$jb] ?? 0) + 1;

        if (!isset($rekap_dpjp[$dpjp])) {
            $rekap_dpjp[$dpjp] = [
                'total' => 0,
                'bayar' => []
            ];
        }
        $rekap_dpjp[$dpjp]['total']++;
        $rekap_dpjp[$dpjp]['bayar'][$jb] = ($rekap_dpjp[$dpjp]['bayar'][$jb] ?? 0) + 1;
    }
    $stmt_rekap->close();
}
uasort($rekap_dpjp, function($a, $b) {
    return $b['total'] <=> $a['total'];
});
arsort($rekap_bayar);

// HITUNG STATISTIK KAMAR / BED AKTIF
$bed_total   = 0;
$bed_isi     = 0;
$bed_kosong  = 0;
$bed_booking = 0;
$where_bed = "k.statusdata = '1'";
if ($kd_bangsal && isset($map_bangsal_sql[$kd_bangsal])) {
    $where_bed .= " AND " . $map_bangsal_sql[$kd_bangsal];
}
$res_bed = $conn->query("SELECT 
                            COUNT(*) AS total,
                            SUM(CASE WHEN k.status = 'ISI' THEN 1 ELSE 0 END) AS isi,
                            SUM(CASE WHEN k.status = 'KOSONG' THEN 1 ELSE 0 END) AS kosong,
                            SUM(CASE WHEN k.status = 'DIBOOKING' THEN 1 ELSE 0 END) AS booking
                         FROM kamar k
                         WHERE $where_bed");
if ($res_bed && ($row_bed = $res_bed->fetch_assoc())) {
    $bed_total   = (int)($row_bed['total'] ?? 0);
    $bed_isi     = (int)($row_bed['isi'] ?? 0);
    $bed_kosong  = (int)($row_bed['kosong'] ?? 0);
    $bed_booking = (int)($row_bed['booking'] ?? 0);
}

$list_bed_kosong = [];
$res_bed_kosong = $conn->query("SELECT k.kd_kamar, COALESCE(b.nm_bangsal, '') AS nm_bangsal, COALESCE(k.kelas, '') AS kelas
                                FROM kamar k
                                LEFT JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
                                WHERE $where_bed AND k.status = 'KOSONG'
                                ORDER BY k.kd_kamar ASC");
if ($res_bed_kosong) {
    while ($rk = $res_bed_kosong->fetch_assoc()) {
        $list_bed_kosong[] = $rk;
    }
}

// 4. OPTIMIZED QUERY
$sql_diet_col = "COALESCE(
    (SELECT NULLIF(TRIM(pr.instruksi), '')
     FROM pemeriksaan_ranap pr
     WHERE pr.no_rawat = r.no_rawat
       AND (pr.instruksi LIKE '%diet%' OR pr.instruksi LIKE '%diit%')
     ORDER BY pr.tgl_perawatan DESC, pr.jam_rawat DESC
     LIMIT 1),
    '-'
) as diet_pasien,";

$sql = "SELECT 
            r.no_rawat, r.no_rkm_medis, p.nm_pasien, p.tgl_lahir,
            $sql_diet_col
            COALESCE(
                NULLIF(bs.klsrawat, ''),
                (SELECT k_last.kelas
                 FROM kamar_inap ki_last
                 JOIN kamar k_last ON ki_last.kd_kamar = k_last.kd_kamar
                 WHERE ki_last.no_rawat = r.no_rawat
                 ORDER BY ki_last.tgl_masuk DESC, ki_last.jam_masuk DESC
                 LIMIT 1),
                '-'
            ) as kelas, 
            (SELECT CONCAT(k_last.kd_kamar, ' ', b_last.nm_bangsal)
             FROM kamar_inap ki_last
             JOIN kamar k_last ON ki_last.kd_kamar = k_last.kd_kamar
             JOIN bangsal b_last ON k_last.kd_bangsal = b_last.kd_bangsal
             WHERE ki_last.no_rawat = r.no_rawat
             ORDER BY ki_last.tgl_masuk DESC, ki_last.jam_masuk DESC
             LIMIT 1) as nm_bangsal,
            pj.png_jawab,
            COALESCE((SELECT MIN(ki0.tgl_masuk) FROM kamar_inap ki0 WHERE ki0.no_rawat = r.no_rawat), MIN(ki.tgl_masuk)) as tgl_masuk,
            (SELECT ki_last.tgl_keluar
             FROM kamar_inap ki_last
             WHERE ki_last.no_rawat = r.no_rawat
             ORDER BY ki_last.tgl_masuk DESC, ki_last.jam_masuk DESC
             LIMIT 1) as tgl_keluar,
            COALESCE((SELECT NULLIF(TRIM(ki_last.stts_pulang), '')
                      FROM kamar_inap ki_last
                      WHERE ki_last.no_rawat = r.no_rawat
                      ORDER BY ki_last.tgl_masuk DESC, ki_last.jam_masuk DESC
                      LIMIT 1), '-') as status_pulang,
            COALESCE(
                NULLIF(TRIM(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(TRIM(ki.diagnosa_awal), '') ORDER BY ki.tgl_masuk ASC, ki.jam_masuk ASC SEPARATOR '||'), '||', 1)), ''),
                '-'
            ) as diagnosa_awal,
            COALESCE(
                (SELECT NULLIF(TRIM(ki_last.diagnosa_akhir), '')
                 FROM kamar_inap ki_last
                 WHERE ki_last.no_rawat = r.no_rawat
                 ORDER BY ki_last.tgl_masuk DESC, ki_last.jam_masuk DESC
                 LIMIT 1),
                (SELECT NULLIF(TRIM(rpr.diagnosa_utama), '') FROM resume_pasien_ranap rpr WHERE rpr.no_rawat = r.no_rawat LIMIT 1),
                '-'
            ) as diagnosa_akhir,
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

            -- Surat Kontrol BPJS (SKDP VClaim)
            IF((SELECT COUNT(sk.no_surat) FROM bridging_surat_kontrol_bpjs sk INNER JOIN bridging_sep sep ON sk.no_sep = sep.no_sep WHERE sep.no_rawat = r.no_rawat) > 0, 1, 0) as f_skdp_bpjs,

            -- Surat Kontrol (SKDP / Surat Kontrol RS)
            IF((SELECT COUNT(sb.no_antrian) FROM skdp_bpjs sb WHERE sb.no_rkm_medis = r.no_rkm_medis AND DATE(sb.tanggal_rujukan) BETWEEN MIN(ki.tgl_masuk) AND IF(MAX(ki.tgl_keluar) IS NULL OR MAX(ki.tgl_keluar) = '0000-00-00', CURDATE(), MAX(ki.tgl_keluar))) > 0, 1, 0) as f_surat_kontrol

        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
        INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
        INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
        INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
        LEFT JOIN bridging_sep bs ON r.no_rawat = bs.no_rawat
        WHERE $where_sql
        GROUP BY r.no_rawat
        ORDER BY MIN(ki.tgl_masuk) DESC
        $limit_sql";

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
$data_rows = [];
if ($result) {
    while ($row_item = $result->fetch_assoc()) {
        $data_rows[] = $row_item;
    }
}

function status_cek($val, $isExcel = false) {
    if($isExcel) {
        return $val ? "<td bgcolor='#E8F5E9' style='color:#0f7541; text-align:center; font-weight:bold;'>Ada</td>" : "<td bgcolor='#FEF2F2' style='color:#c0392b; text-align:center;'>-</td>";
    }
    return $val ? "<td style='background:#e8f5e9; color:#0f7541; font-weight:bold'>Ada</td>" : "<td style='background:#fff3e0; color:#d35400; font-weight:bold'>-</td>";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring RME Perawat  - RS Kartika Husada Jatiasih</title>
    <?php if (!$isExcel): ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --kh-hijau: #0f7541;
            --kh-hijau-dark: #0b5730;
            --kh-oranye: #e67e22;
            --kh-oranye-dark: #d35400;
        }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f5; margin: 0; padding: 20px; font-size: 11px; color: #333; }
        .container { background: white; padding: 0 20px 20px 20px; border-radius: 12px; box-shadow: 0 6px 20px rgba(15, 117, 65, 0.08); overflow: hidden; border: 1px solid #dce5df; }
        .header-banner { background: linear-gradient(135deg, var(--kh-hijau) 0%, var(--kh-hijau-dark) 100%); color: white; margin: 0 -20px 20px -20px; padding: 18px 20px; border-bottom: 4px solid var(--kh-oranye); text-align: center; }
        h2 { color: #ffffff; text-transform: uppercase; margin: 0; font-size: 18px; letter-spacing: 0.8px; }
        h3 { color: #ffe0b2; margin: 4px 0 0 0; font-size: 13px; font-weight: 600; letter-spacing: 0.5px; }
        .filter { background: #ffffff; padding: 15px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #e0e6ed; border-top: 3px solid var(--kh-oranye); box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
        .stat-cards-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid #d4ede1;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 117, 65, 0.08);
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: #e2f7ec;
            color: var(--kh-hijau);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .stat-label {
            font-size: 10px;
            font-weight: 700;
            color: #6b8577;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .stat-val {
            font-size: 20px;
            font-weight: 700;
            color: #094f2b;
            line-height: 1.25;
            margin-top: 2px;
        }
        .stat-sub {
            font-size: 11px;
            color: #6b8577;
            margin-top: 2px;
        }
        .bed-kosong-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
            max-height: 75px;
            overflow-y: auto;
            padding-right: 2px;
        }
        .bed-kosong-pill {
            display: inline-block;
            background: #e8f5e9;
            color: var(--kh-hijau);
            border: 1px solid #b7e1cd;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 9.5px;
            font-weight: 700;
            line-height: 1.3;
        }
        @media (max-width: 992px) {
            .stat-cards-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px) {
            .stat-cards-grid { grid-template-columns: 1fr; }
        }
        .filter-row { display: flex; justify-content: center; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .f-group { display: flex; flex-direction: column; gap: 4px; }
        .f-group label { font-weight: bold; font-size: 10px; color: var(--kh-hijau); text-transform: uppercase; }
        input, select, button { padding: 8px 10px; border-radius: 5px; border: 1px solid #cbd5e1; font-size: 11px; outline: none; transition: 0.2s; }
        input:focus, select:focus { border-color: var(--kh-oranye); box-shadow: 0 0 0 2px rgba(230, 126, 34, 0.15); }
        .btn-cari { background: var(--kh-hijau); color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 16px; border-radius: 5px; }
        .btn-cari:hover { background: var(--kh-hijau-dark); }
        .btn-excel { background: var(--kh-oranye); color: white; border: none; font-weight: bold; cursor: pointer; padding: 8px 16px; text-decoration: none; border-radius: 5px; display: inline-block; transition: 0.2s; }
        .btn-excel:hover { background: var(--kh-oranye-dark); color: white; }
        .badge-total { font-size: 13px; font-weight: bold; color: var(--kh-hijau); background: #e8f5e9; border: 1px solid var(--kh-hijau); border-left: 4px solid var(--kh-oranye); padding: 6px 12px; border-radius: 5px; }
        .btn-reset { text-decoration: none; font-size: 11px; color: var(--kh-oranye-dark); font-weight: bold; padding: 8px 6px; }
        .btn-reset:hover { text-decoration: underline; }
        .scroll-area { max-height: 600px; overflow: auto; border: 1px solid #dce5df; border-radius: 6px; margin-top: 10px; }
        table { border-collapse: collapse; width: 100%; }
        .scroll-area table { min-width: 2700px; }
        thead th { position: sticky; top: 0; background: var(--kh-hijau); color: white; padding: 12px 6px; z-index: 10; border: 1px solid var(--kh-hijau-dark); border-bottom: 3px solid var(--kh-oranye); text-align: center; }
        tbody tr:hover td { background-color: #f9fbf9; }
        td { padding: 10px 6px; border: 1px solid #e9ecef; text-align: center; vertical-align: middle; }
        .norm { font-weight: bold; font-family: monospace; color: var(--kh-hijau); }
        .badge { padding: 4px 8px; border-radius: 10px; color: white; font-weight: bold; font-size: 10px; }
        .pagination-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding: 12px 15px; background: #fff; border: 1px solid #e0e6ed; border-left: 4px solid var(--kh-oranye); border-radius: 6px; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: 12px; color: var(--kh-hijau); font-weight: bold; }
        .pagination-links { display: flex; gap: 6px; align-items: center; }
        .page-btn { padding: 6px 12px; border: 1px solid var(--kh-hijau); border-radius: 5px; text-decoration: none; color: var(--kh-hijau); font-weight: bold; font-size: 11px; background: #fff; transition: 0.2s; }
        .page-btn:hover { background: #fff3e0; border-color: var(--kh-oranye); color: var(--kh-oranye-dark); }
        .page-btn.active { background: var(--kh-oranye); border-color: var(--kh-oranye); color: #fff; }
        .page-btn.disabled { border-color: #cbd5e1; color: #94a3b8; pointer-events: none; background: #f8fafc; }
        .rekap-section { margin-top: 25px; }
        .rekap-title { color: var(--kh-hijau); font-size: 13px; font-weight: bold; text-transform: uppercase; border-bottom: 2px solid var(--kh-oranye); padding-bottom: 6px; margin-bottom: 12px; }
        .rekap-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 15px; align-items: start; }
        .rekap-card { background: #fff; border: 1px solid #dce5df; border-top: 3px solid var(--kh-hijau); border-radius: 8px; padding: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
        .rekap-card-oranye { border-top-color: var(--kh-oranye); }
        .rekap-card h4 { margin: 0 0 10px 0; font-size: 12px; color: var(--kh-hijau); text-transform: uppercase; }
        .rekap-card-oranye h4 { color: var(--kh-oranye-dark); }
        .rekap-table th { position: static; padding: 8px; font-size: 11px; }
        .rekap-table td { padding: 7px 8px; font-size: 11px; }
        .jb-pill { display: inline-block; background: #fff3e0; color: var(--kh-oranye-dark); border: 1px solid #f5cba7; border-radius: 4px; padding: 2px 7px; margin: 2px 3px 2px 0; font-size: 10px; font-weight: 600; }
        .jb-pill strong { color: var(--kh-hijau); }
        .tab-nav {
            display: flex;
            gap: 8px;
            border-bottom: 3px solid var(--kh-hijau);
            margin-bottom: 14px;
            padding-top: 4px;
        }
        .tab-btn {
            background: #e8f5e9;
            color: var(--kh-hijau);
            border: 1px solid #b7e1cd;
            border-bottom: none;
            padding: 10px 20px;
            border-radius: 8px 8px 0 0;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .tab-btn:hover {
            background: #d4ede1;
        }
        .tab-btn.active {
            background: var(--kh-hijau);
            color: #ffffff;
            border-color: var(--kh-hijau);
            box-shadow: 0 -2px 8px rgba(15, 117, 65, 0.15);
        }
        .tab-pane {
            display: none;
        }
        .tab-pane.active {
            display: block;
        }
        .diet-header-box {
            background: linear-gradient(135deg, #f0faf4 0%, #fff8f0 100%);
            border: 1px solid #d4ede1;
            border-left: 5px solid var(--kh-oranye);
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .diet-header-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--kh-hijau);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }
        .diet-header-sub {
            font-size: 11px;
            color: #555;
            margin-top: 3px;
            font-weight: 600;
        }
        .diet-table {
            width: 100%;
            border-collapse: collapse;
        }
        .diet-table thead th {
            position: sticky;
            top: 0;
            background: var(--kh-hijau);
            color: #fff;
            padding: 11px 8px;
            border: 1px solid var(--kh-hijau-dark);
            border-bottom: 3px solid var(--kh-oranye);
            font-size: 11px;
            text-transform: uppercase;
        }
        .diet-table tbody td {
            padding: 10px 8px;
            border: 1px solid #dce5df;
            font-size: 11px;
        }
        .diet-input-cell {
            min-width: 140px;
            background: #fffdf9;
            font-weight: 600;
            color: #094f2b;
        }
        .diet-input-cell:focus {
            background: #fff3e0;
            outline: 2px solid var(--kh-oranye);
        }
        @media print {
            .filter, .stat-cards-grid, .tab-nav, .pagination-bar, .rekap-section, .no-print {
                display: none !important;
            }
            .container {
                box-shadow: none;
                border: none;
                padding: 0;
            }
            .scroll-area {
                max-height: none !important;
                overflow: visible !important;
                border: none !important;
            }
        }
    </style>
    <?php else: ?>
    <style>
        .str { mso-number-format:"\@"; } /* Mengunci data string agar No. RM / No. Rawat tidak corrupt */
        thead th { background-color: #0F7541; color: #FFFFFF; font-weight: bold; text-align: center; border-bottom: 2px solid #E67E22; }
        td { vertical-align: middle; }
    </style>
    <?php endif; ?>
</head>
<body>

<div class="container">
    <?php
    if ($tgl_masuk_awal && $tgl_masuk_akhir && $tgl_pulang_awal && $tgl_pulang_akhir) {
        $label_periode = 'Masuk: ' . date('d/m/Y', strtotime($tgl_masuk_awal)) . ' - ' . date('d/m/Y', strtotime($tgl_masuk_akhir)) . ' | Pulang: ' . date('d/m/Y', strtotime($tgl_pulang_awal)) . ' - ' . date('d/m/Y', strtotime($tgl_pulang_akhir));
    } elseif ($tgl_pulang_awal && $tgl_pulang_akhir) {
        $label_periode = 'Periode Pulang: ' . date('d/m/Y', strtotime($tgl_pulang_awal)) . ' - ' . date('d/m/Y', strtotime($tgl_pulang_akhir));
    } else {
        $label_periode = 'Periode Masuk: ' . date('d/m/Y', strtotime($tgl_masuk_awal)) . ' - ' . date('d/m/Y', strtotime($tgl_masuk_akhir));
    }
    ?>
    <?php if (!$isExcel): ?>
    <div class="header-banner">
        <h2>RS KARTIKA HUSADA JATIASIH</h2>
        <h3>KUNJUNGAN & MONITORING RME PERAWAT RAWAT INAP</h3>
    </div>
    <div class="filter">
        <form method="GET">
            <div class="filter-row">
                <div class="f-group">
                    <label>TANGGAL MASUK</label>
                    <div>
                        <input type="date" id="tgl_masuk_awal" name="tgl_masuk_awal" value="<?=$tgl_masuk_awal?>" onchange="onMasukChange()"> s/d 
                        <input type="date" id="tgl_masuk_akhir" name="tgl_masuk_akhir" value="<?=$tgl_masuk_akhir?>" onchange="onMasukChange()">
                    </div>
                </div>
                
                <div class="f-group">
                    <label>TANGGAL PULANG </label>
                    <div>
                        <input type="date" id="tgl_pulang_awal" name="tgl_pulang_awal" value="<?=$tgl_pulang_awal?>" onchange="onPulangChange()"> s/d 
                        <input type="date" id="tgl_pulang_akhir" name="tgl_pulang_akhir" value="<?=$tgl_pulang_akhir?>" onchange="onPulangChange()">
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
                              " . (($tgl_masuk_awal && $tgl_masuk_akhir)
                                  ? "AND ki.tgl_masuk BETWEEN '" . $conn->real_escape_string($tgl_masuk_awal) . "' AND '" . $conn->real_escape_string($tgl_masuk_akhir) . "' "
                                  : "") . "
                              " . (($tgl_pulang_awal && $tgl_pulang_akhir)
                                  ? "AND (ki.tgl_keluar BETWEEN '" . $conn->real_escape_string($tgl_pulang_awal) . "' AND '" . $conn->real_escape_string($tgl_pulang_akhir) . "' AND ki.tgl_keluar != '0000-00-00' AND (ki.stts_pulang IS NULL OR ki.stts_pulang != 'Pindah Kamar')) "
                                  : "") . "
                              " . (($kd_bangsal && isset($map_bangsal_sql[$kd_bangsal]))
                                  ? "AND " . $map_bangsal_sql[$kd_bangsal] . " "
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
                    <label>NURSE STATION / LANTAI</label>
                    <select name="kd_bangsal">
                        <option value="">- Semua Lantai / Nurse Station -</option>
                        <option value="lt3" <?= $kd_bangsal === 'lt3' ? 'selected' : '' ?>>1. Lantai 3 (Anggrek 1-3, Bougenvil 1-2, Mawar, Jasmin)</option>
                        <option value="lt3_vip" <?= $kd_bangsal === 'lt3_vip' ? 'selected' : '' ?>>2. Lantai 3 VIP (Lily 1-7, Magnolia 1-6)</option>
                        <option value="lt2" <?= $kd_bangsal === 'lt2' ? 'selected' : '' ?>>3. Lantai 2 (Sakura 1-5, R.212-213, Edelweis 1-4, Tulip 1-4)</option>
                        <option value="icu" <?= $kd_bangsal === 'icu' ? 'selected' : '' ?>>4. ICU (ICU, NICU, PICU)</option>
                        <option value="perina" <?= $kd_bangsal === 'perina' ? 'selected' : '' ?>>5. Perina</option>
                    </select>
                </div>

                <div class="f-group">
                    <label>CARI PASIEN</label>
                    <input type="text" name="keyword" value="<?=$keyword?>" placeholder="Nama / No. RM / No. Rawat" style="width:180px">
                </div>

                <input type="hidden" id="active_tab" name="tab" value="<?=htmlspecialchars($tab)?>">
                <button type="submit" class="btn-cari">FILTER DATA</button>
                <a id="btn_export_excel" href="?action=excel&tab=<?=urlencode($tab)?>&tgl_masuk_awal=<?=$tgl_masuk_awal?>&tgl_masuk_akhir=<?=$tgl_masuk_akhir?>&tgl_pulang_awal=<?=$tgl_pulang_awal?>&tgl_pulang_akhir=<?=$tgl_pulang_akhir?>&kd_dokter=<?=$kd_dokter?>&kd_bangsal=<?=$kd_bangsal?>&keyword=<?=$keyword?>" class="btn-excel">EXPORT EXCEL</a>
                <div class="badge-total">
                    Jumlah Pasien: <?=number_format($total_pasien, 0, ',', '.') ?>
                </div>
                <a href="?" class="btn-reset">Reset</a>
            </div>
        </form>
        <script>
            function onPulangChange() {
                var pAwal  = document.getElementById('tgl_pulang_awal');
                var pAkhir = document.getElementById('tgl_pulang_akhir');
                if (pAwal.value || pAkhir.value) {
                    document.getElementById('tgl_masuk_awal').value  = '';
                    document.getElementById('tgl_masuk_akhir').value = '';
                }
            }
            function onMasukChange() {
                var mAwal  = document.getElementById('tgl_masuk_awal');
                var mAkhir = document.getElementById('tgl_masuk_akhir');
                if (mAwal.value || mAkhir.value) {
                    document.getElementById('tgl_pulang_awal').value  = '';
                    document.getElementById('tgl_pulang_akhir').value = '';
                }
            }
            function switchTab(tabName) {
                document.getElementById('active_tab').value = tabName;
                document.getElementById('btn-tab-rme').classList.toggle('active', tabName === 'rme');
                document.getElementById('btn-tab-diet').classList.toggle('active', tabName === 'diet');
                document.getElementById('pane-rme').classList.toggle('active', tabName === 'rme');
                document.getElementById('pane-diet').classList.toggle('active', tabName === 'diet');

                // Update link export excel & pagination agar menyertakan tab yang sedang aktif
                var excelBtn = document.getElementById('btn_export_excel');
                if (excelBtn) {
                    excelBtn.href = excelBtn.href.replace(/([?&])tab=[^&]*/, '$1tab=' + encodeURIComponent(tabName));
                }
                var pageLinks = document.querySelectorAll('.pagination-links a.page-btn');
                pageLinks.forEach(function(link) {
                    if (link.href) {
                        link.href = link.href.replace(/([?&])tab=[^&]*/, '$1tab=' + encodeURIComponent(tabName));
                    }
                });
            }
        </script>
    </div>

    <?php
    $pct_isi    = $bed_total > 0 ? round(($bed_isi / $bed_total) * 100, 1) : 0;
    $pct_kosong = $bed_total > 0 ? round(($bed_kosong / $bed_total) * 100, 1) : 0;
    $label_unit_bed = 'Semua lantai / nurse station';
    $map_nama_unit = [
        'lt3'     => '1. Lantai 3',
        'lt3_vip' => '2. Lantai 3 VIP',
        'lt2'     => '3. Lantai 2',
        'icu'     => '4. ICU (ICU, NICU, PICU)',
        'perina'  => '5. Perina',
    ];
    if ($kd_bangsal && isset($map_nama_unit[$kd_bangsal])) {
        $label_unit_bed = 'Unit: ' . $map_nama_unit[$kd_bangsal];
    }
    ?>
    <div class="stat-cards-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-bed"></i>
            </div>
            <div>
                <div class="stat-label">Total Kamar / Bed</div>
                <div class="stat-val"><?= number_format($bed_total, 0, ',', '.') ?></div>
                <div class="stat-sub"><?= htmlspecialchars($label_unit_bed) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-bed-pulse"></i>
            </div>
            <div>
                <div class="stat-label">Terisi</div>
                <div class="stat-val"><?= number_format($bed_isi, 0, ',', '.') ?></div>
                <div class="stat-sub"><?= $pct_isi ?>% dari total bed<?= $bed_booking > 0 ? ' (+' . $bed_booking . ' dibooking)' : '' ?></div>
            </div>
        </div>
        <div class="stat-card" style="align-items: flex-start;">
            <div class="stat-icon" style="margin-top: 2px;">
                <i class="fa-solid fa-door-open"></i>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div class="stat-label">Kosong</div>
                <div class="stat-val"><?= number_format($bed_kosong, 0, ',', '.') ?></div>
                <div class="stat-sub"><?= $pct_kosong ?>% dari total bed tersedia</div>
                <?php if (!empty($list_bed_kosong)): ?>
                <div class="bed-kosong-list">
                    <?php foreach ($list_bed_kosong as $bk): ?>
                        <span class="bed-kosong-pill" title="<?= htmlspecialchars(trim($bk['kd_kamar']) . ($bk['nm_bangsal'] ? ' - ' . $bk['nm_bangsal'] : '') . ($bk['kelas'] ? ' (' . $bk['kelas'] . ')' : '')) ?>">
                            <?= htmlspecialchars(trim($bk['kd_kamar'])) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-hospital-user"></i>
            </div>
            <div>
                <div class="stat-label">Total Pasien (Periode)</div>
                <div class="stat-val"><?= number_format($total_pasien, 0, ',', '.') ?></div>
                <div class="stat-sub"><?= htmlspecialchars($label_periode) ?></div>
            </div>
        </div>
    </div>

    <div class="tab-nav">
        <button type="button" id="btn-tab-rme" class="tab-btn <?= $tab === 'rme' ? 'active' : '' ?>" onclick="switchTab('rme')">
            <i class="fa-solid fa-clipboard-check"></i> MONITORING RME PERAWAT
        </button>
        <button type="button" id="btn-tab-diet" class="tab-btn <?= $tab === 'diet' ? 'active' : '' ?>" onclick="switchTab('diet')">
            <i class="fa-solid fa-utensils"></i> FORMULIR ORDER DIET
        </button>
    </div>
    <?php else: ?>
    <table border="0">
        <tr>
            <td colspan="<?= $tab === 'diet' ? 8 : 24 ?>" style="font-size:16px; font-weight:bold; text-align:center;">RS KARTIKA HUSADA JATIASIH</td>
        </tr>
        <tr>
            <td colspan="<?= $tab === 'diet' ? 8 : 24 ?>" style="font-size:13px; font-weight:bold; text-align:center; color:#555;">
                <?= $tab === 'diet' ? 'FORMULIR ORDER DIET PASIEN' : 'DASHBOARD MONITORING RME PERAWAT RAWAT INAP' ?>
            </td>
        </tr>
        <tr>
            <td colspan="<?= $tab === 'diet' ? 8 : 24 ?>" style="font-size:11px; text-align:center;"><?= htmlspecialchars($label_periode) ?></td>
        </tr>
        <tr><td colspan="<?= $tab === 'diet' ? 8 : 24 ?>"></td></tr>
    </table>
    <?php endif; ?>

    <?php if (!$isExcel || $tab === 'rme'): ?>
    <div id="pane-rme" class="<?= (!$isExcel) ? ('tab-pane ' . ($tab === 'rme' ? 'active' : '')) : '' ?>">
        <div class="<?= (!$isExcel) ? 'scroll-area' : '' ?>">
            <table border="1" cellpadding="5" cellspacing="0">
                <thead>
                    <tr>
                        <th width="40">No</th>
                        <th width="140">No. Rawat</th>
                        <th width="90">No. RM</th>
                        <th width="220">Nama Pasien</th>
                        <th width="200">Bangsal / Kamar</th>
                        <th width="70">Kelas</th>
                        <th width="250">DPJP Utama</th>
                        <th width="130">Jenis Bayar</th>
                        <th width="100">Tgl Masuk</th>
                        <th width="100">Tgl Pulang</th>
                        <th width="160">Diagnosa Awal</th>
                        <th width="160">Diagnosa Akhir</th>
                        <th width="130">Status Pulang</th>
                        <th width="140">Awal Keperawatan IGD</th>
                        <th width="120">SOAP Rajal</th>
                        <th width="140">Awal Keperawatan Ranap</th>
                        <th width="120">SOAP Ranap</th>
                        <th width="120">Resiko Jatuh</th>
                        <th width="130">Pemantauan PEWS /EWS/MEOWS</th>
                        <th width="130">CPPT Perawat</th>
                        <th width="150">Perencanaan Pemulangan</th>
                        <th width="160">Transfer Ruangan (Opsional)</th> 
                        <th width="150">Surat Kontrol BPJS (SKDP)</th>
                        <th width="130">Surat Kontrol</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = $isExcel ? 1 : ($offset + 1);
                    foreach ($data_rows as $r):
                    ?>
                    <tr>
                        <td style="text-align:center;"><?=$no++?></td>
                        <td class="str" style="text-align:center; mso-number-format:'\@';"><?=$r['no_rawat']?></td>
                        <td class="str" style="text-align:center; mso-number-format:'\@';"><?=$r['no_rkm_medis']?></td>
                        <td style="text-align:left;"><?=$r['nm_pasien']?></td>
                        <td style="text-align:left;"><?=$r['nm_bangsal']?></td>
                        <td style="text-align:center;"><?=$r['kelas']?></td>
                        <td style="text-align:left; font-size:10px;"><?=$r['daftar_dpjp'] ?: '-'?></td>
                        <td style="text-align:center;"><?=$r['png_jawab'] ?: '-'?></td>
                        <td style="text-align:center;"><?=date('d/m/Y', strtotime($r['tgl_masuk']))?></td>
                        <td style="text-align:center;"><?=($r['tgl_keluar'] && $r['tgl_keluar'] != '0000-00-00') ? date('d/m/Y', strtotime($r['tgl_keluar'])) : "Masih di rawat"?></td>
                        <td style="text-align:left;"><?=htmlspecialchars($r['diagnosa_awal'])?></td>
                        <td style="text-align:left;"><?=htmlspecialchars($r['diagnosa_akhir'])?></td>
                        <td style="text-align:center;"><?=htmlspecialchars($r['status_pulang'] ?: '-')?></td>
                        
                        <?=status_cek($r['f_awal_keperawatan_igd'], $isExcel)?>
                        <?=status_cek($r['f_soap_rajal'], $isExcel)?>
                        <?=status_cek($r['f_awal_keperawatan'], $isExcel)?>
                        <?=status_cek($r['f_soap_ranap'], $isExcel)?>
                        <?=status_cek($r['f_resiko_jatuh'], $isExcel)?>
                        <?=status_cek($r['f_pews'], $isExcel)?>
                        <?=status_cek($r['f_cppt_perawat'], $isExcel)?>
                        <?=status_cek($r['f_discharge_planning'], $isExcel)?>

                        <?=status_cek($r['f_transfer_ruang'], $isExcel)?>
                        <?=status_cek($r['f_skdp_bpjs'], $isExcel)?>
                        <?=status_cek($r['f_surat_kontrol'], $isExcel)?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($data_rows) == 0): ?>
                    <tr>
                        <td colspan="23" style="padding:20px; font-weight:bold; color:red; text-align:center;">Data tidak ditemukan berdasarkan filter pilihan.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isExcel || $tab === 'diet'): ?>
    <div id="pane-diet" class="<?= (!$isExcel) ? ('tab-pane ' . ($tab === 'diet' ? 'active' : '')) : '' ?>">
        <?php if (!$isExcel): ?>
        <div class="diet-header-box">
            <div>
                <h3 class="diet-header-title"><i class="fa-solid fa-utensils"></i> Formulir Order Diet Pasien</h3>
                <div class="diet-header-sub">
                    <?= htmlspecialchars($label_unit_bed) ?> &bull; <?= htmlspecialchars($label_periode) ?>
                </div>
            </div>
            <div class="no-print" style="display:flex; gap:8px;">
                <button type="button" onclick="window.print()" class="btn-cari" style="display:inline-flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-print"></i> CETAK FORMULIR
                </button>
                <a href="?action=excel&tab=diet&tgl_masuk_awal=<?=$tgl_masuk_awal?>&tgl_masuk_akhir=<?=$tgl_masuk_akhir?>&tgl_pulang_awal=<?=$tgl_pulang_awal?>&tgl_pulang_akhir=<?=$tgl_pulang_akhir?>&kd_dokter=<?=$kd_dokter?>&kd_bangsal=<?=$kd_bangsal?>&keyword=<?=$keyword?>" class="btn-excel" style="display:inline-flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-file-excel"></i> EXPORT EXCEL DIET
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?= (!$isExcel) ? 'scroll-area' : '' ?>" style="<?= (!$isExcel) ? 'max-height:none;' : '' ?>">
            <table class="diet-table" border="1" cellpadding="6" cellspacing="0" style="min-width:100%;">
                <thead>
                    <tr>
                        <th width="45">No.</th>
                        <th width="200">Bangsal / Kamar</th>
                        <th width="220">Nama Pasien</th>
                        <th width="110">Tanggal Lahir</th>
                        <th width="130">Jenis Bayar</th>
                        <th width="230">DPJP</th>
                        <th width="200">Diagnosa</th>
                        <th width="180">DIET</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no_diet = $isExcel ? 1 : ($offset + 1);
                    foreach ($data_rows as $r):
                        $tgl_lahir_fmt = (!empty($r['tgl_lahir']) && $r['tgl_lahir'] !== '0000-00-00')
                            ? date('d/m/Y', strtotime($r['tgl_lahir']))
                            : '-';
                        $diagnosa_diet = (!empty($r['diagnosa_akhir']) && $r['diagnosa_akhir'] !== '-')
                            ? $r['diagnosa_akhir']
                            : ($r['diagnosa_awal'] ?: '-');

                        $raw_instruksi = ($r['diet_pasien'] && $r['diet_pasien'] !== '-') ? trim($r['diet_pasien']) : '';
                        $diet_tampil   = $raw_instruksi;
                        if ($raw_instruksi !== '') {
                            $lines = preg_split('/\r\n|\r|\n/', $raw_instruksi);
                            $matched_lines = [];
                            $count_lines = count($lines);
                            for ($i = 0; $i < $count_lines; $i++) {
                                $ln = trim($lines[$i]);
                                if ($ln !== '' && preg_match('/(diet|diit)/i', $ln)) {
                                    $matched_lines[] = $ln;
                                    // Jika baris hanya berisi "Diet:" atau "Diit:", sertakan baris berikutnya
                                    if (preg_match('/^(?:[-*•\d.]+\s*)?(?:diet|diit)\s*[:=-]?\s*$/i', $ln) && isset($lines[$i + 1]) && trim($lines[$i + 1]) !== '') {
                                        $matched_lines[] = trim($lines[$i + 1]);
                                        $i++;
                                    }
                                }
                            }
                            if (!empty($matched_lines)) {
                                $diet_tampil = implode('; ', $matched_lines);
                            }
                        }
                    ?>
                    <tr>
                        <td style="text-align:center;"><?=$no_diet++?></td>
                        <td style="text-align:left; font-weight:600;"><?=htmlspecialchars($r['nm_bangsal'] ?: '-')?></td>
                        <td style="text-align:left;">
                            <strong><?=htmlspecialchars($r['nm_pasien'])?></strong><br>
                            <span style="font-size:10px; color:#0f7541; font-family:monospace;">RM: <?=htmlspecialchars($r['no_rkm_medis'])?></span>
                        </td>
                        <td style="text-align:center;"><?=$tgl_lahir_fmt?></td>
                        <td style="text-align:center;"><?=htmlspecialchars($r['png_jawab'] ?: '-')?></td>
                        <td style="text-align:left; font-size:10.5px;"><?=htmlspecialchars($r['daftar_dpjp'] ?: '-')?></td>
                        <td style="text-align:left;"><?=htmlspecialchars($diagnosa_diet)?></td>
                        <td class="diet-input-cell" style="text-align:left;" <?= !$isExcel ? 'contenteditable="true" title="' . htmlspecialchars($raw_instruksi ?: 'Klik untuk mengisi/mengubah catatan diet') . '"' : '' ?>>
                            <?=htmlspecialchars($diet_tampil ?: '-')?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($data_rows) == 0): ?>
                    <tr>
                        <td colspan="8" style="padding:20px; font-weight:bold; color:red; text-align:center;">Data tidak ditemukan berdasarkan filter pilihan.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isExcel && $total_pasien > 0):
        $base_query = [
            'tab'              => $tab,
            'tgl_masuk_awal'   => $tgl_masuk_awal,
            'tgl_masuk_akhir'  => $tgl_masuk_akhir,
            'tgl_pulang_awal'  => $tgl_pulang_awal,
            'tgl_pulang_akhir' => $tgl_pulang_akhir,
            'kd_dokter'        => $kd_dokter,
            'kd_bangsal'       => $kd_bangsal,
            'keyword'          => $keyword
        ];
        $start_item = $offset + 1;
        $end_item   = min($offset + $limit, $total_pasien);
    ?>
    <div class="pagination-bar">
        <div class="pagination-info">
            Menampilkan <?= $start_item ?> - <?= $end_item ?> dari <?= number_format($total_pasien, 0, ',', '.') ?> pasien (Halaman <?= $page ?> dari <?= $total_pages ?>)
        </div>
        <div class="pagination-links">
            <a class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($base_query, ['page' => $page - 1])) ?>">&laquo; Prev</a>
            <?php
            $start_p = max(1, $page - 2);
            $end_p   = min($total_pages, $page + 2);
            for ($p = $start_p; $p <= $end_p; $p++):
            ?>
                <a class="page-btn <?= ($p == $page) ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($base_query, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($base_query, ['page' => $page + 1])) ?>">Next &raquo;</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($total_pasien > 0): ?>
    <div class="rekap-section">
        <?php if (!$isExcel): ?>
        <div class="rekap-title">REKAPITULASI PASIEN PER DPJP & JENIS BAYAR</div>
        <div class="rekap-grid">
            <div class="rekap-card">
                <h4>Rekapitulasi Per DPJP Utama</h4>
                <table class="rekap-table" border="1" cellpadding="5" cellspacing="0">
                    <thead>
                        <tr>
                            <th width="40">No</th>
                            <th>Dokter DPJP</th>
                            <th>Rincian Jenis Bayar</th>
                            <th width="110">Jumlah Pasien</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no_dpjp = 1; foreach ($rekap_dpjp as $dpjp_name => $info): ?>
                        <tr>
                            <td><?= $no_dpjp++ ?></td>
                            <td style="text-align:left; font-weight:bold; color:#0f7541;"><?= htmlspecialchars($dpjp_name) ?></td>
                            <td style="text-align:left;">
                                <?php foreach ($info['bayar'] as $jb_name => $jb_cnt): ?>
                                    <span class="jb-pill"><?= htmlspecialchars($jb_name) ?>: <strong><?= $jb_cnt ?></strong></span>
                                <?php endforeach; ?>
                            </td>
                            <td style="font-weight:bold; color:#d35400; background:#fff3e0;"><?= $info['total'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#e8f5e9; font-weight:bold;">
                            <td colspan="3" style="text-align:right; color:#0f7541;">TOTAL KESELURUHAN PASIEN</td>
                            <td style="color:#0f7541; font-size:12px;"><?= number_format($total_pasien, 0, ',', '.') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rekap-card rekap-card-oranye">
                <h4>Rekapitulasi Jenis Bayar</h4>
                <table class="rekap-table" border="1" cellpadding="5" cellspacing="0">
                    <thead>
                        <tr>
                            <th width="40">No</th>
                            <th>Jenis Bayar</th>
                            <th width="110">Jumlah Pasien</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no_jb = 1; foreach ($rekap_bayar as $jb_name => $jb_cnt): ?>
                        <tr>
                            <td><?= $no_jb++ ?></td>
                            <td style="text-align:left; font-weight:bold;"><?= htmlspecialchars($jb_name) ?></td>
                            <td style="font-weight:bold; color:#0f7541;"><?= $jb_cnt ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#fff3e0; font-weight:bold;">
                            <td colspan="2" style="text-align:right; color:#d35400;">TOTAL KESELURUHAN</td>
                            <td style="color:#d35400; font-size:12px;"><?= number_format($total_pasien, 0, ',', '.') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <br>
        <table border="1" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th colspan="4" style="background-color:#0F7541; color:#FFFFFF;">REKAPITULASI PASIEN PER DPJP & JENIS BAYAR</th>
                </tr>
                <tr>
                    <th style="background-color:#0F7541; color:#FFFFFF;">No</th>
                    <th style="background-color:#0F7541; color:#FFFFFF;">Dokter DPJP</th>
                    <th style="background-color:#0F7541; color:#FFFFFF;">Rincian Jenis Bayar</th>
                    <th style="background-color:#0F7541; color:#FFFFFF;">Jumlah Pasien</th>
                </tr>
            </thead>
            <tbody>
                <?php $no_dpjp = 1; foreach ($rekap_dpjp as $dpjp_name => $info): 
                    $parts = [];
                    foreach ($info['bayar'] as $jb_name => $jb_cnt) {
                        $parts[] = $jb_name . ': ' . $jb_cnt;
                    }
                ?>
                <tr>
                    <td style="text-align:center;"><?= $no_dpjp++ ?></td>
                    <td style="text-align:left; font-weight:bold;"><?= htmlspecialchars($dpjp_name) ?></td>
                    <td style="text-align:left;"><?= htmlspecialchars(implode(' | ', $parts)) ?></td>
                    <td style="text-align:center; font-weight:bold;"><?= $info['total'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="3" style="text-align:right; font-weight:bold;">TOTAL KESELURUHAN PASIEN</td>
                    <td style="text-align:center; font-weight:bold;"><?= $total_pasien ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!$isExcel): ?>
<div style="text-align:center; font-size:12px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. All Rights Reserved.
</div>
<?php endif; ?>

</body>
</html>
