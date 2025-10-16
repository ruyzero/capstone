<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* SHOW ERRORS (so we don't get a blank page) */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* Auth: Super Admin only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php"); exit();
}

/* Small helpers */
function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s", $name); $q->execute();
    $ok = (bool)$q->get_result()->fetch_row();
    $q->close(); return $ok;
}
function require_table(mysqli $conn, string $name, string $ddl_hint){
    if (!table_exists($conn, $name)) {
        $_SESSION['error'] = "Table `{$name}` does not exist. Please create it first:\n<pre>{$ddl_hint}</pre>";
        header("Location: superadmin_add_monitoring.php"); exit();
    }
}

/* Ensure required tables exist (don't assume specific columns) */
require_table($conn, 'pregnant_women', "SELECT 1; /* existing */");
require_table($conn, 'prenatal_enrollments', 
"CREATE TABLE `prenatal_enrollments` (
  `patient_id` INT NOT NULL PRIMARY KEY,
  `enrolled_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `enrolled_by` INT NULL,
  INDEX (`enrolled_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
require_table($conn, 'prenatal_monitoring_details',
"CREATE TABLE `prenatal_monitoring_details` (
  `patient_id` INT NOT NULL PRIMARY KEY,
  `lmp` DATE NULL,
  `edd` DATE NULL,
  `gravida` INT NULL,
  `para` INT NULL,
  `abortions` INT NULL,
  `height_cm` DECIMAL(6,2) NULL,
  `weight_kg` DECIMAL(6,2) NULL,
  `risk_level` ENUM('normal','caution','high') NULL,
  `checkups_done` TINYINT NULL,
  `next_schedule` DATE NULL,
  `notes` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* Read POST */
$patient_id      = (int)($_POST['patient_id'] ?? 0);
$barangay_id     = (int)($_POST['barangay_id'] ?? 0);
$lmp             = trim($_POST['lmp'] ?? '');
$edd             = trim($_POST['edd'] ?? '');
$gravida         = ($_POST['gravida'] === '' ? null : (int)$_POST['gravida']);
$para            = ($_POST['para'] === '' ? null : (int)$_POST['para']);
$abortions       = ($_POST['abortions'] === '' ? null : (int)$_POST['abortions']);
$height_cm       = ($_POST['height_cm'] === '' ? null : (float)$_POST['height_cm']);
$weight_kg       = ($_POST['weight_kg'] === '' ? null : (float)$_POST['weight_kg']);
$risk_level_in   = trim($_POST['risk_level'] ?? '');
$checkups_done   = ($_POST['checkups_done'] === '' ? null : (int)$_POST['checkups_done']);
$next_schedule   = trim($_POST['next_schedule'] ?? '');
$notes_in        = trim($_POST['notes'] ?? '');
$enrolled_by     = (int)($_SESSION['user_id'] ?? 0);

/* Validate basics */
if ($patient_id <= 0 || $barangay_id <= 0) {
    $_SESSION['error'] = "Please select a Barangay and a Patient.";
    header("Location: superadmin_add_monitoring.php"); exit();
}
if ($checkups_done !== null && ($checkups_done < 0 || $checkups_done > 3)) {
    $_SESSION['error'] = "No. of Prenatal Checkups must be 0 to 3 only.";
    header("Location: superadmin_add_monitoring.php"); exit();
}
if ($risk_level_in !== '' && !in_array($risk_level_in, ['normal','caution','high'], true)) {
    $_SESSION['error'] = "Invalid risk level.";
    header("Location: superadmin_add_monitoring.php"); exit();
}

/* Verify patient belongs to the chosen barangay */
$chk = $conn->prepare("SELECT 1 FROM pregnant_women WHERE id = ? AND barangay_id = ? LIMIT 1");
$chk->bind_param("ii", $patient_id, $barangay_id);
$chk->execute();
if (!$chk->get_result()->fetch_row()) {
    $_SESSION['error'] = "Invalid patient / barangay combination.";
    header("Location: superadmin_add_monitoring.php"); exit();
}
$chk->close();

/* Normalize to NULLs where appropriate */
$LMP  = ($lmp !== '') ? $lmp : null;
$EDD  = ($edd !== '') ? $edd : null;
$NEXT = ($next_schedule !== '') ? $next_schedule : null;
$RISK = ($risk_level_in !== '') ? $risk_level_in : null;
/* For TEXT it’s fine to store empty string, but you can switch to NULL if you prefer: */
$NOTES = ($notes_in !== '') ? $notes_in : null;

$conn->begin_transaction();
try {
    /* 1) Enroll (use primary key patient_id so we can REPLACE) */
    // If your table has a surrogate id instead, this still works because we defined a fallback DDL above.
    $en = $conn->prepare("
        INSERT INTO prenatal_enrollments (patient_id, enrolled_at, enrolled_by)
        VALUES (?, NOW(), ?)
        ON DUPLICATE KEY UPDATE enrolled_at = VALUES(enrolled_at), enrolled_by = VALUES(enrolled_by)
    ");
    $en->bind_param("ii", $patient_id, $enrolled_by);
    $en->execute(); $en->close();

    /* 2) Does a details row already exist for this patient? (NO reliance on `id`) */
    $has = $conn->prepare("SELECT COUNT(*) AS c FROM prenatal_monitoring_details WHERE patient_id = ?");
    $has->bind_param("i", $patient_id);
    $has->execute();
    $exists = (int)($has->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $has->close();

    if ($exists) {
        /* UPDATE by patient_id */
        $upd = $conn->prepare("
            UPDATE prenatal_monitoring_details
               SET lmp = ?, edd = ?, gravida = ?, para = ?, abortions = ?,
                   height_cm = ?, weight_kg = ?, risk_level = ?, checkups_done = ?,
                   next_schedule = ?, notes = ?
             WHERE patient_id = ?
        ");
        // TYPES: s s i i i d d s i s s i
        $upd->bind_param(
            "ssiiiddsissi",
            $LMP, $EDD, $gravida, $para, $abortions,
            $height_cm, $weight_kg, $RISK, $checkups_done,
            $NEXT, $NOTES, $patient_id
        );
        $upd->execute(); $upd->close();
    } else {
        /* INSERT new details row */
        $ins = $conn->prepare("
            INSERT INTO prenatal_monitoring_details
                (patient_id, lmp, edd, gravida, para, abortions, height_cm, weight_kg, risk_level, checkups_done, next_schedule, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // TYPES: i s s i i i d d s i s s
        $ins->bind_param(
            "issiiiddsiss",
            $patient_id, $LMP, $EDD, $gravida, $para, $abortions,
            $height_cm, $weight_kg, $RISK, $checkups_done, $NEXT, $NOTES
        );
        $ins->execute(); $ins->close();
    }

    $conn->commit();
    $_SESSION['success'] = "Patient enrolled and monitoring details saved.";
    header("Location: superadmin_prenatal_monitoring.php");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['error'] = "Error saving monitoring: " . $e->getMessage();
    header("Location: superadmin_add_monitoring.php");
    exit();
}
