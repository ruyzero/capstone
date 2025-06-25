<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_month = date('Y-m-01');

// Fetch barangays & midwives
if ($role === 'admin') {
    $barangays = $conn->query("SELECT id, name FROM barangays");
    $midwives = $conn->query("SELECT id, username FROM users WHERE role = 'midwife'");
} else {
    $stmt = $conn->prepare("
        SELECT b.id, b.name 
        FROM barangays b
        INNER JOIN midwife_access ma ON ma.barangay_id = b.id
        WHERE ma.midwife_id = ? AND ma.access_month = ?
    ");
    $stmt->bind_param("is", $user_id, $current_month);
    $stmt->execute();
    $barangays = $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Pregnancy Record</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }
        .container {
            max-width: 1000px;
            margin-top: 40px;
        }
    </style>
</head>
<body>

<div class="container bg-white p-4 shadow rounded">
    <h3 class="mb-4">➕ Add Pregnancy Record</h3>
    <a href="<?= $role === 'admin' ? 'admin_dashboard.php' : 'midwife_dashboard.php'; ?>" class="btn btn-secondary mb-4">← Back to Dashboard</a>

    <form method="POST" action="save_pregnancy.php">
        <div class="row mb-3">
            <div class="col-md-3">
                <label>First Name</label>
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>Middle Name</label>
                <input type="text" name="middle_name" class="form-control">
            </div>
            <div class="col-md-3">
                <label>Last Name</label>
                <input type="text" name="last_name" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>Suffix</label>
                <input type="text" name="suffix" class="form-control">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3">
                <label>Sex</label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="sex" value="Male" required>
                    <label class="form-check-label">Male</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="sex" value="Female">
                    <label class="form-check-label">Female</label>
                </div>
            </div>
            <div class="col-md-3">
                <label>Date of Birth</label>
                <input type="date" name="dob" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>Birthplace</label>
                <input type="text" name="birthplace" class="form-control">
            </div>
            <div class="col-md-3">
                <label>Blood Type</label>
                <select name="blood_type" class="form-select">
                    <option value="">Select</option>
                    <?php foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'] as $type): ?>
                        <option value="<?= $type ?>"><?= $type ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label>Civil Status</label><br>
            <?php
            $statuses = ['Single', 'Married', 'Annulled', 'Widow/er', 'Separated', 'Co-habitation'];
            foreach ($statuses as $status): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="civil_status" value="<?= $status ?>" required>
                    <label class="form-check-label"><?= $status ?></label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label>Spouse's Name</label>
                <input type="text" name="spouse_name" class="form-control">
            </div>
            <div class="col-md-6">
                <label>Mother's Name</label>
                <input type="text" name="mother_name" class="form-control">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label>Educational Attainment</label>
                <select name="education" class="form-select">
                    <option value="">Select</option>
                    <?php foreach (['No Formal Education', 'Elementary', 'High School', 'College', 'Vocational', 'Post Graduate'] as $edu): ?>
                        <option value="<?= $edu ?>"><?= $edu ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label>Employment Status</label>
                <select name="employment" class="form-select">
                    <option value="">Select</option>
                    <?php foreach (['Student', 'Employed', 'Unknown', 'Retired', 'None/Unemployed'] as $emp): ?>
                        <option value="<?= $emp ?>"><?= $emp ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label>Residential Address</label>
                <input type="text" name="address" class="form-control" placeholder="Purok, Barangay, City, Misamis Occidental">
            </div>
            <div class="col-md-4">
                <label>Contact Number</label>
                <input type="text" name="contact" class="form-control">
            </div>
            <div class="col-md-4">
                <label>Barangay</label>
                <select name="barangay_id" class="form-select" required>
                    <option value="">-- Select Barangay --</option>
                    <?php while ($b = $barangays->fetch_assoc()): ?>
                        <option value="<?= $b['id']; ?>"><?= $b['name']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3">
                <label>DSWD NHTS?</label>
                <select name="dswd" class="form-select">
                    <option value="No">No</option>
                    <option value="Yes">Yes</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>4Ps Member?</label>
                <select name="four_ps" class="form-select">
                    <option value="No">No</option>
                    <option value="Yes">Yes</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>Household No.</label>
                <input type="text" name="household_no" class="form-control">
            </div>
            <div class="col-md-3">
                <label>PhilHealth Member?</label>
                <select name="philhealth" class="form-select">
                    <option value="No">No</option>
                    <option value="Yes">Yes</option>
                </select>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label>Status Type</label>
                <select name="status_type" class="form-select">
                    <option value="">Select</option>
                    <option value="Member">Member</option>
                    <option value="Dependent">Dependent</option>
                </select>
            </div>
            <div class="col-md-4">
                <label>PhilHealth No.</label>
                <input type="text" name="philhealth_no" class="form-control">
            </div>
            <div class="col-md-4">
                <label>PCB Member?</label>
                <select name="pcb" class="form-select">
                    <option value="No">No</option>
                    <option value="Yes">Yes</option>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label>If Member, Choose Category</label><br>
            <?php
            $categories = ['FE - Private', 'FE - Government', 'IE', 'Others'];
            foreach ($categories as $cat): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="phil_category[]" value="<?= $cat ?>">
                    <label class="form-check-label"><?= $cat ?></label>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($role === 'admin'): ?>
            <div class="mb-3">
                <label>Assign to Midwife</label>
                <select name="midwife_id" class="form-select" required>
                    <option value="">-- Select Midwife --</option>
                    <?php while ($m = $midwives->fetch_assoc()): ?>
                        <option value="<?= $m['id']; ?>"><?= $m['username']; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary w-100">Save Record</button>
    </form>
</div>

</body>
</html>
