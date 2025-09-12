<?php
require 'db.php';

header('Content-Type: application/json; charset=utf-8');

$province_id = isset($_GET['province_id']) ? intval($_GET['province_id']) : 0;

if ($province_id <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $province_id);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = ['id' => (int)$row['id'], 'name' => $row['name']];
}

$stmt->close();

echo json_encode($data);
