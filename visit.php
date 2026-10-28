<?php
/* Laporan visiting rawat inap berdasarkan tanggal pasien masuk. */
$host = '192.168.10.29';
$db = 'rskhj';
$user = 'root';
$pass = '1nternet';
$charset = 'utf8mb4';

$koneksi = mysqli_connect($host, $user, $pass, $db);
if (!$koneksi) {
    die('Koneksi gagal : ' . mysqli_connect_error());
}
mysqli_set_charset($koneksi, $charset);

$tanggalValid = static function ($nilai, $default) {
    if (!is_string($nilai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
        return $default;
    }
    $tanggal = DateTime::createFromFormat('Y-m-d', $nilai);
    return $tanggal && $tanggal->format('Y-m-d') === $nilai ? $nilai : $default;
};
$tgl_awal = $tanggalValid($_GET['tgl_awal'] ?? null, date('Y-m-01'));
$tgl_akhir = $tanggalValid($_GET['tgl_akhir'] ?? null, date('Y-m-d'));
$cari = mysqli_real_escape_string($koneksi, (string)($_GET['cari'] ?? ''));

$sql = "SELECT
            ki.tgl_masuk,
            ki.jam_masuk,
            r.no_rawat,
            p.no_rkm_medis,
            p.nm_pasien,
            CONCAT(k.kd_kamar,
                IF(k.kelas IS NULL OR k.kelas = '', '', CONCAT(' Kamar ', k.kelas))) AS ruangan,
            CASE
                WHEN UPPER(CONCAT(b.nm_bangsal, ' ', k.kd_kamar)) REGEXP 'SAKURA|EDELWEIS|TULIP|R[.]?[[:space:]]*OBGYN' THEN 'RANAP 2'
                WHEN UPPER(CONCAT(b.nm_bangsal, ' ', k.kd_kamar)) REGEXP 'BOUGENVIL(LE|E)?|ANGGREK|MAWAR|JASMINE' THEN 'RANAP 3'
                WHEN UPPER(CONCAT(b.nm_bangsal, ' ', k.kd_kamar)) REGEXP 'LILY|MAGNOLIA' THEN 'VIP/VVIP'
                ELSE '-' END AS unit
        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN kamar_inap ki ON r.no_rawat = ki.no_rawat
        INNER JOIN kamar k ON ki.kd_kamar = k.kd_kamar
        INNER JOIN bangsal b ON k.kd_bangsal = b.kd_bangsal
        WHERE ki.tgl_masuk BETWEEN '$tgl_awal' AND '$tgl_akhir'
          AND NOT EXISTS (
              SELECT 1
              FROM kamar_inap ki2
              WHERE ki2.no_rawat = ki.no_rawat
                AND ki2.tgl_masuk BETWEEN '$tgl_awal' AND '$tgl_akhir'
                AND (
                    ki2.tgl_masuk > ki.tgl_masuk
                    OR (ki2.tgl_masuk = ki.tgl_masuk AND ki2.jam_masuk > ki.jam_masuk)
                    OR (ki2.tgl_masuk = ki.tgl_masuk AND ki2.jam_masuk = ki.jam_masuk AND ki2.kd_kamar > ki.kd_kamar)
                )
          )
          AND b.nm_bangsal NOT LIKE '%VK%'
          AND b.nm_bangsal NOT LIKE '%PERINA%'
          AND b.nm_bangsal NOT LIKE '%RUANG BAYI%'
          AND b.nm_bangsal NOT LIKE '%KAMAR BAYI%'
          AND b.nm_bangsal NOT LIKE '%ISOLASI%'
          AND b.nm_bangsal NOT LIKE '%ICU%'
        ";
if ($cari !== '') {
    $sql = rtrim($sql, ";\r\n ");
    $sql .= " AND (p.nm_pasien LIKE '%$cari%' OR p.no_rkm_medis = '$cari'
                   OR EXISTS (SELECT 1 FROM bridging_sep s WHERE s.no_rawat = r.no_rawat AND s.no_sep = '$cari'))";
}
$sql .= " ORDER BY ki.tgl_masuk, ki.jam_masuk, p.nm_pasien, ki.kd_kamar";

