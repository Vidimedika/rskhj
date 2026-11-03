<?php
// 1. KONEKSI & KONFIGURASI
$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   = "db-sim";

$koneksi = mysqli_connect($host, $user, $pass, $db);
if (!$koneksi) {
    die("<div class='alert alert-danger'>Koneksi Gagal: " . mysqli_connect_error() . "</div>");
}
mysqli_set_charset($koneksi, "utf8");

// 2. TANGKAP INPUT (Menggunakan $_REQUEST agar bisa menerima POST dari form atau GET dari link export)
$no_rawat   = isset($_REQUEST['no_rawat']) ? trim($_REQUEST['no_rawat']) : '';
$status_sel = isset($_REQUEST['status']) ? $_REQUEST['status'] : 'Semua Status';
$tgl_awal   = isset($_REQUEST['tgl_awal']) ? $_REQUEST['tgl_awal'] : date('Y-m-d');
$tgl_akhir  = isset($_REQUEST['tgl_akhir']) ? $_REQUEST['tgl_akhir'] : date('Y-m-d');

// Mapping Status
$status_mapped = str_replace(
    ["Obat Rawat Jalan", "Obat Rawat Inap", "Semua Status"], 
    ["Ralan", "Ranap", ""], 
    $status_sel
);

// --- LOGIKA EXCEL ---
$isExport = (isset($_GET['action']) && $_GET['action'] == 'export');

if ($isExport) {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Monitoring_Obat_".$tgl_awal."_sd_".$tgl_akhir.".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
}

// 3. QUERY DATA (Berlaku untuk Web dan Excel)
$sql = "SELECT 
            dpo.tgl_perawatan,
            dpo.no_rawat,
            rp.no_rkm_medis,
            (SELECT no_sep FROM bridging_sep WHERE no_rawat = dpo.no_rawat LIMIT 1) as no_sep,
            p.nm_pasien,
            d.nm_dokter,
            db.nama_brng, 
            SUM(dpo.jml) as jml,
            SUM(dpo.total) as total 
        FROM detail_pemberian_obat dpo
        INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
        INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
        INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
        INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng 
        WHERE dpo.tgl_perawatan BETWEEN ? AND ?";

$params = [$tgl_awal, $tgl_akhir];
$types = "ss";

if (!empty($no_rawat)) {
    $sql .= " AND (dpo.no_rawat LIKE ? OR p.nm_pasien LIKE ?)";
    $search = "%$no_rawat%";
    $params[] = $search;
    $params[] = $search;
    $types .= "ss";
}

if (!empty($status_mapped)) {
    $sql .= " AND dpo.status = ?";
    $params[] = $status_mapped;
    $types .= "s";
}

$sql .= " GROUP BY dpo.tgl_perawatan, dpo.no_rawat, dpo.kode_brng 
          ORDER BY dpo.tgl_perawatan DESC, dpo.no_rawat DESC";

