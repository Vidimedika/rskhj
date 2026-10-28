<?php
/* RS KARTIKA HUSADA TAMBUN - Dashboard Monitoring & Statistik
   Optimized & Separated Columns Version
   Developer: Vidi Medika | Vidi Medika Konsultan IT
*/

// 1. KONFIGURASI KONEKSI DATABASE
$host    = '192.168.10.29';
$db      = 'rskhj';
$user    = 'root';
$pass    = '1nternet';
$charset = 'utf8mb4';

// Inisialisasi koneksi MySQLi
$koneksi = mysqli_connect($host, $user, $pass, $db);

// Cek status koneksi
if (!$koneksi) {
    die("Koneksi gagal : " . mysqli_connect_error());
}

// Set character set
mysqli_set_charset($koneksi, $charset);

/* ================= FILTER & SANITASI ================= */
$tgl_awal  = isset($_GET['tgl_awal']) ? mysqli_real_escape_string($koneksi, $_GET['tgl_awal']) : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? mysqli_real_escape_string($koneksi, $_GET['tgl_akhir']) : date('Y-m-d');
$cari      = isset($_GET['cari']) ? mysqli_real_escape_string($koneksi, $_GET['cari']) : "";
$dokter    = isset($_GET['dokter']) ? mysqli_real_escape_string($koneksi, $_GET['dokter']) : "";
$poli      = isset($_GET['poli']) ? mysqli_real_escape_string($koneksi, $_GET['poli']) : "";

// Fungsi Opsi Dropdown
function getOptions($koneksi, $sql, $field) {
    $res = mysqli_query($koneksi, $sql);
    $data = [];
    if ($res) {
        while($row = mysqli_fetch_assoc($res)) {
            $data[] = $row[$field];
        }
    }
    return $data;
}

$opt_dokter = getOptions($koneksi, "SELECT nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter", "nm_dokter");
$opt_poli   = getOptions($koneksi, "SELECT nm_poli FROM poliklinik WHERE status='1' AND TRIM(nm_poli) <> '-' ORDER BY nm_poli", "nm_poli");

/* ================= OPTIMIZED QUERY ================= */
$where = "WHERE r.tgl_registrasi BETWEEN '$tgl_awal' AND '$tgl_akhir'
          AND pj.png_jawab LIKE '%BPJS%'
          AND pj.png_jawab NOT LIKE '%Alkes BPJS%'
          AND r.status_lanjut = 'Ralan'
          AND po.status = '1'
          AND NULLIF(TRIM(po.nm_poli), '') IS NOT NULL
          AND TRIM(po.nm_poli) <> '-'
          AND po.nm_poli NOT LIKE '%IGD%'
          AND po.nm_poli NOT LIKE '%GAWAT DARURAT%'";
if($cari)   $where .= " AND (p.nm_pasien LIKE '%$cari%' OR p.no_rkm_medis = '$cari' OR s.no_sep = '$cari')";
if($dokter) $where .= " AND d.nm_dokter = '$dokter'";
if($poli)   $where .= " AND po.nm_poli = '$poli'";

$sql = "SELECT 
            r.tgl_registrasi, r.no_rawat, r.stts, r.stts_daftar,
            p.no_rkm_medis, p.nm_pasien, p.jk,
            po.nm_poli, d.nm_dokter, pj.png_jawab,
            s.no_sep, s.nmppkrujukan, s.klsrawat,
            GROUP_CONCAT(DISTINCT CONCAT(dp.kd_penyakit, ' - ', py.nm_penyakit) SEPARATOR '<br>') as diagnosa
        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON r.kd_dokter = d.kd_dokter
        INNER JOIN poliklinik po ON r.kd_poli = po.kd_poli
        INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
        LEFT JOIN bridging_sep s ON r.no_rawat = s.no_rawat
        LEFT JOIN diagnosa_pasien dp ON r.no_rawat = dp.no_rawat
        LEFT JOIN penyakit py ON dp.kd_penyakit = py.kd_penyakit
        $where
        GROUP BY r.no_rawat
        ORDER BY RAND()";

$res = mysqli_query($koneksi, $sql);
$data_tampil = [];
$pasien_per_hari_poli = [];
if ($res) {
    // Acak kandidat terlebih dahulu, lalu ambil bergiliran antar-poli per hari.
    while ($row = mysqli_fetch_assoc($res)) {
        $tanggal = $row['tgl_registrasi'];
        $nama_poli = $row['nm_poli'];
        $pasien_per_hari_poli[$tanggal][$nama_poli][] = $row;
    }

    // Acak responden di dalam tanggal, tetapi tampilkan tanggal dari yang paling awal.
    ksort($pasien_per_hari_poli, SORT_STRING);

    foreach ($pasien_per_hari_poli as $poli_harian) {
        $jumlah_poli = count($poli_harian);
        $indeks_poli = array_keys($poli_harian);
        $urutan_poli = 0;
        $terpilih_hari_ini = 0;

        while ($terpilih_hari_ini < 10 && $jumlah_poli > 0) {
            $ada_pasien = false;
            for ($i = 0; $i < $jumlah_poli && $terpilih_hari_ini < 10; $i++) {
                $nama_poli = $indeks_poli[($urutan_poli + $i) % $jumlah_poli];
                if (!empty($poli_harian[$nama_poli])) {
                    $data_tampil[] = array_shift($poli_harian[$nama_poli]);
                    $terpilih_hari_ini++;
                    $ada_pasien = true;
                }
            }
            if (!$ada_pasien) break;
            $urutan_poli++;
        }
    }
}
$total_data = count($data_tampil);