$res = mysqli_query($koneksi, $sql);
$data_tampil = [];
if ($res) {
    $rawat_sudah_tampil = [];
    while ($row = mysqli_fetch_assoc($res)) {
        // Pastikan satu baris per kunjungan walau ada relasi duplikat di database.
        if (isset($rawat_sudah_tampil[$row['no_rawat']])) {
            continue;
        }
        $rawat_sudah_tampil[$row['no_rawat']] = true;
        $data_tampil[] = $row;
    }
}
$total_data = count($data_tampil);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Visiting Rawat Inap</title>
    <style>
        :root { --hijau: #00843D; --oren: #FF8300; --bg: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); padding: 20px; font-size: 12px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,.1); padding: 20px; border-top: 5px solid var(--oren); }
        .header { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:15px; }
        .header h2 { color:var(--hijau); margin:0; }
        .total { background:var(--hijau); color:white; padding:10px 20px; border-radius:8px; white-space:nowrap; }
        .filter-bar { display:flex; flex-wrap:wrap; gap:8px; background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #ddd; }
        input, button { padding:7px; border:1px solid #ccc; border-radius:4px; }
        button { color:white; border:0; cursor:pointer; font-weight:bold; }
        .btn-filter { background:var(--hijau); }
        .btn-export { background:var(--oren); }
        .table-container { overflow:auto; max-height:70vh; border:1px solid #eee; }
        table { width:100%; border-collapse:collapse; min-width:850px; }
        th { background:var(--hijau); color:white; position:sticky; top:0; padding:9px; text-align:left; z-index:1; }
        td, th { border:1px solid #ddd; padding:8px; }
        td:nth-child(1), td:nth-child(2), td:nth-child(3), td:nth-child(6) { text-align:center; }
    </style>
</head>
<body>
<div class="card">
    <div class="header">
        <h2>DATA VISITING RAWAT INAP</h2>
        <div class="total">Total Pasien Masuk: <strong><?= number_format($total_data) ?></strong></div>
    </div>
    <form method="GET">
        <div class="filter-bar">
            <label>Dari <input type="date" name="tgl_awal" value="<?= htmlspecialchars($tgl_awal, ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Sampai <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($tgl_akhir, ENT_QUOTES, 'UTF-8') ?>"></label>
            <input type="text" name="cari" placeholder="Nama / No. RM / No. SEP" value="<?= htmlspecialchars($cari, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-filter">FILTER</button>
            <button type="button" class="btn-export" onclick="exportExcel()">EXPORT EXCEL</button>
        </div>
    </form>
    <div class="table-container">
        <table>
            <thead><tr>
                <th>NO</th><th>TANGGAL MASUK</th><th>NO. RM</th><th>NAMA PASIEN</th><th>RUANGAN</th><th>UNIT</th><th>KETERANGAN</th>
            </tr></thead>
            <tbody>
            <?php if ($total_data): foreach ($data_tampil as $i => $row): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($row['tgl_masuk'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['no_rkm_medis'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(strtoupper($row['nm_pasien']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['ruangan'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['unit'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" style="text-align:center">Data tidak ditemukan.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function exportExcel() {
    const table = document.querySelector('.table-container table');
    const start = <?= json_encode($tgl_awal) ?>;
    const end = <?= json_encode($tgl_akhir) ?>;
    const html = '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #777;padding:6px}th{background:#00843D;color:#fff}</style></head><body>' +
        '<h3>DATA VISITING RAWAT INAP</h3><p>Pasien masuk: ' + start + ' s.d. ' + end + '</p>' + table.outerHTML + '</body></html>';
    const blob = new Blob(['\ufeff', html], {type:'application/vnd.ms-excel'});
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'Visiting_Rawat_Inap_' + start + '_' + end + '.xls';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>

