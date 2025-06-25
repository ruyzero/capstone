<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Fetch recent announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY date_created DESC LIMIT 5");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - RHU-MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            font-family: 'Inter', sans-serif;
        }
        .sidebar {
            width: 250px;
            background-color:rgb(4, 94, 36);
            color: white;
        }
        .sidebar a {
            color: white;
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            transition: background 0.2s;
        }
        .sidebar a:hover {
            background-color: #0056b3;
        }
        .sidebar .logo {
            text-align: center;
            padding: 20px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .sidebar .logo img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            margin-bottom: 10px;
        }
        .main-content {
            flex-grow: 1;
            padding: 40px;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column">
        <div class="logo">
            <img src="logo.png" alt="RHU Logo">
            <h5>RHU-MIS</h5>
        </div>
        <a href="view_pregnancies.php">📋 Prenatal Records</a>
        <a href="manage_announcements.php">📢 Manage Announcements</a>
        <a href="manage_midwives.php">🧑‍⚕️ Manage Midwives</a>
        <a href="grant_access.php">📅 Grant Barangay Access</a>
        <a href="add_pregnancy.php">➕ Add Pregnancy Record</a>
        <a href="barangay_pregnancies.php">🏘️ Barangay Health Centers</a>
        <a href="add_barangay.php">🏘️ Add Barangay</a>
        <a href="logout.php">🚪 Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <h1 class="mb-4">Welcome, Admin <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>

        <div class="mb-4">
            <h4>📢 Recent Announcements</h4>
            <?php if ($announcements->num_rows > 0): ?>
                <?php while ($row = $announcements->fetch_assoc()): ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <strong><?php echo htmlspecialchars($row['title']); ?></strong>
                            <span class="text-muted float-end"><?php echo date('F j, Y g:i A', strtotime($row['date_created'])); ?></span>
                        </div>
                        <div class="card-body">
                            <p class="card-text"><?php echo nl2br(htmlspecialchars($row['message'])); ?></p>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No announcements posted yet.</p>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
