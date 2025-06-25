<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'midwife') {
    header("Location: index.php");
    exit();
}

$midwife_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$current_month = date('Y-m-01'); // e.g. 2025-06-01

// Fetch all barangays
$barangays = $conn->query("SELECT id, name FROM barangays");

// Fetch assigned barangay IDs for this month
$access_stmt = $conn->prepare("
    SELECT barangay_id 
    FROM midwife_access 
    WHERE midwife_id = ? AND access_month = ?
");
$access_stmt->bind_param("is", $midwife_id, $current_month);
$access_stmt->execute();
$access_result = $access_stmt->get_result();

$access_ids = [];
while ($row = $access_result->fetch_assoc()) {
    $access_ids[] = $row['barangay_id'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Midwife Dashboard - RHU MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            background-color: rgb(9, 61, 25);
            color: white;
            padding: 20px;
        }
        .sidebar img {
            width: 100px;
            display: block;
            margin: 0 auto 20px;
        }
        .sidebar a {
            display: block;
            color: white;
            margin: 10px 0;
            text-decoration: none;
        }
        .sidebar a:hover {
            text-decoration: underline;
        }
        .main {
            margin-left: 270px;
            padding: 30px;
        }
        .card:hover {
            box-shadow: 0 0 10px rgba(0,0,0,0.15);
        }
        .access-badge {
            font-size: 0.8rem;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <img src="logo.png" alt="RHU Logo">
    <h5 class="text-center">Welcome, <?php echo htmlspecialchars($username); ?></h5>
    <a href="add_pregnancy.php">➕ Add Pregnancy Record</a>
    <a href="view_pregnancies.php">📋 View Pregnancy Records</a>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="main">
    <h2>🏘️ All Barangays — <?php echo date('F Y'); ?></h2>
    <p class="text-muted">Only accessible barangays (✅) can be clicked.</p>

    <div class="row">
        <?php while ($b = $barangays->fetch_assoc()): ?>
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm text-center">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo htmlspecialchars($b['name']); ?></h5>
                        <?php if (in_array($b['id'], $access_ids)): ?>
                            <a href="midwife_barangay_records.php?barangay_id=<?php echo $b['id']; ?>" class="btn btn-success">✅ View Records</a>
                        <?php else: ?>
                            <span class="badge bg-danger access-badge">❌ No Access</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
