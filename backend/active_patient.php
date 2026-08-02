<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once "config.php";

$conn->query("
    CREATE TABLE IF NOT EXISTS nst_setting (
        k   VARCHAR(50) PRIMARY KEY,
        v   VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $conn->query("SELECT v FROM nst_setting WHERE k = 'active_patient_id'");
    $row = $res ? $res->fetch_assoc() : null;

    if (!$row || !$row['v']) {
        echo json_encode(null);
        exit;
    }

    $pid  = intval($row['v']);
    $stmt = $conn->prepare("SELECT id, nama FROM pasien WHERE id = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $stmt->store_result();
    $id_val = null; $nama_val = null;
    $stmt->bind_result($id_val, $nama_val);
    $pasien = $stmt->fetch() ? ["id" => $id_val, "nama" => $nama_val] : null;

    if (!$pasien) {
        echo json_encode(null);
        exit;
    }

    echo json_encode([
        "patient_id" => intval($pasien['id']),
        "nama"       => $pasien['nama']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents("php://input"), true);
    $pid  = intval($body['patient_id'] ?? 0);

    if ($pid <= 0) {
        // patient_id 0 = reset (tidak ada pasien aktif)
        $conn->query("DELETE FROM nst_setting WHERE k = 'active_patient_id'");
        echo json_encode(["status" => "ok", "patient_id" => 0]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, nama FROM pasien WHERE id = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $stmt->store_result();
    $id_val = null; $nama_val = null;
    $stmt->bind_result($id_val, $nama_val);
    $pasien = $stmt->fetch() ? ["id" => $id_val, "nama" => $nama_val] : null;

    if (!$pasien) {
        http_response_code(404);
        echo json_encode(["error" => "Pasien tidak ditemukan"]);
        exit;
    }

    $stmt2 = $conn->prepare("
        INSERT INTO nst_setting (k, v) VALUES ('active_patient_id', ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()
    ");
    $pid_str = (string)$pid;
    $stmt2->bind_param("s", $pid_str);
    $stmt2->execute();

    echo json_encode([
        "status"     => "ok",
        "patient_id" => intval($pasien['id']),
        "nama"       => $pasien['nama']
    ]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method tidak diizinkan"]);
$conn->close();
?>
