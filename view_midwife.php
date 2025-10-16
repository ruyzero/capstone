<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth Guard ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

/* ---------- Context for Sidebar & Stats ---------- */
$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$username        = $_SESSION['username'] ?? 'admin';

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fetch_count(mysqli $conn, string $sql, array $bind = [], string $types = ''): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types && $bind) $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $stmt->close();
    return (int)($row[0] ?? 0);
}

/* ---------- Location for brand ---------- */
$location_stmt = $conn->prepare("
    SELECT m.name AS municipality, p.name AS province, r.name AS region
    FROM municipalities m
    LEFT JOIN provinces p ON m.province_id = p.id
    LEFT JOIN regions r   ON p.region_id = r.id
    WHERE m.id = ?
");
$location_stmt->bind_param("i", $municipality_id);
$location_stmt->execute();
$location = $location_stmt->get_result()->fetch_assoc() ?: [];
$municipality_name = $location['municipality'] ?? 'Unknown';
$location_stmt->close();

/* ---------- Right-rail stats (same as dashboard) ---------- */
$totalPatients = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM pregnant_women WHERE municipality_id = ? AND sex = 'Female'",
    [$municipality_id],
    "i"
);
$totalBrgyCenters = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM barangays WHERE municipality_id = ?",
    [$municipality_id],
    "i"
);
$totalPregnant = $totalPatients;
$handle = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

/* ---------- Load Midwife ---------- */
$midwife_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$not_found = false; $midwife = null;

