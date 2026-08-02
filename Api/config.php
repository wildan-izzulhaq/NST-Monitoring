<?php

define('DB_HOST', 'localhost');
define('DB_USER', '');
define('DB_PASS', ''); 
define('DB_NAME', '');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(["error" => "Koneksi database gagal: " . $conn->connect_error]));
}

$conn->set_charset("utf8");
?>
