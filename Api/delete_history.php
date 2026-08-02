<?php
// ================================================================
//  delete_history.php — Hapus riwayat sesi perekaman
//
//  POST body JSON:
//    { "session_id": 5, "patient_id": 1 }
//
//  Yang dihapus:
//  - Data di nst_realtime dengan session_id tersebut
//  - Data di nst_bookmark dengan session_id tersebut
//  - Baris di nst_session dengan id tersebut
//
//  nst_live tidak dihapus (data live berjalan terus)
// ================================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method tidak diizinkan"]);
    exit;
}

require_once "config.php";

$body       = json_decode(file_get_contents("php://input"), true);
$session_id = intval($body['session_id'] ?? 0);
$patient_id = intval($body['patient_id'] ?? 0);

if ($session_id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "session_id tidak valid"]);
    exit;
}

// Verifikasi sesi milik pasien yang benar (keamanan)
if ($patient_id > 0) {
    $check = $conn->query("SELECT id FROM nst_session WHERE id=$session_id AND patient_id=$patient_id LIMIT 1");
    if (!$check || $check->num_rows === 0) {
        http_response_code(403);
        echo json_encode(["error" => "Sesi tidak ditemukan atau bukan milik pasien ini"]);
        exit;
    }
}

// Pastikan sesi tidak sedang aktif (recording)
$checkActive = $conn->query("SELECT id FROM nst_session WHERE id=$session_id AND status='recording' LIMIT 1");
if ($checkActive && $checkActive->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["error" => "Tidak bisa menghapus sesi yang sedang aktif. Stop dulu sebelum menghapus."]);
    exit;
}

// Hapus data di nst_realtime
$conn->query("DELETE FROM nst_realtime WHERE session_id=$session_id");
$deleted_realtime = $conn->affected_rows;

// Hapus data di nst_bookmark
$conn->query("DELETE FROM nst_bookmark WHERE session_id=$session_id");
$deleted_bookmark = $conn->affected_rows;

// Hapus sesi di nst_session
$conn->query("DELETE FROM nst_session WHERE id=$session_id");
$deleted_session = $conn->affected_rows;

echo json_encode([
    "status"           => "ok",
    "session_id"       => $session_id,
    "deleted_realtime" => $deleted_realtime,
    "deleted_bookmark" => $deleted_bookmark,
    "deleted_session"  => $deleted_session,
]);

$conn->close();
?>