if ($midwife_id > 0) {
    $stmt = $conn->prepare("
        SELECT 
            u.id, u.username, u.full_name, u.email, u.contact_no, u.role, u.is_active, 
            u.municipality_id, u.province_id, u.created_at,
            m.name AS municipality_name,
            p.name AS province_name
        FROM users u
        LEFT JOIN municipalities m ON u.municipality_id = m.id
        LEFT JOIN provinces p      ON u.province_id     = p.id
        WHERE u.id = ? AND u.role = 'midwife'
        LIMIT 1
    ");
    $stmt->bind_param("i", $midwife_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $midwife = $res->fetch_assoc();
    $stmt->close();
    if (!$midwife) $not_found = true;
} else {
    $not_found = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Midwife - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
:root{
    --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb;
    --sidebar-w:260px;
}
*{ box-sizing:border-box }
body{ margin:0; background:var(--bg); font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

/* LEFT SIDEBAR */
.leftbar{
    width: var(--sidebar-w);
    background:#ffffff;
    border-right: 1px solid #eef0f3;
    padding: 24px 16px;
    color:#111827;
}
.brand{
    display:flex; gap:10px; align-items:center; margin-bottom:24px;
    font-family: 'Merriweather', serif; font-weight:700; color:#111;
}
.brand .mark{
    width:36px; height:36px; border-radius:50%;
    background: linear-gradient(135deg, #25d3c7, #0fb5aa);
    display:grid; place-items:center; color:#fff; font-weight:800;
}
.nav-link{
    color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600;
}
.nav-link:hover{ background:#f2f6f9; color:#0f172a; }
.nav-link.active{ background: linear-gradient(135deg, #2fd4c8, #0fb5aa); color:#fff; }
.nav-link i{ width:22px; text-align:center; margin-right:8px; }

/* MAIN */
.main{ padding:24px; }
.searchwrap{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--ring); border-radius:14px; padding:10px 14px; max-width:560px; }
.searchwrap input{ border:0; outline:0; width:100%; }
.section-title{ font-weight:800; margin:20px 0 12px; }

.center-wrap{ display:flex; justify-content:center; }
.card-details{
    background:#fff; border:1px solid #eef2f7; border-radius:18px; padding:22px;
    box-shadow:0 8px 24px rgba(24,39,75,.05); width:100%; max-width:720px;
}
.value{ font-weight:600; color:#111827; }
.badge-active{ background:linear-gradient(135deg, #22c55e, #16a34a); }
.badge-inactive{ background:linear-gradient(135deg, #ef4444, #b91c1c); }

/* RIGHT RAIL */
.rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; background:transparent; }
.profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
.avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
.stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
.stat .label{ color:#6b7280; font-weight:600; }
.stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
.stat.gradient{
    color:#fff; border:0; background:linear-gradient(160deg, var(--teal-1), var(--teal-2));
    box-shadow: 0 10px 28px rgba(16,185,129,.2);
}
.stat.gradient .label{ color:#e7fffb; }

@media (max-width: 1100px){
    .layout{ grid-template-columns: var(--sidebar-w) 1fr; }
    .rail{ grid-column: 1 / -1; }
}
</style>
</head>
<body>

<div class="layout">
    <!-- ===== Left Sidebar ===== -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div>RHU-MIS</div>
                <small class="text-muted"><?= h(strtoupper($municipality_name)) ?></small>
            </div>
        </div>
        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="view_pregnancies.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link active" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts_admin.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- ===== Main ===== -->
    <main class="main">
        <div class="searchwrap mb-3">
            <i class="bi bi-search text-muted"></i>
            <input type="text" placeholder="Search Here" aria-label="Search">
        </div>

        <div class="d-flex align-items-center justify-content-between">
            <h4 class="section-title m-0">👩‍⚕️ Midwife Details</h4>
            <div class="d-flex gap-2">
                <a href="manage_midwives.php" class="btn btn-sm btn-outline-secondary">← Back to Midwives</a>
                <?php if (!$not_found && $midwife): ?>
                    <a href="edit_midwife.php?id=<?= (int)$midwife['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="center-wrap mt-3">
            <div class="card-details">
                <?php if ($not_found): ?>
                    <div class="alert alert-danger mb-0">Midwife not found. Please go back to the list.</div>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Full Name</div>
                            <div class="value"><?= h($midwife['full_name'] ?? '') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Username</div>
                            <div class="value"><?= h($midwife['username'] ?? '') ?></div>
                        </div>

                        <div class="col-md-6">
                            <div class="text-muted small">Email</div>
                            <div class="value"><?= h($midwife['email'] ?? '') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Contact No.</div>
                            <div class="value"><?= h($midwife['contact_no'] ?? '') ?></div>
                        </div>

                        <div class="col-md-4">
                            <div class="text-muted small">Role</div>
                            <div class="value text-capitalize"><?= h($midwife['role'] ?? '') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Status</div>
                            <?php if ((int)($midwife['is_active'] ?? 0) === 1): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Created At</div>
                            <div class="value">
                                <?= h($midwife['created_at'] ? date('M d, Y g:i A', strtotime($midwife['created_at'])) : '') ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="text-muted small">Municipality</div>
                            <div class="value">
                                <?= h($midwife['municipality_name'] ?? '') ?>
                                <?php if (!empty($midwife['municipality_id'])): ?>
                                    <span class="text-muted"> (ID: <?= (int)$midwife['municipality_id'] ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Province</div>
                            <div class="value">
                                <?= h($midwife['province_name'] ?? '') ?>
                                <?php if (!empty($midwife['province_id'])): ?>
                                    <span class="text-muted"> (ID: <?= (int)$midwife['province_id'] ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="text-muted small">
                        <i class="bi bi-info-circle"></i>
                        Passwords are not displayed here for security. Use <strong>Edit</strong> to reset credentials if needed.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- ===== Right Rail ===== -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?= h($username) ?></div>
                <div class="text-muted small"><?= h($handle) ?></div>
            </div>
        </div>

        <div class="stat">
            <div class="label">Total Patient Record</div>
            <div class="big"><?= $totalPatients ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?= $totalBrgyCenters ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?= $totalPregnant ?></div>
        </div>
    </aside>
</div>

</body>
</html>
