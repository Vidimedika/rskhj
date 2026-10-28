<?php
$host = "192.168.10.29";
$db = "rskhj";
$user = "root";
$pass = "1nternet";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div style='padding:20px;color:#b02a37;font-family:Arial,sans-serif'>Koneksi database gagal.</div>");
}

$bulan_input = $_GET['bulan'] ?? date('m');
$tahun_input = $_GET['tahun'] ?? date('Y');
if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $bulan_input, $bulan_lama)) {
    $tahun_input = $bulan_lama[1];
    $bulan_input = $bulan_lama[2];
}
$bulan = preg_match('/^(0[1-9]|1[0-2])$/', (string) $bulan_input) ? $bulan_input : date('m');
$tahun = preg_match('/^\d{4}$/', (string) $tahun_input) ? $tahun_input : date('Y');
$tgl_awal = $tahun . '-' . $bulan . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));
$nama_bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$tahun_options = range(min(2020, (int) $tahun), max((int) date('Y') + 1, (int) $tahun));

$sql = "SELECT
            b.tanggal_booking AS tanggal,
            COUNT(*) AS total_booking,
            SUM(CASE WHEN skdp.nomr IS NOT NULL THEN 1 ELSE 0 END) AS booking_dengan_skdp,
            SUM(CASE WHEN skdp.nomr IS NULL THEN 1 ELSE 0 END) AS booking_tanpa_skdp,
            SUM(CASE WHEN skdp.nomr IS NOT NULL AND hadir.no_rkm_medis IS NOT NULL THEN 1 ELSE 0 END) AS hadir_dengan_skdp,
            SUM(CASE WHEN skdp.nomr IS NULL AND hadir.no_rkm_medis IS NOT NULL THEN 1 ELSE 0 END) AS hadir_tanpa_skdp
        FROM (
            SELECT
                br.no_rkm_medis,
                br.tanggal_booking,
                COALESCE(br.tanggal_periksa, skmax.tgl_rencana_max, br.tanggal_booking) AS tgl_kontrol
            FROM booking_registrasi br
            LEFT JOIN (
                SELECT sep.nomr, MAX(sk.tgl_rencana) AS tgl_rencana_max
                FROM bridging_sep sep
                INNER JOIN bridging_surat_kontrol_bpjs sk ON sk.no_sep = sep.no_sep
                GROUP BY sep.nomr
            ) skmax ON skmax.nomr = br.no_rkm_medis
            WHERE br.tanggal_booking BETWEEN ? AND ?
        ) b
        LEFT JOIN (
            SELECT sep.nomr, sk.tgl_rencana
            FROM bridging_sep sep
            INNER JOIN bridging_surat_kontrol_bpjs sk ON sk.no_sep = sep.no_sep
            GROUP BY sep.nomr, sk.tgl_rencana
        ) skdp ON skdp.nomr = b.no_rkm_medis AND skdp.tgl_rencana = b.tgl_kontrol
        LEFT JOIN (
            SELECT no_rkm_medis, tgl_registrasi
            FROM reg_periksa
            WHERE stts != 'Batal'
            GROUP BY no_rkm_medis, tgl_registrasi
        ) hadir ON hadir.no_rkm_medis = b.no_rkm_medis AND hadir.tgl_registrasi = b.tgl_kontrol
        LEFT JOIN (
            SELECT no_rkm_medis, tgl_registrasi
            FROM reg_periksa
            WHERE stts = 'Batal'
            GROUP BY no_rkm_medis, tgl_registrasi
        ) batal ON batal.no_rkm_medis = b.no_rkm_medis AND batal.tgl_registrasi = b.tgl_kontrol
        WHERE batal.no_rkm_medis IS NULL
        GROUP BY b.tanggal_booking
        ORDER BY b.tanggal_booking";
$stmt = $conn->prepare($sql);
$stmt->execute([$tgl_awal, $tgl_akhir]);
$booking_harian = $stmt->fetchAll();

