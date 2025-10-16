<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* --- Auth --- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

/* --- Helpers --- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* --- Identity --- */
$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$user_id         = (int)($_SESSION['user_id'] ?? 0);
if (!$municipality_id) { $_SESSION['error'] = "No municipality set."; header("Location: prenatal_monitoring.php"); exit(); }

/* --- Input --- */
$barangay_id    = (int)($_POST['barangay_id'] ?? 0);
$patient_id     = (int)($_POST['patient_id'] ?? 0);

$lmp            = trim($_POST['lmp'] ?? '');
$edd            = trim($_POST['edd'] ?? '');
$gravida        = trim($_POST['gravida'] ?? '');
$para           = trim($_POST['para'] ?? '');
$abortions      = trim($_POST['abortions'] ?? '');
$height_cm      = trim($_POST['height_cm'] ?? '');
$weight_kg      = trim($_POST['weight_kg'] ?? '');
$risk_level     = trim($_POST['risk_level'] ?? '');
$checkups_done  = ($_POST['checkups_done'] === '' ? null : (int)$_POST['checkups_done']);
$next_schedule  = trim($_POST['next_schedule'] ?? '');
$notes          = trim($_POST['notes'] ?? '');

/* --- Normalize/guard --- */
if ($checkups_done !== null) {
    if ($checkups_done < 0) $checkups_done = 0;
    if ($checkups_done > 3) $checkups_done = 3;
}
$lmp           = ($lmp !== '') ? $lmp : null;
$edd           = ($edd !== '') ? $edd : null;
$next_schedule = ($next_schedule !== '') ? $next_schedule : null;

if (!$barangay_id || !$patient_id) {
    $_SESSION['error'] = "Please select barangay and patient.";
    header("Location: add_monitoring.php"); exit();
}

/* --- Validate patient belongs to municipality --- */
$chk = $conn->prepare("SELECT 1 FROM pregnant_women WHERE id = ? AND municipality_id = ? LIMIT 1");
$chk->bind_param("ii", $patient_id, $municipality_id);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    $_SESSION['error'] = "Invalid patient/municipality."; header("Location: add_monitoring.php"); exit();
}
$chk->close();

/* --- Ensure enrollment row exists (roster only) --- */
$sel = $conn->prepare("SELECT id FROM prenatal_enrollments WHERE patient_id = ? LIMIT 1");
$sel->bind_param("i", $patient_id);
$sel->execute();
$er = $sel->get_result()->fetch_assoc();
$sel->close();

if ($er) {
    $eid = (int)$er['id'];
    $u = $conn->prepare("UPDATE prenatal_enrollments SET enrolled_at = COALESCE(enrolled_at, NOW()), enrolled_by = COALESCE(enrolled_by, ?) WHERE id = ?");
    $u->bind_param("ii", $user_id, $eid);
    $u->execute(); $u->close();
} else {
    $i = $conn->prepare("INSERT INTO prenatal_enrollments (patient_id, enrolled_at, enrolled_by) VALUES (?, NOW(), ?)");
    $i->bind_param("ii", $patient_id, $user_id);
    $i->execute(); $i->close();
}

/* --- Upsert into clinical source of truth: prenatal_monitoring_details --- */
$has = $conn->prepare("SELECT 1 FROM prenatal_monitoring_details WHERE patient_id = ? LIMIT 1");
$has->bind_param("i", $patient_id);
$has->execute();
$exists = (bool)$has->get_result()->fetch_row();
$has->close();

if ($exists) {
    $sql = "UPDATE prenatal_monitoring_details
            SET lmp = ?, edd = ?, next_schedule = ?,
                checkups_done = IFNULL(?, checkups_done),
                gravida = NULLIF(?, ''), para = NULLIF(?, ''), abortions = NULLIF(?, ''),
                height_cm = NULLIF(?, ''), weight_kg = NULLIF(?, ''), risk_level = NULLIF(?, ''),
                notes = NULLIF(?, ''), updated_at = NOW(), updated_by = ?
            WHERE patient_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssisssssssii",
        $lmp, $edd, $next_schedule,
        $checkups_done,
        $gravida, $para, $abortions,
        $height_cm, $weight_kg, $risk_level,
        $notes, $user_id, $patient_id
    );
    $stmt->execute(); $stmt->close();
} else {
    $sql = "INSERT INTO prenatal_monitoring_details
            (patient_id, lmp, edd, next_schedule, checkups_done,
             gravida, para, abortions, height_cm, weight_kg, risk_level, notes,
             created_at, created_by, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NOW(), ?, NOW(), ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "isssissssssssi",
        $patient_id, $lmp, $edd, $next_schedule, $checkups_done,
        $gravida, $para, $abortions, $height_cm, $weight_kg, $risk_level, $notes,
        $user_id, $user_id
    );
    $stmt->execute(); $stmt->close();
}

$_SESSION['success'] = "Patient added/updated for monitoring.";
header("Location: prenatal_monitoring.php");
