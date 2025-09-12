<?php
require 'db.php';

if (isset($_GET['barangay_id']) && is_numeric($_GET['barangay_id'])) {
    $barangay_id = intval($_GET['barangay_id']);

    $stmt = $conn->prepare("SELECT id, name FROM puroks WHERE barangay_id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }

    $stmt->bind_param("i", $barangay_id);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["error" => "Execute failed: " . $stmt->error]);
        exit;
    }

    $result = $stmt->get_result();
    $puroks = [];
    while ($row = $result->fetch_assoc()) {
        $puroks[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($puroks);
} else {
    http_response_code(400);
    echo json_encode(["error" => "Invalid or missing barangay_id"]);
}
