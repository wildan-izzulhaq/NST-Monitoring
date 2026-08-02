<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once "config.php";

$out = [];

// Cek struktur tabel nst_realtime
$r1 = $conn->query("DESCRIBE nst_realtime");
$out['nst_realtime_columns'] = [];
if ($r1) while ($row = $r1->fetch_assoc()) $out['nst_realtime_columns'][] = $row;
else $out['nst_realtime_columns_error'] = $conn->error;

// Cek apakah kolom session_id ada di nst_realtime
$r3 = $conn->query("SHOW COLUMNS FROM nst_realtime LIKE 'session_id'");
$out['realtime_has_session_id'] = ($r3 && $r3->num_rows > 0);

// Coba tambah kolom session_id jika belum ada
if (!$out['realtime_has_session_id']) {
    $addCol = $conn->query("ALTER TABLE nst_realtime ADD COLUMN session_id INT DEFAULT NULL");
    $out['add_session_id_result'] = $addCol ? 'berhasil' : $conn->error;
    $r4 = $conn->query("SHOW COLUMNS FROM nst_realtime LIKE 'session_id'");
    $out['realtime_has_session_id_after'] = ($r4 && $r4->num_rows > 0);
}

// Cek 3 baris nst_realtime dengan SELECT *
$r5 = $conn->query("SELECT * FROM nst_realtime LIMIT 3");
$out['realtime_sample'] = [];
if ($r5) while ($row = $r5->fetch_assoc()) $out['realtime_sample'][] = $row;
else $out['realtime_sample_error'] = $conn->error;

// Semua tabel
$r6 = $conn->query("SHOW TABLES");
$out['all_tables'] = [];
if ($r6) while ($row = $r6->fetch_row()) $out['all_tables'][] = $row[0];

echo json_encode($out, JSON_PRETTY_PRINT);
$conn->close();
?>
