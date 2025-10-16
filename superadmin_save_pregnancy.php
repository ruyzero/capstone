<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth: SUPER ADMIN ONLY ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ---------- Helpers ---------- */
function clean($v){ return is_string($v) ? trim($v) : $v; }
function fail($msg, $back='superadmin_add_patient.php'){ $_SESSION['error']=$msg; header("Location: $back"); exit(); }

/* ---------- Collect & Validate ---------- */
$must_text    = ['first_name','last_name','birthplace'];
$must_selects = ['sex','blood_type','civil_status','education','employment','dswd','four_ps','philhealth','status_type','pcb'];
$must_misc    = ['dob','contact','household_no'];
$must_loc     = ['municipality_id','barangay_id','purok_id'];

foreach ($must_text as $k)    if (empty($_POST[$k])) fail("Missing required field: $k");
foreach ($must_selects as $k) if (!isset($_POST[$k]) || $_POST[$k]==='') fail("Please select: $k");
foreach ($must_misc as $k)    if (empty($_POST[$k])) fail("Missing required field: $k");
foreach ($must_loc as $k)     if (empty($_POST[$k])) fail("Please complete location: $k");

/* Map POST -> columns (per your schema) */
$first_name   = clean($_POST['first_name']);
$middle_name  = clean($_POST['middle_name'] ?? null);
$last_name    = clean($_POST['last_name']);
$suffix       = clean($_POST['suffix'] ?? null);

$sex          = clean($_POST['sex']);
$dob          = clean($_POST['dob']);
$birthplace   = clean($_POST['birthplace']);
$blood_type   = clean($_POST['blood_type']);
$civil_status = clean($_POST['civil_status']);

$spouse_name  = clean($_POST['spouse_name'] ?? null);
$mother_name  = clean($_POST['mother_name'] ?? null);

$education    = clean($_POST['education']);
$employment   = clean($_POST['employment']);

$contact      = preg_replace('/\s+/', '', (string)($_POST['contact'] ?? ''));
$household_no = clean($_POST['household_no']);

$municipality_id = (int)($_POST['municipality_id'] ?? 0);
$barangay_id     = (int)($_POST['barangay_id'] ?? 0);
$purok_id        = (int)($_POST['purok_id'] ?? 0);

$dswd_nhts         = clean($_POST['dswd']);        // Yes/No
$four_ps           = clean($_POST['four_ps']);     // Yes/No
$philhealth_member = clean($_POST['philhealth']);  // Yes/No
$status_type       = clean($_POST['status_type']); // Member/Dependent
$philhealth_no     = clean($_POST['philhealth_no'] ?? null);
$pcb_member        = clean($_POST['pcb']);         // Yes/No

$phil_category_arr = (isset($_POST['phil_category']) && is_array($_POST['phil_category'])) ? $_POST['phil_category'] : [];
$philhealth_category = implode(',', array_map('clean', $phil_category_arr));

$registered_by = (int)($_POST['registered_by'] ?? ($_SESSION['user_id'] ?? 0));
$assigned_midwife_id = null;

/* Formats */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob))     fail("Invalid date format for DOB.");
if (!preg_match('/^09\d{9}$/', $contact))           fail("Contact number must be 11 digits and start with 09.");
if (!preg_match('/^\d+$/', $household_no))          fail("Household No. must be numeric.");
if ($municipality_id<=0 || $barangay_id<=0 || $purok_id<=0) fail("Please complete Region → Province → Municipality → Barangay and Purok.");

/* Cross-checks */
$chk = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE id=? AND municipality_id=?");
$chk->bind_param("ii", $barangay_id, $municipality_id);
$chk->execute(); $ok = (int)$chk->get_result()->fetch_assoc()['c']; $chk->close();
if ($ok===0) fail("Selected barangay does not belong to the chosen municipality.");

$chk2 = $conn->prepare("SELECT COUNT(*) c FROM puroks WHERE id=? AND barangay_id=?");
$chk2->bind_param("ii", $purok_id, $barangay_id);
$chk2->execute(); $ok2 = (int)$chk2->get_result()->fetch_assoc()['c']; $chk2->close();
if ($ok2===0) fail("Selected purok does not belong to the chosen barangay.");

/* Province (from municipality) */
$province = null;
$qp = $conn->prepare("
    SELECT p.name AS province_name
    FROM municipalities m
    JOIN provinces p ON p.id = m.province_id
    WHERE m.id = ? LIMIT 1
");
$qp->bind_param("i", $municipality_id);
$qp->execute();
$prow = $qp->get_result()->fetch_assoc();
$qp->close();
if ($prow && isset($prow['province_name'])) $province = $prow['province_name'];

/* Insert */
$conn->begin_transaction();
try {
    $sql = "
        INSERT INTO pregnant_women (
            first_name, middle_name, last_name, suffix,
            dob, sex, birthplace, blood_type, civil_status,
            spouse_name, mother_name,
            education, employment,
            contact, dswd_nhts, four_ps, household_no,
            philhealth_member, status_type, philhealth_no, pcb_member, philhealth_category,
            barangay_id, municipality_id, purok_id, province,
            status, registered_by, assigned_midwife_id
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?
        )
    ";

    $stmt = $conn->prepare($sql);

    // ✅ EXACTLY 29 types: 24 's' then 5 'i' in this order:
    // [22 's'] first_name..philhealth_category, [iii] brgy, muni, purok, [s] province, [s] status, [ii] registered_by, assigned_midwife_id
    $types = "ssssssssssssssssssssssiiissii"; // 24s + 5i = 29

    $status_default = 'under_monitoring';

    $stmt->bind_param(
        $types,
        $first_name, $middle_name, $last_name, $suffix,
        $dob, $sex, $birthplace, $blood_type, $civil_status,
        $spouse_name, $mother_name,
        $education, $employment,
        $contact, $dswd_nhts, $four_ps, $household_no,
        $philhealth_member, $status_type, $philhealth_no, $pcb_member, $philhealth_category,
        $barangay_id, $municipality_id, $purok_id, $province,
        $status_default, $registered_by, $assigned_midwife_id
    );

    if (!$stmt->execute()) throw new Exception("Insert failed: " . $stmt->error);

    $new_id = $stmt->insert_id;
    $stmt->close();
    $conn->commit();

    $_SESSION['success'] = "Patient saved (ID: {$new_id}).";
    header("Location: superadmin_add_patient.php");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['error'] = "Error saving record: " . $e->getMessage();
    header("Location: superadmin_add_patient.php");
    exit();
}
