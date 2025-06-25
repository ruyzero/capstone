<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get data from POST
$first_name = $_POST['first_name'];
$middle_name = $_POST['middle_name'];
$last_name = $_POST['last_name'];
$suffix = $_POST['suffix'];
$sex = $_POST['sex'];
$dob = $_POST['dob'];
$birthplace = $_POST['birthplace'];
$blood_type = $_POST['blood_type'];
$civil_status = $_POST['civil_status'];
$spouse_name = $_POST['spouse_name'];
$mother_name = $_POST['mother_name'];
$education = $_POST['education'];
$employment = $_POST['employment'];
$address = $_POST['address'];
$contact = $_POST['contact'];
$dswd_nhts = $_POST['dswd'];
$four_ps = $_POST['four_ps'];
$household_no = $_POST['household_no'];
$philhealth_member = $_POST['philhealth'];
$status_type = $_POST['status_type'];
$philhealth_no = $_POST['philhealth_no'];
$pcb_member = $_POST['pcb'];
$philhealth_category = isset($_POST['phil_category']) ? implode(',', $_POST['phil_category']) : '';
$barangay_id = $_POST['barangay_id'];
$midwife_id = ($role === 'admin') ? $_POST['midwife_id'] : $user_id;
$status = 'under_monitoring';

// Insert into database
$stmt = $conn->prepare("
    INSERT INTO pregnant_women (
        first_name, middle_name, last_name, suffix, sex, dob, birthplace, blood_type, civil_status,
        spouse_name, mother_name, education, employment, address, contact, dswd_nhts, four_ps,
        household_no, philhealth_member, status_type, philhealth_no, pcb_member, philhealth_category,
        barangay_id, midwife_id, status
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
");

$stmt->bind_param(
    "sssssssssssssssssssssssiss",
    $first_name, $middle_name, $last_name, $suffix, $sex, $dob, $birthplace, $blood_type, $civil_status,
    $spouse_name, $mother_name, $education, $employment, $address, $contact, $dswd_nhts, $four_ps,
    $household_no, $philhealth_member, $status_type, $philhealth_no, $pcb_member, $philhealth_category,
    $barangay_id, $midwife_id, $status
);

if ($stmt->execute()) {
    $_SESSION['success'] = "Pregnancy record saved successfully.";
} else {
    $_SESSION['error'] = "Error saving record: " . $stmt->error;
}

$stmt->close();
$conn->close();

header("Location: add_pregnancy.php");
exit();
