<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache");

require_once "config.php";

$liveResult = null;
$rLive = $conn->query("SELECT id, bpm, toco, bookmark, created_at FROM nst_live ORDER BY id DESC LIMIT 1");
if ($rLive && $rLive->num_rows > 0) {
    $liveResult = $rLive->fetch_assoc();
}

if (!$liveResult) {
    $rFallback = $conn->query("SELECT id, bpm, toco, bookmark, created_at FROM nst_realtime ORDER BY id DESC LIMIT 1");
    if ($rFallback && $rFallback->num_rows > 0) {
        $liveResult = $rFallback->fetch_assoc();
    }
}

$fhr_online  = false;
$toco_online = false;

if ($liveResult) {
    $created = $liveResult['created_at'];
    $rFhr = $conn->query("
        SELECT id FROM nst_live
        WHERE created_at >= NOW() - INTERVAL 10 SECOND
        ORDER BY id DESC LIMIT 1
    ");
    if ($rFhr && $rFhr->num_rows > 0) {
        $fhr_online  = true;
        $toco_online = true;
    }
}

$recording      = false;
$session_id     = null;
$session_started = null;

$rSetting = $conn->query("SELECT v FROM nst_setting WHERE k='active_session_id'");
$rowSetting = $rSetting ? $rSetting->fetch_assoc() : null;
if ($rowSetting && $rowSetting['v']) {
    $sid = intval($rowSetting['v']);
    $rSess = $conn->query("
        SELECT id, started_at FROM nst_session
        WHERE id=$sid AND status='recording'
        LIMIT 1
    ");
    if ($rSess && $rSess->num_rows > 0) {
        $sessRow        = $rSess->fetch_assoc();
        $recording      = true;
        $session_id     = intval($sessRow['id']);
        $session_started = $sessRow['started_at'];
    }
}

$recent_bookmark = 0;
$rBm = $conn->query("SELECT id FROM nst_bookmark ORDER BY id DESC LIMIT 1");
if ($rBm && $rBm->num_rows > 0) {
    $bmRow = $rBm->fetch_assoc();
    $recent_bookmark = intval($bmRow['id']);
}

if (!$liveResult) {
    echo json_encode([
        "live_id"             => 0,
        "bpm"                 => 0,
        "toco"                => 0,
        "bookmark"            => 0,
        "recent_bookmark_id"  => $recent_bookmark,
        "fhr_online"          => false,
        "toco_online"         => false,
        "recording"           => $recording,
        "session_id"          => $session_id,
        "session_started_at"  => $session_started,
        "created_at"          => null,
    ]);
} else {
    echo json_encode([
        "live_id"             => intval($liveResult['id']),
        "bpm"                 => intval($liveResult['bpm']),
        "toco"                => intval($liveResult['toco']),
        "bookmark"            => intval($liveResult['bookmark']),
        "recent_bookmark_id"  => $recent_bookmark,
        "fhr_online"          => $fhr_online,
        "toco_online"         => $toco_online,
        "recording"           => $recording,
        "session_id"          => $session_id,
        "session_started_at"  => $session_started,
        "created_at"          => $liveResult['created_at'],
    ]);
}

$conn->close();
?>
