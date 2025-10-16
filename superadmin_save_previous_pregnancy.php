<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* Auth: Super Admin only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php"); exit();
}

/* Helpers */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s", $name); $q->execute();
    $ok = (bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}
function require_table(mysqli $conn, string $name, string $ddl_hint){
    if (!table_exists($conn, $name)) {
        $_SESSION['error'] = "Table `{$name}` does not exist. Please create it first:\n<pre>{$ddl_hint}</pre>";
        header("Location: superadmin_add_previous_pregnancy.php"); exit();
    }
}

/* Ensure tables */
require_table($conn, 'pregnant_women', "SELECT 1; /* existing */");
require_table($conn, 'previous_pregnancies',
"CREATE TABLE `previous_pregnancies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `pregnancy_year` INT NULL,
  `outcome` VARCHAR(50) NULL,
  `delivery_place` VARCHAR(100) NULL,
  `birth_weight_kg` DECIMAL(5,2) NULL,
  `complications` VARCHAR(150) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* Read POST */
$barangay_id       = (int)($_POST['barangay_id'] ?? 0);
$patient_id        = (int)($_POST['patient_id'] ?? 0);
$pregnancy_year    = ($_POST['pregnancy_year'] === '' ? null : (int)$_POST['pregnancy_year']);
$outcome           = trim($_POST['outcome'] ?? '');
$delivery_place    = trim($_POST['delivery_place'] ?? '');
$birth_weight_kg   = ($_POST['birth_weight_kg'] === '' ? null : (float)$_POST['birth_weight_kg']);
$complications     = trim($_POST['complications'] ?? '');
$notes             = trim($_POST['notes'] ?? '');

/* Validate */
if ($patient_id <= 0 || $barangay_id <= 0) {
    $_SESSION['error'] = "Please select a Barangay and a Patient.";
    header("Location: superadmin_add_previous_pregnancy.php?" . http_build_query($_GET)); exit();
}
$year_now = (int)date('Y');
if ($pregnancy_year !== null && ($pregnancy_year < 1950 || $pregnancy_year > $year_now)) {
    $_SESSION['error'] = "Pregnancy year must be between 1950 and {$year_now}.";
    header("Location: superadmin_add_previous_pregnancy.php"); exit();
}
if ($outcome !== '' && !in_array($outcome, ['Live Birth','Stillbirth','Miscarriage'], true)) {
    $_SESSION['error'] = "Invalid outcome.";
    header("Location: superadmin_add_previous_pregnancy.php"); exit();
}

/* Verify patient belongs to barangay */
$chk = $conn->prepare("SELECT id FROM pregnant_women WHERE id = ? AND barangay_id = ? LIMIT 1");
$chk->bind_param("ii", $patient_id, $barangay_id);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    $_SESSION['error'] = "Invalid patient / barangay combination.";
    header("Location: superadmin_add_previous_pregnancy.php"); exit();
}
$chk->close();

/* Insert row */
$ins = $conn->prepare("
    INSERT INTO previous_pregnancies
        (patient_id, pregnancy_year, outcome, delivery_place, birth_weight_kg, complications, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$ins->bind_param(
    "iissdss",
    $patient_id, $pregnancy_year, $outcome, $delivery_place, $birth_weight_kg, $complications, $notes
);

if ($ins->execute()) {
    $_SESSION['success'] = "Previous pregnancy record saved.";
    header("Location: superadmin_add_previous_pregnancy.php");
    exit();
} else {
    $_SESSION['error'] = "Error saving record: " . $ins->error;
    header("Location: superadmin_add_previous_pregnancy.php");
    exit();
}
