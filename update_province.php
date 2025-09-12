<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $province_id = $_POST['province_id'];
    $province_name = $_POST['province_name'];
    $region_id = $_POST['region_id'];

    $stmt = $conn->prepare("UPDATE provinces SET name = ?, region_id = ? WHERE id = ?");
    $stmt->bind_param("sii", $province_name, $region_id, $province_id);

    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
}
?>
