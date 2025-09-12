<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Fetch midwives and barangays
$midwives = $conn->query("SELECT id, username FROM users WHERE role = 'midwife'");
$barangays = $conn->query("SELECT id, name FROM barangays");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $midwife_id = $_POST['midwife_id'];
    $selected_month = $_POST['start_month']; // format: YYYY-MM
    $duration = (int) $_POST['month_count'];
    $selected_barangays = $_POST['barangay_ids']; // array

    $messages = [];

    for ($i = 0; $i < $duration; $i++) {
        $access_month = date('Y-m-01', strtotime("+$i months", strtotime($selected_month . '-01')));

        foreach ($selected_barangays as $barangay_id) {
            // Check if access already exists
            $check = $conn->prepare("SELECT * FROM midwife_access WHERE midwife_id = ? AND barangay_id = ? AND access_month = ?");
            $check->bind_param("iis", $midwife_id, $barangay_id, $access_month);
            $check->execute();
            $check_result = $check->get_result();

            if ($check_result->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO midwife_access (midwife_id, barangay_id, access_month) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $midwife_id, $barangay_id, $access_month);
                $stmt->execute();
            } else {
                $messages[] = "Already has access for barangay ID $barangay_id on " . date("F Y", strtotime($access_month));
            }
        }
    }

    $success = "Access granted successfully.";
}

// Fetch all access entries
$access_list = $conn->query("
    SELECT ma.id, u.username, b.name AS barangay, ma.access_month
    FROM midwife_access ma
    JOIN users u ON ma.midwife_id = u.id
    JOIN barangays b ON ma.barangay_id = b.id
    ORDER BY ma.access_month DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grant Midwife Access - RHU MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Inter', sans-serif;
        }
        h2, h4 {
            font-family: 'Merriweather', serif;
        }
        .container {
            max-width: 960px;
            margin-top: 40px;
        }
        .form-label {
            font-weight: 600;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .month-box {
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 0.85rem;
        text-align: center;
        min-width: 80px;
    }

    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-calendar2-plus-fill"></i> Grant Midwife Barangay Access</h2>
        <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>

    <!-- Success + Warning Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success d-flex align-items-center" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= $success; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($messages)): ?>
        <div class="alert alert-warning">
            <strong><i class="bi bi-exclamation-circle-fill"></i> Notice:</strong><br>
            <?php foreach ($messages as $msg): ?>
                <div>• <?= $msg; ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Form Section -->
    <div class="card mb-5 shadow-sm">
        <div class="card-header bg-primary text-white">
            <i class="bi bi-person-plus-fill"></i> Assign Barangay Access
        </div>
        <div class="card-body">
            <form method="POST">
                <!-- Midwife Dropdown -->
                <div class="mb-3">
                    <label class="form-label">Select Midwife</label>
                    <select name="midwife_id" class="form-select" required>
                        <option value="">-- Choose Midwife --</option>
                        <?php while ($m = $midwives->fetch_assoc()): ?>
                            <option value="<?= $m['id']; ?>"><?= htmlspecialchars($m['username']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Barangay Multi-Select -->
                <div class="mb-3">
                    <label class="form-label">Select Barangays</label>
                    <select name="barangay_ids[]" class="form-select" multiple required size="6">
                        <?php mysqli_data_seek($barangays, 0); while ($b = $barangays->fetch_assoc()): ?>
                            <option value="<?= $b['id']; ?>"><?= htmlspecialchars($b['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                    <small class="text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple.</small>
                </div>

                <!-- Start Month and Duration -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Start Month</label>
                        <input type="month" name="start_month" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Duration (Months)</label>
                        <select name="month_count" class="form-select" required>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i; ?>"><?= $i; ?> month<?= $i > 1 ? 's' : ''; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-success w-100">
                    <i class="bi bi-check2-circle"></i> Grant Access
                </button>
            </form>
        </div>
    </div>

    <!-- Access Table -->
    <h4 class="mb-3"><i class="bi bi-list-columns-reverse"></i> Access Overview by Midwife</h4>

<?php
$allMonths = ['January','February','March','April','May','June','July','August','September','October','November','December'];

// Fetch all access and group it
$access_by_midwife = [];
mysqli_data_seek($access_list, 0);
while ($row = $access_list->fetch_assoc()) {
    $midwife = $row['username'];
    $barangay = $row['barangay'];
    $month = date('F', strtotime($row['access_month']));
    $access_by_midwife[$midwife][$barangay][] = $month;
}
?>

<?php if (!empty($access_by_midwife)): ?>
    <?php foreach ($access_by_midwife as $midwife => $barangays): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                <strong><i class="bi bi-person-badge-fill"></i> Midwife:</strong> <?= htmlspecialchars($midwife); ?>
            </div>
            <div class="card-body">
                <?php foreach ($barangays as $barangay => $months): ?>
                    <div class="mb-3">
                        <h6 class="mb-2"><i class="bi bi-house-fill text-secondary"></i> <?= htmlspecialchars($barangay); ?></h6>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($allMonths as $m): ?>
                                <div class="month-box <?= in_array($m, $months) ? 'bg-danger text-white fw-bold' : 'bg-light text-muted'; ?>">
                                    <?= $m ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> No barangay access records found.</div>
<?php endif; ?>


</body>
</html>