function getBadgeColor($stts) {
    $colors = [
        'Belum'   => '#95a5a6', 
        'Sudah'   => '#27ae60', 
        'Batal'   => '#e74c3c', 
        'Dirujuk' => '#f39c12', 
        'Dirawat' => '#2980b9'
    ];
    return $colors[$stts] ?? '#34495e';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring RSKH Separated Columns</title>
    <style>
        :root { --hijau: #00843D; --oren: #FF8300; --bg: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); padding: 20px; font-size: 11px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); padding: 20px; border-top: 5px solid var(--oren); }
        .filter-bar { display: flex; flex-wrap: wrap; gap: 8px; background: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
        input, select, button { padding: 6px; border: 1px solid #ccc; border-radius: 4px; }
        .btn-filter { background: var(--hijau); color: white; border: none; cursor: pointer; font-weight: bold; }
        .table-container { overflow-x: auto; max-height: 550px; border: 1px solid #eee; }
        table { width: 100%; border-collapse: collapse; min-width: 1500px; }
        th { background: var(--hijau); color: white; position: sticky; top: 0; padding: 10px; text-align: left; z-index: 10; border: 1px solid #006b31; }
        td { padding: 8px; border: 1px solid #eee; vertical-align: top; }
        .badge { padding: 3px 6px; border-radius: 4px; color: white; font-size: 10px; font-weight: bold; display: inline-block; }
        .no-sep { color: #0056b3; font-weight: bold; }
    </style>
</head>
<body>

<div class="card" id="areaLaporan">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h2 style="color: var(--hijau); margin: 0;">RESPONDEN TENTANG PELAYANAN RAWAT JALAN</h2>
        <div style="background: var(--hijau); color: white; padding: 10px 20px; border-radius: 8px;">
            Total Responden: <strong><?= number_format($total_data) ?></strong>
        </div>
    </div>

    <form method="GET">
        <div class="filter-bar">
            <input type="date" name="tgl_awal" value="<?= htmlspecialchars($tgl_awal) ?>">
            <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($tgl_akhir) ?>">
            <input type="text" name="cari" placeholder="Nama/RM/No.SEP" value="<?= htmlspecialchars($cari) ?>">
            
            <select name="poli">
                <option value="">- Semua Poli -</option>
                <?php foreach($opt_poli as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $p == $poli ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="dokter">
                <option value="">- Semua Dokter -</option>
                <?php foreach($opt_dokter as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= $d == $dokter ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-filter">FILTER</button>
            <button type="button" onclick="exportExcel()" style="background: var(--oren); border:none; color:white; cursor:pointer; padding:6px 15px; border-radius:4px; font-weight:bold;">EXPORT EXCEL</button>
        </div>
    </form>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>NO</th>
                    <th>TANGGAL</th>
                    <th>NAMA PASIEN</th>
                    <th>JK</th>
                    <th>POLI TUJUAN</th>
                    <th>Jenis Pelayanan</th>
                    <th>Kemudahan Pelayanan</th>
                    <th>Waktu Pelayanan</th>
                    <th>Biaya/Tarif Pelayanan</th>
                    <th>Standar Pelayanan</th>
                    <th>Kompetensi Petugas Pelayanan</th>
                    <th>Sikap Keramahan Pelayanan</th>
                    <th>Kualitas Sarana &amp; Prasarana</th>
                    <th>Pelayanan Penanganan Pengaduan</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1; 
                if ($total_data > 0):
                    foreach ($data_tampil as $row):
                ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['tgl_registrasi']) ?></td>
                    <td><?= htmlspecialchars(strtoupper($row['nm_pasien'])) ?></td>
                    <td><?= htmlspecialchars($row['jk'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php 
                    endforeach;
                else: 
                ?>
                <tr>
                    <td colspan="14" style="text-align:center; padding:20px;">Data tidak ditemukan.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function exportExcel() {
    var tabel = document.querySelector(".table-container table");
    if (!tabel) return;

    var tanggalAwal = <?= json_encode($tgl_awal) ?>;
    var tanggalAkhir = <?= json_encode($tgl_akhir) ?>;
    var html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">' +
        '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Data Pasien</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->' +
        '<style>table{border-collapse:collapse}th,td{border:1px solid #777;padding:6px}th{background:#00843D;color:#fff;text-align:left}</style></head><body>' +
        '<h3>RESPONDEN TENTANG PELAYANAN RAWAT JALAN</h3>' +
        '<p>Periode: ' + tanggalAwal + ' s.d. ' + tanggalAkhir + ' | Maksimal 10 pasien per hari</p>' +
        tabel.outerHTML + '</body></html>';
    var blob = new Blob(['\ufeff', html], {
        type: 'application/vnd.ms-excel'
    });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = "Monitoring_BPJS_Poliklinik_" + tanggalAwal + "_" + tanggalAkhir + ".xls";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>