$stmt = mysqli_prepare($koneksi, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Jika Export, langsung tampilkan tabel sederhana tanpa CSS Bootstrap agar file tidak corrupt
if ($isExport): ?>
    <table border="1">
        <tr>
            <th colspan="10" style="font-size:16px;">MONITORING PENGGUNAAN OBAT RS ASSALAM</th>
        </tr>
        <tr>
            <th colspan="10">Periode: <?= $tgl_awal ?> s/d <?= $tgl_akhir ?></th>
        </tr>
        <thead>
            <tr style="background-color: #f2f2f2;">
                <th>No.</th>
                <th>Tgl. Resep</th>
                <th>No. Rawat</th>
                <th>No. RM</th>
                <th>No. SEP</th>
                <th>Nama Pasien</th>
                <th>Dokter</th>
                <th>Nama Barang</th>
                <th>Jml</th>
                <th>Total (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1; $grandTotal = 0;
            while($row = mysqli_fetch_assoc($result)): 
                $grandTotal += $row['total'];
            ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= $row['tgl_perawatan'] ?></td>
                <td style='mso-number-format:"\@";'><?= $row['no_rawat'] ?></td>
                <td style='mso-number-format:"\@";'><?= $row['no_rkm_medis'] ?></td>
                <td><?= $row['no_sep'] ?? '-' ?></td>
                <td><?= $row['nm_pasien'] ?></td>
                <td><?= $row['nm_dokter'] ?></td>
                <td><?= $row['nama_brng'] ?></td>
                <td><?= $row['jml'] ?></td>
                <td><?= $row['total'] ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="9" align="right">TOTAL KESELURUHAN:</th>
                <th><?= $grandTotal ?></th>
            </tr>
        </tfoot>
    </table>
<?php 
    exit; // Hentikan script agar kode HTML di bawah tidak ikut masuk ke file Excel
endif; 
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring Penggunaan Obat - SIMRS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Tahoma', sans-serif; font-size: 11px; }
        .card { border-top: 3px solid #198754; border-radius: 0; }
        .table thead th { background-color: #f8f9fa !important; vertical-align: middle; text-align: center; text-transform: uppercase; white-space: nowrap; }
        @media print { .no-print { display: none; } body { background-color: white; } }
    </style>
</head>
<body>

<div class="container-fluid py-3">
    <div class="card shadow-sm mb-4 no-print">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-0 text-success fw-bold">::  MONITORING PENGGUNAAN OBAT ::</h6>
                <h6 class="mb-0 text-success fw-bold">::  RS ASSALAM ::</h6>
            </div>
            <a href="?action=export&tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>&no_rawat=<?= urlencode($no_rawat) ?>&status=<?= urlencode($status_sel) ?>" 
               class="btn btn-primary btn-sm">
               💾 Export Excel
            </a>
        </div>
        <div class="card-body bg-light">
            <form method="POST" class="row g-2">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tanggal Mulai</label>
                    <input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= $tgl_awal ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tanggal Selesai</label>
                    <input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= $tgl_akhir ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">No. Rawat / Nama Pasien</label>
                    <input type="text" name="no_rawat" class="form-control form-control-sm" value="<?= htmlspecialchars($no_rawat) ?>" placeholder="Cari...">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option <?= $status_sel == 'Semua Status' ? 'selected' : '' ?>>Semua Status</option>
                        <option <?= $status_sel == 'Obat Rawat Jalan' ? 'selected' : '' ?>>Obat Rawat Jalan</option>
                        <option <?= $status_sel == 'Obat Rawat Inap' ? 'selected' : '' ?>>Obat Rawat Inap</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success btn-sm w-100">Filter Data</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="20">No.</th>
                            <th>Tgl. Resep</th>
                            <th>No. Rawat</th>
                            <th>No. RM</th>
                            <th>No. SEP</th>
                            <th>Nama Pasien</th>
                            <th>Dokter</th>
                            <th>Nama Barang</th>
                            <th width="40">Jml</th>
                            <th>Total (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no = 1;
                        $grandTotal = 0;
                        if (mysqli_num_rows($result) > 0) {
                            while ($row = mysqli_fetch_assoc($result)) {
                                $grandTotal += $row['total'];
                                ?>
                                <tr>
                                    <td class='text-center'><?= $no++ ?></td>
                                    <td class='text-center'><?= $row['tgl_perawatan'] ?></td> 
                                    <td><?= $row['no_rawat'] ?></td>
                                    <td class='text-center'><?= $row['no_rkm_medis'] ?></td>
                                    <td><?= $row['no_sep'] ?? '-' ?></td>
                                    <td><?= $row['nm_pasien'] ?></td>
                                    <td><?= $row['nm_dokter'] ?></td>
                                    <td><?= $row['nama_brng'] ?></td>
                                    <td class='text-center'><?= $row['jml'] ?></td>
                                    <td class='text-end fw-bold'><?= number_format($row['total'], 0, ',', '.') ?></td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo "<tr><td colspan='10' class='text-center py-4'>Data tidak ditemukan.</td></tr>";
                        }
                        ?>
                    </tbody>
                    <tfoot class="table-success fw-bold">
                        <tr>
                            <td colspan="9" class="text-end">TOTAL KESELURUHAN :</td>
                            <td class="text-end"><?= number_format($grandTotal, 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>
