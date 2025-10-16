<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth Guard ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
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

/* ---------- Location (brand + province_id lookup) ---------- */
$location_stmt = $conn->prepare("
    SELECT m.name AS municipality, p.name AS province, r.name AS region, p.id AS province_id
    FROM municipalities m
    LEFT JOIN provinces p ON m.province_id = p.id
    LEFT JOIN regions r   ON p.region_id = r.id
    WHERE m.id = ?
");
$location_stmt->bind_param("i", $municipality_id);
$location_stmt->execute();
$location = $location_stmt->get_result()->fetch_assoc() ?: [];
$municipality_name = $location['municipality'] ?? 'Unknown';
$province_id_from_session = (int)($location['province_id'] ?? 0);
$location_stmt->close();

/* ---------- Stats (right rail) ---------- */
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
$handle = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}") );

/* ---------- Form Processing ---------- */
$error = null; $success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_in  = trim($_POST['username'] ?? '');
    $full_name_in = trim($_POST['full_name'] ?? '');
    $email_in     = trim($_POST['email'] ?? '');
    $contact_in   = trim($_POST['contact_no'] ?? '');
    $password_in  = trim($_POST['password'] ?? '');

    // Basic validations
    if ($username_in === '' || strlen($username_in) < 3) {
        $error = "Username must be at least 3 characters.";
    } elseif ($password_in === '' || strlen($password_in) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($full_name_in === '') {
        $error = "Full name is required.";
    } elseif (!filter_var($email_in, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($contact_in !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contact_in)) {
        $error = "Please enter a valid contact number.";
    } else {
        // Duplicate username
        $checkU = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $checkU->bind_param("s", $username_in);
        $checkU->execute();
        $existsU = (bool)$checkU->get_result()->fetch_row();
        $checkU->close();

        // Duplicate email
        $checkE = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkE->bind_param("s", $email_in);
        $checkE->execute();
        $existsE = (bool)$checkE->get_result()->fetch_row();
        $checkE->close();

        if ($existsU) {
            $error = "Username already exists.";
        } elseif ($existsE) {
            $error = "Email is already registered.";
        } else {
            $hashed_password = password_hash($password_in, PASSWORD_DEFAULT);
            $role = 'midwife';
            $is_active = 1;

            // Province id comes from the admin's session municipality lookup (above)
            $province_id = $province_id_from_session ?: null;

            $stmt = $conn->prepare("
                INSERT INTO users (
                    username, full_name, email, contact_no,
                    password, password_plain, role, is_active,
                    municipality_id, province_id, created_at
                ) VALUES (?,?,?,?,?,?,?, ?, ?, ?, NOW())
            ");
            //            s         s         s      s       s          s        s   i   i   i
            $stmt->bind_param(
                "sssssssiii",
                $username_in, $full_name_in, $email_in, $contact_in,
                $hashed_password, $password_in, $role, $is_active,
                $municipality_id, $province_id
            );

            if ($stmt->execute()) {
                $success = "Midwife account created successfully.";
                $_POST = []; // clear form
            } else {
                $error = "Error creating midwife: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Midwife - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
:root { --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb; --sidebar-w:260px; }
*{ box-sizing:border-box }
body{ margin:0; background:var(--bg); font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
/* Left Sidebar */
.leftbar{ width: var(--sidebar-w); background:#ffffff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
.brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
.brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
.nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
.nav-link:hover{ background:#f2f6f9; color:#0f172a; }
.nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
.nav-link i{ width:22px; text-align:center; margin-right:8px; }
/* Main */
.main{ padding:24px; }
.searchwrap{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--ring); border-radius:14px; padding:10px 14px; max-width:560px; }
.searchwrap input{ border:0; outline:0; width:100%; }
.center-wrap{ display:flex; justify-content:center; }
.card-form{ background:#fff; border:1px solid #eef2f7; border-radius:18px; padding:22px; box-shadow:0 8px 24px rgba(24,39,75,.05); width:100%; max-width:540px; }
.form-control{ border-radius:10px; height:44px; border:1px solid #e5e7eb; }
.btn-teal{ background:linear-gradient(135deg,#1bb4a1,#0ea5a3); color:#fff; border:none; border-radius:999px; height:44px; padding:0 26px; font-weight:600; }
/* Right Rail */
.rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
.profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
.avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
.stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
.stat .label{ color:#6b7280; font-weight:600; }
.stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
.stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg,var(--teal-1),var(--teal-2)); box-shadow:0 10px 28px rgba(16,185,129,.2); }
.stat.gradient .label{ color:#e7fffb; }
@media (max-width:1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column:1 / -1; } }
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

        <h4 class="fw-bold mb-3">➕ Register New Midwife</h4>
        <a class="btn btn-sm btn-outline-secondary mb-3" href="manage_midwives.php">← Back to Midwives List</a>

        <div class="center-wrap">
            <div class="card-form">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= h($success) ?></div>
                <?php elseif ($error): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <div class="mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required value="<?= h($_POST['full_name'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required value="<?= h($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Contact No.</label>
                        <input type="text" name="contact_no" class="form-control" placeholder="+63 9xx xxx xxxx"
                               value="<?= h($_POST['contact_no'] ?? '') ?>">
                    </div>

                    <hr class="my-4">

                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required value="<?= h($_POST['username'] ?? '') ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-teal px-5">Register Midwife</button>
                    </div>

                    <?php if ($municipality_id): ?>
                        <div class="text-muted small mt-3">
                            <i class="bi bi-geo"></i>
                            Saved under <strong>Municipality:</strong> <?= h($municipality_name) ?>
                            <?php if ($province_id_from_session): ?>
                                — <strong>Province ID:</strong> <?= (int)$province_id_from_session ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
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
