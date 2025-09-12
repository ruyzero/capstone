<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $municipality_id = $_POST['municipality_id'];
    $municipality_name = $_POST['municipality_name'];
    $province_id = $_POST['province_id'];
    $region_id = $_POST['region_id'];

    $stmt = $conn->prepare("UPDATE municipalities SET name = ?, province_id = ?, region_id = ? WHERE id = ?");
    $stmt->bind_param("siii", $municipality_name, $province_id, $region_id, $municipality_id);

    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
}
?>
