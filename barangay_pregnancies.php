<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Fetch all barangays
$barangays = $conn->query("SELECT id, name FROM barangays");

// Function to fetch pregnancies for a barangay
function getPregnanciesByBarangay($conn, $barangay_id) {
    $stmt = $conn->prepare("
        SELECT 
            CONCAT(p.first_name, ' ', p.middle_name, ' ', p.last_name, ' ', IFNULL(p.suffix, '')) AS full_name,
            p.dob, p.status, u.username AS midwife
        FROM pregnant_women p
        LEFT JOIN users u ON p.midwife_id = u.id
        WHERE p.barangay_id = ?
    ");
    $stmt->bind_param("i", $barangay_id);
    $stmt->execute();
    return $stmt->get_result();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Barangay Pregnancy Records - RHU MIS</title>
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
        <h2>🏘️ Barangay Pregnancy Records</h2>
        <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if ($barangays->num_rows > 0): ?>
        <div class="accordion" id="barangayAccordion">
            <?php $i = 0; while ($barangay = $barangays->fetch_assoc()): ?>
                <?php $pregnancies = getPregnanciesByBarangay($conn, $barangay['id']); ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading<?php echo $i; ?>">
                        <button class="accordion-button <?php echo $i > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $i; ?>" aria-expanded="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars($barangay['name']); ?> (<?php echo $pregnancies->num_rows; ?> record<?php echo $pregnancies->num_rows !== 1 ? 's' : ''; ?>)
                        </button>
                    </h2>
                    <div id="collapse<?php echo $i; ?>" class="accordion-collapse collapse <?php echo $i === 0 ? 'show' : ''; ?>" data-bs-parent="#barangayAccordion">
                        <div class="accordion-body">
                            <?php if ($pregnancies->num_rows > 0): ?>
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Full Name</th>
                                            <th>Date of Birth</th>
                                            <th>Status</th>
                                            <th>Assigned Midwife</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($p = $pregnancies->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($p['full_name']); ?></td>
                                                <td><?php echo $p['dob']; ?></td>
                                                <td><?php echo ucfirst($p['status']); ?></td>
                                                <td><?php echo htmlspecialchars($p['midwife'] ?? 'Not assigned'); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="text-muted">No pregnancy records found for this barangay.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php $i++; endwhile; ?>
        </div>
    <?php else: ?>
        <p>No barangays found.</p>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
