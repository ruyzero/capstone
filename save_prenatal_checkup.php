<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

$user_id    = (int)($_SESSION['user_id'] ?? 0);
$patient_id = (int)($_POST['patient_id'] ?? 0);
$checkup_no = max(1, min(3, (int)($_POST['checkup_no'] ?? 0)));

if (!$patient_id || !$checkup_no) { $_SESSION['error'] = "Missing patient/checkup."; header("Location: prenatal_monitoring.php"); exit(); }

/* ---------- helper: normalize empty to NULL ---------- */
$S = fn($k)=>isset($_POST[$k]) ? trim($_POST[$k]) : null;
$N = function($k){ $x = isset($_POST[$k]) ? trim($_POST[$k]) : null; return $x === '' ? null : $x; };

/* ---------- fields ('' -> NULL) ---------- */
$checkup_date = ($x=$S('checkup_date'))===''?null:$x;
$weight_kg    = ($x=$S('weight_kg'))===''?null:$x;
$height_ft    = ($x=$S('height_ft'))===''?null:$x;
$gest_age     = ($x=$S('gest_age'))===''?null:$x;
$bp           = ($x=$S('blood_pressure'))===''?null:$x;
$nutr_status  = ($x=$S('nutritional_status'))===''?null:$x;

$urinalysis   = ($x=$S('urinalysis'))===''?null:$x;
$cbc          = ($x=$S('cbc'))===''?null:$x;
$sti_syp_date = ($x=$S('sti_syphilis_date'))===''?null:$x;
$sti_hiv      = ($x=$S('sti_hiv'))===''?null:$x;
$sti_hbsag    = ($x=$S('sti_hbsag'))===''?null:$x;
$stool_exam   = ($x=$S('stool_exam'))===''?null:$x;
$aaw          = ($x=$S('acetic_acid_wash'))===''?null:$x;

$birth_plan   = ($x=$S('birth_plan'))===''?null:$x;
$dental       = ($x=$S('dental_checkup'))===''?null:$x;
$hgb_count    = ($x=$S('hemoglobin_count'))===''?null:$x;
$lab_done     = ($x=$S('lab_tests_done'))===''?null:$x;
$tt_date      = ($x=$S('tetanus_vax_date'))===''?null:$x;

$t_syphilis   = isset($_POST['treat_syphilis']) ? 1 : 0;
$t_arv        = isset($_POST['treat_arv']) ? 1 : 0;
$t_bact       = isset($_POST['treat_bacteriuria']) ? 1 : 0;
$t_anemia     = isset($_POST['treat_anemia']) ? 1 : 0;

/* ---------- helper: dynamic bind_param with refs ---------- */
function bind_with_types(mysqli_stmt $stmt, string $types, array $values): void {
    // build refs for call_user_func_array
    $bind = array_merge([$types], $values);
    foreach ($bind as $k => $v) { $bind[$k] = &$bind[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

/* ---------- exists? ---------- */
$sel = $conn->prepare("SELECT id FROM prenatal_checkups WHERE patient_id=? AND checkup_no=? LIMIT 1");
$sel->bind_param("ii", $patient_id, $checkup_no);
$sel->execute();
$found = $sel->get_result()->fetch_assoc();
$sel->close();

/* ---------- UPDATE or INSERT ---------- */
if ($found) {
    $id = (int)$found['id'];

    $sql = "UPDATE prenatal_checkups SET
        checkup_date=?, weight_kg=?, height_ft=?, gest_age_weeks=?, blood_pressure=?, nutritional_status=?,
        urinalysis=?, cbc=?, stis_syphilis_date=?, stis_hiv=?, stis_hbsag=?, stool_exam=?, acetic_acid_wash=?,
        birth_plan=?, dental_checkup=?, hemoglobin_count=?, lab_tests_done=?, tetanus_vaccine_date=?,
        treat_syphilis=?, treat_arv=?, treat_bacteriuria=?, treat_anemia=?,
        updated_at=NOW(), updated_by=?
      WHERE id=?";

    $values = [
        $checkup_date, $weight_kg, $height_ft, $gest_age, $bp, $nutr_status,
        $urinalysis, $cbc, $sti_syp_date, $sti_hiv, $sti_hbsag, $stool_exam, $aaw,
        $birth_plan, $dental, $hgb_count, $lab_done, $tt_date,
        $t_syphilis, $t_arv, $t_bact, $t_anemia, $user_id, $id
    ];
    // 18 string-like + 6 ints
    $types = str_repeat('s', 18) . str_repeat('i', 6);

    $stmt = $conn->prepare($sql);
    bind_with_types($stmt, $types, $values);
    $stmt->execute(); $stmt->close();

    $_SESSION['success'] = "Checkup #{$checkup_no} updated.";
} else {
    $sql = "INSERT INTO prenatal_checkups
      (patient_id, checkup_no,
       checkup_date, weight_kg, height_ft, gest_age_weeks, blood_pressure, nutritional_status,
       urinalysis, cbc, stis_syphilis_date, stis_hiv, stis_hbsag, stool_exam, acetic_acid_wash,
       birth_plan, dental_checkup, hemoglobin_count, lab_tests_done, tetanus_vaccine_date,
       treat_syphilis, treat_arv, treat_bacteriuria, treat_anemia,
       created_at, created_by, updated_at, updated_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), ?, NOW(), ?)";

    $values = [
        $patient_id, $checkup_no,
        $checkup_date, $weight_kg, $height_ft, $gest_age, $bp, $nutr_status,
        $urinalysis, $cbc, $sti_syp_date, $sti_hiv, $sti_hbsag, $stool_exam, $aaw,
        $birth_plan, $dental, $hgb_count, $lab_done, $tt_date,
        $t_syphilis, $t_arv, $t_bact, $t_anemia, $user_id, $user_id
    ];
    // 2 ints + 18 string-like + 6 ints
    $types = str_repeat('i', 2) . str_repeat('s', 18) . str_repeat('i', 6);

    $stmt = $conn->prepare($sql);
    bind_with_types($stmt, $types, $values);
    $stmt->execute(); $stmt->close();

    $_SESSION['success'] = "Checkup #{$checkup_no} saved.";
}

/* ---------- Sync overall count (0..3) ---------- */
$c = $conn->prepare("SELECT COUNT(*) AS c FROM prenatal_checkups WHERE patient_id=?");
$c->bind_param("i",$patient_id); $c->execute();
$cnt = (int)($c->get_result()->fetch_assoc()['c'] ?? 0); $c->close();
if ($cnt > 3) $cnt = 3;

/* Ensure details row exists and update count */
$u = $conn->prepare("
  INSERT INTO prenatal_monitoring_details (patient_id, checkups_done, created_at, updated_at)
  VALUES (?, ?, NOW(), NOW())
  ON DUPLICATE KEY UPDATE checkups_done=VALUES(checkups_done), updated_at=NOW()
");
$u->bind_param("ii", $patient_id, $cnt);
$u->execute(); $u->close();

/* back to tiles */
header("Location: prenatal_checkup.php?patient_id={$patient_id}");
