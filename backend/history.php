<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache");

require_once "config.php";

$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;

$chk = $conn->query("SHOW COLUMNS FROM nst_realtime LIKE 'session_id'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE nst_realtime ADD COLUMN session_id INT DEFAULT NULL");
}

$wherePatient = $patient_id > 0 ? "WHERE r.patient_id = $patient_id" : "";

$result = $conn->query("
    SELECT
        COALESCE(r.session_id, 0)             AS session_id,
        DATE(r.created_at)                     AS tanggal,
        DATE_FORMAT(MIN(r.created_at), '%H:%i') AS jam,
        DATE_FORMAT(MAX(r.created_at), '%H:%i') AS jam_selesai,
        ROUND(AVG(r.bpm))                      AS avg_bpm,
        ROUND(AVG(r.toco))                     AS avg_toco,
        SUM(r.bookmark)                        AS bm_count,
        COUNT(*)                               AS total_data,
        s.status                               AS session_status,
        CASE WHEN AVG(r.bpm) BETWEEN 110 AND 160 THEN 'Normal' ELSE 'Indikasi' END AS status_bpm,
        CASE WHEN AVG(r.toco) <= 50            THEN 'Normal' ELSE 'Indikasi' END AS status_toco
    FROM nst_realtime r
    LEFT JOIN nst_session s ON s.id = r.session_id
    $wherePatient
    AND r.session_id IS NOT NULL
    GROUP BY r.session_id, DATE(r.created_at)
    ORDER BY MIN(r.created_at) DESC
    LIMIT 30
");

$sessions = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $total = intval($row['total_data']);
        $row['durasi']    = round($total * 0.5 / 60) . " mnt";
        $row['avg_bpm']   = intval($row['avg_bpm']);
        $row['avg_toco']  = intval($row['avg_toco']);
        $row['bm_count']  = intval($row['bm_count']);
        $row['session_id']= intval($row['session_id']);
        unset($row['total_data']);
        $sessions[] = $row;
    }
}

echo json_encode($sessions);
$conn->close();
?>
