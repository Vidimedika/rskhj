<?php
/**
 * Laporan Detail Periksa Radiologi - Terintegrasi dengan  SIMRS Khanza Rs Assalam
 * 
 */

// 1. KONEKSI DATABASE
$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   = "db-sim";

$koneksi = mysqli_connect($host, $user, $pass, $db);
if (!$koneksi) { die("Koneksi Gagal: " . mysqli_connect_error()); }

// 2. FILTER DATA & SANITASI
$tgl_awal      = isset($_GET['tgl_awal']) ? mysqli_real_escape_string($koneksi, $_GET['tgl_awal']) : date('Y-m-d');
$tgl_akhir     = isset($_GET['tgl_akhir']) ? mysqli_real_escape_string($koneksi, $_GET['tgl_akhir']) : date('Y-m-d');
$status_lanjut = isset($_GET['status_lanjut']) ? mysqli_real_escape_string($koneksi, $_GET['status_lanjut']) : '';
$kd_pj         = isset($_GET['kd_pj']) ? mysqli_real_escape_string($koneksi, $_GET['kd_pj']) : '';
$action        = isset($_GET['action']) ? $_GET['action'] : '';

// 3. LOGIKA EKSPOR EXCEL
if ($action == "export") {
    $filename = "Laporan_Radiologi_RS_Assalam_" . $tgl_awal . "_sd_" . $tgl_akhir . ".xls";
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=$filename");
    header("Pragma: no-cache");
    header("Expires: 0");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Periksa Radiologi - RS Assalam</title>
    <?php if ($action != 'export'): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --rs-green-dark: #1e5631;
            --rs-green-light: #2d8b49;
            --rs-red: #d32f2f;
            --rs-bg: #f8f9fa;
        }
        body { background-color: var(--rs-bg); font-size: 10px; font-family: sans-serif; color: #333; }
        .card { border: none; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 30px; }
        .header-table { background: linear-gradient(135deg, var(--rs-green-dark), var(--rs-green-light)); color: white; padding: 20px; border-bottom: 4px solid var(--rs-red); }
        .header-title { font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .btn-filter { background-color: var(--rs-red); border-color: var(--rs-red); color: white; font-weight: 600; text-decoration: none; padding: 5px 15px; border-radius: 4px; display: inline-block; }
        .btn-excel { background-color: #2e7d32; border-color: #2e7d32; color: white; text-decoration: none; padding: 5px 15px; border-radius: 4px; display: inline-block; }
        .table thead th { background: #f1f8f4; color: var(--rs-green-dark); font-weight: 700; text-align: center; vertical-align: middle; white-space: nowrap; }
    </style>
    <?php endif; ?>
</head>
<body>

<div class="container-fluid py-4">
    <div class="card">
        <?php if ($action != 'export'): ?>
        <div class="header-table">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h5 class="m-0 header-title">Detail Biaya Radiologi</h5>
                    <h5 class="m-0 header-title">Rs Assalam</h5>
                    <small class="opacity-75">Periode: <?= date('d-m-Y', strtotime($tgl_awal)) ?> s/d <?= date('d-m-Y', strtotime($tgl_akhir)) ?></small>
                </div>
                <div class="col-md-9">
                    <form class="d-flex gap-2 justify-content-md-end mt-3 mt-md-0" method="GET">
                        <select name="status_lanjut" class="form-select form-select-sm" style="width: auto;">
                            <option value="">- Layanan -</option>
                            <option value="Ralan" <?= $status_lanjut == 'Ralan' ? 'selected' : '' ?>>Ralan</option>
                            <option value="Ranap" <?= $status_lanjut == 'Ranap' ? 'selected' : '' ?>>Ranap</option>
                        </select>

                        <select name="kd_pj" class="form-select form-select-sm" style="width: auto;">
                            <option value="">- Cara Bayar -</option>
                            <?php
                            $pjs = mysqli_query($koneksi, "SELECT kd_pj, png_jawab FROM penjab WHERE status = '1' ORDER BY png_jawab");
                            while($pj_row = mysqli_fetch_assoc($pjs)) {
                                $selected = ($kd_pj == $pj_row['kd_pj']) ? 'selected' : '';
                                echo "<option value='{$pj_row['kd_pj']}' $selected>{$pj_row['png_jawab']}</option>";
                            }
                            ?>
                        </select>

                        <input type="date" name="tgl_awal" class="form-control form-control-sm" style="width: auto;" value="<?= $tgl_awal ?>">
                        <input type="date" name="tgl_akhir" class="form-control form-control-sm" style="width: auto;" value="<?= $tgl_akhir ?>">
                        
                        <button type="submit" class="btn-filter">Tampilkan</button>
                        <a href="?action=export&tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>&status_lanjut=<?= $status_lanjut ?>&kd_pj=<?= $kd_pj ?>" class="btn-excel">Ekspor Excel</a>
                    </form>
                </div>
            </div>
        </div>
        <?php else: ?>
            <table width="100%">
                <tr><td colspan="17" align="center"><strong>DETAIL PERIKSA RADIOLOGI RS ASSALAM</strong></td></tr>
                <tr><td colspan="17" align="center"><strong>PERIODE: <?= date('d-m-Y', strtotime($tgl_awal)) ?> s/d <?= date('d-m-Y', strtotime($tgl_akhir)) ?></strong></td></tr>
            </table>
        <?php endif; ?>

        <div class="table-responsive">
            <table border="<?= ($action == 'export') ? '1' : '0' ?>" class="table table-sm table-bordered m-0">
                <thead>
                    <tr style="<?= ($action == 'export') ? 'background-color: #f1f8f4;' : '' ?>">
                        <th>NO.</th>
                        <th>NO. RAWAT</th>
                        <th>NO. RM</th>
                        <th>NO. SEP</th>
                        <th>NAMA PASIEN</th>
                        <th>STATUS</th> 
                        <th>JENIS BAYAR</th> 
                        <th>TINDAKAN</th>
                        <th>WAKTU PERIKSA</th>
                        <th>BAGIAN RS</th>
                        <th>BHP</th>
                        <th>JM PERUJUK</th>
                        <th>JM DOKTER</th>
                        <th>JM PETUGAS</th>
                        <th>KSO</th>
                        <th>MANAJEMEN</th>
                        <th style="background-color: #0d6efd; color: white;">TOTAL BIAYA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Optimization: Build WHERE clause more cleanly
                    $conditions = ["pr.tgl_periksa BETWEEN '$tgl_awal' AND '$tgl_akhir'"];
                    if ($status_lanjut != "") { $conditions[] = "reg.status_lanjut = '$status_lanjut'"; }
                    if ($kd_pj != "") { $conditions[] = "reg.kd_pj = '$kd_pj'"; }
                    $where_clause = implode(" AND ", $conditions);

                    // Optimization: Use LEFT JOIN instead of Subquery for no_sep
                    $sql = "SELECT 
                                pr.no_rawat, pr.tgl_periksa, pr.jam, pr.bagian_rs, pr.bhp, 
                                pr.tarif_perujuk, pr.tarif_tindakan_dokter, pr.tarif_tindakan_petugas, 
                                pr.kso, pr.menejemen, pr.biaya,
                                p.nm_pasien, 
                                reg.no_rkm_medis, reg.status_lanjut, 
                                jns.nm_perawatan, 
                                pj.png_jawab,
                                sep.no_sep
                            FROM periksa_radiologi pr
                            INNER JOIN reg_periksa reg ON pr.no_rawat = reg.no_rawat
                            INNER JOIN pasien p ON reg.no_rkm_medis = p.no_rkm_medis
                            INNER JOIN penjab pj ON reg.kd_pj = pj.kd_pj
                            INNER JOIN jns_perawatan_radiologi jns ON pr.kd_jenis_prw = jns.kd_jenis_prw
                            LEFT JOIN bridging_sep sep ON pr.no_rawat = sep.no_rawat
                            WHERE $where_clause
                            ORDER BY pr.tgl_periksa DESC, pr.jam DESC";

                    $query = mysqli_query($koneksi, $sql);
                    $no = 1; $t_biaya = 0;
                    
                    if (mysqli_num_rows($query) > 0) {
                        while ($row = mysqli_fetch_assoc($query)) {
                            $t_biaya += $row['biaya'];
                            ?>
                            <tr>
                                <td align="center"><?= $no++ ?></td>
                                <td><?= $row['no_rawat'] ?></td>
                                <td align="center">`<?= $row['no_rkm_medis'] ?>`</td>
                                <td align="center"><?= ($row['no_sep'] ?? '-') ?></td>
                                <td><?= strtoupper($row['nm_pasien']) ?></td>
                                <td align="center"><?= $row['status_lanjut'] ?></td>
                                <td><?= $row['png_jawab'] ?></td>
                                <td><?= $row['nm_perawatan'] ?></td>
                                <td align="center"><?= $row['tgl_periksa'] ?> <?= $row['jam'] ?></td>
                                <td align="right"><?= number_format($row['bagian_rs'], 0, ',', '.') ?></td>
                                <td align="right"><?= number_format($row['bhp'], 0, ',', '.') ?></td>
                                <td align="right"><?= number_format($row['tarif_perujuk'], 0, ',', '.') ?></td>
                                <td align="right"><?= number_format($row['tarif_tindakan_dokter'], 0, ',', '.') ?></td>
                                <td align="right"><?= number_format($row['tarif_tindakan_petugas'], 0, ',', '.') ?></td>
                                <td align="right"><?= number_format($row['kso'], 0, ',', '.') ?></td>
                                <td align="right"><?= number_format($row['menejemen'], 0, ',', '.') ?></td>
                                <td align="right" style="font-weight:bold; color:#0d6efd;"><?= number_format($row['biaya'], 0, ',', '.') ?></td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo "<tr><td colspan='17' align='center'>Data tidak ditemukan untuk periode ini.</td></tr>";
                    }
                    ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #e8f5e9; font-weight: bold;">
                        <td colspan="16" align="right">TOTAL KESELURUHAN :</td>
                        <td align="right">Rp <?= number_format($t_biaya, 0, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <footer class="text-center text-muted mt-3" style="font-size: 9px;">
        <p>&copy; <?= date('Y') ?> VIDI MEDIKA IT HEALTHCARE CONSULTANT :: Smart IT For Health ::</p>
    </footer>
</div>

</body>
</html>
