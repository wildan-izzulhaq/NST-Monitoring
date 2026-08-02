<?php
// ================================================================
//  history_data.php — Revisi 3: FULL DATA (tanpa downsample)
//
//  GET ?session_id=5        → data grafik sesi tertentu (recommended)
//  GET ?date=2025-03-01     → data grafik by tanggal (backward-compat)
//
//  Mengembalikan SEMUA titik mentah tanpa downsample, agar grafik
//  & PDF dari halaman Riwayat 100% identik dengan dari Monitor Live.
// ================================================================

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache");

require_once "config.php";

$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$date       = $_GET['date'] ?? '';

if ($session_id > 0) {
    // Query by session_id (lebih akurat) — intval sudah aman dari injection
    $result = $conn->query("
        SELECT bpm, toco, bookmark
        FROM nst_realtime
        WHERE session_id = $session_id
        ORDER BY id ASC
    ");
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    // Backward-compat: query by tanggal
    $date_safe = $conn->real_escape_string($date);
    $result = $conn->query("
        SELECT bpm, toco, bookmark
        FROM nst_realtime
        WHERE DATE(created_at) = '$date_safe' AND session_id IS NOT NULL
        ORDER BY id ASC
    ");
} else {
    http_response_code(400);
    echo json_encode(["error" => "Kirim ?session_id=N atau ?date=YYYY-MM-DD"]);
    exit;
}

if (!$result) {
    http_response_code(500);
    echo json_encode(["error" => "DB query failed: " . $conn->error]);
    exit;
}

$bpm_data   = [];
$toco_data  = [];
$bm_indexes = [];
$idx        = 0;

while ($row = $result->fetch_assoc()) {
    $bpm_data[]  = intval($row['bpm']);
    $toco_data[] = intval($row['toco']);
    if (intval($row['bookmark']) === 1) $bm_indexes[] = $idx;
    $idx++;
}

$total = count($bpm_data);

// ── Tidak ada downsample — kirim semua titik apa adanya ──
echo json_encode([
    "bpm_data"    => $bpm_data,
    "toco_data"   => $toco_data,
    "bm_indexes"  => $bm_indexes,
    "total_raw"   => $total,
    "downsampled" => false,
]);

$conn->close();
?>
