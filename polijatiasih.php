<?php if (isset($_GET['view']) && $_GET['view'] === 'lama_pelayanan') { ?>
<?php
$host = "192.168.10.29"; $db = "rskhj"; $user = "root"; $pass = "1nternet";
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$namaRS = (string)($pdo->query("SELECT nama_instansi FROM setting LIMIT 1")->fetchColumn() ?: 'Nama Rumah Sakit');
$tgl1 = $_GET['tgl1'] ?? date('Y-m-d'); $tgl2 = $_GET['tgl2'] ?? date('Y-m-d');
foreach (['tgl1', 'tgl2'] as $key) { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$key) || strtotime($$key) === false) $$key = date('Y-m-d'); }
if ($tgl2 < $tgl1) $tgl2 = $tgl1;
$sql = "SELECT rp.no_rawat,rp.no_rkm_medis,ps.nm_pasien,p.nm_poli,pj.png_jawab,rp.status_poli,d.nm_dokter,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(TIME_FORMAT(j.jam_mulai,'%H:%i'),'-',TIME_FORMAT(j.jam_selesai,'%H:%i')) ORDER BY j.jam_mulai SEPARATOR '; ')
     FROM jadwal j WHERE j.kd_dokter=rp.kd_dokter AND j.kd_poli=rp.kd_poli AND j.jam_mulai < '12:00:00'
       AND j.hari_kerja=CASE DAYOFWEEK(rp.tgl_registrasi) WHEN 1 THEN 'AKHAD' WHEN 2 THEN 'SENIN' WHEN 3 THEN 'SELASA' WHEN 4 THEN 'RABU' WHEN 5 THEN 'KAMIS' WHEN 6 THEN 'JUMAT' WHEN 7 THEN 'SABTU' END) AS jam_praktek_pagi,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(TIME_FORMAT(j.jam_mulai,'%H:%i'),'-',TIME_FORMAT(j.jam_selesai,'%H:%i')) ORDER BY j.jam_mulai SEPARATOR '; ')
     FROM jadwal j WHERE j.kd_dokter=rp.kd_dokter AND j.kd_poli=rp.kd_poli AND j.jam_mulai >= '12:00:00'
       AND j.hari_kerja=CASE DAYOFWEEK(rp.tgl_registrasi) WHEN 1 THEN 'AKHAD' WHEN 2 THEN 'SENIN' WHEN 3 THEN 'SELASA' WHEN 4 THEN 'RABU' WHEN 5 THEN 'KAMIS' WHEN 6 THEN 'JUMAT' WHEN 7 THEN 'SABTU' END) AS jam_praktek_sore,
    rp.tgl_registrasi,rp.jam_reg,sp.jam_perawat,sd.jam_dokter,rx.jam_resep
FROM reg_periksa rp JOIN pasien ps ON ps.no_rkm_medis=rp.no_rkm_medis JOIN poliklinik p ON p.kd_poli=rp.kd_poli JOIN penjab pj ON pj.kd_pj=rp.kd_pj JOIN dokter d ON d.kd_dokter=rp.kd_dokter
LEFT JOIN (SELECT pr.no_rawat,pr.tgl_perawatan,MIN(pr.jam_rawat) jam_perawat FROM pemeriksaan_ralan pr WHERE pr.tgl_perawatan BETWEEN :p1 AND :p2 AND NOT EXISTS(SELECT 1 FROM dokter dx WHERE dx.kd_dokter=pr.nip) GROUP BY pr.no_rawat,pr.tgl_perawatan) sp ON sp.no_rawat=rp.no_rawat AND sp.tgl_perawatan=rp.tgl_registrasi
LEFT JOIN (SELECT pr.no_rawat,pr.tgl_perawatan,MIN(pr.jam_rawat) jam_dokter FROM pemeriksaan_ralan pr JOIN dokter dx ON dx.kd_dokter=pr.nip WHERE pr.tgl_perawatan BETWEEN :d1 AND :d2 GROUP BY pr.no_rawat,pr.tgl_perawatan) sd ON sd.no_rawat=rp.no_rawat AND sd.tgl_perawatan=rp.tgl_registrasi
LEFT JOIN (SELECT ro.no_rawat,ro.tgl_peresepan,MIN(ro.jam_peresepan) jam_resep FROM resep_obat ro WHERE ro.tgl_peresepan BETWEEN :r1 AND :r2 GROUP BY ro.no_rawat,ro.tgl_peresepan) rx ON rx.no_rawat=rp.no_rawat AND rx.tgl_peresepan=rp.tgl_registrasi
WHERE rp.tgl_registrasi BETWEEN :t1 AND :t2
  AND rp.stts <> 'Batal'
  AND TRIM(rp.no_rkm_medis) <> ''
  AND TRIM(ps.nm_pasien) <> ''
  AND TRIM(rp.kd_poli) <> ''
  AND TRIM(p.nm_poli) <> ''
  AND TRIM(rp.kd_dokter) <> ''
  AND TRIM(d.nm_dokter) <> ''
  AND LOWER(p.nm_poli) NOT LIKE '%darurat%'
  AND LOWER(p.nm_poli) NOT LIKE '%igd%'
  AND LOWER(p.nm_poli) NOT LIKE '%rawat inap%'
  AND LOWER(p.nm_poli) NOT LIKE '%radiologi%'
  AND LOWER(p.nm_poli) NOT LIKE '%laboratorium%'
  AND LOWER(p.nm_poli) NOT LIKE '%laboratory%'
  AND LOWER(p.nm_poli) NOT LIKE '%aps%'