$rekap = [
    'total_booking' => 0,
    'booking_dengan_skdp' => 0,
    'booking_tanpa_skdp' => 0,
    'hadir_dengan_skdp' => 0,
    'hadir_tanpa_skdp' => 0,
];
$harian = [];
foreach ($booking_harian as $row) {
    $harian[$row['tanggal']] = $row;
    foreach ($rekap as $kolom => $total) {
        $rekap[$kolom] += (int) ($row[$kolom] ?? 0);
    }
}

$sql_surat_harian = "SELECT
        sk.tgl_surat,
        COUNT(DISTINCT CASE WHEN batal.no_rkm_medis IS NULL THEN sk.no_surat END) AS total,
        COUNT(DISTINCT CASE WHEN batal.no_rkm_medis IS NULL AND hadir.no_rkm_medis IS NOT NULL THEN sk.no_surat END) AS hadir
    FROM bridging_surat_kontrol_bpjs sk
    LEFT JOIN bridging_sep sep ON sep.no_sep = sk.no_sep
    LEFT JOIN (
        SELECT no_rkm_medis, tgl_registrasi
        FROM reg_periksa
        WHERE stts = 'Batal'
        GROUP BY no_rkm_medis, tgl_registrasi
    ) batal ON batal.no_rkm_medis = sep.nomr AND batal.tgl_registrasi = sk.tgl_rencana
    LEFT JOIN (
        SELECT no_rkm_medis, tgl_registrasi
        FROM reg_periksa
        WHERE stts != 'Batal'
        GROUP BY no_rkm_medis, tgl_registrasi
    ) hadir ON hadir.no_rkm_medis = sep.nomr AND hadir.tgl_registrasi = sk.tgl_rencana
    WHERE sk.tgl_surat BETWEEN ? AND ?
    GROUP BY sk.tgl_surat";
$stmt_surat_harian = $conn->prepare($sql_surat_harian);
$stmt_surat_harian->execute([$tgl_awal, $tgl_akhir]);
$surat_harian = [];
$surat_hadir_harian = [];
$total_surat_terbit = 0;
 $total_surat_hadir = 0;
foreach ($stmt_surat_harian->fetchAll() as $row) {
    $surat_harian[$row['tgl_surat']] = (int) $row['total'];
    $total_surat_terbit += (int) $row['total'];
    $surat_hadir_harian[$row['tgl_surat']] = (int) $row['hadir'];
    $total_surat_hadir += (int) $row['hadir'];
}

$total_booking_non_skdp = (int) $rekap['booking_tanpa_skdp'];
$total_hadir_non_skdp = (int) $rekap['hadir_tanpa_skdp'];
$total_kunjungan_gabungan = $total_surat_terbit + $total_booking_non_skdp;
$total_hadir_gabungan = $total_surat_hadir + $total_hadir_non_skdp;
$total_tidak_hadir_gabungan = $total_kunjungan_gabungan - $total_hadir_gabungan;

$nama_hari = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$tanggal_harian = [];
for ($tanggal = strtotime($tgl_awal); $tanggal <= strtotime($tgl_akhir); $tanggal = strtotime('+1 day', $tanggal)) {
    $tanggal_harian[] = date('Y-m-d', $tanggal);
}

function tanggalIndo($tanggal, $namaHari, $namaBulan) {
    $waktu = strtotime($tanggal);
    return $namaHari[(int) date('N', $waktu)] . ', ' . date('d', $waktu) . ' ' . $namaBulan[(int) date('n', $waktu)] . ' ' . date('Y', $waktu);
}

