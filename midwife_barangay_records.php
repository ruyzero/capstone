<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'midwife') {
    header("Location: index.php");
    exit();
}

$midwife_id = $_SESSION['user_id'];
$barangay_id = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;
$current_month = date('Y-m') . '-01';

// Check if the midwife has access to this barangay this month
$access_check = $conn->prepare("
    SELECT b.name 
    FROM midwife_access ma
    JOIN barangays b ON ma.barangay_id = b.id
    WHERE ma.midwife_id = ? AND ma.barangay_id = ? AND ma.access_month = ?
");
$access_check->bind_param("iis", $midwife_id, $barangay_id, $current_month);
$access_check->execute();
$access_result = $access_check->get_result();

if ($access_result->num_rows === 0) {
    echo "<script>alert('You do not have access to this barangay this month.'); window.location.href='midwife_dashboard.php';</script>";
    exit();
}

$barangay_name = $access_result->fetch_assoc()['name'];

// Get all pregnancy records in this barangay
$stmt = $conn->prepare("
    SELECT 
        p.id,
        CONCAT(p.first_name, ' ', p.middle_name, ' ', p.last_name, ' ', IFNULL(p.suffix, '')) AS full_name,
        p.dob,
        p.status
    FROM pregnant_women p
    WHERE p.barangay_id = ? AND p.midwife_id = ?
    ORDER BY p.last_name ASC
");
$stmt->bind_param("ii", $barangay_id, $midwife_id);
$stmt->execute();
$pregnancies = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($barangay_name); ?> - Patient Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            margin-top: 40px;
            max-width: 900px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>🗂️ Records for <?php echo htmlspecialchars($barangay_name); ?></h2>
        <a href="midwife_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if ($pregnancies->num_rows > 0): ?>
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Full Name</th>
                    <th>Date of Birth</th>
                    <th>Status</th>
                    <!-- Future: Edit button -->
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; while ($row = $pregnancies->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><?php echo date('F d, Y', strtotime($row['dob'])); ?></td>
                        <td>
                            <?php if ($row['status'] === 'under_monitoring'): ?>
                                <span class="badge bg-warning text-dark">Under Monitoring</span>
                            <?php else: ?>
                                <span class="badge bg-success">Completed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-info">No pregnancy records found in this barangay yet.</div>
    <?php endif; ?>
</div>

</body>
</html>
