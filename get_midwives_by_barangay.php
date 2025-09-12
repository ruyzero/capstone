<?php
session_start();
require 'db.php';
if (!isset($_GET['barangay_id'])) { echo json_encode([]); exit; }

$barangay_id = (int)$_GET['barangay_id'];
$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);

$stmt = $conn->prepare("
  SELECT DISTINCT u.id, u.username
  FROM users u
  JOIN midwife_access ma ON ma.midwife_id = u.id
  JOIN barangays b ON b.id = ma.barangay_id
  WHERE u.role = 'midwife'
    AND u.is_active = 1
    AND b.municipality_id = ?
    AND ma.barangay_id = ?
  ORDER BY u.username
");
$stmt->bind_param("ii", $municipality_id, $barangay_id);
$stmt->execute();
$res = $stmt->get_result();
$out = [];
while ($r = $res->fetch_assoc()) { $out[] = $r; }
header('Content-Type: application/json');
echo json_encode($out);