ORDER BY rp.tgl_registrasi,rp.jam_reg,p.nm_poli,d.nm_dokter";
$stmt=$pdo->prepare($sql); $stmt->execute(['p1'=>$tgl1,'p2'=>$tgl2,'d1'=>$tgl1,'d2'=>$tgl2,'r1'=>$tgl1,'r2'=>$tgl2,'t1'=>$tgl1,'t2'=>$tgl2]); $rows=$stmt->fetchAll();
$durasi=static function($awal,$akhir){if(!$awal||!$akhir)return '-';$s=strtotime($akhir)-strtotime($awal);return $s<0?'-':sprintf('%02d:%02d',intdiv($s,3600),intdiv($s%3600,60));};
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $namaFile = 'Lama_Pelayanan_Poliklinik_' . $tgl1 . '_sd_' . $tgl2 . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <tr><th colspan="16">LAPORAN LAMA PELAYANAN POLIKLINIK</th></tr>
        <tr><th colspan="16">Periode: <?= htmlspecialchars(date('d-m-Y', strtotime($tgl1)) . ' s/d ' . date('d-m-Y', strtotime($tgl2)), ENT_QUOTES, 'UTF-8') ?></th></tr>
        <tr><th>No</th><th>Tanggal</th><th>No Rawat</th><th>No RM</th><th>Nama Pasien</th><th>Poliklinik</th><th>Jenis Bayar</th><th>Status Poli</th><th>Dokter</th><th>Jam Praktik Pagi</th><th>Jam Praktik Sore</th><th>Jam Registrasi</th><th>Jam SOAP Perawat</th><th>Jam SOAP Dokter</th><th>Jam Input Resep</th><th>Total Reg-Resep</th></tr>
        <?php foreach ($rows as $i => $r): ?>
            <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars(date('d-m-Y', strtotime($r['tgl_registrasi'])), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['no_rawat'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['no_rkm_medis'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['nm_pasien'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['nm_poli'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['png_jawab'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['status_poli'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['nm_dokter'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['jam_praktek_pagi'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($r['jam_praktek_sore'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(substr((string)$r['jam_reg'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td><td><?= $r['jam_perawat'] ? htmlspecialchars(substr($r['jam_perawat'], 0, 5), ENT_QUOTES, 'UTF-8') : '-' ?></td><td><?= $r['jam_dokter'] ? htmlspecialchars(substr($r['jam_dokter'], 0, 5), ENT_QUOTES, 'UTF-8') : '-' ?></td><td><?= $r['jam_resep'] ? htmlspecialchars(substr($r['jam_resep'], 0, 5), ENT_QUOTES, 'UTF-8') : '-' ?></td><td><?= htmlspecialchars($durasi($r['jam_reg'], $r['jam_resep']), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php
    exit;
}
?>
<!doctype html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Lama Pelayanan Poliklinik</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{background:#f4f7f6;font:0.85rem 'Segoe UI',system-ui,sans-serif;color:#2c3e50}.page-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.page-tab{padding:8px 14px;border:1px solid #dce5e0;border-radius:20px;background:white;color:#2c3e50;text-decoration:none;font-weight:600}.page-tab.active{background:#0f7541;color:white}.card{border:0;border-radius:12px;box-shadow:0 4px 14px #00000014;overflow:hidden}.head{background:#0f7541;color:white;border-bottom:4px solid #e67e22}.wrap{overflow:auto;max-height:72vh}.table thead th{position:sticky;top:0;background:#0f7541;color:white;white-space:nowrap;z-index:2}.table td{white-space:nowrap}</style></head><body><main class="container-fluid py-4"><nav class="page-tabs"><a class="page-tab" href="poli.php">Sensus Poliklinik</a><span class="page-tab active">Lama Pelayanan Poliklinik</span><a class="page-tab" href="poli.php?view=kunjungan">Rekap Kunjungan</a><a class="page-tab" href="poli.php?view=kunjungan&amp;tab=bulanan">Grafik per Bulan</a><a class="page-tab" href="poli.php?view=dokter">Dokter</a></nav><section class="card"><header class="head text-center p-3"><h5 class="fw-bold mb-1"><?= htmlspecialchars($namaRS,ENT_QUOTES,'UTF-8') ?></h5><h5 class="fw-bold mb-0">LAMA PELAYANAN POLIKLINIK</h5></header><div class="card-body"><form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="view" value="lama_pelayanan"><div class="col-auto"><label class="form-label">Dari tanggal</label><input class="form-control form-control-sm" type="date" name="tgl1" value="<?= htmlspecialchars($tgl1) ?>"></div><div class="col-auto"><label class="form-label">Sampai tanggal</label><input class="form-control form-control-sm" type="date" name="tgl2" value="<?= htmlspecialchars($tgl2) ?>"></div><div class="col-auto"><button class="btn btn-success btn-sm">Tampilkan</button></div><div class="col-auto"><button class="btn btn-warning btn-sm" type="submit" name="export" value="excel">Ekspor Excel</button></div></form><div class="wrap"><table class="table table-sm table-bordered table-hover"><thead><tr><th>No</th><th>Tanggal</th><th>No Rawat</th><th>No RM</th><th>Nama Pasien</th><th>Poliklinik</th><th>Jenis Bayar</th><th>Status Poli</th><th>Dokter</th><th>Jam Praktik Pagi</th><th>Jam Praktik Sore</th><th>Jam Registrasi</th><th>Jam SOAP Perawat</th><th>Jam SOAP Dokter</th><th>Jam Input Resep</th><th>Total Reg–Resep</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="16" class="text-center text-muted">Tidak ada data.</td></tr><?php else: foreach($rows as $i=>$r): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars(date('d-m-Y',strtotime($r['tgl_registrasi']))) ?></td><td><?= htmlspecialchars($r['no_rawat']) ?></td><td><?= htmlspecialchars($r['no_rkm_medis']) ?></td><td><?= htmlspecialchars($r['nm_pasien']) ?></td><td><?= htmlspecialchars($r['nm_poli']) ?></td><td><?= htmlspecialchars($r['png_jawab']) ?></td><td><?= htmlspecialchars($r['status_poli'] ?: '-') ?></td><td><?= htmlspecialchars($r['nm_dokter']) ?></td><td><?= htmlspecialchars($r['jam_praktek_pagi'] ?: '-') ?></td><td><?= htmlspecialchars($r['jam_praktek_sore'] ?: '-') ?></td><td><?= htmlspecialchars(substr((string)$r['jam_reg'],0,5)) ?></td><td><?= $r['jam_perawat']?htmlspecialchars(substr($r['jam_perawat'],0,5)):'-' ?></td><td><?= $r['jam_dokter']?htmlspecialchars(substr($r['jam_dokter'],0,5)):'-' ?></td><td><?= $r['jam_resep']?htmlspecialchars(substr($r['jam_resep'],0,5)):'-' ?></td><td class="fw-bold"><?= $durasi($r['jam_reg'],$r['jam_resep']) ?></td></tr><?php endforeach; endif; ?></tbody></table></div><small class="text-muted">Jam SOAP dibedakan memakai NIP yang cocok dengan kode dokter. Resep menggunakan jam_peresepan. Durasi ditampilkan dalam jam:menit.</small></div></section><footer style="text-align:center;font-size:11px;color:#6c757d;margin:24px 12px 0;border-top:1px dashed #e0e6ed;padding-top:12px">© 2026 <b style="color:#0f7541">VIDI MEDIKA KONSULTAN IT</b>. SMART IT FOR HEALTH.</footer></main></body></html>
<?php exit; ?>
<?php } ?>
<?php if (isset($_GET['view']) && $_GET['view'] === 'dokter') { ?>
<?php
$host = "192.168.5.52:3382"; $db = "db-sim"; $user = "clientkhanza"; $pass = "Client1453";
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$namaRsDokter = (string)($pdo->query("SELECT nama_instansi FROM setting LIMIT 1")->fetchColumn() ?: 'Nama Rumah Sakit');
$mulai = $_GET['tgl1'] ?? date('Y-m-d'); $selesai = $_GET['tgl2'] ?? date('Y-m-d', strtotime('+1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) || strtotime($mulai) === false) $mulai = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai) || strtotime($selesai) === false) $selesai = date('Y-m-d', strtotime('+1 day'));
if ($selesai < $mulai) $selesai = $mulai;
if ((strtotime($selesai) - strtotime($mulai)) / 86400 > 13) $selesai = date('Y-m-d', strtotime($mulai . ' +13 days'));
$dates = []; $date = new DateTimeImmutable($mulai); $endDate = new DateTimeImmutable($selesai);
for (; $date <= $endDate; $date = $date->modify('+1 day')) $dates[] = $date;
$dayNames = [1=>'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'AKHAD'];
$dayLabels = [1=>'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$monthLabels = [1=>'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$days = array_values(array_unique(array_map(function ($d) use ($dayNames) {
    return $dayNames[(int)$d->format('N')];
}, $dates)));
$params = []; $holders = [];
foreach ($days as $i => $day) { $holders[] = ':day' . $i; $params['day' . $i] = $day; }
$sqlDokter = "SELECT d.kd_dokter,d.nm_dokter,j.hari_kerja,j.jam_mulai,j.jam_selesai,p.nm_poli FROM jadwal j JOIN dokter d ON d.kd_dokter=j.kd_dokter JOIN poliklinik p ON p.kd_poli=j.kd_poli WHERE j.hari_kerja IN (" . implode(',', $holders) . ") ORDER BY d.nm_dokter,j.hari_kerja,j.jam_mulai,p.nm_poli";
$stmtDokter = $pdo->prepare($sqlDokter); $stmtDokter->execute($params); $doctors = [];
foreach ($stmtDokter->fetchAll() as $r) { $id=$r['kd_dokter']; $doctors[$id]['nama']=$r['nm_dokter']; $waktuPraktek = substr($r['jam_mulai'],0,5) . '-' . substr($r['jam_selesai'],0,5); $doctors[$id]['jadwal'][$r['hari_kerja']][] = $waktuPraktek . ' (' . $r['nm_poli'] . ')'; $doctors[$id]['jam_mulai'][$r['hari_kerja']][] = substr($r['jam_mulai'],0,8); }
foreach ($doctors as &$doctor) {
    foreach ($doctor['jadwal'] as $hariPraktek => &$daftarJadwal) {
        if (count($daftarJadwal) > 1) {
            foreach ($daftarJadwal as $index => $jadwalPraktek) {
                $jamMulai = $doctor['jam_mulai'][$hariPraktek][$index] ?? '00:00:00';
                $sesiPraktek = $jamMulai < '12:00:00' ? 'Pagi' : 'Sore';
                $daftarJadwal[$index] = substr($jadwalPraktek, 0, -1) . ' - ' . $sesiPraktek . ')';
            }
        }
    }
    unset($daftarJadwal);
}
unset($doctor);
$stmtKehadiran = $pdo->prepare("SELECT rp.kd_dokter, pr.tgl_perawatan,
    MIN(CASE WHEN pr.jam_rawat < '12:00:00' THEN pr.jam_rawat END) AS jam_pagi,
    MIN(CASE WHEN pr.jam_rawat >= '12:00:00' THEN pr.jam_rawat END) AS jam_sore
    FROM pemeriksaan_ralan pr
    INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
    INNER JOIN dokter d ON d.kd_dokter = pr.nip AND d.kd_dokter = rp.kd_dokter
    WHERE pr.tgl_perawatan BETWEEN :mulai AND :selesai
    GROUP BY rp.kd_dokter, pr.tgl_perawatan");
$stmtKehadiran->execute(['mulai' => $mulai, 'selesai' => $selesai]);
$kehadiranDokter = [];
foreach ($stmtKehadiran->fetchAll() as $r) {
    $kehadiranDokter[$r['kd_dokter']][$r['tgl_perawatan']]['pagi'] = $r['jam_pagi'];
    $kehadiranDokter[$r['kd_dokter']][$r['tgl_perawatan']]['sore'] = $r['jam_sore'];
}
$doctorRows = [];
foreach ($doctors as $idDokter => $doctor) {
    foreach ($doctor['jadwal'] as $hariPraktek => $daftarJadwal) {
        foreach ($daftarJadwal as $indexJadwal => $jadwalPraktek) {
            $doctorRows[] = [
                'id' => $idDokter,
                'nama' => $doctor['nama'],
                'hari' => $hariPraktek,
                'jadwal' => $jadwalPraktek,
                'jam_mulai' => $doctor['jam_mulai'][$hariPraktek][$indexJadwal] ?? '00:00:00',
            ];
        }
    }
}
?>
<!doctype html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Dokter - <?= htmlspecialchars($namaRsDokter, ENT_QUOTES, 'UTF-8') ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>
:root{--green:#0f7541;--orange:#e67e22}body{background:#f4f7f6;color:#2c3e50;font:0.85rem 'Segoe UI',system-ui,sans-serif}.page-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;border-bottom:2px solid #e0e6ed;padding-bottom:10px}.page-tab{display:inline-flex;padding:8px 18px;border:1px solid #e0e6ed;border-radius:20px;color:#2c3e50;background:#fff;text-decoration:none;font-weight:600}.page-tab.active{color:#fff;background:var(--green);border-color:var(--green)}.card{border:0;border-radius:12px;box-shadow:0 4px 14px #00000014;overflow:hidden}.report-header{background:linear-gradient(135deg,var(--green),#0d6337);color:#fff;border-bottom:4px solid var(--orange)}.schedule-wrap{overflow:auto;max-height:72vh}#doctorTable{white-space:nowrap}#doctorTable thead th{position:sticky;top:0;z-index:2;background:var(--green);color:#fff;text-align:center;vertical-align:middle}#doctorTable thead tr:nth-child(2) th{top:38px;background:#0c5c33}#doctorTable tbody tr:hover td{background:#fff5e9}.doctor-name{min-width:170px;font-weight:600}.schedule{min-width:240px;white-space:normal}
</style></head><body><main class="container-fluid py-4"><nav class="page-tabs" aria-label="Menu laporan"><a class="page-tab" href="poli.php">Sensus Poliklinik</a><a class="page-tab" href="poli.php?view=lama_pelayanan">Lama Pelayanan Poliklinik</a><a class="page-tab" href="poli.php?view=kunjungan">Rekap Kunjungan</a><a class="page-tab" href="poli.php?view=kunjungan&amp;tab=bulanan">Grafik per Bulan</a><span class="page-tab active" aria-current="page">Dokter</span></nav><section class="card"><header class="report-header text-center p-3"><h5 class="fw-bold mb-1"><?= htmlspecialchars($namaRsDokter, ENT_QUOTES, 'UTF-8') ?></h5><h5 class="fw-bold mb-0">JADWAL DAN KEHADIRAN DOKTER</h5></header><div class="card-body"><form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="view" value="dokter"><div class="col-auto"><label class="form-label mb-1">Tanggal mulai</label><input type="date" name="tgl1" value="<?= htmlspecialchars($mulai) ?>" class="form-control form-control-sm"></div><div class="col-auto"><label class="form-label mb-1">Tanggal selesai</label><input type="date" name="tgl2" value="<?= htmlspecialchars($selesai) ?>" class="form-control form-control-sm"></div><div class="col-auto"><button class="btn btn-success btn-sm">Tampilkan</button></div></form><div class="schedule-wrap"><table id="doctorTable" class="table table-sm table-bordered table-hover mb-0"><thead><tr><th rowspan="2">No</th><th rowspan="2">Nama Dokter</th><th rowspan="2">Jadwal Praktik</th><?php foreach($dates as $d): ?><th colspan="3"><?= $dayLabels[(int)$d->format('N')] . ' ' . $d->format('d') . ' ' . $monthLabels[(int)$d->format('n')] . ' ' . $d->format('Y') ?></th><?php endforeach; ?></tr><tr><?php foreach($dates as $d): ?><th>Jam Datang</th><th>Keterlambatan</th><th>Ket</th><?php endforeach; ?></tr></thead><tbody>
<?php if (!$doctorRows): ?><tr><td colspan="<?= 3 + count($dates)*3 ?>" class="text-center text-muted py-4">Tidak ada jadwal dokter pada tanggal yang dipilih.</td></tr><?php else: $no=1; foreach($doctorRows as $doctorRow): ?><tr><td><?= $no++ ?></td><td class="doctor-name"><?= htmlspecialchars($doctorRow['nama'], ENT_QUOTES, 'UTF-8') ?></td><td class="schedule"><?= htmlspecialchars($dayLabels[array_search($doctorRow['hari'], $dayNames, true)] . ' ' . $doctorRow['jadwal'], ENT_QUOTES, 'UTF-8') ?></td><?php foreach($dates as $d): ?><?php $tanggal=$d->format('Y-m-d'); $hari=$dayNames[(int)$d->format('N')]; $periode=$doctorRow['jam_mulai'] < '12:00:00' ? 'pagi' : 'sore'; $jamDatang=$hari===$doctorRow['hari'] ? ($kehadiranDokter[$doctorRow['id']][$tanggal][$periode]??null) : null; $jamMulaiJadwal=$doctorRow['jam_mulai']; $durasiTerlambat='-'; $keteranganJam='-'; if($jamDatang && $jamMulaiJadwal){$selisihDetik=strtotime($jamDatang)-strtotime($jamMulaiJadwal); $keteranganJam=$selisihDetik>0?'Terlambat':'Tepat waktu'; $durasiTerlambat=$selisihDetik>0?sprintf('%02d:%02d',floor($selisihDetik/3600),floor(($selisihDetik%3600)/60)):'00:00';} ?><td class="text-center"><?= $jamDatang ? htmlspecialchars(substr($jamDatang,0,5), ENT_QUOTES, 'UTF-8') : '-' ?></td><td class="text-center"><?= htmlspecialchars($durasiTerlambat, ENT_QUOTES, 'UTF-8') ?></td><td class="text-center"><?php if($keteranganJam==='Terlambat'): ?><span class="text-danger fw-semibold">Terlambat</span><?php elseif($keteranganJam==='Tepat waktu'): ?><span class="text-success">Tepat waktu</span><?php else: ?>-<?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?></tbody></table></div></div></section><footer style="text-align:center;font-size:11px;color:#6c757d;margin:24px 12px 0;border-top:1px dashed #e0e6ed;padding-top:12px">© 2026 <b style="color:#0f7541">VIDI MEDIKA KONSULTAN IT</b>. SMART IT FOR HEALTH.</footer></main></body></html>
<?php exit; ?>
<?php } ?>
<?php if (isset($_GET['view']) && $_GET['view'] === 'kunjungan') { ?>
<?php
$host = "192.168.5.52:3382";
$db = "db-sim";
$user = "clientkhanza";
$pass = "Client1453";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Koneksi Gagal: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
$nama_rs = (string)($pdo->query("SELECT nama_instansi FROM setting LIMIT 1")->fetchColumn() ?: 'Nama Rumah Sakit');

$bulan = filter_input(INPUT_GET, 'bulan', FILTER_VALIDATE_INT) ?: (int)date('n');
$tahun = filter_input(INPUT_GET, 'tahun', FILTER_VALIDATE_INT) ?: (int)date('Y');
$tabGrafik = isset($_GET['tab']) && $_GET['tab'] === 'bulanan';
$bulan = max(1, min(12, $bulan));
$tahun = max(2000, min(2100, $tahun));
$awal = sprintf('%04d-%02d-01', $tahun, $bulan);
$akhir = date('Y-m-t', strtotime($awal));
$jumlahHariBulan = (int)date('t', strtotime($awal));
$hariFilter = filter_input(INPUT_GET, 'hari', FILTER_VALIDATE_INT);
$hariFilter = ($hariFilter !== false && $hariFilter !== null && $hariFilter >= 1 && $hariFilter <= $jumlahHariBulan) ? $hariFilter : 0;
$awalTahun = sprintf('%04d-01-01', $tahun);
$akhirTahun = sprintf('%04d-12-31', $tahun);
$namaBulan = [1=>'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$harian = [];
for ($hari = 1; $hari <= 31; $hari++) {
    $harian[] = "SUM(CASE WHEN DAY(r.tgl_registrasi) = $hari THEN 1 ELSE 0 END) AS hari_$hari";
}
$sql = "SELECT p.kd_poli, p.nm_poli, d.kd_dokter, d.nm_dokter, pj.kd_pj, pj.png_jawab, " . implode(', ', $harian) . ", COUNT(*) AS total
        FROM reg_periksa r
        INNER JOIN pasien ps ON ps.no_rkm_medis = r.no_rkm_medis
        INNER JOIN poliklinik p ON p.kd_poli = r.kd_poli
        INNER JOIN dokter d ON d.kd_dokter = r.kd_dokter
        INNER JOIN penjab pj ON pj.kd_pj = r.kd_pj
        WHERE r.tgl_registrasi BETWEEN :awal AND :akhir
          AND r.stts <> 'Batal'
          AND TRIM(ps.nm_pasien) <> ''
          AND TRIM(d.nm_dokter) <> ''
          AND TRIM(p.nm_poli) <> ''
          AND LOWER(p.nm_poli) NOT LIKE '%darurat%'
          AND LOWER(p.nm_poli) NOT LIKE '%rawat inap%'
          AND LOWER(p.nm_poli) NOT LIKE '%radiologi%'
          AND LOWER(p.nm_poli) NOT LIKE '%laboratorium%'
          AND LOWER(p.nm_poli) NOT LIKE '%laboratory%'
          " . ($hariFilter ? "AND DAY(r.tgl_registrasi) = :hariFilter\n          " : "") . "
        GROUP BY p.kd_poli, p.nm_poli, d.kd_dokter, d.nm_dokter, pj.kd_pj, pj.png_jawab
        ORDER BY p.nm_poli, d.nm_dokter, pj.png_jawab";
$stmt = $pdo->prepare($sql);
$paramsKunjungan = ['awal' => $awal, 'akhir' => $akhir];
if ($hariFilter) $paramsKunjungan['hariFilter'] = $hariFilter;
$stmt->execute($paramsKunjungan);
$data = $stmt->fetchAll();
$rekapBayar = [];
$rekapPoli = [];
$rekapDokter = [];
foreach ($data as $baris) {
    $jenisBayar = $baris['png_jawab'];
    if (!isset($rekapBayar[$jenisBayar])) $rekapBayar[$jenisBayar] = 0;
    $rekapBayar[$jenisBayar] += (int)$baris['total'];

    $namaPoli = $baris['nm_poli'];
    if (!isset($rekapPoli[$namaPoli])) $rekapPoli[$namaPoli] = 0;
    $rekapPoli[$namaPoli] += (int)$baris['total'];

    $kodeDokter = $baris['kd_dokter'];
    if (!isset($rekapDokter[$kodeDokter])) {
        $rekapDokter[$kodeDokter] = ['nama' => $baris['nm_dokter'], 'jumlah' => 0];
    }
    $rekapDokter[$kodeDokter]['jumlah'] += (int)$baris['total'];
}
$sqlGrafik = "SELECT MONTH(tgl_registrasi) AS bulan, COUNT(*) AS jumlah
              FROM reg_periksa r
              INNER JOIN pasien ps ON ps.no_rkm_medis = r.no_rkm_medis
              INNER JOIN poliklinik p ON p.kd_poli = r.kd_poli
              INNER JOIN dokter d ON d.kd_dokter = r.kd_dokter
              WHERE r.tgl_registrasi BETWEEN :awal AND :akhir AND r.stts <> 'Batal'
                AND TRIM(ps.nm_pasien) <> '' AND TRIM(p.nm_poli) <> '' AND TRIM(d.nm_dokter) <> ''
                AND LOWER(p.nm_poli) NOT LIKE '%darurat%'
                AND LOWER(p.nm_poli) NOT LIKE '%rawat inap%'
                AND LOWER(p.nm_poli) NOT LIKE '%radiologi%'
                AND LOWER(p.nm_poli) NOT LIKE '%laboratorium%'
                AND LOWER(p.nm_poli) NOT LIKE '%laboratory%'
              GROUP BY MONTH(tgl_registrasi)";
$stmtGrafik = $pdo->prepare($sqlGrafik);
$stmtGrafik->execute(['awal' => $awalTahun, 'akhir' => $akhirTahun]);
$hasilGrafik = $stmtGrafik->fetchAll(PDO::FETCH_KEY_PAIR);
$awalTahunSebelumnya = sprintf('%04d-01-01', $tahun - 1);
$akhirTahunSebelumnya = sprintf('%04d-12-31', $tahun - 1);
$stmtGrafik->execute(['awal' => $awalTahunSebelumnya, 'akhir' => $akhirTahunSebelumnya]);
$hasilGrafikSebelumnya = $stmtGrafik->fetchAll(PDO::FETCH_KEY_PAIR);
$dataGrafik = [];
$dataGrafikSebelumnya = [];
for ($nomorBulan = 1; $nomorBulan <= 12; $nomorBulan++) {
    $dataGrafik[] = (int)($hasilGrafik[$nomorBulan] ?? 0);
    $dataGrafikSebelumnya[] = (int)($hasilGrafikSebelumnya[$nomorBulan] ?? 0);
}
$export = isset($_GET['export']) && $_GET['export'] === 'excel';
$totalPerHari = array_fill(1, 31, 0);
$grandTotal = 0;
foreach ($data as $baris) {
    for ($hari = 1; $hari <= 31; $hari++) {
        $totalPerHari[$hari] += (int)$baris['hari_' . $hari];
    }
    $grandTotal += (int)$baris['total'];
}
if ($export) {
    $namaFile = 'Rekap_Kunjungan_' . $tahun . '_' . sprintf('%02d', $bulan) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <tr><th colspan="36">LAPORAN REKAP KUNJUNGAN PASIEN PER POLIKLINIK DAN DOKTER</th></tr>
        <tr><th colspan="36">Periode: Bulan <?= htmlspecialchars($namaBulan[$bulan] . ' Tahun ' . $tahun, ENT_QUOTES, 'UTF-8') ?></th></tr>
        <tr><th rowspan="2">No</th><th rowspan="2">Poliklinik</th><th rowspan="2">Dokter</th><th rowspan="2">Jenis Bayar</th><th colspan="31">Jumlah Pasien</th><th rowspan="2">Total</th></tr>
        <tr><?php for ($hari = 1; $hari <= 31; $hari++): ?><th><?= $hari ?></th><?php endfor; ?></tr>
        <?php foreach ($data as $i => $baris): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($baris['nm_poli'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($baris['nm_dokter'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($baris['png_jawab'], ENT_QUOTES, 'UTF-8') ?></td>
                <?php for ($hari = 1; $hari <= 31; $hari++): ?><td><?= (int)$baris['hari_' . $hari] ?></td><?php endfor; ?>
                <td><?= (int)$baris['total'] ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th colspan="4">Total per hari</th><?php for ($hari = 1; $hari <= 31; $hari++): ?><th><?= $totalPerHari[$hari] ?></th><?php endfor; ?><th><?= $grandTotal ?></th></tr>
    </table>
    <br>
    <table border="1">
        <tr><th colspan="2">REKAP KUNJUNGAN PER JENIS BAYAR</th></tr>
        <tr><th>Jenis Bayar</th><th>Jumlah Pasien</th></tr>
        <?php foreach ($rekapBayar as $jenis => $jumlah): ?><tr><td><?= htmlspecialchars($jenis, ENT_QUOTES, 'UTF-8') ?></td><td><?= $jumlah ?></td></tr><?php endforeach; ?>
        <tr><th>Total</th><th><?= $grandTotal ?></th></tr>
    </table>
    <br>
    <table border="1">
        <tr><th colspan="2">REKAP KUNJUNGAN PER POLIKLINIK</th></tr>
        <tr><th>Poliklinik</th><th>Jumlah Kunjungan Pasien</th></tr>
        <?php foreach ($rekapPoli as $poli => $jumlah): ?><tr><td><?= htmlspecialchars($poli, ENT_QUOTES, 'UTF-8') ?></td><td><?= $jumlah ?></td></tr><?php endforeach; ?>
        <tr><th>Total</th><th><?= $grandTotal ?></th></tr>
    </table>
    <br>
    <table border="1">
        <tr><th colspan="2">REKAP KUNJUNGAN PER DOKTER</th></tr>
        <tr><th>Dokter</th><th>Jumlah Kunjungan Pasien</th></tr>
        <?php foreach ($rekapDokter as $dokter): ?><tr><td><?= htmlspecialchars($dokter['nama'], ENT_QUOTES, 'UTF-8') ?></td><td><?= $dokter['jumlah'] ?></td></tr><?php endforeach; ?>
        <tr><th>Total</th><th><?= $grandTotal ?></th></tr>
    </table>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rekap Kunjungan Pasien per Poli dan Dokter</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f1f6f2; font-size:13px; }
        .card { border-color:#d8e6dc; }
        .header { background:#0f7541; color:#fff; border-bottom:4px solid #e67e22; }
        .table-wrap { overflow:auto; max-height:72vh; }
        table { white-space:nowrap; }
        thead th { position:sticky; z-index:2; text-align:center; }
        thead tr:first-child th { top:0; background:#0f7541 !important; color:#fff !important; }
        thead tr:nth-child(2) th { top:29px; background:#e67e22 !important; color:#fff !important; }
        thead th:first-child, tbody td:first-child { position:sticky; left:0; z-index:3; }
        thead th:first-child { z-index:4; }
        tbody td:first-child { background:#fff; }
        tfoot th { position:sticky; bottom:0; background:#e7f1ea; color:#0f7541; z-index:2; border-top:2px solid #e67e22; }
        .btn-green { background:#0f7541; color:#fff; }
        .btn-green:hover { background:#0b5730; color:#fff; }
        .form-control:focus, .form-select:focus { border-color:#0f7541; box-shadow:0 0 0 .2rem rgba(15,117,65,.18); }
        .total-count { color:#0f7541; }
        .page-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
        .page-tab { display:inline-block; padding:8px 14px; border:1px solid #0f7541; border-radius:6px; color:#0f7541; background:#fff; text-decoration:none; font-weight:600; }
        .page-tab:hover, .page-tab.active { color:#fff; background:#0f7541; }
        tbody tr:hover td { background:#fff5e9; }
        .nav-report .nav-link { color:#0f7541; font-weight:600; border:1px solid #d8e6dc; margin-right:5px; }
        .nav-report .nav-link.active { background:#0f7541; color:#fff; border-color:#0f7541; }
        .report-panel { display:none; }
        .report-panel.active { display:block; }
    </style>
</head>
<body>
<main class="container-fluid py-4">
    <nav class="page-tabs" aria-label="Menu data kunjungan">
        <a class="page-tab" href="poli.php">Sensus Poliklinik</a><a class="page-tab" href="poli.php?view=lama_pelayanan">Lama Pelayanan Poliklinik</a>
        <?php if ($tabGrafik): ?>
            <a class="page-tab" href="poli.php?view=kunjungan">Rekap Kunjungan</a>
            <span class="page-tab active" aria-current="page">Grafik per Bulan</span>
        <?php else: ?>
            <span class="page-tab active" aria-current="page">Rekap Kunjungan</span>
            <a class="page-tab" href="poli.php?view=kunjungan&amp;tab=bulanan">Grafik per Bulan</a>
        <?php endif; ?>
        <a class="page-tab" href="poli.php?view=dokter">Dokter</a>
    </nav>
    <section class="card shadow-sm">
        <header class="header text-center p-3">
            <h5 class="mb-1 fw-bold">LAPORAN REKAP KUNJUNGAN PASIEN PER POLIKLINIK DAN DOKTER</h5>
            <small>Periode: Bulan <?= htmlspecialchars($namaBulan[$bulan] . ' Tahun ' . $tahun, ENT_QUOTES, 'UTF-8') ?></small>
        </header>
        <div class="card-body">
            <section id="panel-rekap" class="report-panel <?= $tabGrafik ? '' : 'active' ?>">
            <form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="view" value="kunjungan">
                <div class="col-auto">
                    <label for="bulan" class="form-label mb-1">Bulan</label>
                    <select id="bulan" name="bulan" class="form-select form-select-sm">
                        <?php foreach ($namaBulan as $nomor => $nama): ?>
                            <option value="<?= $nomor ?>" <?= $bulan === $nomor ? 'selected' : '' ?>><?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label for="tahun" class="form-label mb-1">Tahun</label>
                    <input id="tahun" name="tahun" type="number" min="2000" max="2100" value="<?= $tahun ?>" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label for="hari" class="form-label mb-1">Hari</label>
                    <select id="hari" name="hari" class="form-select form-select-sm">
                        <option value="0" <?= $hariFilter === 0 ? 'selected' : '' ?>>Semua hari</option>
                        <?php for ($nomorHari = 1; $nomorHari <= $jumlahHariBulan; $nomorHari++): ?>
                            <option value="<?= $nomorHari ?>" <?= $hariFilter === $nomorHari ? 'selected' : '' ?>><?= $nomorHari ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto"><button class="btn btn-green btn-sm fw-bold">Tampilkan</button></div>
                <div class="col-auto"><button type="submit" name="export" value="excel" class="btn btn-sm fw-bold text-white" style="background:#e67e22">Ekspor Excel</button></div>
                <div class="col-auto ms-auto fw-bold total-count">Total kunjungan: <?= number_format($grandTotal, 0, ',', '.') ?></div>
            </form>
            <p class="text-muted small mb-2">Jumlah adalah kunjungan terdaftar per hari. Pasien dengan status <strong>Batal</strong> tidak dihitung.</p>
            <div class="table-wrap">
                <table id="rekap" class="table table-sm table-bordered table-hover mb-0">
                    <thead>
                        <tr>
                            <th rowspan="2">No</th><th rowspan="2">Poliklinik</th><th rowspan="2">Dokter</th><th rowspan="2">Jenis Bayar</th>
                            <th colspan="31">Jumlah Pasien</th><th rowspan="2">Total</th>
                        </tr>
                        <tr><?php for ($hari = 1; $hari <= 31; $hari++): ?><th><?= $hari ?></th><?php endfor; ?></tr>
                    </thead>
                    <tbody>
                        <?php if (!$data): ?>
                            <tr><td colspan="36" class="text-center text-muted py-4">Tidak ada data kunjungan pada bulan ini.</td></tr>
                        <?php else: ?>
                            <?php $rowIndex = 0; while ($rowIndex < count($data)): ?>
                                <?php
                                $baris = $data[$rowIndex];
                                $awalGrup = $rowIndex;
                                while (isset($data[$rowIndex + 1]) && $data[$rowIndex + 1]['kd_poli'] === $baris['kd_poli']) $rowIndex++;
                                $akhirGrupPoli = $rowIndex;
                                $jumlahDokterPoli = $akhirGrupPoli - $awalGrup + 1;
                                $rowIndex = $awalGrup;
                                ?>
                                <?php while ($rowIndex <= $akhirGrupPoli): $baris = $data[$rowIndex]; ?>
                                    <tr>
                                        <td class="text-center"><?= $rowIndex + 1 ?></td>
                                        <?php if ($rowIndex === $awalGrup): ?>
                                            <td rowspan="<?= $jumlahDokterPoli ?>"><?= htmlspecialchars($baris['nm_poli'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($baris['nm_dokter'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($baris['png_jawab'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php for ($hari = 1; $hari <= 31; $hari++): ?><td class="text-center"><?= (int)$baris['hari_' . $hari] ?></td><?php endfor; ?>
                                        <td class="text-center fw-bold"><?= number_format((int)$baris['total'], 0, ',', '.') ?></td>
                                    </tr>
                                    <?php $rowIndex++; endwhile; ?>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">Total per hari</th>
                            <?php for ($hari = 1; $hari <= 31; $hari++): ?><th class="text-center"><?= $totalPerHari[$hari] ?></th><?php endfor; ?>
                            <th class="text-center"><?= number_format($grandTotal, 0, ',', '.') ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="row g-4 mt-1">
                <section class="col-12 col-xl-4">
            <h6 class="fw-bold" style="color:#0f7541;border-bottom:2px solid #e67e22;padding-bottom:8px">REKAP KUNJUNGAN PER JENIS BAYAR</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover w-100">
                    <thead><tr><th>Jenis Bayar</th><th>Jumlah Pasien</th></tr></thead>
                    <tbody>
                        <?php if (!$rekapBayar): ?>
                            <tr><td colspan="2" class="text-center text-muted">Tidak ada data.</td></tr>
                        <?php else: foreach ($rekapBayar as $jenis => $jumlah): ?>
                            <tr><td><?= htmlspecialchars($jenis, ENT_QUOTES, 'UTF-8') ?></td><td class="text-end fw-bold"><?= number_format($jumlah, 0, ',', '.') ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th>Total</th><th class="text-end"><?= number_format($grandTotal, 0, ',', '.') ?></th></tr></tfoot>
                </table>
            </div>
                </section>
                <section class="col-12 col-xl-4">
            <h6 class="fw-bold" style="color:#0f7541;border-bottom:2px solid #e67e22;padding-bottom:8px">REKAP KUNJUNGAN PER POLIKLINIK</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover w-100">
                    <thead><tr><th>Poliklinik</th><th>Jumlah Kunjungan Pasien</th></tr></thead>
                    <tbody>
                        <?php if (!$rekapPoli): ?>
                            <tr><td colspan="2" class="text-center text-muted">Tidak ada data.</td></tr>
                        <?php else: foreach ($rekapPoli as $poli => $jumlah): ?>
                            <tr><td><?= htmlspecialchars($poli, ENT_QUOTES, 'UTF-8') ?></td><td class="text-end fw-bold"><?= number_format($jumlah, 0, ',', '.') ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th>Total</th><th class="text-end"><?= number_format($grandTotal, 0, ',', '.') ?></th></tr></tfoot>
                </table>
            </div>
                </section>
                <section class="col-12 col-xl-4">
            <h6 class="fw-bold" style="color:#0f7541;border-bottom:2px solid #e67e22;padding-bottom:8px">REKAP KUNJUNGAN PER DOKTER</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover w-100">
                    <thead><tr><th>Dokter</th><th>Jumlah Kunjungan Pasien</th></tr></thead>
                    <tbody>
                        <?php if (!$rekapDokter): ?>
                            <tr><td colspan="2" class="text-center text-muted">Tidak ada data.</td></tr>
                        <?php else: foreach ($rekapDokter as $dokter): ?>
                            <tr><td><?= htmlspecialchars($dokter['nama'], ENT_QUOTES, 'UTF-8') ?></td><td class="text-end fw-bold"><?= number_format($dokter['jumlah'], 0, ',', '.') ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr><th>Total</th><th class="text-end"><?= number_format($grandTotal, 0, ',', '.') ?></th></tr></tfoot>
                </table>
            </div>
                </section>
            </div>
            </section>
            <section id="panel-bulanan" class="report-panel <?= $tabGrafik ? 'active' : '' ?>">
              <form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="view" value="kunjungan">
                <input type="hidden" name="tab" value="bulanan">
                <input type="hidden" name="bulan" value="<?= $bulan ?>">
                <div class="col-auto"><label for="tahun-bulanan" class="form-label mb-1">Tahun grafik</label><input id="tahun-bulanan" name="tahun" type="number" min="2000" max="2100" value="<?= $tahun ?>" class="form-control form-control-sm"></div>
                <div class="col-auto"><button class="btn btn-green btn-sm fw-bold">Tampilkan</button></div>
              </form>
              <section id="grafik" class="p-3 border rounded bg-white">
                <h6 class="fw-bold mb-3" style="color:#0f7541">GRAFIK KUNJUNGAN PER BULAN - <?= $tahun ?></h6>
                <canvas id="grafikKunjungan" height="100" aria-label="Grafik jumlah kunjungan pasien per bulan"></canvas>
                <small class="text-muted">Kunjungan berstatus Batal tidak dihitung.</small>
              </section>
            </section>
        </div>
    </section>
    <footer class="text-center small text-muted mt-4 pt-3 border-top">© 2026 <b class="text-success">VIDI MEDIKA KONSULTAN IT</b>. SMART IT FOR HEALTH.</footer>
</main>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('grafikKunjungan'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_values($namaBulan), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: '<?= $tahun ?>',
            data: <?= json_encode($dataGrafik) ?>,
            backgroundColor: '#0f7541',
            borderColor: '#0f7541',
            borderWidth: 1
        }, {
            label: '<?= $tahun - 1 ?>',
            data: <?= json_encode($dataGrafikSebelumnya) ?>,
            backgroundColor: '#e67e22',
            borderColor: '#c96516',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        layout: { padding: { top: 22 } },
        plugins: { legend: { display: true, position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    },
    plugins: [{
        id: 'labelKunjunganBulanan',
        afterDatasetsDraw: function(chart) {
            const ctx = chart.ctx;
            ctx.save();
            ctx.font = '12px sans-serif';
            ctx.fillStyle = '#26352b';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            chart.data.datasets.forEach(function(dataset, datasetIndex) {
                const meta = chart.getDatasetMeta(datasetIndex);
                meta.data.forEach(function(bar, index) {
                    const nilai = dataset.data[index];
                    ctx.fillText(Number(nilai).toLocaleString('id-ID'), bar.x, bar.y - 4);
                });
            });
            ctx.restore();
        }
    }]
});
</script>
</body>
</html>
<?php exit; ?>

<?php } ?>


<?php
$nama_rs = 'Nama Rumah Sakit';
// 1. KONFIGURASI KONEKSI
$host = "192.168.5.52:3382"; 
$db   = "db-sim"; 
$user = "clientkhanza"; 
$pass = "Client1453"; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage());
}
try {
    $nama_rs = (string)($pdo->query("SELECT nama_instansi FROM setting LIMIT 1")->fetchColumn() ?: $nama_rs);
} catch (PDOException $e) {
    // Gunakan nama cadangan jika data setting tidak tersedia.
}

// 2. LOGIKA FILTER
$tgl1      = $_GET['tgl1'] ?? date('Y-m-d');
$tgl2      = $_GET['tgl2'] ?? date('Y-m-d');
$status   = $_GET['status'] ?? 'semua';
$kd_poli  = $_GET['kd_poli'] ?? 'semua';
$kd_dokter= $_GET['kd_dokter'] ?? 'semua';
$kd_pj    = $_GET['kd_pj'] ?? 'semua';

// Ambil list Poli, Dokter, dan Penjab untuk filter
$list_poli   = $pdo->query("SELECT kd_poli, nm_poli FROM poliklinik
                            WHERE LOWER(nm_poli) NOT LIKE '%darurat%'
                              AND LOWER(nm_poli) NOT LIKE '%rawat inap%'
                              AND LOWER(nm_poli) NOT LIKE '%radiologi%'
                              AND LOWER(nm_poli) NOT LIKE '%laboratorium%'
                              AND LOWER(nm_poli) NOT LIKE '%laboratory%'
                            ORDER BY nm_poli")->fetchAll();
$list_dokter = $pdo->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status='1' ORDER BY nm_dokter")->fetchAll();
$list_pj     = $pdo->query("SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab")->fetchAll();

// 3. QUERY SQL (OPTIMIZED)
try {
    $params = ['tgl1' => $tgl1, 'tgl2' => $tgl2];
    
    // Optimasi: Memisahkan pencarian diagnosa utama (prioritas 1) dan tambahan agar tidak membebani JOIN utama
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
                reg_periksa.stts_daftar, 
                reg_periksa.status_poli,
                reg_periksa.status_lanjut,
                reg_periksa.stts,
                (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(pr.evaluasi), '') SEPARATOR ' | ')
                 FROM pemeriksaan_ralan pr
                 WHERE pr.no_rawat = reg_periksa.no_rawat) AS evaluasi,
                CONCAT_WS(', ',
                    (SELECT GROUP_CONCAT(DISTINCT jp2.nm_perawatan SEPARATOR ', ')
                     FROM rawat_jl_dr rjd2 JOIN jns_perawatan jp2 ON jp2.kd_jenis_prw = rjd2.kd_jenis_prw
                     WHERE rjd2.no_rawat = reg_periksa.no_rawat),
                    (SELECT GROUP_CONCAT(DISTINCT jp3.nm_perawatan SEPARATOR ', ')
                     FROM rawat_jl_drpr rjdp2 JOIN jns_perawatan jp3 ON jp3.kd_jenis_prw = rjdp2.kd_jenis_prw
                     WHERE rjdp2.no_rawat = reg_periksa.no_rawat)
                ) AS tindakan_dokter,
                CONCAT_WS(', ',
                    (SELECT GROUP_CONCAT(DISTINCT jp4.nm_perawatan SEPARATOR ', ')
                     FROM rawat_jl_pr rjp2 JOIN jns_perawatan jp4 ON jp4.kd_jenis_prw = rjp2.kd_jenis_prw
                     WHERE rjp2.no_rawat = reg_periksa.no_rawat),
                    (SELECT GROUP_CONCAT(DISTINCT jp5.nm_perawatan SEPARATOR ', ')
                     FROM rawat_jl_drpr rjdp3 JOIN jns_perawatan jp5 ON jp5.kd_jenis_prw = rjdp3.kd_jenis_prw
                     WHERE rjdp3.no_rawat = reg_periksa.no_rawat)
                ) AS tindakan_paramedis,
                
                /* Subquery Diagnosa Utama (Prioritas 1) - Lebih Cepat dibanding MAX(IF) Multi-Join */
                (SELECT dp.kd_penyakit FROM diagnosa_pasien dp WHERE dp.no_rawat = reg_periksa.no_rawat AND dp.prioritas = 1 LIMIT 1) as kd_utama,
                (SELECT p.nm_penyakit FROM diagnosa_pasien dp JOIN penyakit p ON dp.kd_penyakit = p.kd_penyakit WHERE dp.no_rawat = reg_periksa.no_rawat AND dp.prioritas = 1 LIMIT 1) as nm_utama,
                /* Subquery Diagnosa Tambahan */
                (SELECT GROUP_CONCAT(CONCAT(dp2.kd_penyakit, ' - ', p2.nm_penyakit) SEPARATOR ' | ') 
                 FROM diagnosa_pasien dp2 
                 JOIN penyakit p2 ON dp2.kd_penyakit = p2.kd_penyakit 
                 WHERE dp2.no_rawat = reg_periksa.no_rawat AND dp2.prioritas > 1) as diagnosa_tambahan

            FROM reg_periksa 
            INNER JOIN pasien ON reg_periksa.no_rkm_medis = pasien.no_rkm_medis
            INNER JOIN poliklinik ON reg_periksa.kd_poli = poliklinik.kd_poli
            INNER JOIN dokter ON reg_periksa.kd_dokter = dokter.kd_dokter
            INNER JOIN penjab ON reg_periksa.kd_pj = penjab.kd_pj
            WHERE reg_periksa.tgl_registrasi BETWEEN :tgl1 AND :tgl2
              AND TRIM(reg_periksa.no_rkm_medis) <> ''
              AND TRIM(pasien.nm_pasien) <> ''
              AND TRIM(reg_periksa.kd_poli) <> ''
              AND TRIM(poliklinik.nm_poli) <> ''
              AND TRIM(reg_periksa.kd_dokter) <> ''
              AND TRIM(dokter.nm_dokter) <> ''
              AND LOWER(poliklinik.nm_poli) NOT LIKE '%darurat%'
              AND LOWER(poliklinik.nm_poli) NOT LIKE '%rawat inap%'
              AND LOWER(poliklinik.nm_poli) NOT LIKE '%radiologi%'
              AND LOWER(poliklinik.nm_poli) NOT LIKE '%laboratorium%'
              AND LOWER(poliklinik.nm_poli) NOT LIKE '%laboratory%'";

    if ($status == 'non_batal') { $sql .= " AND reg_periksa.stts <> 'Batal'"; }
    if ($kd_poli != 'semua') { $sql .= " AND reg_periksa.kd_poli = :kd_poli"; $params['kd_poli'] = $kd_poli; }
    if ($kd_dokter != 'semua') { $sql .= " AND reg_periksa.kd_dokter = :kd_dokter"; $params['kd_dokter'] = $kd_dokter; }
    if ($kd_pj != 'semua') { $sql .= " AND reg_periksa.kd_pj = :kd_pj"; $params['kd_pj'] = $kd_pj; }

    $sql .= " ORDER BY reg_periksa.tgl_registrasi ASC, reg_periksa.jam_reg ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Inisialisasi Grouping Rekapitulasi
    $rekap_daftar = [];
    $rekap_poli   = [];
    $rekap_lanjut = [];
    $rekap_bayar  = [];
    $rekap_jk     = ['L' => 0, 'P' => 0];

    foreach($data as $r) {
        // Demografi JK
        if($r['jk'] == 'L') $rekap_jk['L']++; else $rekap_jk['P']++;

        // Rekap Status Daftar
        $stts_daftar = !empty($r['stts_daftar']) ? $r['stts_daftar'] : '-';
        $rekap_daftar[$stts_daftar] = ($rekap_daftar[$stts_daftar] ?? 0) + 1;

        // Rekap Status Poli
        $status_poli = !empty($r['status_poli']) ? $r['status_poli'] : '-';
        $rekap_poli[$status_poli] = ($rekap_poli[$status_poli] ?? 0) + 1;

        // Rekap Status Lanjut
        $status_lanjut = !empty($r['status_lanjut']) ? $r['status_lanjut'] : '-';
        $rekap_lanjut[$status_lanjut] = ($rekap_lanjut[$status_lanjut] ?? 0) + 1;

        $jenis_bayar = !empty($r['png_jawab']) ? $r['png_jawab'] : '-';
        $rekap_bayar[$jenis_bayar] = ($rekap_bayar[$jenis_bayar] ?? 0) + 1;

    }
} catch (Exception $e) {
    $error_msg = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sensus Poliklinik - <?= htmlspecialchars($nama_rs, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        body { background-color: #f4f7f5; font-size: 0.75rem; padding-bottom: 60px; }
        /* Tema RS Kartika Husada: Hijau Utama (#0f7541) & Aksentuasi Oranye (#e67e22) */
        .header-title { background: #0f7541; color: white; padding: 15px; border-radius: 8px 8px 0 0; border-bottom: 4px solid #e67e22; }
        .table thead { background-color: #0f7541; color: white; vertical-align: middle; }
        #sensusTable tbody td { text-align: left !important; }
        .rekap-box { background: #fff; border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; height: 100%; border-top: 3px solid #0f7541; }
        .rekap-total-box { background: #0f7541 !important; color: white; border-top: 3px solid #e67e22 !important; }
        .dt-buttons { margin-bottom: 10px; }
        .btn-kh-hijau { background-color: #0f7541; color: white; border: none; }
        .btn-kh-hijau:hover { background-color: #0b5730; color: white; }
        .btn-kh-oranye { background-color: #e67e22; color: white; border: none; }
        .btn-kh-oranye:hover { background-color: #d35400; color: white; }
        .diag-tambahan { color: #e67e22; font-style: italic; }
        .single-line { white-space: nowrap; }
        .doctor-name { white-space: nowrap; min-width: 170px; }
        .text-kh-hijau { color: #0f7541 !important; }
        .text-kh-oranye { color: #e67e22 !important; }
        .border-kh-hijau { border-bottom: 2px solid #0f7541 !important; }
        .page-item.active .page-link { background-color: #0f7541 !important; border-color: #0f7541 !important; }
        .page-link { color: #0f7541; }
        .page-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .page-tab { display: inline-block; padding: 8px 14px; border: 1px solid #0f7541; border-radius: 6px; color: #0f7541; background: #fff; text-decoration: none; font-weight: 600; }
        .page-tab:hover, .page-tab.active { color: #fff; background: #0f7541; }
        
        /* Style Watermark Profesional Vidi Medika */
        .vidi-watermark {
            position: fixed;
            bottom: 12px;
            right: 20px;
            opacity: 0.3;
            font-size: 0.7rem;
            color: #0f7541;
            font-weight: bold;
            z-index: 9999;
            pointer-events: none;
            letter-spacing: 1px;
        }
    
        /* Gaya profesional dari lampiran */
/* ==========================================================
       GLOBAL RESETS & TYPOGRAPHY
       ========================================================== */
    :root {
        --kh-green: #0f7541;
        --kh-green-hover: #0b5730;
        --kh-green-light: #e8f5e9;
        --kh-orange: #e67e22;
        --kh-orange-hover: #d35400;
        --kh-orange-light: #fff5e9;
        --bg-main: #f4f7f6;
        --text-dark: #2c3e50;
        --text-muted: #6c757d;
        --border-color: #e0e6ed;
        --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
        --shadow-md: 0 4px 14px rgba(0,0,0,0.08);
    }

    body {
        background-color: var(--bg-main);
        font-family: 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif;
        color: var(--text-dark);
        font-size: 0.82rem;
        padding-bottom: 40px;
    }

    /* ==========================================================
       CARDS & HEADER SECTION
       ========================================================== */
    .card {
        border: none;
        border-radius: 12px;
        box-shadow: var(--shadow-md);
        background: #ffffff;
        overflow: hidden;
    }

    .header, .header-title {
        background: linear-gradient(135deg, var(--kh-green) 0%, #0d6337 100%);
        color: #ffffff;
        padding: 20px 15px;
        border-bottom: 4px solid var(--kh-orange);
        text-shadow: 0 1px 2px rgba(0,0,0,0.15);
    }

    .header h5, .header-title h5 {
        font-weight: 700;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .header small, .header-title small {
        color: rgba(255, 255, 255, 0.88);
        font-size: 0.85rem;
    }

    /* ==========================================================
       NAVIGATION TABS & BUTTONS
       ========================================================== */
    .page-tabs {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 20px;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 10px;
    }

    .page-tab {
        display: inline-flex;
        align-items: center;
        padding: 8px 18px;
        border: 1px solid var(--border-color);
        border-radius: 20px;
        color: var(--text-dark);
        background: #ffffff;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.82rem;
        transition: all 0.25s ease;
        box-shadow: var(--shadow-sm);
    }

    .page-tab:hover {
        background: var(--kh-green-light);
        color: var(--kh-green);
        border-color: var(--kh-green);
        transform: translateY(-1px);
    }

    .page-tab.active {
        color: #ffffff !important;
        background: var(--kh-green) !important;
        border-color: var(--kh-green) !important;
        box-shadow: 0 4px 10px rgba(15, 117, 65, 0.25);
    }

    /* Button Custom Styles */
    .btn-green, .btn-kh-hijau {
        background-color: var(--kh-green) !important;
        color: #ffffff !important;
        border: none !important;
        border-radius: 6px;
        padding: 6px 16px;
        transition: all 0.2s ease;
    }

    .btn-green:hover, .btn-kh-hijau:hover {
        background-color: var(--kh-green-hover) !important;
        box-shadow: 0 4px 10px rgba(15, 117, 65, 0.2);
    }

    .btn-kh-oranye {
        background-color: var(--kh-orange) !important;
        color: #ffffff !important;
        border: none !important;
        border-radius: 6px;
        padding: 6px 16px;
        transition: all 0.2s ease;
    }

    .btn-kh-oranye:hover {
        background-color: var(--kh-orange-hover) !important;
        box-shadow: 0 4px 10px rgba(230, 126, 34, 0.25);
    }

    /* Form Inputs Focus */
    .form-control:focus, .form-select:focus {
        border-color: var(--kh-green) !important;
        box-shadow: 0 0 0 0.2rem rgba(15, 117, 65, 0.2) !important;
    }

    /* ==========================================================
       TABLES STYLING
       ========================================================== */
    .table-wrap {
        overflow: auto;
        max-height: 70vh;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        box-shadow: var(--shadow-sm);
    }

    .table {
        margin-bottom: 0;
        font-size: 0.8rem;
    }

    .table th, .table td {
        vertical-align: middle;
        padding: 8px 10px;
    }

    /* Header Tabel */
    thead th {
        position: sticky;
        z-index: 2;
        text-align: center;
        font-weight: 600;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        font-size: 0.75rem;
    }

    thead tr:first-child th {
        top: 0;
        background: var(--kh-green) !important;
        color: #ffffff !important;
    }

    thead tr:nth-child(2) th {
        top: 31px;
        background: #0c5c33 !important;
        color: #ffffff !important;
    }

    /* Sticky Columns (No & Poliklinik) */
    thead th:first-child, tbody td:first-child {
        position: sticky;
        left: 0;
        z-index: 3;
    }

    tbody td:first-child {
        background: #ffffff;
    }

    /* Table Hover & Row States */
    tbody tr:hover td {
        background-color: var(--kh-orange-light) !important;
    }

    tfoot th {
        position: sticky;
        bottom: 0;
        background: #e8f3ec !important;
        color: var(--kh-green);
        z-index: 2;
        border-top: 2px solid var(--kh-orange) !important;
        font-weight: 700;
    }

    /* Special Text Badges inside Table */
    .diag-tambahan {
        color: var(--kh-orange);
        font-style: italic;
        font-size: 0.72rem;
        display: block;
        margin-top: 2px;
    }

    .single-line { white-space: nowrap; }
    .doctor-name { white-space: nowrap; min-width: 160px; font-weight: 600; }
    .text-kh-hijau { color: var(--kh-green) !important; }
    .text-kh-oranye { color: var(--kh-orange) !important; }

    /* DataTables Custom Pagination & Controls */
    .page-item.active .page-link {
        background-color: var(--kh-green) !important;
        border-color: var(--kh-green) !important;
    }

    .page-link {
        color: var(--kh-green);
        border-radius: 4px;
        margin: 0 2px;
    }

    .dt-buttons { margin-bottom: 12px; }

    /* ==========================================================
       REKAPITULASI CARDS
       ========================================================== */
    .rekap-box {
        background: #ffffff;
        border: 1px solid var(--border-color);
        padding: 14px;
        border-radius: 10px;
        height: 100%;
        border-top: 4px solid var(--kh-green);
        box-shadow: var(--shadow-sm);
        transition: transform 0.2s ease;
    }

    .rekap-box:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .rekap-total-box {
        background: linear-gradient(135deg, var(--kh-green) 0%, #0b5730 100%) !important;
        color: #ffffff;
        border-top: 4px solid var(--kh-orange) !important;
    }

    /* ==========================================================
       FOOTER BRANDING
       ========================================================== */
    .vidi-footer {
        text-align: center;
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 35px;
        border-top: 1px dashed var(--border-color);
        padding-top: 15px;
        letter-spacing: 0.5px;
    }

    .vidi-footer b {
        color: var(--kh-green);
    }
    </style>
</head>
<body>


<div class="container-fluid py-4">
    <?php
 if (isset($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
    <?php
 endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="header-title text-center">
            <h5 class="mb-0 fw-bold"><?= htmlspecialchars($nama_rs, ENT_QUOTES, 'UTF-8') ?></h5>
            <h5 class="mb-0 fw-bold">SENSUS POLIKLINIK</h5>
            <small class="fw-medium">Periode: <?= htmlspecialchars(date('d-m-Y', strtotime($tgl1))) ?> s/d <?= htmlspecialchars(date('d-m-Y', strtotime($tgl2))) ?></small>
        </div>

        <div class="card-body">
            <nav class="page-tabs" aria-label="Menu data kunjungan">
                <span class="page-tab active" aria-current="page">Sensus Poliklinik</span>
                <a class="page-tab" href="poli.php?view=lama_pelayanan">Lama Pelayanan Poliklinik</a>
                <a class="page-tab" href="poli.php?view=kunjungan">Rekap Kunjungan</a>
                <a class="page-tab" href="poli.php?view=kunjungan&amp;tab=bulanan">Grafik per Bulan</a>
            <a class="page-tab" href="poli.php?view=dokter">Dokter</a>
            </nav>

            <form method="GET" class="row g-2 mb-4 p-3 bg-white rounded border">
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Dari Tanggal</label>
                    <input type="date" name="tgl1" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl1) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small">Sampai Tanggal</label>
                    <input type="date" name="tgl2" class="form-control form-control-sm" value="<?= htmlspecialchars($tgl2) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Poliklinik</label>
                    <select name="kd_poli" class="form-select form-select-sm">
                        <option value="semua">Semua Poli</option>
                        <?php
 foreach($list_poli as $p): ?>
                            <option value="<?= $p['kd_poli'] ?>" <?= $kd_poli == $p['kd_poli'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nm_poli']) ?></option>
                        <?php
 endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Cara Bayar</label>
                    <select name="kd_pj" class="form-select form-select-sm">
                        <option value="semua">Semua Bayar</option>
                        <?php
 foreach($list_pj as $pj): ?>
                            <option value="<?= $pj['kd_pj'] ?>" <?= $kd_pj == $pj['kd_pj'] ? 'selected' : '' ?>><?= htmlspecialchars($pj['png_jawab']) ?></option>
                        <?php
 endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end mt-2">
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
                            <th>ALAMAT</th>
                            <th>DOKTER</th>
                            <th>POLIKLINIK</th>
                            <th>Diagnosa /ICD 10</th>
                            <th>Status Daftar</th>
                            <th>Status Poli</th>
                            <th>Status lanjut Rajal/Ranap</th>
                            <th>TINDAKAN DOKTER / PARAMEDIS</th>
                            <th>Evaluasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
 if (!empty($data)): ?>
                            <?php
 $no = 1; foreach ($data as $row): ?>
                            <tr>
                                <td class="text-center"><?= $no++ ?></td>
                                <td class="text-center"><?= date('d-m-Y', strtotime($row['tgl_registrasi'])) ?></td>
                                <td><?= htmlspecialchars($row['no_rawat']) ?></td>
                                <td class="fw-bold text-kh-hijau"><?= htmlspecialchars($row['no_rkm_medis']) ?></td>
                                <td><?= htmlspecialchars($row['no_ktp'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['nm_pasien']) ?></td>
                                <td><?= htmlspecialchars($row['png_jawab']) ?></td>
                                <td class="single-line" style="font-size: 0.65rem;"><?= htmlspecialchars($row['alamat']) ?></td>
                                <td class="doctor-name"><?= htmlspecialchars($row['nm_dokter']) ?></td>
                                <td><?= htmlspecialchars($row['nm_poli']) ?></td>
                                <td class="single-line">
                                    <?php
 if($row['kd_utama']): ?>
                                        <strong class="text-kh-hijau"><?= htmlspecialchars($row['kd_utama']) ?></strong> - <?= htmlspecialchars($row['nm_utama']) ?>
                                        <?php
 if($row['diagnosa_tambahan']): ?>
                                            <br><span class="diag-tambahan">Sekunder: <?= htmlspecialchars($row['diagnosa_tambahan']) ?></span>
                                        <?php
 endif; ?>
                                    <?php
 else: ?>
                                        -
                                    <?php
 endif; ?>
                                </td>
                                <td class="text-center"><?= htmlspecialchars($row['stts_daftar']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['status_poli']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($row['status_lanjut']) ?></td>
                                <td class="single-line text-center"><?php
                                    $tindakanPenangan = array_filter(array_merge(
                                        explode(', ', (string)($row['tindakan_dokter'] ?? '')),
                                        explode(', ', (string)($row['tindakan_paramedis'] ?? ''))
                                    ));
                                    $tindakanPenangan = array_values(array_unique($tindakanPenangan));
                                    echo htmlspecialchars($tindakanPenangan ? implode(', ', $tindakanPenangan) : '-');
                                ?></td>
                                <td class="single-line"><?= htmlspecialchars($row['evaluasi'] ?: '-') ?></td>
                            </tr>
                            <?php
 endforeach; ?>
                        <?php
 endif; ?>
                    </tbody>
                </table>
            </div>

            <h6 class="mt-4 fw-bold text-kh-hijau border-kh-hijau pb-2">REKAPITULASI SENSUS</h6>
            <div class="row g-3 row-cols-1 row-cols-md-2 row-cols-xl-5">
                <div class="col">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-hijau small d-block mb-1">Status Daftar</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php
 foreach($rekap_daftar as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php
 endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-oranye small d-block mb-1">Status Poli</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php
 foreach($rekap_poli as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php
 endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-hijau small d-block mb-1">Status Lanjut</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php
 foreach($rekap_lanjut as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php
 endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col">
                    <div class="rekap-box shadow-sm bg-white">
                        <span class="fw-bold text-kh-hijau small d-block mb-1">Jenis Bayar</span>
                        <table class="table table-sm table-borderless mb-0">
                            <?php foreach($rekap_bayar as $key => $val): ?>
                                <tr><td><?= htmlspecialchars($key) ?></td><td>: <strong><?= $val ?></strong></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <div class="col">
                    <div class="rekap-box rekap-total-box shadow-sm">
                        <span class="fw-bold text-light small d-block mb-1">Total Ringkasan</span>
                        <table class="table table-sm table-borderless text-white mb-0">
                            <tr><td>Laki-laki</td><td>: <?= $rekap_jk['L'] ?></td></tr>
                            <tr><td>Perempuan</td><td>: <?= $rekap_jk['P'] ?></td></tr>
                            <tr class="border-top">
                                <td class="fw-bold text-kh-oranye">Total Pasien</td>
                                <td>: <strong class="text-kh-oranye"><?= count($data) ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div style="text-align:center; font-size:11px; color:#555; margin-top:30px; border-top:1px solid #ddd; padding-top:10px">
    © 2026 <b>VIDI MEDIKA KONSULTAN IT</b>. SMART IT FOR HEALTH .
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
                title: 'SENSUS POLIKLINIK',
                messageTop: '<?= htmlspecialchars($nama_rs, ENT_QUOTES, 'UTF-8') ?>\nPeriode: <?= htmlspecialchars(date("d-m-Y", strtotime($tgl1))) ?> s/d <?= htmlspecialchars(date("d-m-Y", strtotime($tgl2))) ?>\n',
                messageBottom: '\n by: PT Vidi Medika Konsultan IT (Smart IT for Health)',
                filename: 'Sensus_Poliklinik_<?= htmlspecialchars($tgl1) ?>_sd_<?= htmlspecialchars($tgl2) ?>',
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

                    function setCell(rowIndex, colLetter, value, isHeader = false) {
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

                    // --- DATA REKAPITULASI GRID ---
                    setCell(startRow, 'A', 'REKAPITULASI SENSUS POLIKLINIK', true);
                    startRow += 2; 

                    var rekapDaftar = <?= json_encode($rekap_daftar) ?>;
                    var rekapPoli   = <?= json_encode($rekap_poli) ?>;
                    var rekapLanjut = <?= json_encode($rekap_lanjut) ?>;
                    var rekapBayar  = <?= json_encode($rekap_bayar) ?>;
                    var rekapJk     = <?= json_encode($rekap_jk) ?>;
                    var totalPasien = <?= count($data) ?>;

                    setCell(startRow, 'A', '[ Status Daftar ]', true);
                    setCell(startRow, 'D', '[ Status Poli ]', true);
                    setCell(startRow, 'G', '[ Status Lanjut ]', true);
                    setCell(startRow, 'J', '[ Jenis Bayar ]', true);
                    setCell(startRow, 'M', '[ Total Ringkasan ]', true);
                    
                    var pointerDaftar = startRow + 1;
                    var pointerPoli   = startRow + 1;
                    var pointerLanjut = startRow + 1;
                    var pointerBayar  = startRow + 1;
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

                    Object.keys(rekapBayar).forEach(function(key) {
                        setCell(pointerBayar, 'J', key);
                        setCell(pointerBayar, 'K', ': ' + rekapBayar[key]);
                        pointerBayar++;
                    });

                    setCell(pointerJk, 'M', 'Laki-laki'); setCell(pointerJk, 'N', ': ' + rekapJk['L']); pointerJk++;
                    setCell(pointerJk, 'M', 'Perempuan'); setCell(pointerJk, 'N', ': ' + rekapJk['P']); pointerJk++;
                    setCell(pointerJk, 'M', '-------------------------'); pointerJk++;
                    setCell(pointerJk, 'M', 'Total Pasien'); setCell(pointerJk, 'N', ': ' + totalPasien); pointerJk += 2;
                    
                    // Watermark Konsultan di baris akhir file Excel
                    setCell(pointerJk, 'M', 'IT Consultant: PT Vidi Medika Pratama', true);
                }
            }
        ],
        language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json" }
    });
});
</script>
</body>
</html>


