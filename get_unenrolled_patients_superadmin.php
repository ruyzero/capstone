<?php
// get_unenrolled_patients_superadmin.php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']); exit;
}

function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s", $name);
    $q->execute();
    $ok = (bool)$q->get_result()->fetch_row();
    $q->close();
    return $ok;
}
function full_name(array $r): string {
    $parts = array_filter([
        $r['first_name'] ?? '',
        $r['middle_name'] ?? '',
        $r['last_name'] ?? '',
        $r['suffix'] ?? ''
    ], fn($x) => $x !== null && trim($x) !== '');
    return trim(implode(' ', $parts));
}

$barangay_id = (int)($_GET['barangay_id'] ?? 0);
$q           = trim($_GET['q'] ?? '');

if ($barangay_id <= 0) {
    echo json_encode([]); exit;
}

$has_enroll = table_exists($conn, 'prenatal_enrollments');

$params = [$barangay_id];
$types  = "i";

$sql = "
    SELECT p.id, p.first_name, p.middle_name, p.last_name, p.suffix, p.dob
    FROM pregnant_women p
    WHERE p.barangay_id = ?
";
if ($q !== '') {
    $sql .= " AND CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ?";
    $params[] = "%{$q}%";
    $types   .= "s";
}

if ($has_enroll) {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM prenatal_enrollments e WHERE e.patient_id = p.id)";
}

$sql .= " ORDER BY p.last_name IS NULL, p.last_name ASC, p.first_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
    $out[] = [
        'id'        => (int)$row['id'],
        'full_name' => full_name($row),
        'dob'       => $row['dob'] ?: null,
    ];
}
$stmt->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE);
