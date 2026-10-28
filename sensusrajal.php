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
$tgl1       = $_GET['tgl1'] ?? date('Y-m-d');
$tgl2       = $_GET['tgl2'] ?? date('Y-m-d');
$status     = $_GET['status'] ?? 'non_batal';
$kd_poli    = $_GET['kd_poli'] ?? 'semua';
$kd_dokter  = $_GET['kd_dokter'] ?? 'semua';
$kd_pj      = $_GET['kd_pj'] ?? 'semua';

// Ambil list Poli, Dokter, dan Penjab untuk filter
$list_poli   = $pdo->query("SELECT kd_poli, nm_poli FROM poliklinik ORDER BY nm_poli")->fetchAll();
$list_dokter = $pdo->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter")->fetchAll();
$list_pj     = $pdo->query("SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab")->fetchAll();

// 3. QUERY SQL (OPTIMIZED)
try {
    $params = ['tgl1' => $tgl1, 'tgl2' => $tgl2];
    
    // Query Utama dengan Subquery UNION ALL untuk mengambil semua tindakan Dokter, Perawat, dan Dr+Pr
    $sql = "SELECT 
                reg_periksa.no_rawat, 
                reg_periksa.no_rkm_medis, 
                pasien.nm_pasien, 
                pasien.no_ktp, 
                pasien.jk, 
                pasien.alamat, 
                reg_periksa.tgl_registrasi, 
                poliklinik.nm_poli, 
                dokter.nm_dokter, 
                reg_periksa.umurdaftar, 
                reg_periksa.sttsumur, 
                penjab.png_jawab, 
                bridging_sep.nmppkrujukan AS perujuk,
                reg_periksa.stts_daftar, 
                reg_periksa.status_poli,
                reg_periksa.status_lanjut,
                reg_periksa.stts,
                
                dp_utama.kd_penyakit AS kd_utama,
                p_utama.nm_penyakit AS nm_utama,
                dp_utama.status_penyakit AS jenis_kasus,
                
                /* Subquery Diagnosa Tambahan (Prioritas > 1) */
                (SELECT GROUP_CONCAT(CONCAT(dp2.kd_penyakit, ' - ', p2.nm_penyakit) SEPARATOR ' | ') 
                 FROM diagnosa_pasien dp2 
                 JOIN penyakit p2 ON dp2.kd_penyakit = p2.kd_penyakit 
                 WHERE dp2.no_rawat = reg_periksa.no_rawat AND dp2.prioritas > 1) AS diagnosa_tambahan,

                /* Subquery Gabungan Tindakan Dokter, Paramedis, dan Dr+Pr */
                (SELECT GROUP_CONCAT(DISTINCT jp.nm_perawatan SEPARATOR ' | ')
                 FROM (
                     SELECT no_rawat, kd_jenis_prw FROM rawat_jl_dr
                     UNION ALL
                     SELECT no_rawat, kd_jenis_prw FROM rawat_jl_pr
                     UNION ALL
                     SELECT no_rawat, kd_jenis_prw FROM rawat_jl_drpr
                 ) tdn
                 JOIN jns_perawatan jp ON tdn.kd_jenis_prw = jp.kd_jenis_prw
                 WHERE tdn.no_rawat = reg_periksa.no_rawat) AS list_tindakan

            FROM reg_periksa 
            INNER JOIN pasien ON reg_periksa.no_rkm_medis = pasien.no_rkm_medis
            INNER JOIN poliklinik ON reg_periksa.kd_poli = poliklinik.kd_poli
            INNER JOIN dokter ON reg_periksa.kd_dokter = dokter.kd_dokter
            INNER JOIN penjab ON reg_periksa.kd_pj = penjab.kd_pj
            LEFT JOIN bridging_sep ON reg_periksa.no_rawat = bridging_sep.no_rawat
            LEFT JOIN diagnosa_pasien dp_utama ON reg_periksa.no_rawat = dp_utama.no_rawat AND dp_utama.prioritas = 1
            LEFT JOIN penyakit p_utama ON dp_utama.kd_penyakit = p_utama.kd_penyakit
            WHERE reg_periksa.tgl_registrasi BETWEEN :tgl1 AND :tgl2";

    if ($status == 'non_batal') { 
        $sql .= " AND reg_periksa.stts <> 'Batal'"; 
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
    $rekap_daftar = [];
    $rekap_poli   = [];
    $rekap_lanjut = [];
    $rekap_kasus  = [];
    $rekap_jk     = ['L' => 0, 'P' => 0];

    foreach($data as $r) {
        // Demografi JK
        if(($r['jk'] ?? '') == 'L') { 
            $rekap_jk['L']++; 
        } else { 
            $rekap_jk['P']++; 
        }

        // Rekap Status Daftar
        $stts_daftar = !empty($r['stts_daftar']) ? $r['stts_daftar'] : '-';
        $rekap_daftar[$stts_daftar] = ($rekap_daftar[$stts_daftar] ?? 0) + 1;

        // Rekap Status Poli
        $status_poli = !empty($r['status_poli']) ? $r['status_poli'] : '-';
        $rekap_poli[$status_poli] = ($rekap_poli[$status_poli] ?? 0) + 1;

        // Rekap Status Lanjut
        $status_lanjut = !empty($r['status_lanjut']) ? $r['status_lanjut'] : '-';
        $rekap_lanjut[$status_lanjut] = ($rekap_lanjut[$status_lanjut] ?? 0) + 1;

        // Rekap Jenis Kasus Diagnosa
        $jenis_kasus = !empty($r['jenis_kasus']) ? $r['jenis_kasus'] : '-';
        $rekap_kasus[$jenis_kasus] = ($rekap_kasus[$jenis_kasus] ?? 0) + 1;
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
            <h5 class="mb-0 fw-bold">DATA SENSUS HARIAN PASIEN RAWAT JALAN</h5>
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
                    <label class="form-label fw-bold small">Status Pasien</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="non_batal" <?= $status == 'non_batal' ? 'selected' : '' ?>>Kecualikan Batal</option>
                        <option value="semua" <?= $status == 'semua' ? 'selected' : '' ?>>Semua Status</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
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
                            <th>NAMA PERUJUK</th>
                            <th>ALAMAT</th>
                            <th>DOKTER</th>
                            <th>POLIKLINIK</th>
                            <th>Diagnosa /ICD 10</th>
                            <th>Status Daftar</th>
                            <th>Status Poli</th>
                            <th>Status lanjut Rajal/Ranap</th>
                            <th>JENIS KASUS Diagnosa</th>
                            <th>TINDAKAN MEDIS / PERAWATAN</th>
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
                                <td><?= htmlspecialchars($row['perujuk'] ?? '-') ?></td>
                                <td style="font-size: 0.65rem;"><?= htmlspecialchars($row['alamat']) ?></td>
                                <td><?= htmlspecialchars($row['nm_dokter']) ?></td>
                                <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                                <td>
                                    <?php if(!empty($row['kd_utama'])): ?>
                                        <strong class="text-kh-hijau"><?= htmlspecialchars($row['kd_utama']) ?></strong> - <?= htmlspecialchars($row['nm_utama']) ?>
                                        <?php if(!empty($row['diagnosa_tambahan'])): ?>
                                            <br><span class="diag-tambahan">Sekunder: <?= htmlspecialchars($row['diagnosa_tambahan']) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= htmlspecialchars($row['stts_daftar']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['status_poli']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['status_lanjut']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['jenis_kasus'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($row['list_tindakan'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h6 class="mt-4 fw-bold text-kh-hijau border-kh-hijau pb-2">REKAPITULASI SENSUS</h6>
            <div class="row g-3">
                <div class="col-md-2">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-hijau small d-block mb-1">Status Daftar</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php foreach($rekap_daftar as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-oranye small d-block mb-1">Status Poli</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php foreach($rekap_poli as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-hijau small d-block mb-1">Status Lanjut</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php foreach($rekap_lanjut as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-oranye small d-block mb-1">Jenis Kasus Diagnosa</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php foreach($rekap_kasus as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="rekap-box rekap-total-box shadow-sm">
                        <span class="fw-bold text-light small d-block mb-1">Total Ringkasan</span>
                        <table class="table table-sm table-borderless text-white mb-0">
                            <tr><td>Laki-laki</td><td>: <?= $rekap_jk['L'] ?></td></tr>
                            <tr><td>Perempuan</td><td>: <?= $rekap_jk['P'] ?></td></tr>
                            <tr class="border-top">
                                <td class="fw-bold text-kh-oranye">Total Pasien</td>
                                <td>: <strong class="text-kh-oranye"><?= count($data ?? []) ?></strong></td>
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

                    var rekapDaftar = <?= json_encode($rekap_daftar) ?>;
                    var rekapPoli   = <?= json_encode($rekap_poli) ?>;
                    var rekapLanjut = <?= json_encode($rekap_lanjut) ?>;
                    var rekapKasus  = <?= json_encode($rekap_kasus) ?>;
                    var rekapJk     = <?= json_encode($rekap_jk) ?>;
                    var totalPasien = <?= count($data ?? []) ?>;

                    setCell(startRow, 'A', '[ Status Daftar ]', true);
                    setCell(startRow, 'D', '[ Status Poli ]', true);
                    setCell(startRow, 'G', '[ Status Lanjut ]', true);
                    setCell(startRow, 'J', '[ Jenis Kasus ]', true);
                    setCell(startRow, 'M', '[ Total Ringkasan ]', true);
                    
                    var pointerDaftar = startRow + 1;
                    var pointerPoli   = startRow + 1;
                    var pointerLanjut = startRow + 1;
                    var pointerKasus  = startRow + 1;
                    var pointerJk     = startRow + 1;

                    Object.keys(rekapDaftar).forEach(function(key) {
                        setCell(pointerDaftar, 'A', key);
                        setCell(pointerDaftar, 'B', ': ' + rekapDaftar[key]);
                        pointerDaftar++;
                    });

                    Object.keys(rekapPoli).forEach(function(key) {
                        setCell(pointerPoli, 'D', key);
                        setCell(pointerPoli, 'E', ': ' + rekapPoli[key]);
                        pointerPoli++;
                    });

                    Object.keys(rekapLanjut).forEach(function(key) {
                        setCell(pointerLanjut, 'G', key);
                        setCell(pointerLanjut, 'H', ': ' + rekapLanjut[key]);
                        pointerLanjut++;
                    });

                    Object.keys(rekapKasus).forEach(function(key) {
                        setCell(pointerKasus, 'J', key);
                        setCell(pointerKasus, 'K', ': ' + rekapKasus[key]);
                        pointerKasus++;
                    });

                    // Penulisan Rekap Jenis Kelamin & Total Pasien ke Excel
                    setCell(pointerJk, 'M', 'Laki-laki');
                    setCell(pointerJk, 'N', ': ' + rekapJk['L']);
                    pointerJk++;

                    setCell(pointerJk, 'M', 'Perempuan');
                    setCell(pointerJk, 'N', ': ' + rekapJk['P']);
                    pointerJk++;

                    setCell(pointerJk, 'M', 'Total Pasien', true);
                    setCell(pointerJk, 'N', ': ' + totalPasien, true);
                }
            }
        ]
    });
});
</script>

</body>
</html>
