<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$enrolled_by     = (int)($_SESSION['user_id'] ?? 0);

function back_with($msg, $ok=false){
    $_SESSION[$ok ? 'success' : 'error'] = $msg;
    header("Location: add_monitoring.php");
    exit();
}

$barangay_id       = (int)($_POST['barangay_id'] ?? 0);
$patient_id        = (int)($_POST['patient_id'] ?? 0);
$first_checkup_date= $_POST['first_checkup_date'] ?? null;
$weight_kg         = $_POST['weight_kg'] ?? null;
$height_ft         = $_POST['height_ft'] ?? null;
$nutritional_status= $_POST['nutritional_status'] ?? null;
$lmp               = $_POST['lmp'] ?? null;
$edd               = $_POST['edd'] ?? null;
$pregnancy_number  = $_POST['pregnancy_number'] ?? null;
$remarks           = $_POST['remarks'] ?? null;

if (!$municipality_id || !$barangay_id || !$patient_id || !$first_checkup_date) {
    back_with("Please complete required fields (barangay, patient, first checkup date).");
}

/* Validate patient belongs to your municipality + barangay and not already enrolled */
$sql = "
    SELECT p.id
    FROM pregnant_women p
    LEFT JOIN prenatal_enrollments e ON e.patient_id = p.id
    WHERE p.id = ?
      AND p.municipality_id = ?
      AND p.barangay_id = ?
      AND e.patient_id IS NULL
";
$chk = $conn->prepare($sql);
$chk->bind_param("iii", $patient_id, $municipality_id, $barangay_id);
$chk->execute();
$valid = $chk->get_result()->num_rows > 0;
$chk->close();

if (!$valid) { back_with("Invalid patient selection, or already enrolled."); }

/* Enroll */
$ins = $conn->prepare("INSERT INTO prenatal_enrollments (patient_id, enrolled_by) VALUES (?, ?)");
$ins->bind_param("ii", $patient_id, $enrolled_by);
if (!$ins->execute()) {
    back_with("Failed to enroll: ".$ins->error);
}
$ins->close();

/* OPTIONAL: update some details in pregnant_women if columns exist */
function col_exists(mysqli $conn, string $tbl, string $col): bool {
    $q = $conn->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1
    ");
    $q->bind_param("ss", $tbl, $col);
    $q->execute();
    $ok = $q->get_result()->num_rows > 0;
    $q->close();
    return $ok;
}
$fields = [];
$types  = '';
$vals   = [];

/* only add when column exists in your pregnant_women table */
if ($first_checkup_date && col_exists($conn,'pregnant_women','first_checkup_date')) { $fields[]='first_checkup_date=?'; $types.='s'; $vals[]=$first_checkup_date; }
if ($lmp && col_exists($conn,'pregnant_women','lmp'))                                   { $fields[]='lmp=?';                $types.='s'; $vals[]=$lmp; }
if ($edd && col_exists($conn,'pregnant_women','edd'))                                   { $fields[]='edd=?';                $types.='s'; $vals[]=$edd; }
if ($weight_kg!==null && col_exists($conn,'pregnant_women','weight_kg'))                { $fields[]='weight_kg=?';          $types.='d'; $vals[]=$weight_kg; }
if ($height_ft!==null && col_exists($conn,'pregnant_women','height_ft'))                { $fields[]='height_ft=?';          $types.='d'; $vals[]=$height_ft; }
if ($nutritional_status && col_exists($conn,'pregnant_women','nutritional_status'))     { $fields[]='nutritional_status=?'; $types.='s'; $vals[]=$nutritional_status; }
if ($pregnancy_number && col_exists($conn,'pregnant_women','pregnancy_number'))         { $fields[]='pregnancy_number=?';   $types.='i'; $vals[]=(int)$pregnancy_number; }
if ($remarks && col_exists($conn,'pregnant_women','remarks'))                            { $fields[]='remarks=?';            $types.='s'; $vals[]=$remarks; }

if (!empty($fields)) {
    $sqlu = "UPDATE pregnant_women SET ".implode(', ', $fields)." WHERE id = ?";
    $types .= 'i'; $vals[] = $patient_id;
    $u = $conn->prepare($sqlu);
    $u->bind_param($types, ...$vals);
    $u->execute(); // ignore failure to keep enrollment success
    $u->close();
}

back_with("Patient enrolled to Prenatal Monitoring.", true);
