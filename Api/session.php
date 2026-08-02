<?php
// ================================================================
//  session.php — Manajemen Sesi Recording NST
//
//  POST body JSON:
//    { "action": "start",  "patient_id": 3 }  → mulai rekam
//    { "action": "stop",   "patient_id": 3 }  → stop rekam
//    { "action": "reset",  "patient_id": 3 }  → reset (stop + clear active)
//
//  GET → ambil status sesi aktif saat ini
//    Response: {
//      "recording": true/false,
//      "session_id": 12,          // null jika tidak ada
//      "patient_id": 3,
//      "started_at": "2025-01-01 10:00:00"
//    }
//
//  Logika:
//  - START  : buat baris baru di nst_session, set status='recording'
//             update nst_setting key='active_session_id'
//  - STOP   : update nst_session set status='done', ended_at=NOW()
//             hapus nst_setting key='active_session_id'
//  - RESET  : sama seperti STOP (data tetap tersimpan di DB)
//             + reset nst_setting active_patient_id jika perlu
// ================================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once "config.php";

// ── Auto-create tabel nst_session ────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS nst_session (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        patient_id  INT NOT NULL DEFAULT 0,
        status      ENUM('recording','done','cancelled') NOT NULL DEFAULT 'recording',
        started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at    DATETIME DEFAULT NULL,
        note        VARCHAR(255) DEFAULT NULL,
        INDEX idx_patient (patient_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Pastikan tabel nst_setting ada
$conn->query("
    CREATE TABLE IF NOT EXISTS nst_setting (
        k   VARCHAR(50) PRIMARY KEY,
        v   VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Tambah kolom session_id di nst_realtime jika belum ada
$chk2 = $conn->query("SHOW COLUMNS FROM nst_realtime LIKE 'session_id'");
if ($chk2 && $chk2->num_rows === 0) {
    $conn->query("ALTER TABLE nst_realtime ADD COLUMN session_id INT DEFAULT NULL");
    $conn->query("ALTER TABLE nst_realtime ADD INDEX idx_session (session_id)");
}

// ── Helper: ambil sesi aktif ──────────────────────────────────────
function getActiveSession($conn) {
    $res = $conn->query("SELECT v FROM nst_setting WHERE k = 'active_session_id'");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || !$row['v']) return null;

    $sid = intval($row['v']);
    $stmt = $conn->prepare("SELECT id, patient_id, status, started_at FROM nst_session WHERE id = ? AND status = 'recording'");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $stmt->store_result();
    $s_id = null; $s_pid = null; $s_status = null; $s_started = null;
    $stmt->bind_result($s_id, $s_pid, $s_status, $s_started);
    return $stmt->fetch()
        ? ["id"=>$s_id,"patient_id"=>$s_pid,"status"=>$s_status,"started_at"=>$s_started]
        : null;
}

// ── GET: status sesi aktif ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sess = getActiveSession($conn);
    if ($sess) {
        echo json_encode([
            "recording"  => true,
            "session_id" => intval($sess['id']),
            "patient_id" => intval($sess['patient_id']),
            "started_at" => $sess['started_at'],
        ]);
    } else {
        echo json_encode(["recording" => false, "session_id" => null]);
    }
    exit;
}

// ── POST: aksi dari ESP32 ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents("php://input"), true);
    $action = strtolower(trim($body['action'] ?? ''));

    if (!in_array($action, ['start','stop','reset'])) {
        http_response_code(400);
        echo json_encode(["error" => "Action tidak valid. Gunakan: start, stop, reset"]);
        exit;
    }

    // Ambil patient_id: dari body jika ada, atau dari active_patient_id di DB
    // Alat tidak perlu tahu patient_id — website yang set saat pilih pasien
    $pid = intval($body['patient_id'] ?? 0);
    if ($pid <= 0) {
        $res_p = $conn->query("SELECT v FROM nst_setting WHERE k='active_patient_id'");
        $row_p = $res_p ? $res_p->fetch_assoc() : null;
        $pid   = ($row_p && $row_p['v']) ? intval($row_p['v']) : 0;
    }

    // ── START ─────────────────────────────────────────────────────
    if ($action === 'start') {
        // Jika sudah ada sesi recording, jangan buat lagi
        $existing = getActiveSession($conn);
        if ($existing) {
            echo json_encode([
                "status"     => "already_recording",
                "session_id" => intval($existing['id']),
                "started_at" => $existing['started_at'],
            ]);
            exit;
        }

        // Buat sesi baru
        $stmt = $conn->prepare("INSERT INTO nst_session (patient_id, status) VALUES (?, 'recording')");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $newSid = $conn->insert_id;

        // Simpan ke setting
        $sid_str = (string)$newSid;
        $stmt2 = $conn->prepare("
            INSERT INTO nst_setting (k, v) VALUES ('active_session_id', ?)
            ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()
        ");
        $stmt2->bind_param("s", $sid_str);
        $stmt2->execute();

        echo json_encode([
            "status"     => "ok",
            "action"     => "start",
            "session_id" => $newSid,
            "patient_id" => $pid,
        ]);
        exit;
    }

    // ── STOP ──────────────────────────────────────────────────────
    if ($action === 'stop') {
        $existing = getActiveSession($conn);
        if (!$existing) {
            echo json_encode(["status" => "no_active_session", "action" => "stop"]);
            exit;
        }

        $sid = intval($existing['id']);
        $conn->query("UPDATE nst_session SET status='done', ended_at=NOW() WHERE id=$sid");
        $conn->query("DELETE FROM nst_setting WHERE k='active_session_id'");

        echo json_encode([
            "status"     => "ok",
            "action"     => "stop",
            "session_id" => $sid,
        ]);
        exit;
    }

    // ── RESET ─────────────────────────────────────────────────────
    if ($action === 'reset') {
        $existing = getActiveSession($conn);
        if ($existing) {
            $sid = intval($existing['id']);
            // Tandai sesi sebagai done (data tetap tersimpan)
            $conn->query("UPDATE nst_session SET status='done', ended_at=NOW() WHERE id=$sid");
        }
        // Hapus sesi aktif dari setting
        $conn->query("DELETE FROM nst_setting WHERE k='active_session_id'");

        echo json_encode([
            "status" => "ok",
            "action" => "reset",
            "info"   => "Sesi dihentikan. Data tetap tersimpan di Riwayat.",
        ]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["error" => "Method tidak diizinkan"]);
$conn->close();
?>
