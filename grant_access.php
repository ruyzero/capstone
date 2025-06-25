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
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            max-width: 1000px;
            margin-top: 40px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📅 Grant Midwife Barangay Access</h2>
        <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if (!empty($messages)): ?>
        <div class="alert alert-warning">
            <strong>Notice:</strong><br>
            <?php foreach ($messages as $msg) echo "<div>• $msg</div>"; ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">Assign Barangay Access to Midwife</div>
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Select Midwife:</label>
                    <select name="midwife_id" class="form-select" required>
                        <option value="">-- Choose Midwife --</option>
                        <?php while ($m = $midwives->fetch_assoc()): ?>
                            <option value="<?php echo $m['id']; ?>"><?php echo $m['username']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Select Barangays:</label>
                    <select name="barangay_ids[]" class="form-select" multiple required size="6">
                        <?php mysqli_data_seek($barangays, 0); while ($b = $barangays->fetch_assoc()): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                        <?php endwhile; ?>
                    </select>
                    <small class="text-muted">Hold Ctrl (Windows) or Command (Mac) to select multiple.</small>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Start Month (YYYY-MM):</label>
                        <input type="month" name="start_month" class="form-control" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Duration (Months):</label>
                        <select name="month_count" class="form-select" required>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?> month<?php echo $i > 1 ? 's' : ''; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success w-100">Grant Access</button>
            </form>
        </div>
    </div>

    <h4 class="mb-3">🗂️ Existing Access Records</h4>
    <table class="table table-bordered bg-white">
        <thead class="table-light">
            <tr>
                <th>Midwife</th>
                <th>Barangay</th>
                <th>Access Month</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $access_list->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td><?php echo htmlspecialchars($row['barangay']); ?></td>
                    <td><?php echo date("F Y", strtotime($row['access_month'])); ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
