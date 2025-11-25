<?php
// Konfigurasi Database
$servername = "localhost";
$username = "root"; // Ganti dengan username MySQL Anda
$password = "";     // Ganti dengan password MySQL Anda
$dbname = "vape_store";

// Membuat koneksi
$conn = new mysqli($servername, $username, $password, $dbname);

// Memeriksa koneksi
if ($conn->connect_error) {
    // Memberi pesan error yang informatif tetapi tidak terlalu detail di production
    die("Koneksi gagal: " . $conn->connect_error);
}

// Opsional: Atur encoding karakter ke UTF-8
$conn->set_charset("utf8");
?>