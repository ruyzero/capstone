<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403); echo json_encode([]); exit();
}

$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$barangay_id     = (int)($_GET['barangay_id'] ?? 0);
if (!$municipality_id || !$barangay_id) { echo json_encode([]); exit(); }

/* Return patients in this barangay & municipality that are NOT yet enrolled */
$sql = "
    SELECT 
        p.id,
        CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) AS full_name,
        p.dob
    FROM pregnant_women p
    LEFT JOIN prenatal_enrollments e ON e.patient_id = p.id
    WHERE p.municipality_id = ?
      AND p.barangay_id = ?
      AND e.patient_id IS NULL
    ORDER BY p.last_name, p.first_name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $municipality_id, $barangay_id);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) {
    $out[] = [
        'id'        => (int)$r['id'],
        'full_name' => $r['full_name'] ?: 'Unnamed',
        'dob'       => $r['dob']
    ];
}
echo json_encode($out);
