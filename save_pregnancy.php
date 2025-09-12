<?php
session_start();
require 'db.php';

// show DB errors clearly
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['role'])) { header("Location: index.php"); exit(); }

$registered_by   = (int)($_SESSION['user_id'] ?? 0);        // who submitted
$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);

$P = $_POST;

/* Required fields (everything except suffix, philhealth_no, assigned_midwife_id, phil_category) */
$required_keys = [
  'first_name','last_name','sex','dob','birthplace','blood_type','civil_status',
  'spouse_name','mother_name','education','employment',
  'contact','household_no','barangay_id','purok_id',
  'dswd','four_ps','philhealth','status_type','pcb'
];
$missing = [];
foreach ($required_keys as $k) {
  if (!isset($P[$k]) || trim((string)$P[$k]) === '') $missing[] = $k;
}
if ($missing) { $_SESSION['error'] = "Please fill out all required fields: ".implode(', ', $missing); header("Location: add_pregnancy.php"); exit(); }

/* Extract + sanitize */
$first_name   = trim($P['first_name']);
$middle_name  = trim($P['middle_name'] ?? '');
$last_name    = trim($P['last_name']);
$suffix       = trim($P['suffix'] ?? '');
$sex          = trim($P['sex']);
$dob          = $P['dob'];
$birthplace   = trim($P['birthplace']);
$blood_type   = trim($P['blood_type']);
$civil_status = trim($P['civil_status']);

$spouse_name  = trim($P['spouse_name']);
$mother_name  = trim($P['mother_name']);
$education    = trim($P['education']);
$employment   = trim($P['employment']);

$contact      = trim($P['contact']);
$household_no = trim($P['household_no']);

$barangay_id  = (int)$P['barangay_id'];
$purok_id     = (int)$P['purok_id'];

$dswd_nhts          = trim($P['dswd']);
$four_ps            = trim($P['four_ps']);
$philhealth_member  = trim($P['philhealth']);
$status_type        = trim($P['status_type']);
$philhealth_no      = trim($P['philhealth_no'] ?? '');
$pcb_member         = trim($P['pcb']);
$philhealth_category = isset($P['phil_category']) ? implode(',', (array)$P['phil_category']) : '';

$assigned_midwife_id = (isset($P['assigned_midwife_id']) && $P['assigned_midwife_id'] !== '')
    ? (int)$P['assigned_midwife_id'] : null;

$status = 'under_monitoring';

/* Format checks */
$nameRe = '/^[A-Za-z.\- ]+$/';  $numRe = '/^\d+$/';
if (!preg_match($nameRe, $first_name) || !preg_match($nameRe, $last_name) ||
    ($middle_name !== '' && !preg_match($nameRe, $middle_name)) ||
    ($suffix !== '' && !preg_match($nameRe, $suffix)) ||
    !preg_match($nameRe, $birthplace)) {
  $_SESSION['error'] = "Names/Birthplace: letters, spaces, periods (.), and hyphens (-) only.";
  header("Location: add_pregnancy.php"); exit();
}
if (!preg_match('/^09\d{9}$/', $contact)) { $_SESSION['error'] = "Contact must be 11 digits starting with 09."; header("Location: add_pregnancy.php"); exit(); }
if (!preg_match($numRe, $household_no)) { $_SESSION['error'] = "Household No. must be digits only."; header("Location: add_pregnancy.php"); exit(); }

/* Enum guards (must match your DB enums exactly) */
$sex_allowed=['Male','Female'];
$civil_allowed=['Single','Married','Annulled','Widow/er','Separated','Co-habitation'];
$yn=['Yes','No']; $type_allowed=['Member','Dependent']; $status_allowed=['under_monitoring','completed'];
if (!in_array($sex,$sex_allowed,true)) { $_SESSION['error']="Invalid sex."; header("Location:add_pregnancy.php"); exit();}
if (!in_array($civil_status,$civil_allowed,true)) { $_SESSION['error']="Invalid civil status."; header("Location:add_pregnancy.php"); exit();}
if (!in_array($dswd_nhts,$yn,true) || !in_array($four_ps,$yn,true) || !in_array($philhealth_member,$yn,true) || !in_array($pcb_member,$yn,true)) {
  $_SESSION['error']="YN fields must be Yes or No."; header("Location:add_pregnancy.php"); exit();
}
if (!in_array($status_type,$type_allowed,true)) { $_SESSION['error']="Invalid status type."; header("Location:add_pregnancy.php"); exit();}
if (!in_array($status,$status_allowed,true)) { $_SESSION['error']="Invalid status."; header("Location:add_pregnancy.php"); exit(); }

/* Scope checks */
$chk = $conn->prepare("SELECT id FROM barangays WHERE id = ? AND municipality_id = ?");
$chk->bind_param("ii", $barangay_id, $municipality_id); $chk->execute();
if ($chk->get_result()->num_rows===0) { $_SESSION['error']="Barangay not in your municipality."; header("Location:add_pregnancy.php"); exit(); }

$chk = $conn->prepare("SELECT id FROM puroks WHERE id = ? AND barangay_id = ?");
$chk->bind_param("ii", $purok_id, $barangay_id); $chk->execute();
if ($chk->get_result()->num_rows===0) { $_SESSION['error']="Purok does not belong to barangay."; header("Location:add_pregnancy.php"); exit(); }

if (!is_null($assigned_midwife_id)) {
  $chk = $conn->prepare("
    SELECT u.id FROM users u
    WHERE u.id = ? AND u.role='midwife' AND u.is_active=1 AND u.municipality_id = ?
  ");
  $chk->bind_param("ii", $assigned_midwife_id, $municipality_id); $chk->execute();
  if ($chk->get_result()->num_rows===0) { $_SESSION['error']="Assigned midwife invalid for this municipality."; header("Location:add_pregnancy.php"); exit(); }
}

/* INSERT (28 columns, 28 placeholders ✅) */
try {
  $stmt = $conn->prepare("
    INSERT INTO pregnant_women (
      first_name, middle_name, last_name, suffix, sex, dob, birthplace, blood_type, civil_status,
      spouse_name, mother_name, education, employment, contact, purok_id,
      dswd_nhts, four_ps, household_no, philhealth_member, status_type, philhealth_no, pcb_member, philhealth_category,
      barangay_id, municipality_id, registered_by, assigned_midwife_id, status
    ) VALUES (
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
  ");

  // 14s + 1i + 8s + 4i + 1s = 28
  $types = "ssssssssssssss" . "i" . "ssssssss" . "iiii" . "s";

  $stmt->bind_param(
    $types,
    $first_name, $middle_name, $last_name, $suffix, $sex, $dob, $birthplace, $blood_type, $civil_status,
    $spouse_name, $mother_name, $education, $employment, $contact, $purok_id,
    $dswd_nhts, $four_ps, $household_no, $philhealth_member, $status_type, $philhealth_no, $pcb_member, $philhealth_category,
    $barangay_id, $municipality_id, $registered_by, $assigned_midwife_id, $status
  );

  $stmt->execute();
  $stmt->close();

  $_SESSION['success'] = "Pregnancy record saved.";
} catch (mysqli_sql_exception $e) {
  $_SESSION['error'] = "Save failed: " . $e->getMessage();
}

$conn->close();
header("Location: add_pregnancy.php"); exit();
