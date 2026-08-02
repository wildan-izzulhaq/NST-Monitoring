<?php

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

define('MERGE_WINDOW_S', 3);

$conn->query("
    CREATE TABLE IF NOT EXISTS nst_buffer (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        patient_id  INT NOT NULL DEFAULT 0,
        sensor      ENUM('FHR','TOCO') NOT NULL,
        value       INT NOT NULL,
        bookmark    TINYINT(1) NOT NULL DEFAULT 0,
        ts          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        INDEX idx_patient_sensor_ts (patient_id, sensor, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS nst_live (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        patient_id  INT NOT NULL DEFAULT 0,
        bpm         SMALLINT UNSIGNED NOT NULL,
        toco        SMALLINT UNSIGNED NOT NULL,
        bookmark    TINYINT(1) NOT NULL DEFAULT 0,
        created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        INDEX idx_patient_time (patient_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$chk = $conn->query("SHOW COLUMNS FROM nst_realtime LIKE 'session_id'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE nst_realtime ADD COLUMN session_id INT DEFAULT NULL");
    $conn->query("ALTER TABLE nst_realtime ADD INDEX idx_session (session_id)");
}

$conn->query("
    CREATE TABLE IF NOT EXISTS nst_bookmark (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        patient_id  INT NOT NULL DEFAULT 0,
        session_id  INT DEFAULT NULL,
        created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        INDEX idx_patient (patient_id),
        INDEX idx_session (session_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function getActiveSessionId($conn) {
    $res = $conn->query("SELECT v FROM nst_setting WHERE k = 'active_session_id'");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || !$row['v']) return null;
    $sid = intval($row['v']);
    $r2 = $conn->query("SELECT id FROM nst_session WHERE id=$sid AND status='recording' LIMIT 1");
    return ($r2 && $r2->num_rows > 0) ? $sid : null;
}

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(["error" => "JSON tidak valid"]); exit; }

$patient_id = intval($data['patient_id'] ?? 0);
if ($patient_id <= 0) {
    $res_pid = $conn->query("SELECT v FROM nst_setting WHERE k='active_patient_id'");
    $row_pid = $res_pid ? $res_pid->fetch_assoc() : null;
    $patient_id = ($row_pid && $row_pid['v']) ? intval($row_pid['v']) : 0;
}
$bookmark = intval($data['bookmark'] ?? 0);
if ($bookmark !== 0 && $bookmark !== 1) $bookmark = 0;

$hasBpm  = isset($data['bpm'])  && is_numeric($data['bpm']);
$hasToco = isset($data['toco']) && is_numeric($data['toco']);
$sensor  = strtoupper(trim($data['sensor'] ?? ''));

if (!$hasBpm && !$hasToco) {
    http_response_code(400);
    echo json_encode(["error" => "Data tidak lengkap. Kirim minimal: bpm atau toco"]);
    exit;
}

function simpanData($conn, $patient_id, $bpm, $toco, $bookmark) {
    $stmt_live = $conn->prepare(
        "INSERT INTO nst_live (patient_id, bpm, toco, bookmark) VALUES (?, ?, ?, ?)"
    );
    $stmt_live->bind_param("iiii", $patient_id, $bpm, $toco, $bookmark);
    $stmt_live->execute();
    $stmt_live->close();

    if ($bookmark === 1) {
        $session_id = getActiveSessionId($conn);
        $stmt_bm = $conn->prepare(
            "INSERT INTO nst_bookmark (patient_id, session_id) VALUES (?, ?)"
        );
        $stmt_bm->bind_param("ii", $patient_id, $session_id);
        $stmt_bm->execute();
        $stmt_bm->close();
    }

    $conn->query(
        "DELETE FROM nst_live WHERE patient_id=$patient_id AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM nst_live WHERE patient_id=$patient_id ORDER BY id DESC LIMIT 200
            ) t
        )"
    );

    $session_id = getActiveSessionId($conn);
    $recorded   = false;

    if ($session_id !== null) {
        $stmt_rec = $conn->prepare(
            "INSERT INTO nst_realtime (patient_id, bpm, toco, bookmark, session_id) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt_rec->bind_param("iiiii", $patient_id, $bpm, $toco, $bookmark, $session_id);
        $stmt_rec->execute();
        $stmt_rec->close();
        $recorded = true;
    }

    return ["recorded" => $recorded, "session_id" => $session_id];
}

if ($hasBpm && $hasToco) {
    $bpm  = intval($data['bpm']);
    $toco = intval($data['toco']);
    if ($bpm < 0 || $bpm > 250)  { http_response_code(400); echo json_encode(["error" => "BPM tidak valid"]); exit; }
    if ($toco < 0 || $toco > 200) { http_response_code(400); echo json_encode(["error" => "TOCO tidak valid"]); exit; }

    $result = simpanData($conn, $patient_id, $bpm, $toco, $bookmark);
    echo json_encode(array_merge(["status"=>"ok","mode"=>"single_esp","bpm"=>$bpm,"toco"=>$toco], $result));
    $conn->close(); exit;
}

if ($hasBpm) {
    $sensorType = "FHR";
    $value = intval($data['bpm']);
    if ($value < 0 || $value > 250) { http_response_code(400); echo json_encode(["error" => "BPM tidak valid"]); exit; }
} else {
    $sensorType = "TOCO";
    $value = intval($data['toco']);
    if ($value < 0 || $value > 200) { http_response_code(400); echo json_encode(["error" => "TOCO tidak valid"]); exit; }
}
if ($sensor === "FHR" || $sensor === "TOCO") $sensorType = $sensor;

$lastLive = $conn->query("SELECT bpm, toco, bookmark FROM nst_live ORDER BY id DESC LIMIT 1");
$prevLive = $lastLive ? $lastLive->fetch_assoc() : null;

if ($sensorType === "FHR") {
    $live_bpm  = $value;
    $live_toco = $prevLive ? intval($prevLive['toco']) : 0;
    $live_bookmark = $bookmark;
} else {
    $live_bpm  = $prevLive ? intval($prevLive['bpm']) : 0;
    $live_toco = $value;
    $live_bookmark = $bookmark;
}

$stmt_live = $conn->prepare("INSERT INTO nst_live (patient_id, bpm, toco, bookmark) VALUES (?, ?, ?, ?)");
$stmt_live->bind_param("iiii", $patient_id, $live_bpm, $live_toco, $live_bookmark);
$stmt_live->execute();
$stmt_live->close();

if ($live_bookmark === 1) {
    $bm_session_id = getActiveSessionId($conn);
    $stmt_bm = $conn->prepare("INSERT INTO nst_bookmark (patient_id, session_id) VALUES (?, ?)");
    $stmt_bm->bind_param("ii", $patient_id, $bm_session_id);
    $stmt_bm->execute();
    $stmt_bm->close();
}

$conn->query(
    "DELETE FROM nst_live WHERE patient_id=$patient_id AND id NOT IN (
        SELECT id FROM (
            SELECT id FROM nst_live WHERE patient_id=$patient_id ORDER BY id DESC LIMIT 200
        ) t
    )"
);

$session_id = getActiveSessionId($conn);
$recorded   = false;

if ($session_id !== null) {
    $stmt_rec = $conn->prepare(
        "INSERT INTO nst_realtime (patient_id, bpm, toco, bookmark, session_id) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt_rec->bind_param("iiiii", $patient_id, $live_bpm, $live_toco, $live_bookmark, $session_id);
    $stmt_rec->execute();
    $stmt_rec->close();
    $recorded = true;
}

$conn->query("DELETE FROM nst_buffer WHERE ts < NOW(3) - INTERVAL 10 SECOND");

echo json_encode([
    "status"     => "ok",
    "mode"       => "dual_esp_direct",
    "sensor"     => $sensorType,
    "bpm"        => $live_bpm,
    "toco"       => $live_toco,
    "bookmark"   => $live_bookmark,
    "recorded"   => $recorded,
    "session_id" => $session_id,
]);

$conn->close();
?>
