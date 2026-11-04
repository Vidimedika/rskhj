<?php
// 1. KONFIGURASI KONEKSI
$db_hostname = "192.168.10.29";
$db_username = "root";
$db_password = "1nternet";
$db_name     = "rskhj";

try {
    $pdo = new PDO("mysql:host=$db_hostname;dbname=$db_name;charset=utf8", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage());
}

// 2. LOGIKA FILTER
$tgl1          = $_GET['tgl1'] ?? date('Y-m-d');
$tgl2          = $_GET['tgl2'] ?? date('Y-m-d');
$status        = $_GET['status'] ?? 'non_batal';
$status_lanjut = $_GET['status_lanjut'] ?? 'semua';
$kd_poli       = $_GET['kd_poli'] ?? 'semua';
$kd_dokter     = $_GET['kd_dokter'] ?? 'semua';
$kd_pj         = $_GET['kd_pj'] ?? 'semua';

// Ambil list Poli (hanya yang ada datanya pada periode terpilih), Dokter, dan Penjab untuk filter
$sql_poli = "SELECT DISTINCT p.kd_poli, p.nm_poli
             FROM poliklinik p
             INNER JOIN reg_periksa r ON p.kd_poli = r.kd_poli
             WHERE r.tgl_registrasi BETWEEN :tgl1 AND :tgl2";
$params_poli = ['tgl1' => $tgl1, 'tgl2' => $tgl2];
if ($status == 'non_batal') {
    $sql_poli .= " AND r.stts <> 'Batal'";
}
if ($status_lanjut != 'semua') {
    $sql_poli .= " AND r.status_lanjut = :status_lanjut";
    $params_poli['status_lanjut'] = $status_lanjut;
}
if ($kd_pj != 'semua') {
    $sql_poli .= " AND r.kd_pj = :kd_pj";
    $params_poli['kd_pj'] = $kd_pj;
}
$sql_poli .= " ORDER BY p.nm_poli";
$stmt_poli = $pdo->prepare($sql_poli);
$stmt_poli->execute($params_poli);
$list_poli = $stmt_poli->fetchAll(PDO::FETCH_ASSOC);

// Jika kd_poli yang dipilih sebelumnya tidak ada datanya pada periode ini, kembalikan ke 'semua'
if ($kd_poli != 'semua' && !in_array($kd_poli, array_column($list_poli, 'kd_poli'), true)) {
    $kd_poli = 'semua';
}

$list_dokter = $pdo->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter")->fetchAll();
$list_pj     = $pdo->query("SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab")->fetchAll();

