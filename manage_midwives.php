<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Fetch all midwives
$midwives = $conn->query("SELECT id, username FROM users WHERE role = 'midwife'");

// Function to get barangay access for a midwife
function getMidwifeAccess($conn, $midwife_id) {
    $stmt = $conn->prepare("
        SELECT b.name, ma.access_month 
        FROM midwife_access ma
        JOIN barangays b ON ma.barangay_id = b.id
        WHERE ma.midwife_id = ?
        ORDER BY ma.access_month DESC
    ");
    $stmt->bind_param("i", $midwife_id);
    $stmt->execute();
    return $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Midwives - RHU-MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .month-box {
            padding: 6px 12px;
            border-radius: 5px;
            font-size: 0.9rem;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-primary">🧑‍⚕️ Manage Midwives</h2>
        <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <div class="mb-3">
        <a href="add_midwife.php" class="btn btn-primary me-2">➕ Register New Midwife</a>
        <a href="grant_access.php" class="btn btn-success">📅 Grant Barangay Access</a>
    </div>

    <?php
    $allMonths = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    ?>

    <?php if ($midwives->num_rows > 0): ?>
        <?php while ($midwife = $midwives->fetch_assoc()): ?>
            <?php $midwife_id = $midwife['id']; ?>
            <div class="card mb-3 shadow">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div><strong>Username:</strong> <?php echo htmlspecialchars($midwife['username']); ?></div>
                    <button class="btn btn-light btn-sm" data-bs-toggle="collapse" data-bs-target="#midwifeDetails<?php echo $midwife_id; ?>" aria-expanded="false" aria-controls="midwifeDetails<?php echo $midwife_id; ?>">
                        View Assigned Area
                    </button>
                </div>

                <div id="midwifeDetails<?php echo $midwife_id; ?>" class="collapse">
                    <div class="card-body">
                        <h6 class="mb-3">📍 Barangay Access:</h6>
                        <?php
                        $access = getMidwifeAccess($conn, $midwife_id);
                        $barangay_map = [];

                        // Organize by barangay name
                        while ($row = $access->fetch_assoc()) {
                            $month = date('F', strtotime($row['access_month']));
                            $barangay_map[$row['name']][] = $month;
                        }
                        ?>

                        <?php if (!empty($barangay_map)): ?>
                            <div class="row">
                                <?php foreach ($barangay_map as $barangay => $months): ?>
                                    <div class="col-md-6 mb-4">
                                        <div class="border p-3 rounded">
                                            <h6 class="text-dark"><?php echo htmlspecialchars($barangay); ?></h6>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ($allMonths as $month): ?>
                                                    <div class="month-box <?php echo in_array($month, $months) ? 'bg-danger text-white fw-bold' : 'bg-light text-muted'; ?>">
                                                        <?php echo $month; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No barangay access granted yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p class="text-muted">No midwives registered yet.</p>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
