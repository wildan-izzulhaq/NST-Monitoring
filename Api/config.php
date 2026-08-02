<?php
// ================================================================
//  config.php — Konfigurasi Database
//  EDIT BAGIAN INI sesuai data dari cPanel Domainesia kamu
// ================================================================

define('DB_HOST', 'localhost');       // Jangan diubah (biasanya localhost)
define('DB_USER', 'diagtemx_nst');     // << GANTI: username database dari cPanel
define('DB_PASS', 'M+XD$hxrX~OqYlf*'); // << GANTI: password database dari cPanel
define('DB_NAME', 'diagtemx_nst');     // << GANTI: nama database dari cPanel

// ================================================================
//  Jangan ubah kode di bawah ini
// ================================================================

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(["error" => "Koneksi database gagal: " . $conn->connect_error]));
}

$conn->set_charset("utf8");
?>