function angka($nilai) {
    return number_format((int) ($nilai ?? 0), 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Booking Bulanan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { background: #fff; padding: 24px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,.08); max-width: 1400px; margin: 0 auto; }
        h2 { text-align: center; margin: 4px 0; color: #0a7c3b; text-transform: uppercase; letter-spacing: .5px; }
        h4 { text-align: center; margin: 0 0 20px; color: #555; font-weight: 400; font-size: 14px; }
        .page-tabs { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin: 0 0 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 12px; }
        .page-tab { display: inline-flex; align-items: center; gap: 7px; padding: 9px 15px; border: 1px solid #ced4da; border-radius: 6px; color: #495057; background: #f8f9fa; text-decoration: none; font-weight: 600; }
        .page-tab:hover { background: #e9ecef; }
        .page-tab.active { color: #fff; background: #0a7c3b; border-color: #0a7c3b; }
        form { background: #f8f9fa; padding: 18px; border-radius: 8px; border: 1px solid #e9ecef; margin-bottom: 20px; display: flex; align-items: end; gap: 10px; flex-wrap: wrap; }
        label { display: flex; flex-direction: column; gap: 5px; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #495057; }
        input, select, button { padding: 8px 10px; border: 1px solid #ced4da; border-radius: 5px; font-size: 12px; }
        button { background: #0a7c3b; border-color: #0a7c3b; color: white; cursor: pointer; font-weight: 600; }
        .summary-container { display: grid; grid-template-columns: repeat(auto-fit,minmax(210px,1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-box { padding: 15px; border-radius: 8px; border: 1px solid #e1e4e8; background: #fafbfc; box-shadow: 0 2px 4px rgba(0,0,0,.02); border-left: 5px solid #0a7c3b; }
        .stat-box small { font-size: 11px; color: #6a737d; font-weight: 700; text-transform: uppercase; }
        .stat-box div { font-size: 22px; font-weight: 700; margin-top: 5px; color: #24292e; }
        .stat-box.hadir { border-left-color: #2e7d32; background: #f1f8e9; }
        .stat-box.tanpa { border-left-color: #d97706; }
        .note { color: #666; font-size: 12px; margin: 0 0 18px; }
        .table-responsive { overflow-x: auto; border: 1px solid #dee2e6; border-radius: 6px; }
        table { border-collapse: collapse; width: 100%; background: #fff; }
        th, td { border: 1px solid #e9ecef; padding: 9px 12px; text-align: center; white-space: nowrap; }
        th { background: #0a7c3b; color: #fff; font-size: 11px; text-transform: uppercase; }
        tbody tr:nth-child(even) { background: #f8f9fa; }
        tfoot { font-weight: 700; background: #e8f5e9; }
        .footer { text-align: center; font-size: 11px; color: #6c757d; margin-top: 25px; border-top: 1px solid #dee2e6; padding-top: 15px; }
    </style>
</head>
<body>
<main class="container">
    <h2>Rekap Booking Kontrol Bulanan</h2>
    <h4>Terintegrasi dengan SIMRS Khanza</h4>
    <nav class="page-tabs" aria-label="Menu booking pasien">
        <a class="page-tab" href="bookingkontrol.php"><i class="fa-solid fa-id-card"></i> Pasien Booking Kontrol BPJS</a>
        <a class="page-tab" href="bookingkontrol.php?view=non_skdp"><i class="fa-solid fa-user"></i> Pasien Booking Kontrol Non-SKDP</a>
        <span class="page-tab active" aria-current="page"><i class="fa-solid fa-calendar-days"></i> Booking 1 Bulan</span>
    </nav>
    <form method="get">
        <?php if (($_GET['view'] ?? '') === 'monthly'): ?>
            <input type="hidden" name="view" value="monthly">
        <?php endif; ?>
        <label>Bulan
            <select name="bulan">
                <?php foreach ($nama_bulan as $nomor_bulan => $nama): ?>
                    <option value="<?= sprintf('%02d', $nomor_bulan) ?>" <?= $bulan === sprintf('%02d', $nomor_bulan) ? 'selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Tahun
            <select name="tahun">
                <?php foreach ($tahun_options as $opsi_tahun): ?>
                    <option value="<?= $opsi_tahun ?>" <?= (int) $tahun === $opsi_tahun ? 'selected' : '' ?>><?= $opsi_tahun ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><i class="fa-solid fa-filter"></i> Tampilkan</button>
    </form>
    <p class="note">Periode <?= htmlspecialchars($tgl_awal) ?> sampai <?= htmlspecialchars($tgl_akhir) ?>. Surat kontrol BPJS dikelompokkan menurut tanggal terbit dan booking Non-SKDP menurut tanggal booking. Total pasien = surat kontrol BPJS + booking Non-SKDP; total hadir/tidak hadir adalah penjumlahan kedua kelompok. Kehadiran memakai registrasi pada tanggal kontrol dengan status selain Batal.</p>
    <section class="summary-container">
        <div class="stat-box"><small>Surat Kontrol Terbit BPJS</small><div><?= angka($total_surat_terbit) ?></div></div>
        <div class="stat-box tanpa"><small>Booking Non-SKDP</small><div><?= angka($total_booking_non_skdp) ?></div></div>
        <div class="stat-box"><small>Total BPJS + Non-SKDP</small><div><?= angka($total_kunjungan_gabungan) ?></div></div>
        <div class="stat-box hadir"><small>Total Hadir Gabungan</small><div><?= angka($total_hadir_gabungan) ?></div></div>
        <div class="stat-box tanpa"><small>Total Tidak Hadir Gabungan</small><div><?= angka($total_tidak_hadir_gabungan) ?></div></div>
    </section>
    <h3>Rekap Harian</h3>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Surat Kontrol BPJS Terbit</th>
                    <th>Booking Non-SKDP</th>
                    <th>Total BPJS + Non-SKDP</th>
                    <th>Hadir BPJS</th>
                    <th>Hadir Non-SKDP</th>
                    <th>Total Hadir Gabungan</th>
                    <th>Tidak Hadir BPJS</th>
                    <th>Tidak Hadir Non-SKDP</th>
                    <th>Total Tidak Hadir Gabungan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tanggal_harian as $tgl):
                    $data = $harian[$tgl] ?? [];
                    $surat_hari_ini = (int) ($surat_harian[$tgl] ?? 0);
                    $hadir_surat_hari_ini = (int) ($surat_hadir_harian[$tgl] ?? 0);
                    $booking_non_skdp_hari_ini = (int) ($data['booking_tanpa_skdp'] ?? 0);
                    $hadir_non_skdp_hari_ini = (int) ($data['hadir_tanpa_skdp'] ?? 0);
                    $tidak_hadir_surat_hari_ini = max(0, $surat_hari_ini - $hadir_surat_hari_ini);
                    $tidak_hadir_non_skdp_hari_ini = max(0, $booking_non_skdp_hari_ini - $hadir_non_skdp_hari_ini);
                    $total_hadir_hari_ini = $hadir_surat_hari_ini + $hadir_non_skdp_hari_ini;
                    $total_tidak_hadir_hari_ini = $tidak_hadir_surat_hari_ini + $tidak_hadir_non_skdp_hari_ini;
                ?>
                    <tr>
                        <td><?= htmlspecialchars(tanggalIndo($tgl, $nama_hari, $nama_bulan)) ?></td>
                        <td><?= angka($surat_hari_ini) ?></td>
                        <td><?= angka($booking_non_skdp_hari_ini) ?></td>
                        <td><?= angka($surat_hari_ini + $booking_non_skdp_hari_ini) ?></td>
                        <td><?= angka($hadir_surat_hari_ini) ?></td>
                        <td><?= angka($hadir_non_skdp_hari_ini) ?></td>
                        <td><?= angka($total_hadir_hari_ini) ?></td>
                        <td><?= angka($tidak_hadir_surat_hari_ini) ?></td>
                        <td><?= angka($tidak_hadir_non_skdp_hari_ini) ?></td>
                        <td><?= angka($total_tidak_hadir_hari_ini) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td><?= angka($total_surat_terbit) ?></td>
                    <td><?= angka($total_booking_non_skdp) ?></td>
                    <td><?= angka($total_kunjungan_gabungan) ?></td>
                    <td><?= angka($total_surat_hadir) ?></td>
                    <td><?= angka($total_hadir_non_skdp) ?></td>
                    <td><?= angka($total_hadir_gabungan) ?></td>
                    <td><?= angka(max(0, $total_surat_terbit - $total_surat_hadir)) ?></td>
                    <td><?= angka(max(0, $total_booking_non_skdp - $total_hadir_non_skdp)) ?></td>
                    <td><?= angka($total_tidak_hadir_gabungan) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="footer">© 2026 PT VIDI MEDIKA PRATAMA. All Rights Reserved.<br><i>Smart IT for Health</i></div>
</main>
</body>
</html>
