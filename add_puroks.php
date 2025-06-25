<?php
session_start();
require 'db.php';

if (!isset($_SESSION['new_barangay_name']) || !isset($_SESSION['purok_count'])) {
    header("Location: add_barangay.php");
    exit();
}

$purok_count = $_SESSION['purok_count'];
$barangay_name = $_SESSION['new_barangay_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("INSERT INTO barangays (name) VALUES (?)");
    $stmt->bind_param("s", $barangay_name);
    $stmt->execute();
    $barangay_id = $stmt->insert_id;

    $purok_stmt = $conn->prepare("INSERT INTO puroks (barangay_id, name) VALUES (?, ?)");

    for ($i = 1; $i <= $purok_count; $i++) {
        $purok_name = $_POST['purok_' . $i];
        $purok_stmt->bind_param("is", $barangay_id, $purok_name);
        $purok_stmt->execute();
    }

    unset($_SESSION['new_barangay_name']);
    unset($_SESSION['purok_count']);

    $success = "Barangay and puroks added successfully.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Puroks</title>
</head>
<body>
    <h2>🏘️ Enter Names of Puroks for <?php echo htmlspecialchars($barangay_name); ?></h2>

    <?php if (isset($success)) {
        echo "<p style='color: green;'>$success</p>";
        echo "<a href='admin_dashboard.php'>← Back to Dashboard</a>";
        exit();
    } ?>

    <form method="POST">
        <?php for ($i = 1; $i <= $purok_count; $i++): ?>
            <label>Purok <?php echo $i; ?> Name:</label><br>
            <input type="text" name="purok_<?php echo $i; ?>" required><br><br>
        <?php endfor; ?>
        <button type="submit">Save Puroks</button>
    </form>
</body>
</html>
