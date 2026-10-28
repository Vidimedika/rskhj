<<<<<<< HEAD
<?php
/**
 * Dashboard Monitoring / Rekapitulasi Pemanfaatan Tempat Tidur Rawat Inap
 * Sesuai Format Lampiran Klaim BPJS
 * Support: Perpindahan Kamar Lebih dari 1 Kali (Multi-pindah)
 * Developed for: Vidi Medika  Konsultan it
 */

$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   ="db-sim";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { 
    die("Koneksi ke database SIK gagal: " . $conn->connect_error); 
}

$tgl_awal  = $_GET['tgl_awal']  ?? '2026-05-01';
$tgl_akhir = $_GET['tgl_akhir'] ?? '2026-05-31';
$action    = $_GET['action']    ?? '';

// Query Utama: Menggunakan GROUP_CONCAT dengan separator tag <br> untuk multi-pindah
$sql = "SELECT 
            r.no_rawat,
            sep.no_sep as nosep,
            r.no_rkm_medis as no_rm,
            p.nm_pasien,
            sep.klsrawat as hak_kelas_sep,

            -- 1. DATA MASUK (Kamar Pertama)
            ki_masuk.tgl_masuk as tgl_masuk_ritl,
            ki_masuk.jam_masuk as jam_masuk_ritl,
            b_masuk.nm_bangsal as nama_kamar_masuk,
            k_masuk.kd_kamar as nomor_kamar_masuk,
            k_masuk.kelas as kelas_kamar_masuk,

            -- 2. DATA PERPINDAHAN (Multi-pindah digabung menyamping, dipisah berdasarkan <br>)
            (SELECT GROUP_CONCAT(ki2.tgl_masuk ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as tgl_pindah_all,

            (SELECT GROUP_CONCAT(ki2.jam_masuk ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as jam_pindah_all,

            (SELECT GROUP_CONCAT(b2.nm_bangsal ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 JOIN kamar k2 ON ki2.kd_kamar=k2.kd_kamar JOIN bangsal b2 ON k2.kd_bangsal=b2.kd_bangsal 
             WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as nama_kamar_pindah_all,

            (SELECT GROUP_CONCAT(k2.kd_kamar ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 JOIN kamar k2 ON ki2.kd_kamar=k2.kd_kamar 
             WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as nomor_kamar_pindah_all,

            (SELECT GROUP_CONCAT(k2.kelas ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 JOIN kamar k2 ON ki2.kd_kamar=k2.kd_kamar 
             WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as kelas_kamar_pindah_all,

            -- 3. DATA PULANG (Kamar Terakhir)
            ki_pulang.tgl_keluar as tgl_pulang_ritl,
            ki_pulang.jam_keluar as jam_pulang_ritl,
            b_pulang.nm_bangsal as nama_kamar_pulang,
            k_pulang.kd_kamar as nomor_kamar_pulang,
            k_pulang.kelas as kelas_kamar_pulang

        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN bridging_sep sep ON r.no_rawat = sep.no_rawat

        -- Kamar Pertama (Awal Masuk)
        INNER JOIN kamar_inap ki_masuk ON r.no_rawat = ki_masuk.no_rawat 
            AND ki_masuk.tgl_masuk = (SELECT MIN(tgl_masuk) FROM kamar_inap WHERE no_rawat = r.no_rawat LIMIT 1)
        INNER JOIN kamar k_masuk ON ki_masuk.kd_kamar = k_masuk.kd_kamar
        INNER JOIN bangsal b_masuk ON k_masuk.kd_bangsal = b_masuk.kd_bangsal

        -- Kamar Terakhir (Saat Pulang)
        INNER JOIN kamar_inap ki_pulang ON r.no_rawat = ki_pulang.no_rawat 
            AND ki_pulang.tgl_keluar = (SELECT MAX(tgl_keluar) FROM kamar_inap WHERE no_rawat = r.no_rawat LIMIT 1)
        INNER JOIN kamar k_pulang ON ki_pulang.kd_kamar = k_pulang.kd_kamar
        INNER JOIN bangsal b_pulang ON k_pulang.kd_bangsal = b_pulang.kd_bangsal

        WHERE (ki_pulang.tgl_keluar BETWEEN ? AND ?)
        GROUP BY r.no_rawat
        ORDER BY ki_pulang.tgl_keluar ASC, ki_pulang.jam_keluar ASC";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Query Gagal: " . $conn->error); }

$stmt->bind_param("ss", $tgl_awal, $tgl_akhir);
$stmt->execute();
$result = $stmt->get_result();

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Rekap_Pemanfaatan_Bed_BPJS_".date('Ymd').".xls");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Rekapitulasi Pemanfaatan Tempat Tidur - RS ASSALAM</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; }
        .container { padding: 20px; }
        table { border-collapse: collapse; width: 100%; font-size: 10px; margin-top: 10px; }
        th, td { padding: 5px 4px; border: 1px solid #000; vertical-align: top; }
        .bg-blue { background-color: #f2f7fa; }
        .bg-pink { background-color: #fff5f5; }
        .str { mso-number-format: "\@"; } 
    </style>
</head>
<body>

<div class="container">
    <div style="font-size: 11px;">
        <span style="font-weight:bold; font-size:13px;">Rekapitulasi Pemanfaatan Tempat Tidur Rawat Inap</span><br>
        <b>Nama RS :</b> RS ASSALAM | <b>Bulan Pelayanan:</b> <?= date('F Y', strtotime($tgl_awal)) ?>
    </div>

    <?php if ($action != 'excel'): ?>
    <div style="margin: 15px 0; padding:10px; background:#f5f5f5; border:1px solid #ddd;">
        <form method="GET">
            Periode Pulang: <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> s/d <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
            <button type="submit">Cari Data</button>
            <a href="?action=excel&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>" style="margin-left:20px; color:green; text-decoration:none; font-weight:bold;">[ Export Excel ]</a>
        </form>
    </div>
    <?php endif; ?>

    <table border="1">
        <thead>
            <tr>
                <th rowspan="2">No</th>
                <th rowspan="2">NOSEP</th>
                <th rowspan="2">NO RM</th>
                <th rowspan="2">Nama Pasien</th>
                <th rowspan="2" style="color: #0d47a1;">Hak Kelas</th>
                <th colspan="5" class="bg-blue">Pasien Masuk RITL</th>
                <th colspan="5" class="bg-pink">Diisi jika ada perpindahan</th>
                <th colspan="5" class="bg-blue">Pasien Pulang RITL</th>
            </tr>
            <tr>
                <th class="bg-blue">Tanggal</th><th class="bg-blue">Pukul</th><th class="bg-blue">Nama</th><th class="bg-blue">Nomor</th><th class="bg-blue">Kelas</th>
                <th class="bg-pink">Tanggal</th><th class="bg-pink">Pukul</th><th class="bg-pink">Nama</th><th class="bg-pink">Nomor</th><th class="bg-pink">Kelas</th>
                <th class="bg-blue">Tanggal</th><th class="bg-blue">Pukul</th><th class="bg-blue">Nama</th><th class="bg-blue">Nomor</th><th class="bg-blue">Kelas</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            if ($result->num_rows > 0):
                while ($row = $result->fetch_assoc()): 
                    // Cek jika tgl_pindah_all tidak kosong artinya ada perpindahan (bisa 1x atau lebih)
                    $ada_pindah = !empty($row['tgl_pindah_all']);
                    
                    // Format tampilan tanggal pindah multi baris
                    $tgl_pindah_tampil = '-';
                    if($ada_pindah) {
                        $arr_tgl = explode('<br>', $row['tgl_pindah_all']);
                        $arr_fmt = array_map(function($t) { return date('d/m/Y', strtotime($t)); }, $arr_tgl);
                        $tgl_pindah_tampil = implode('<br>', $arr_fmt);
                    }
                ?>
                <tr>
                    <td align="center"><?=$no++?></td>
                    <td align="center" class="str"><?=$row['nosep']?></td>
                    <td align="center" class="str"><?=$row['no_rm']?></td>
                    <td><?=strtoupper($row['nm_pasien'])?></td>
                    <td align="center" style="font-weight:bold; color:#0d47a1;"><?=$row['hak_kelas_sep']?></td>
                    
                    <td align="center"><?=date('d/m/Y', strtotime($row['tgl_masuk_ritl']))?></td>
                    <td align="center"><?=substr($row['jam_masuk_ritl'], 0, 5)?></td>
                    <td><?=$row['nama_kamar_masuk']?></td>
                    <td align="center" class="str"><?=$row['nomor_kamar_masuk']?></td>
                    <td align="center"><?=$row['kelas_kamar_masuk']?></td> 
                    
                    <td align="center"><?=$tgl_pindah_tampil?></td>
                    <td align="center"><?= $ada_pindah ? str_replace(':00', '', $row['jam_pindah_all']) : '-' ?></td>
                    <td><?=$ada_pindah ? $row['nama_kamar_pindah_all'] : '-'?></td>
                    <td align="center" class="str"><?=$ada_pindah ? $row['nomor_kamar_pindah_all'] : '-'?></td>
                    <td align="center"><?=$ada_pindah ? $row['kelas_kamar_pindah_all'] : '-'?></td> 
                    
                    <td align="center"><?=($row['tgl_pulang_ritl'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tgl_pulang_ritl'])) : 'Aktif'?></td>
                    <td align="center"><?=($row['jam_pulang_ritl'] != '00:00:00') ? substr($row['jam_pulang_ritl'], 0, 5) : '-'?></td>
                    <td><?=$row['nama_kamar_pulang']?></td>
                    <td align="center" class="str"><?=$row['nomor_kamar_pulang']?></td>
                    <td align="center"><?=$row['kelas_kamar_pulang']?></td> 
                </tr>
                <?php endwhile; 
            else: ?>
                <tr><td colspan="20" align="center" style="padding:25px; font-weight:bold;">Data Kosong.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
=======
<?php
/**
 * Dashboard Monitoring / Rekapitulasi Pemanfaatan Tempat Tidur Rawat Inap
 * Sesuai Format Lampiran Klaim BPJS
 * Support: Perpindahan Kamar Lebih dari 1 Kali (Multi-pindah)
 * Developed for: Vidi Medika  Konsultan it
 */

$host = "192.168.5.52:3382";
$user = "clientkhanza";
$pass = "Client1453";
$db   ="db-sim";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { 
    die("Koneksi ke database SIK gagal: " . $conn->connect_error); 
}

$tgl_awal  = $_GET['tgl_awal']  ?? '2026-05-01';
$tgl_akhir = $_GET['tgl_akhir'] ?? '2026-05-31';
$action    = $_GET['action']    ?? '';

// Query Utama: Menggunakan GROUP_CONCAT dengan separator tag <br> untuk multi-pindah
$sql = "SELECT 
            r.no_rawat,
            sep.no_sep as nosep,
            r.no_rkm_medis as no_rm,
            p.nm_pasien,
            sep.klsrawat as hak_kelas_sep,

            -- 1. DATA MASUK (Kamar Pertama)
            ki_masuk.tgl_masuk as tgl_masuk_ritl,
            ki_masuk.jam_masuk as jam_masuk_ritl,
            b_masuk.nm_bangsal as nama_kamar_masuk,
            k_masuk.kd_kamar as nomor_kamar_masuk,
            k_masuk.kelas as kelas_kamar_masuk,

            -- 2. DATA PERPINDAHAN (Multi-pindah digabung menyamping, dipisah berdasarkan <br>)
            (SELECT GROUP_CONCAT(ki2.tgl_masuk ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as tgl_pindah_all,

            (SELECT GROUP_CONCAT(ki2.jam_masuk ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as jam_pindah_all,

            (SELECT GROUP_CONCAT(b2.nm_bangsal ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 JOIN kamar k2 ON ki2.kd_kamar=k2.kd_kamar JOIN bangsal b2 ON k2.kd_bangsal=b2.kd_bangsal 
             WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as nama_kamar_pindah_all,

            (SELECT GROUP_CONCAT(k2.kd_kamar ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 JOIN kamar k2 ON ki2.kd_kamar=k2.kd_kamar 
             WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as nomor_kamar_pindah_all,

            (SELECT GROUP_CONCAT(k2.kelas ORDER BY ki2.tgl_masuk ASC SEPARATOR '<br>') 
             FROM kamar_inap ki2 JOIN kamar k2 ON ki2.kd_kamar=k2.kd_kamar 
             WHERE ki2.no_rawat = r.no_rawat 
             AND CONCAT(ki2.tgl_masuk, ki2.jam_masuk) != CONCAT(ki_masuk.tgl_masuk, ki_masuk.jam_masuk)
             AND CONCAT(ki2.tgl_keluar, ki2.jam_keluar) != CONCAT(ki_pulang.tgl_keluar, ki_pulang.jam_keluar)) as kelas_kamar_pindah_all,

            -- 3. DATA PULANG (Kamar Terakhir)
            ki_pulang.tgl_keluar as tgl_pulang_ritl,
            ki_pulang.jam_keluar as jam_pulang_ritl,
            b_pulang.nm_bangsal as nama_kamar_pulang,
            k_pulang.kd_kamar as nomor_kamar_pulang,
            k_pulang.kelas as kelas_kamar_pulang

        FROM reg_periksa r
        INNER JOIN pasien p ON r.no_rkm_medis = p.no_rkm_medis
        INNER JOIN bridging_sep sep ON r.no_rawat = sep.no_rawat

        -- Kamar Pertama (Awal Masuk)
        INNER JOIN kamar_inap ki_masuk ON r.no_rawat = ki_masuk.no_rawat 
            AND ki_masuk.tgl_masuk = (SELECT MIN(tgl_masuk) FROM kamar_inap WHERE no_rawat = r.no_rawat LIMIT 1)
        INNER JOIN kamar k_masuk ON ki_masuk.kd_kamar = k_masuk.kd_kamar
        INNER JOIN bangsal b_masuk ON k_masuk.kd_bangsal = b_masuk.kd_bangsal

        -- Kamar Terakhir (Saat Pulang)
        INNER JOIN kamar_inap ki_pulang ON r.no_rawat = ki_pulang.no_rawat 
            AND ki_pulang.tgl_keluar = (SELECT MAX(tgl_keluar) FROM kamar_inap WHERE no_rawat = r.no_rawat LIMIT 1)
        INNER JOIN kamar k_pulang ON ki_pulang.kd_kamar = k_pulang.kd_kamar
        INNER JOIN bangsal b_pulang ON k_pulang.kd_bangsal = b_pulang.kd_bangsal

        WHERE (ki_pulang.tgl_keluar BETWEEN ? AND ?)
        GROUP BY r.no_rawat
        ORDER BY ki_pulang.tgl_keluar ASC, ki_pulang.jam_keluar ASC";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Query Gagal: " . $conn->error); }

$stmt->bind_param("ss", $tgl_awal, $tgl_akhir);
$stmt->execute();
$result = $stmt->get_result();

if ($action == 'excel') {
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Rekap_Pemanfaatan_Bed_BPJS_".date('Ymd').".xls");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Rekapitulasi Pemanfaatan Tempat Tidur - RS ASSALAM</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; }
        .container { padding: 20px; }
        table { border-collapse: collapse; width: 100%; font-size: 10px; margin-top: 10px; }
        th, td { padding: 5px 4px; border: 1px solid #000; vertical-align: top; }
        .bg-blue { background-color: #f2f7fa; }
        .bg-pink { background-color: #fff5f5; }
        .str { mso-number-format: "\@"; } 
    </style>
</head>
<body>

<div class="container">
    <div style="font-size: 11px;">
        <span style="font-weight:bold; font-size:13px;">Rekapitulasi Pemanfaatan Tempat Tidur Rawat Inap</span><br>
        <b>Nama RS :</b> RS ASSALAM | <b>Bulan Pelayanan:</b> <?= date('F Y', strtotime($tgl_awal)) ?>
    </div>

    <?php if ($action != 'excel'): ?>
    <div style="margin: 15px 0; padding:10px; background:#f5f5f5; border:1px solid #ddd;">
        <form method="GET">
            Periode Pulang: <input type="date" name="tgl_awal" value="<?=$tgl_awal?>"> s/d <input type="date" name="tgl_akhir" value="<?=$tgl_akhir?>">
            <button type="submit">Cari Data</button>
            <a href="?action=excel&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>" style="margin-left:20px; color:green; text-decoration:none; font-weight:bold;">[ Export Excel ]</a>
        </form>
    </div>
    <?php endif; ?>

    <table border="1">
        <thead>
            <tr>
                <th rowspan="2">No</th>
                <th rowspan="2">NOSEP</th>
                <th rowspan="2">NO RM</th>
                <th rowspan="2">Nama Pasien</th>
                <th rowspan="2" style="color: #0d47a1;">Hak Kelas</th>
                <th colspan="5" class="bg-blue">Pasien Masuk RITL</th>
                <th colspan="5" class="bg-pink">Diisi jika ada perpindahan</th>
                <th colspan="5" class="bg-blue">Pasien Pulang RITL</th>
            </tr>
            <tr>
                <th class="bg-blue">Tanggal</th><th class="bg-blue">Pukul</th><th class="bg-blue">Nama</th><th class="bg-blue">Nomor</th><th class="bg-blue">Kelas</th>
                <th class="bg-pink">Tanggal</th><th class="bg-pink">Pukul</th><th class="bg-pink">Nama</th><th class="bg-pink">Nomor</th><th class="bg-pink">Kelas</th>
                <th class="bg-blue">Tanggal</th><th class="bg-blue">Pukul</th><th class="bg-blue">Nama</th><th class="bg-blue">Nomor</th><th class="bg-blue">Kelas</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            if ($result->num_rows > 0):
                while ($row = $result->fetch_assoc()): 
                    // Cek jika tgl_pindah_all tidak kosong artinya ada perpindahan (bisa 1x atau lebih)
                    $ada_pindah = !empty($row['tgl_pindah_all']);
                    
                    // Format tampilan tanggal pindah multi baris
                    $tgl_pindah_tampil = '-';
                    if($ada_pindah) {
                        $arr_tgl = explode('<br>', $row['tgl_pindah_all']);
                        $arr_fmt = array_map(function($t) { return date('d/m/Y', strtotime($t)); }, $arr_tgl);
                        $tgl_pindah_tampil = implode('<br>', $arr_fmt);
                    }
                ?>
                <tr>
                    <td align="center"><?=$no++?></td>
                    <td align="center" class="str"><?=$row['nosep']?></td>
                    <td align="center" class="str"><?=$row['no_rm']?></td>
                    <td><?=strtoupper($row['nm_pasien'])?></td>
                    <td align="center" style="font-weight:bold; color:#0d47a1;"><?=$row['hak_kelas_sep']?></td>
                    
                    <td align="center"><?=date('d/m/Y', strtotime($row['tgl_masuk_ritl']))?></td>
                    <td align="center"><?=substr($row['jam_masuk_ritl'], 0, 5)?></td>
                    <td><?=$row['nama_kamar_masuk']?></td>
                    <td align="center" class="str"><?=$row['nomor_kamar_masuk']?></td>
                    <td align="center"><?=$row['kelas_kamar_masuk']?></td> 
                    
                    <td align="center"><?=$tgl_pindah_tampil?></td>
                    <td align="center"><?= $ada_pindah ? str_replace(':00', '', $row['jam_pindah_all']) : '-' ?></td>
                    <td><?=$ada_pindah ? $row['nama_kamar_pindah_all'] : '-'?></td>
                    <td align="center" class="str"><?=$ada_pindah ? $row['nomor_kamar_pindah_all'] : '-'?></td>
                    <td align="center"><?=$ada_pindah ? $row['kelas_kamar_pindah_all'] : '-'?></td> 
                    
                    <td align="center"><?=($row['tgl_pulang_ritl'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tgl_pulang_ritl'])) : 'Aktif'?></td>
                    <td align="center"><?=($row['jam_pulang_ritl'] != '00:00:00') ? substr($row['jam_pulang_ritl'], 0, 5) : '-'?></td>
                    <td><?=$row['nama_kamar_pulang']?></td>
                    <td align="center" class="str"><?=$row['nomor_kamar_pulang']?></td>
                    <td align="center"><?=$row['kelas_kamar_pulang']?></td> 
                </tr>
                <?php endwhile; 
            else: ?>
                <tr><td colspan="20" align="center" style="padding:25px; font-weight:bold;">Data Kosong.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
>>>>>>> origin/master
