<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$barangay_id     = (int)($_GET['barangay_id'] ?? 0);
if (!$municipality_id || !$barangay_id) { echo json_encode([]); exit; }

$sql = "
  SELECT 
    p.id,
    CONCAT_WS(' ',
      NULLIF(p.first_name,''), NULLIF(p.middle_name,''), NULLIF(p.last_name,''), NULLIF(p.suffix,'')
    ) AS full_name,
    p.dob
  FROM pregnant_women p
  LEFT JOIN prenatal_enrollments e ON e.patient_id = p.id
  WHERE p.municipality_id = ?
    AND p.barangay_id = ?
    AND e.patient_id IS NULL
  ORDER BY p.last_name IS NULL, p.last_name, p.first_name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $municipality_id, $barangay_id);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) {
    $name = trim($r['full_name'] ?? '');
    if ($name === '') $name = 'Unnamed';
    $out[] = ['id' => (int)$r['id'], 'full_name' => $name, 'dob' => $r['dob']];
}
echo json_encode($out);
