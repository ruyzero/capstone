<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $region_id = $_POST['region_id'];
    $region_name = $_POST['region_name'];

    $stmt = $conn->prepare("UPDATE regions SET name = ? WHERE id = ?");
    $stmt->bind_param("si", $region_name, $region_id);

    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
}
?>
