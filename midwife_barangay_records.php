<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'midwife') {
    header("Location: index.php");
    exit();
}

$midwife_id  = (int)($_SESSION['user_id'] ?? 0);
$username    = $_SESSION['username'] ?? 'midwife';
$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$barangay_id = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;

$current_month_exact = date('Y-m-01');
$current_month_like  = date('Y-m');

// --- Access check (works for both DATE and VARCHAR month fields) ---
$barangay_name = null;
$q1 = $conn->prepare("
    SELECT b.name 
    FROM midwife_access ma
    JOIN barangays b ON b.id = ma.barangay_id
    WHERE ma.midwife_id = ? AND ma.barangay_id = ? AND ma.access_month = ?
");
$q1->bind_param("iis", $midwife_id, $barangay_id, $current_month_exact);
$q1->execute();
$r1 = $q1->get_result();
if ($r1 && $r1->num_rows > 0) {
    $barangay_name = $r1->fetch_assoc()['name'];
}
$q1->close();

if ($barangay_name === null) {
    $like = $current_month_like . '%';
    $q2 = $conn->prepare("
        SELECT b.name 
        FROM midwife_access ma
        JOIN barangays b ON b.id = ma.barangay_id
        WHERE ma.midwife_id = ? AND ma.barangay_id = ? AND ma.access_month LIKE ?
    ");
    $q2->bind_param("iis", $midwife_id, $barangay_id, $like);
    $q2->execute();
    $r2 = $q2->get_result();
    if ($r2 && $r2->num_rows > 0) {
        $barangay_name = $r2->fetch_assoc()['name'];
    }
    $q2->close();
}

if ($barangay_name === null) {
    echo "<script>alert('You do not have access to this barangay this month.'); window.location.href='midwife_patients.php';</script>";
    exit();
}

// --- Get pregnancy records for this barangay ---
$stmt = $conn->prepare("
    SELECT 
        p.id,
        TRIM(CONCAT(
            IFNULL(p.first_name, ''), ' ',
            IFNULL(p.middle_name, ''), ' ',
            IFNULL(p.last_name, ''), 
            IF(p.suffix IS NULL OR p.suffix = '', '', CONCAT(' ', p.suffix))
        )) AS full_name,
        p.dob,
        p.status
    FROM pregnant_women p
    WHERE p.barangay_id = ?
    ORDER BY p.last_name ASC, p.first_name ASC
");
$stmt->bind_param("i", $barangay_id);
$stmt->execute();
$pregnancies = $stmt->get_result();

/* ---------- Totals for right rail ---------- */
$tot_pat = $conn->query("
    SELECT COUNT(p.id)
    FROM pregnant_women p
    INNER JOIN barangays b ON b.id = p.barangay_id
    WHERE b.municipality_id = $municipality_id AND UPPER(p.sex)='FEMALE'
")->fetch_row();
$totalPatients = (int)($tot_pat[0] ?? 0);

$tot_brgy = $conn->query("SELECT COUNT(*) FROM barangays WHERE municipality_id = $municipality_id")->fetch_row();
$totalBrgyCenters = (int)($tot_brgy[0] ?? 0);

$totalPregnant = $totalPatients;

$handle = '@' . strtolower(preg_replace('/\s+/', '', "midwife{$barangay_name}"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($barangay_name); ?> - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
:root{
    --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb; --sidebar-w:260px;
}
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;}
.layout{display:grid;grid-template-columns:var(--sidebar-w) 1fr 320px;min-height:100vh;}
.leftbar{
    width:var(--sidebar-w);background:#fff;border-right:1px solid #eef0f3;
    padding:24px 16px;color:#111827;
}
.brand{
    display:flex;gap:10px;align-items:center;margin-bottom:24px;
    font-family:'Merriweather',serif;font-weight:700;color:#111;
}
.brand .mark{
    width:36px;height:36px;border-radius:50%;
    background:linear-gradient(135deg,#25d3c7,#0fb5aa);
    display:grid;place-items:center;color:#fff;font-weight:800;
}
.nav-link{
    color:#6b7280;border-radius:10px;padding:.6rem .8rem;font-weight:600;
}
.nav-link:hover{background:#f2f6f9;color:#0f172a;}
.nav-link.active{background:linear-gradient(135deg,#2fd4c8,#0fb5aa);color:#fff;}
.nav-link i{width:22px;text-align:center;margin-right:8px;}
.main{padding:24px;}
.section-title{font-weight:800;margin:10px 0 20px;}
.table thead th{background:#f9fafb;}
.rail{padding:24px 18px;display:flex;flex-direction:column;gap:18px;background:transparent;}
.profile{background:#fff;border:1px solid var(--ring);border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;}
.avatar{width:44px;height:44px;border-radius:50%;background:#e6fffb;display:grid;place-items:center;color:#0f766e;font-weight:800;}
.stat{background:#fff;border:1px solid var(--ring);border-radius:16px;padding:16px;text-align:center;}
.stat .label{color:#6b7280;font-weight:600;}
.stat .big{font-size:64px;font-weight:800;line-height:1;color:#111827;}
.stat.gradient{color:#fff;border:0;background:linear-gradient(160deg,var(--teal-1),var(--teal-2));box-shadow:0 10px 28px rgba(16,185,129,.2);}
.stat.gradient .label{color:#e7fffb;}
@media(max-width:1100px){.layout{grid-template-columns:var(--sidebar-w) 1fr;}.rail{grid-column:1 / -1;}}
</style>
</head>
<body>

<div class="layout">
    <!-- Sidebar -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div>RHU-MIS</div>
                <small class="text-muted"><?php echo strtoupper($barangay_name); ?></small>
            </div>
        </div>
        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="midwife_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link active" href="midwife_patients.php"><i class="bi bi-files"></i> Patients</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="section-title"><i class="bi bi-folder2-open text-success"></i> Records for <?php echo htmlspecialchars($barangay_name); ?></h4>
            <a href="midwife_patients.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <?php if ($pregnancies && $pregnancies->num_rows > 0) { ?>
            <div class="table-responsive mt-3">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">#</th>
                            <th>Full Name</th>
                            <th style="width:200px;">Date of Birth</th>
                            <th style="width:220px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $i = 1; while ($row = $pregnancies->fetch_assoc()) { ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td><?php echo !empty($row['dob']) && $row['dob']!='0000-00-00' ? date('F d, Y', strtotime($row['dob'])) : '—'; ?></td>
                            <td>
                                <?php
                                $status = strtolower(trim($row['status']));
                                if ($status === 'under_monitoring') {
                                    echo '<span class="badge bg-warning text-dark">Under Monitoring</span>';
                                } elseif ($status === 'completed') {
                                    echo '<span class="badge bg-success">Completed</span>';
                                } elseif ($status === 'pregnant') {
                                    echo '<span class="badge bg-info text-dark">Active / Pregnant</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">'.htmlspecialchars($row['status']).'</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="alert alert-info mt-4">No pregnancy records found in this barangay yet.</div>
        <?php } ?>
    </main>

    <!-- Right rail -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?php echo htmlspecialchars($username); ?></div>
                <div class="text-muted small"><?php echo htmlspecialchars($handle); ?></div>
            </div>
        </div>

        <div class="stat">
            <div class="label">Total Patient Record</div>
            <div class="big"><?php echo $totalPatients; ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?php echo $totalBrgyCenters; ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?php echo $totalPregnant; ?></div>
        </div>
    </aside>
</div>
</body>
</html>
