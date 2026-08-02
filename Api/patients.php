<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once "config.php";

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? intval($_GET['id']) : null;

if ($method === 'GET' && !$id) {
    $result = $conn->query("
        SELECT
            p.id,
            p.nama,
            p.tanggal_lahir,
            p.no_rekam_medis,
            p.usia_kehamilan,
            p.nama_suami,
            p.no_telp,
            p.catatan,
            p.created_at,
            COUNT(DISTINCT DATE(n.created_at)) AS jumlah_sesi,
            MAX(n.created_at)                  AS pemeriksaan_terakhir
        FROM pasien p
        LEFT JOIN nst_realtime n ON n.patient_id = p.id
        GROUP BY p.id
        ORDER BY p.nama ASC
    ");

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $row['id']           = intval($row['id']);
        $row['jumlah_sesi']  = intval($row['jumlah_sesi']);
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}

if ($method === 'GET' && $id) {
    $stmt = $conn->prepare("SELECT id, nama, tanggal_lahir, no_rekam_medis, usia_kehamilan, nama_suami, no_telp, catatan, created_at FROM pasien WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->store_result();
    $f_id=$f_nama=$f_tl=$f_rm=$f_uk=$f_suami=$f_telp=$f_cat=$f_ca = null;
    $stmt->bind_result($f_id,$f_nama,$f_tl,$f_rm,$f_uk,$f_suami,$f_telp,$f_cat,$f_ca);
    $pasien = $stmt->fetch() ? ["id"=>$f_id,"nama"=>$f_nama,"tanggal_lahir"=>$f_tl,
        "no_rekam_medis"=>$f_rm,"usia_kehamilan"=>$f_uk,"nama_suami"=>$f_suami,
        "no_telp"=>$f_telp,"catatan"=>$f_cat,"created_at"=>$f_ca] : null;

    if (!$pasien) {
        http_response_code(404);
        echo json_encode(["error" => "Pasien tidak ditemukan"]);
        exit;
    }

    $stmt2 = $conn->prepare("
        SELECT
            DATE(created_at)                    AS tanggal,
            DATE_FORMAT(MIN(created_at),'%H:%i') AS jam,
            ROUND(AVG(bpm))                      AS avg_bpm,
            ROUND(AVG(toco))                     AS avg_toco,
            SUM(bookmark)                        AS bm_count,
            COUNT(*)                             AS total_data,
            CASE WHEN AVG(bpm) BETWEEN 110 AND 160 THEN 'Normal' ELSE 'Indikasi' END AS status_bpm,
            CASE WHEN AVG(toco) <= 50             THEN 'Normal' ELSE 'Indikasi' END AS status_toco
        FROM nst_realtime
        WHERE patient_id = ?
        GROUP BY DATE(created_at)
        ORDER BY tanggal DESC
        LIMIT 30
    ");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->store_result();
    $r_tgl=$r_jam=$r_abpm=$r_atoco=$r_bmc=$r_total=$r_sbpm=$r_stoco = null;
    $stmt2->bind_result($r_tgl,$r_jam,$r_abpm,$r_atoco,$r_bmc,$r_total,$r_sbpm,$r_stoco);

    $riwayat = [];
    while ($stmt2->fetch()) {
        $row = ["tanggal"=>$r_tgl,"jam"=>$r_jam,"avg_bpm"=>$r_abpm,"avg_toco"=>$r_atoco,
                "bm_count"=>$r_bmc,"total_data"=>$r_total,"status_bpm"=>$r_sbpm,"status_toco"=>$r_stoco];
        $detik          = intval($row['total_data']) * 0.5;
        $row['durasi']  = round($detik / 60) . " mnt";
        $row['avg_bpm'] = intval($row['avg_bpm']);
        $row['avg_toco']= intval($row['avg_toco']);
        $row['bm_count']= intval($row['bm_count']);
        unset($row['total_data']);
        $riwayat[] = $row;
    }

    $pasien['id']     = intval($pasien['id']);
    $pasien['riwayat']= $riwayat;
    echo json_encode($pasien);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents("php://input"), true);

    $nama    = trim($body['nama']    ?? '');
    $tgl_lhr = trim($body['tanggal_lahir'] ?? '');

    if (!$nama || !$tgl_lhr) {
        http_response_code(400);
        echo json_encode(["error" => "Nama dan tanggal lahir wajib diisi"]);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_lhr)) {
        http_response_code(400);
        echo json_encode(["error" => "Format tanggal lahir: YYYY-MM-DD"]);
        exit;
    }

    $no_rm   = $conn->real_escape_string(trim($body['no_rekam_medis'] ?? '')) ?: null;
    $usia_k  = isset($body['usia_kehamilan']) && $body['usia_kehamilan'] !== '' ? intval($body['usia_kehamilan']) : null;
    $suami   = $conn->real_escape_string(trim($body['nama_suami'] ?? '')) ?: null;
    $telp    = $conn->real_escape_string(trim($body['no_telp']    ?? '')) ?: null;
    $catatan = $conn->real_escape_string(trim($body['catatan']    ?? '')) ?: null;

    $stmt = $conn->prepare("
        INSERT INTO pasien (nama, tanggal_lahir, no_rekam_medis, usia_kehamilan, nama_suami, no_telp, catatan)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssisss", $nama, $tgl_lhr, $no_rm, $usia_k, $suami, $telp, $catatan);

    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        echo json_encode(["status" => "ok", "id" => $new_id, "nama" => $nama]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Gagal menyimpan: " . $stmt->error]);
    }
    exit;
}

if ($method === 'PUT' && $id) {
    $body = json_decode(file_get_contents("php://input"), true);

    $fields = [];
    $types  = "";
    $vals   = [];

    if (isset($body['nama']))            { $fields[] = "nama = ?";            $types .= "s"; $vals[] = trim($body['nama']); }
    if (isset($body['tanggal_lahir']))   { $fields[] = "tanggal_lahir = ?";   $types .= "s"; $vals[] = $body['tanggal_lahir']; }
    if (isset($body['no_rekam_medis']))  { $fields[] = "no_rekam_medis = ?";  $types .= "s"; $vals[] = $body['no_rekam_medis']; }
    if (isset($body['usia_kehamilan']))  { $fields[] = "usia_kehamilan = ?";  $types .= "i"; $vals[] = intval($body['usia_kehamilan']); }
    if (isset($body['nama_suami']))      { $fields[] = "nama_suami = ?";      $types .= "s"; $vals[] = $body['nama_suami']; }
    if (isset($body['no_telp']))         { $fields[] = "no_telp = ?";         $types .= "s"; $vals[] = $body['no_telp']; }
    if (isset($body['catatan']))         { $fields[] = "catatan = ?";         $types .= "s"; $vals[] = $body['catatan']; }

    if (!$fields) { http_response_code(400); echo json_encode(["error" => "Tidak ada data yang diubah"]); exit; }

    $types .= "i";
    $vals[] = $id;
    $sql    = "UPDATE pasien SET " . implode(", ", $fields) . " WHERE id = ?";
    $stmt   = $conn->prepare($sql);
    $stmt->bind_param($types, ...$vals);

    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "id" => $id]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => $stmt->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method tidak diizinkan"]);
$conn->close();
?>
