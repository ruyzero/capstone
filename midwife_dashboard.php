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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
        }
        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #094319;
            color: white;
            padding: 20px 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .sidebar img {
            width: 80px;
            margin-bottom: 15px;
        }
        .sidebar h5 {
            font-size: 1rem;
            margin-bottom: 25px;
            text-align: center;
        }
        .sidebar a {
            display: block;
            color: white;
            font-size: 0.95rem;
            text-decoration: none;
            margin: 8px 0;
            width: 100%;
            padding: 8px 12px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .sidebar a:hover {
            background-color: #0d5e26;
        }
        .main {
            margin-left: 270px;
            padding: 40px;
        }
        .card:hover {
            box-shadow: 0 0 8px rgba(0,0,0,0.1);
        }
        .access-badge {
            font-size: 0.8rem;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <img src="logo.png" alt="RHU Logo">
    <h5>👩‍⚕️ Welcome, <br><?= htmlspecialchars($username); ?></h5>
    <a href="add_pregnancy.php"><i class="bi bi-person-plus-fill"></i> Add Pregnancy Record</a>
    <a href="view_pregnancies.php"><i class="bi bi-journal-text"></i> View Pregnancy Records</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="main">
    <h3 class="mb-1"><i class="bi bi-house-door-fill text-primary"></i> Barangays - <?= date('F Y'); ?></h3>
    <p class="text-muted mb-4">You can only view records from barangays assigned to you this month.</p>

    <div class="row">
        <?php while ($b = $barangays->fetch_assoc()): ?>
            <div class="col-md-4 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <h5 class="card-title mb-3"><?= htmlspecialchars($b['name']); ?></h5>
                        <?php if (in_array($b['id'], $access_ids)): ?>
                            <a href="midwife_barangay_records.php?barangay_id=<?= $b['id']; ?>" class="btn btn-outline-success btn-sm w-75">
                                <i class="bi bi-folder2-open"></i> View Records
                            </a>
                        <?php else: ?>
                            <span class="badge bg-secondary access-badge">
                                <i class="bi bi-lock-fill"></i> No Access
                            </span>
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