// 3. QUERY SQL (OPTIMIZED)
try {
    $params = ['tgl1' => $tgl1, 'tgl2' => $tgl2];
    
    // Query Utama
    $sql = "SELECT 
                reg_periksa.no_rawat, 
                reg_periksa.no_rkm_medis, 
                pasien.nm_pasien, 
                pasien.no_ktp, 
                pasien.jk, 
                reg_periksa.tgl_registrasi, 
                poliklinik.nm_poli, 
                dokter.nm_dokter, 
                reg_periksa.umurdaftar, 
                reg_periksa.sttsumur, 
                reg_periksa.kd_pj,
                penjab.png_jawab, 
                reg_periksa.stts_daftar, 
                reg_periksa.status_poli,
                reg_periksa.status_lanjut,
                reg_periksa.stts,
                COALESCE((SELECT GROUP_CONCAT(DISTINCT sep.no_sep ORDER BY sep.no_sep SEPARATOR ', ')
                          FROM bridging_sep sep
                          WHERE sep.no_rawat = reg_periksa.no_rawat), '-') AS no_sep,
                COALESCE((SELECT GROUP_CONCAT(DISTINCT sk.no_surat SEPARATOR ', ')
                          FROM bridging_sep sep
                          INNER JOIN bridging_surat_kontrol_bpjs sk ON sk.no_sep = sep.no_sep
                          WHERE sep.no_rawat = reg_periksa.no_rawat), '-') AS no_skdp
            FROM reg_periksa 
            INNER JOIN pasien ON reg_periksa.no_rkm_medis = pasien.no_rkm_medis
            INNER JOIN poliklinik ON reg_periksa.kd_poli = poliklinik.kd_poli
            INNER JOIN dokter ON reg_periksa.kd_dokter = dokter.kd_dokter
            INNER JOIN penjab ON reg_periksa.kd_pj = penjab.kd_pj
            WHERE reg_periksa.tgl_registrasi BETWEEN :tgl1 AND :tgl2";

    if ($status == 'non_batal') { 
        $sql .= " AND reg_periksa.stts <> 'Batal'"; 
    }
    if ($status_lanjut != 'semua') {
        $sql .= " AND reg_periksa.status_lanjut = :status_lanjut";
        $params['status_lanjut'] = $status_lanjut;
    }
    if ($kd_poli != 'semua') { 
        $sql .= " AND reg_periksa.kd_poli = :kd_poli"; 
        $params['kd_poli'] = $kd_poli; 
    }
    if ($kd_dokter != 'semua') { 
        $sql .= " AND reg_periksa.kd_dokter = :kd_dokter"; 
        $params['kd_dokter'] = $kd_dokter; 
    }
    if ($kd_pj != 'semua') { 
        $sql .= " AND reg_periksa.kd_pj = :kd_pj"; 
        $params['kd_pj'] = $kd_pj; 
    }

    $sql .= " ORDER BY reg_periksa.tgl_registrasi ASC, reg_periksa.jam_reg ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Inisialisasi Grouping Rekapitulasi
    $rekap_lanjut   = [];
    $rekap_bayar    = [];
    $rekap_bpjs     = [];
    $total_bpjs     = 0;
    $total_non_bpjs = 0;
    $bpjs_ada_sep   = 0;
    $bpjs_tanpa_sep = 0;
    $kode_bpjs_kes  = ['025', '143', '208', '380', '494', '495', '496'];

    foreach($data as $r) {
        // Rekap Status Lanjut
        $stts_lanjut_row = !empty($r['status_lanjut']) ? $r['status_lanjut'] : '-';
        $rekap_lanjut[$stts_lanjut_row] = ($rekap_lanjut[$stts_lanjut_row] ?? 0) + 1;

        // Rekap Jenis Bayar
        $jenis_bayar = !empty($r['png_jawab']) ? $r['png_jawab'] : '-';
        $rekap_bayar[$jenis_bayar] = ($rekap_bayar[$jenis_bayar] ?? 0) + 1;

        // Rekap Kunjungan Pasien BPJS
        $kd_pj_row = trim((string)($r['kd_pj'] ?? ''));
        $is_bpjs = in_array($kd_pj_row, $kode_bpjs_kes, true)
                   || (stripos($jenis_bayar, 'BPJS') !== false
                       && stripos($jenis_bayar, 'KETENAGAKERJAAN') === false
                       && strtoupper(trim($jenis_bayar)) !== 'NON BPJS');
        if ($is_bpjs) {
            $total_bpjs++;
            $rekap_bpjs[$jenis_bayar] = ($rekap_bpjs[$jenis_bayar] ?? 0) + 1;

            $no_sep_val = trim((string)($r['no_sep'] ?? ''));
            if ($no_sep_val !== '' && $no_sep_val !== '-') {
                $bpjs_ada_sep++;
            } else {
                $bpjs_tanpa_sep++;
            }
        } else {
            $total_non_bpjs++;
        }
    }
} catch (Exception $e) {
    $error_msg = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sensus Harian Ralan - RS Kartika Husada</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        body { background-color: #f4f7f5; font-size: 0.75rem; padding-bottom: 60px; }
        .header-title { background: #0f7541; color: white; padding: 15px; border-radius: 8px 8px 0 0; border-bottom: 4px solid #e67e22; }
        .table thead { background-color: #0f7541; color: white; vertical-align: middle; }
        .rekap-box { background: #fff; border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; height: 100%; border-top: 3px solid #0f7541; }
        .rekap-total-box { background: #0f7541 !important; color: white; border-top: 3px solid #e67e22 !important; }
        .dt-buttons { margin-bottom: 10px; }
        .btn-kh-hijau { background-color: #0f7541; color: white; border: none; }
        .btn-kh-hijau:hover { background-color: #0b5730; color: white; }
        .btn-kh-oranye { background-color: #e67e22; color: white; border: none; }
        .btn-kh-oranye:hover { background-color: #d35400; color: white; }
        .diag-tambahan { color: #e67e22; font-style: italic; }
        .text-kh-hijau { color: #0f7541 !important; }
        .text-kh-oranye { color: #e67e22 !important; }
        .border-kh-hijau { border-bottom: 2px solid #0f7541 !important; }
        .page-item.active .page-link { background-color: #0f7541 !important; border-color: #0f7541 !important; }
        .page-link { color: #0f7541; }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="header-title text-center">
            <h5 class="mb-0 fw-bold">RS KARTIKA HUSADA JATIASIH</h5>
            <h5 class="mb-0 fw-bold">DATA KUNJUNGNA HARIAN PASIEN RAWAT JALAN</h5>
            <small class="fw-medium">Periode: <?= htmlspecialchars(date('d-m-Y', strtotime($tgl1))) ?> s/d <?= htmlspecialchars(date('d-m-Y', strtotime($tgl2))) ?></small>
        </div>

        <div class="card-body">
            <form method="GET" class="row g-2 mb-4 p-3 bg-white rounded border">
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Dari Tanggal</label>
                    <input type="date" name="tgl1" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl1) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Sampai Tanggal</label>
                    <input type="date" name="tgl2" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl2) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Poliklinik</label>
                    <select name="kd_poli" class="form-select form-select-sm">
                        <option value="semua">Semua Poli</option>
                        <?php foreach($list_poli as $p): ?>
                            <option value="<?= $p['kd_poli'] ?>" <?= $kd_poli == $p['kd_poli'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nm_poli']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Cara Bayar</label>
                    <select name="kd_pj" class="form-select form-select-sm">
                        <option value="semua">Semua Bayar</option>
                        <?php foreach($list_pj as $pj): ?>
                            <option value="<?= $pj['kd_pj'] ?>" <?= $kd_pj == $pj['kd_pj'] ? 'selected' : '' ?>><?= htmlspecialchars($pj['png_jawab']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Status Rajal / Ranap</label>
                    <select name="status_lanjut" class="form-select form-select-sm">
                        <option value="semua" <?= $status_lanjut == 'semua' ? 'selected' : '' ?>>Semua (Rajal & Ranap)</option>
                        <option value="Ralan" <?= $status_lanjut == 'Ralan' ? 'selected' : '' ?>>Rajal (Ralan)</option>
                        <option value="Ranap" <?= $status_lanjut == 'Ranap' ? 'selected' : '' ?>>Lanjut Ranap</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-bold small">Status Batal</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="non_batal" <?= $status == 'non_batal' ? 'selected' : '' ?>>Non-Batal</option>
                        <option value="semua" <?= $status == 'semua' ? 'selected' : '' ?>>Semua</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-kh-hijau btn-sm w-100 fw-bold">Tampilkan</button>
                </div>
            </form>

            <div class="table-responsive">
                <table id="sensusTable" class="table table-hover table-bordered table-sm w-100">
                    <thead class="text-center">
                        <tr>
                            <th>NO</th>
                            <th>TANGGAL Kunjungan</th>
                            <th>NO RAWAT</th>
                            <th>NO RM</th>
                            <th>NIK</th>
                            <th>NAMA</th>
                            <th>JENIS BAYAR</th>
                            <th>POLIKLINIK</th>
                            <th>DOKTER</th>
                            <th>NO SEP</th>
                            <th>NO SKDP</th>
                            <th>Status lanjut Rajal/Ranap</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($data)): ?>
                            <?php $no = 1; foreach ($data as $row): ?>
                            <tr>
                                <td class="text-center"><?= $no++ ?></td>
                                <td class="text-center"><?= date('d-m-Y', strtotime($row['tgl_registrasi'])) ?></td>
                                <td><?= htmlspecialchars($row['no_rawat']) ?></td>
                                <td class="fw-bold text-kh-hijau"><?= htmlspecialchars($row['no_rkm_medis']) ?></td>
                                <td><?= htmlspecialchars($row['no_ktp'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['nm_pasien']) ?></td>
                                <td><?= htmlspecialchars($row['png_jawab']) ?></td>
                                <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                                <td><?= htmlspecialchars($row['nm_dokter']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['no_sep'] ?? '-') ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['no_skdp'] ?? '-') ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['status_lanjut']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h6 class="mt-4 fw-bold text-kh-hijau border-kh-hijau pb-2">REKAPITULASI KUNJUNGAN</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-hijau small d-block mb-1">Status Lanjut</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php foreach($rekap_lanjut as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-oranye small d-block mb-1">Jenis Bayar</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php foreach($rekap_bayar as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                            <tr class="border-top">
                                <td class="fw-bold text-kh-hijau">Total Keseluruhan</td>
                                <td>: <strong class="text-kh-hijau"><?= count($data) ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="rekap-box rekap-total-box shadow-sm">
                        <span class="fw-bold text-light small d-block mb-1">Rekap Kunjungan Pasien BPJS</span>
                        <table class="table table-sm table-borderless text-white mb-0">
                            <?php foreach($rekap_bpjs as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                            <tr class="border-top">
                                <td class="fw-bold text-kh-oranye">Total Pasien BPJS</td>
                                <td>: <strong class="text-kh-oranye"><?= $total_bpjs ?></strong></td>
                            </tr>
                            <tr>
                                <td>Total Non-BPJS</td>
                                <td>: <strong><?= $total_non_bpjs ?></strong></td>
                            </tr>
                            <tr class="border-top">
                                <td class="fw-bold text-kh-oranye">Total Keseluruhan</td>
                                <td>: <strong class="text-kh-oranye"><?= count($data) ?></strong></td>
                            </tr>
                            <tr class="border-top">
                                <td colspan="2" class="fw-bold text-kh-oranye pt-2">Rekapitulasi SEP Pasien BPJS</td>
                            </tr>
                            <tr>
                                <td>Wajib Ada SEP (Pasien BPJS)</td>
                                <td>: <strong><?= $total_bpjs ?></strong></td>
                            </tr>
                            <tr>
                                <td>Ada SEP</td>
                                <td>: <strong class="text-kh-hijau"><?= $bpjs_ada_sep ?></strong></td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-danger">Tidak Ada SEP</td>
                                <td>: <strong class="text-danger"><?= $bpjs_tanpa_sep ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div style="text-align:center; font-size:11px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
                © <?= date('Y') ?> <b>VIDI MEDIKA KONSULTAN IT</b>. SMART IT FOR HEALTH.
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<script>
$(document).ready(function() {
    $('#sensusTable').DataTable({
        pageLength: 50,
        scrollX: true,
        dom: '<"d-flex justify-content-between mb-2"Bf>rtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: 'Export ke Excel',
                className: 'btn btn-kh-oranye btn-sm fw-bold',
                title: 'DATA SENSUS HARIAN PASIEN RAWAT JALAN',
                messageTop: 'RS KARTIKA HUSADA JATIASIH\nPeriode: <?= htmlspecialchars(date("d-m-Y", strtotime($tgl1))) ?> s/d <?= htmlspecialchars(date("d-m-Y", strtotime($tgl2))) ?>\n',
                messageBottom: '\n by: PT Vidi Medika Konsultan IT (Smart IT for Health)',
                filename: 'Sensus_Harian_Ralan_<?= htmlspecialchars($tgl1) ?>_sd_<?= htmlspecialchars($tgl2) ?>',
                exportOptions: { 
                    columns: ':visible',
                    format: {
                        body: function ( data, row, column, node ) {
                            return node.innerText || node.textContent;
                        }
                    }
                },
                customize: function (xlsx) {
                    var sheet = xlsx.xl.worksheets['sheet1.xml'];
                    var sheetData = sheet.getElementsByTagName('sheetData')[0];
                    var rows = sheetData.getElementsByTagName('row');
                    
                    var startRow = rows.length + 3; 

                    function getOrCreateRow(rowIndex) {
                        var rowNode = sheet.querySelector('row[r="' + rowIndex + '"]');
                        if (!rowNode) {
                            rowNode = sheet.createElement('row');
                            rowNode.setAttribute('r', rowIndex);
                            sheetData.appendChild(rowNode);
                        }
                        return rowNode;
                    }

                    function setCell(rowIndex, colLetter, value, isHeader) {
                        isHeader = isHeader || false;
                        var rowNode = getOrCreateRow(rowIndex);
                        var cellNode = sheet.createElement('c');
                        cellNode.setAttribute('r', colLetter + rowIndex);
                        cellNode.setAttribute('t', 'inlineStr');
                        
                        if (isHeader) {
                            cellNode.setAttribute('s', '2'); 
                        }

                        var isNode = sheet.createElement('is');
                        var tNode = sheet.createElement('t');
                        tNode.textContent = value;
                        
                        isNode.appendChild(tNode);
                        cellNode.appendChild(isNode);
                        rowNode.appendChild(cellNode);
                    }

                    // --- DATA REKAPITULASI GRID KE EXCEL ---
                    setCell(startRow, 'A', 'REKAPITULASI SENSUS HARIAN', true);
                    startRow += 2; 

                    var rekapLanjut  = <?= json_encode($rekap_lanjut) ?>;
                    var rekapBayar   = <?= json_encode($rekap_bayar) ?>;
                    var rekapBpjs    = <?= json_encode($rekap_bpjs) ?>;
                    var totalBpjs    = <?= (int)$total_bpjs ?>;
                    var totalNonBpjs = <?= (int)$total_non_bpjs ?>;
                    var bpjsAdaSep   = <?= (int)$bpjs_ada_sep ?>;
                    var bpjsTanpaSep = <?= (int)$bpjs_tanpa_sep ?>;
                    var totalAll     = <?= count($data) ?>;

                    setCell(startRow, 'A', '[ Status Lanjut ]', true);
                    setCell(startRow, 'D', '[ Jenis Bayar ]', true);
                    setCell(startRow, 'G', '[ Rekap Kunjungan Pasien BPJS ]', true);
                    
                    var pointerLanjut = startRow + 1;
                    var pointerBayar  = startRow + 1;
                    var pointerBpjs   = startRow + 1;

                    Object.keys(rekapLanjut).forEach(function(key) {
                        setCell(pointerLanjut, 'A', key);
                        setCell(pointerLanjut, 'B', ': ' + rekapLanjut[key]);
                        pointerLanjut++;
                    });

                    Object.keys(rekapBayar).forEach(function(key) {
                        setCell(pointerBayar, 'D', key);
                        setCell(pointerBayar, 'E', ': ' + rekapBayar[key]);
                        pointerBayar++;
                    });

                    setCell(pointerBayar, 'D', 'Total Keseluruhan', true);
                    setCell(pointerBayar, 'E', ': ' + totalAll, true);
                    pointerBayar++;

                    Object.keys(rekapBpjs).forEach(function(key) {
                        setCell(pointerBpjs, 'G', key);
                        setCell(pointerBpjs, 'H', ': ' + rekapBpjs[key]);
                        pointerBpjs++;
                    });

                    setCell(pointerBpjs, 'G', 'Total Pasien BPJS', true);
                    setCell(pointerBpjs, 'H', ': ' + totalBpjs, true);
                    pointerBpjs++;

                    setCell(pointerBpjs, 'G', 'Total Non-BPJS');
                    setCell(pointerBpjs, 'H', ': ' + totalNonBpjs);
                    pointerBpjs++;

                    setCell(pointerBpjs, 'G', 'Total Keseluruhan', true);
                    setCell(pointerBpjs, 'H', ': ' + totalAll, true);
                    pointerBpjs += 2;

                    setCell(pointerBpjs, 'G', '[ Rekapitulasi SEP Pasien BPJS ]', true);
                    pointerBpjs++;

                    setCell(pointerBpjs, 'G', 'Wajib Ada SEP (Pasien BPJS)');
                    setCell(pointerBpjs, 'H', ': ' + totalBpjs);
                    pointerBpjs++;

                    setCell(pointerBpjs, 'G', 'Ada SEP');
                    setCell(pointerBpjs, 'H', ': ' + bpjsAdaSep);
                    pointerBpjs++;

                    setCell(pointerBpjs, 'G', 'Tidak Ada SEP', true);
                    setCell(pointerBpjs, 'H', ': ' + bpjsTanpaSep, true);
                }
            }
        ]
    });
});
</script>

</body>
</html>
