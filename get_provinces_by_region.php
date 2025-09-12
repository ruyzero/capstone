<?php
require 'db.php';

if (!isset($_GET['region_id']) || !is_numeric($_GET['region_id'])) {
    echo json_encode([]);
    exit();
}

$region_id = intval($_GET['region_id']);

$stmt = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ?");
$stmt->bind_param("i", $region_id);
$stmt->execute();
$result = $stmt->get_result();

$provinces = [];
while ($row = $result->fetch_assoc()) {
    $provinces[] = $row;
}

echo json_encode($provinces);
?>