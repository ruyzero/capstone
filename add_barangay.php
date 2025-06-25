<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$count_result = $conn->query("SELECT COUNT(*) AS total FROM barangays");
$total = $count_result->fetch_assoc()['total'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $total < 55) {
    $_SESSION['new_barangay_name'] = $_POST['barangay_name'];
    $_SESSION['purok_count'] = $_POST['purok_count'];
    header("Location: add_puroks.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Barangay</title>
</head>
<body>
    <h2>🏘️ Add New Barangay with Puroks</h2>
    <a href="admin_dashboard.php">← Back to Dashboard</a><br><br>

    <p><strong>Total Barangays:</strong> <?php echo $total; ?> / 55</p>

    <?php if ($total < 55): ?>
    <form method="POST">
        <label>Barangay Name:</label><br>
        <input type="text" name="barangay_name" required><br><br>

        <label>Number of Puroks:</label><br>
        <input type="number" name="purok_count" min="1" max="20" required><br><br>

        <button type="submit">Continue to Puroks</button>
    </form>
    <?php else: ?>
        <p style="color: orange;">Barangay limit of 55 reached.</p>
    <?php endif; ?>
</body>
</html>
