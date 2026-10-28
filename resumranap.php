<?php
/**
 * Script Resume Medis Elektronik RAWAT INAP - RS Assalam
 * Source Table: resume_pasien_ranap
 */

$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   = "db-sim";


try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
    ]);
} catch (PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage());
}

if (isset($_GET['action']) && $_GET['action'] == 'read') {
    header('Content-Type: application/json');
    
    $tgl_awal  = !empty($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-d');
    $tgl_akhir = !empty($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

    $sql = "SELECT 
                rs.no_rawat, reg.no_rkm_medis, p.nm_pasien, d.nm_dokter,
                rs.diagnosa_awal, rs.alasan, rs.keluhan_utama, rs.pemeriksaan_fisik,
                rs.jalannya_penyakit, rs.pemeriksaan_penunjang, rs.hasil_laborat,
                rs.tindakan_dan_operasi, rs.obat_di_rs, rs.diagnosa_utama,
                rs.kd_diagnosa_utama, rs.diagnosa_sekunder, rs.kd_diagnosa_sekunder,
                rs.diagnosa_sekunder2, rs.kd_diagnosa_sekunder2,
                rs.diagnosa_sekunder3, rs.kd_diagnosa_sekunder3,
                rs.diagnosa_sekunder4, rs.kd_diagnosa_sekunder4,
                rs.prosedur_utama, rs.kd_prosedur_utama,
                rs.prosedur_sekunder, rs.kd_prosedur_sekunder,
                rs.prosedur_sekunder2, rs.kd_prosedur_sekunder2,
                rs.prosedur_sekunder3, rs.kd_prosedur_sekunder3,
                rs.alergi, rs.diet, rs.lab_belum, rs.edukasi,
                rs.cara_keluar, rs.ket_keluar, rs.keadaan, rs.ket_keadaan,
                rs.dilanjutkan, rs.ket_dilanjutkan, rs.kontrol, rs.obat_pulang
            FROM resume_pasien_ranap rs 
            INNER JOIN reg_periksa reg ON rs.no_rawat = reg.no_rawat
            INNER JOIN pasien p ON reg.no_rkm_medis = p.no_rkm_medis
            INNER JOIN dokter d ON rs.kd_dokter = d.kd_dokter 
            WHERE reg.tgl_registrasi BETWEEN :tgl_awal AND :tgl_akhir
            ORDER BY rs.no_rawat DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['tgl_awal' => $tgl_awal, 'tgl_akhir' => $tgl_akhir]);
    echo json_encode(['data' => $stmt->fetchAll()]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume RANAP - RS Assalam</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <style>
        :root {
            --assalam-green: #2d8a4e;
            --assalam-green-dark: #1e5e35;
            --assalam-red: #c0392b;
            --assalam-light: #f8fafb;
            --assalam-border: #e0e6ed;
        }

        body {
            background-color: #f0f2f5;
            font-family: 'Inter', sans-serif;
            font-size: 12px;
            color: #344767;
        }

        .card { border: none; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); background: #ffffff; }

        .header-assalam {
            background: linear-gradient(135deg, var(--assalam-green) 0%, var(--assalam-green-dark) 100%);
            color: white; padding: 20px; font-weight: 700; font-size: 18px;
            border-bottom: 5px solid var(--assalam-red); text-align: center; border-radius: 12px 12px 0 0;
        }

        .filter-section { background: #fff; padding: 20px; border-bottom: 1px solid var(--assalam-border); }

        .table-assalam thead th {
            background-color: var(--assalam-light) !important;
            color: var(--assalam-green) !important;
            text-transform: uppercase; font-size: 11px; font-weight: 700;
            padding: 12px !important; border-bottom: 2px solid var(--assalam-border) !important;
        }

        .table-assalam td {
            padding: 10px !important; vertical-align: top !important;
            white-space: normal !important; word-wrap: break-word; line-height: 1.5;
        }

        /* Kolom Medis Panjang */
        .col-medis { min-width: 300px !important; text-align: justify; }
        
        /* Kolom Kecil (Kode/ID) */
        .col-sm { min-width: 80px !important; text-align: center; }

        .info-count {
            background: rgba(45, 138, 78, 0.1); padding: 8px 15px;
            border-radius: 8px; color: var(--assalam-green); font-weight: 700;
        }

        .footer { text-align: center; padding: 20px; color: #67748e; font-size: 11px; }

        .dataTables_scrollBody::-webkit-scrollbar { height: 10px; width: 8px; }
        .dataTables_scrollBody::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 10px; }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <div class="card">
        <div class="header-assalam">REKAP RESUME MEDIS PASIEN RAWAT INAP (RANAP) - RS ASSALAM</div>
        
        <div class="filter-section">
            <div class="row align-items-end g-3">
                <div class="col-md-2">
                    <label class="small fw-bold text-muted text-uppercase">Tgl Registrasi Awal</label>
                    <input type="date" id="tgl_awal" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted text-uppercase">Tgl Registrasi Akhir</label>
                    <input type="date" id="tgl_akhir" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label class="small fw-bold text-muted text-uppercase">Dokter DPJP</label>
                    <select id="filterDokter" class="form-select form-select-sm">
                        <option value="">-- Semua Dokter --</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button id="btnFilter" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Cari</button>
                </div>
                <div class="col-md-4 text-end">
                    <span class="info-count me-2">Total: <span id="totalData">0</span> Pasien</span>
                    <span id="btnPlace"></span>
                </div>
            </div>
        </div>

        <div class="card-body">
            <table id="tabelRanap" class="table table-striped table-hover table-bordered w-100 table-assalam">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>No. Rawat</th>
                        <th>No. RM</th>
                        <th>Nama Pasien</th>
                        <th>Dokter DPJP</th>
                        <th>Diagnosa Awal</th>
                        <th>Alasan Masuk</th>
                        <th>Keluhan Utama</th>
                        <th>Pemeriksaan Fisik</th>
                        <th>Jalannya Penyakit</th>
                        <th>Penunjang</th>
                        <th>Laborat</th>
                        <th>Tindakan/Operasi</th>
                        <th>Obat di RS</th>
                        <th>Diagnosa Utama</th>
                        <th>ICD-10</th>
                        <th>Sekunder 1</th>
                        <th>ICD-10</th>
                        <th>Sekunder 2</th>
                        <th>ICD-10</th>
                        <th>Sekunder 3</th>
                        <th>ICD-10</th>
                        <th>Sekunder 4</th>
                        <th>ICD-10</th>
                        <th>Prosedur Utama</th>
                        <th>ICD-9</th>
                        <th>Pros. Sek 1</th>
                        <th>ICD-9</th>
                        <th>Pros. Sek 2</th>
                        <th>ICD-9</th>
                        <th>Pros. Sek 3</th>
                        <th>ICD-9</th>
                        <th>Alergi</th>
                        <th>Diet</th>
                        <th>Lab Belum Keluar</th>
                        <th>Edukasi</th>
                        <th>Cara Keluar</th>
                        <th>Ket. Keluar</th>
                        <th>Keadaan</th>
                        <th>Ket. Keadaan</th>
                        <th>Dilanjutkan</th>
                        <th>Ket. Lanjut</th>
                        <th>Kontrol</th>
                        <th>Obat Pulang</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
    
    <div class="footer">
        © 2026 <b>VIDI MEDIKA KONSULTAN IT</b> - <i>Smart IT For Health</i>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>

<script>
    $(document).ready(function() {
        var table = $('#tabelRanap').DataTable({
            processing: true,
            ajax: {
                url: '<?= $_SERVER['PHP_SELF']; ?>?action=read',
                data: function (d) {
                    d.tgl_awal = $('#tgl_awal').val();
                    d.tgl_akhir = $('#tgl_akhir').val();
                }
            },
            scrollX: true,
            pageLength: 10,
            dom: '<"d-flex justify-content-between mb-3"f>rt<"d-flex justify-content-between mt-3"ip>',
            buttons: [{
               extend: 'excelHtml5',
                className: 'btn btn-success btn-sm shadow-sm',
                text: '<i class="bi bi-file-earmark-excel"></i> Ekspor Excel',
                filename: function(){
                    var dokter = $('#filterDokter').val() || "Semua_Dokter";
                    return "Resume_" + dokter.replace(/[^a-z0-0]/gi, '_') + "_" + $('#tgl_awal').val();
                },
                exportOptions: { columns: ':visible' }
            }],
            columns: [
                { data: null, render: (d,t,r,meta) => meta.row + 1 },
                { data: 'no_rawat' },
                { data: 'no_rkm_medis' },
                { data: 'nm_pasien' },
                { data: 'nm_dokter' },
                { data: 'diagnosa_awal' },
                { data: 'alasan' },
                { data: 'keluhan_utama' },
                { data: 'pemeriksaan_fisik' },
                { data: 'jalannya_penyakit' },
                { data: 'pemeriksaan_penunjang' },
                { data: 'hasil_laborat' },
                { data: 'tindakan_dan_operasi' },
                { data: 'obat_di_rs' },
                { data: 'diagnosa_utama' },
                { data: 'kd_diagnosa_utama' },
                { data: 'diagnosa_sekunder' },
                { data: 'kd_diagnosa_sekunder' },
                { data: 'diagnosa_sekunder2' },
                { data: 'kd_diagnosa_sekunder2' },
                { data: 'diagnosa_sekunder3' },
                { data: 'kd_diagnosa_sekunder3' },
                { data: 'diagnosa_sekunder4' },
                { data: 'kd_diagnosa_sekunder4' },
                { data: 'prosedur_utama' },
                { data: 'kd_prosedur_utama' },
                { data: 'prosedur_sekunder' },
                { data: 'kd_prosedur_sekunder' },
                { data: 'prosedur_sekunder2' },
                { data: 'kd_prosedur_sekunder2' },
                { data: 'prosedur_sekunder3' },
                { data: 'kd_prosedur_sekunder3' },
                { data: 'alergi' },
                { data: 'diet' },
                { data: 'lab_belum' },
                { data: 'edukasi' },
                { data: 'cara_keluar' },
                { data: 'ket_keluar' },
                { data: 'keadaan' },
                { data: 'ket_keadaan' },
                { data: 'dilanjutkan' },
                { data: 'ket_dilanjutkan' },
                { data: 'kontrol' },
                { data: 'obat_pulang' }
            ],
            columnDefs: [
                { targets: [5,6,7,8,9,10,11,12,13,43], className: 'col-medis' },
                { targets: [15,17,19,21,23,25,27,29,31], className: 'col-sm' },
                { targets: '_all', className: 'align-top' }
            ],
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/id.json' },
            drawCallback: function() {
                $('#totalData').html(this.api().rows({filter:'applied'}).data().length);
            },
            initComplete: function () {
                refreshDokterFilter(this.api());
                table.buttons().container().appendTo('#btnPlace');
            }
        });

        function refreshDokterFilter(api) {
            var select = $('#filterDokter').empty().append('<option value="">-- Semua Dokter --</option>');
            api.column(4).data().unique().sort().each(d => { if(d) select.append(`<option value="${d}">${d}</option>`) });
        }

        $('#btnFilter').on('click', () => table.ajax.reload(() => refreshDokterFilter(table)));
        $('#filterDokter').on('change', function() { table.column(4).search(this.value).draw(); });
    });
</script>
</body>
</html>
