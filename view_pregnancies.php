<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Get all pregnancy records with barangay and midwife info
$sql = "
    SELECT 
        p.*, 
        b.name AS barangay, 
        u.username AS midwife 
    FROM pregnant_women p
    LEFT JOIN barangays b ON p.barangay_id = b.id
    LEFT JOIN users u ON p.midwife_id = u.id
    ORDER BY p.id DESC
";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pregnancy Records - RHU MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            margin-top: 40px;
        }
        .table th, .table td {
            vertical-align: middle;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📋 All Pregnancy Records</h2>
        <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if ($result->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-sm align-middle">
                <thead class="table-light text-center">
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Sex</th>
                        <th>Birthdate</th>
                        <th>Birthplace</th>
                        <th>Blood Type</th>
                        <th>Civil Status</th>
                        <th>Spouse</th>
                        <th>Mother's Name</th>
                        <th>Education</th>
                        <th>Employment</th>
                        <th>Address</th>
                        <th>Contact</th>
                        <th>DSWD NHTS</th>
                        <th>4Ps</th>
                        <th>Household No.</th>
                        <th>PhilHealth</th>
                        <th>Status Type</th>
                        <th>PhilHealth No.</th>
                        <th>PCB Member</th>
                        <th>PhilHealth Category</th>
                        <th>Barangay</th>
                        <th>Midwife</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td>
                                <?php 
                                    echo htmlspecialchars($row['first_name'] . ' ' . 
                                                         $row['middle_name'] . ' ' . 
                                                         $row['last_name'] . ' ' . 
                                                         $row['suffix']);
                                ?>
                            </td>
                            <td><?php echo $row['sex']; ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['dob'])); ?></td>
                            <td><?php echo $row['birthplace']; ?></td>
                            <td><?php echo $row['blood_type']; ?></td>
                            <td><?php echo $row['civil_status']; ?></td>
                            <td><?php echo $row['spouse_name']; ?></td>
                            <td><?php echo $row['mother_name']; ?></td>
                            <td><?php echo $row['education']; ?></td>
                            <td><?php echo $row['employment']; ?></td>
                            <td><?php echo $row['address']; ?></td>
                            <td><?php echo $row['contact']; ?></td>
                            <td><?php echo $row['dswd_nhts']; ?></td>
                            <td><?php echo $row['four_ps']; ?></td>
                            <td><?php echo $row['household_no']; ?></td>
                            <td><?php echo $row['philhealth_member']; ?></td>
                            <td><?php echo $row['status_type']; ?></td>
                            <td><?php echo $row['philhealth_no']; ?></td>
                            <td><?php echo $row['pcb_member']; ?></td>
                            <td><?php echo $row['philhealth_category']; ?></td>
                            <td><?php echo $row['barangay']; ?></td>
                            <td><?php echo $row['midwife']; ?></td>
                            <td>
                                <span class="badge bg-<?php echo ($row['status'] === 'completed') ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info">No pregnancy records found.</div>
    <?php endif; ?>
</div>

</body>
</html>
<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Fetch basic pregnancy data
$sql = "
    SELECT 
        p.id, p.first_name, p.middle_name, p.last_name, p.suffix,
        p.dob, p.civil_status, p.address, p.date_registered
    FROM pregnant_women p
    ORDER BY p.id DESC
";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pregnancy Records - RHU MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            margin-top: 40px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📋 Pregnancy Records (Basic View)</h2>
        <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if ($result->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead class="table-light text-center">
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Address</th>
                        <th>Civil Status</th>
                        <th>Age</th>
                        <th>Date Registered</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): 
                        $full_name = $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name'] . ' ' . $row['suffix'];
                        $dob = new DateTime($row['dob']);
                        $today = new DateTime();
                        $age = $today->diff($dob)->y;
                    ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars(trim($full_name)); ?></td>
                            <td><?php echo htmlspecialchars($row['address']); ?></td>
                            <td><?php echo htmlspecialchars($row['civil_status']); ?></td>
                            <td><?php echo $age; ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['date_registered'])); ?></td>
                            <td>
                                <a href="view_pregnancy_detail.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">View</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info">No pregnancy records found.</div>
    <?php endif; ?>
</div>

</body>
</html>
