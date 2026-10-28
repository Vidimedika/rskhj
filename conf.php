<?php
// Konfigurasi koneksi database
$db_hostname = "localhost";
$db_username = "root";
$db_password = "";      // isi dengan password MySQL Anda
$db_name     = "sik"; // isi dengan nama database Anda

// buka koneksi sekali
$konektor = mysqli_connect($db_hostname, $db_username, $db_password, $db_name);
if(!$konektor){
    die("<font color=red><b>Koneksi gagal:</b></font> ".mysqli_connect_error());
}

// fungsi query SELECT
function bukaquery2($sql){
    global $konektor;
    $result = mysqli_query($konektor, $sql);
    if(!$result){
        die("<font color=red><b>Query error:</b></font> ".mysqli_error($konektor));
    }
    return $result;
}

// fungsi sanitasi input sederhana
function cleankar2($dirty){
    global $konektor;
    return mysqli_real_escape_string($konektor, $dirty);
}
?>

