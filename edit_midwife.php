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
$session_muni_id = (int)($_SESSION['municipality_id'] ?? 0);
$session_username = $_SESSION['username'] ?? 'admin';

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
    SELECT m.name AS municipality, p.name AS province, r.name AS region, p.id AS province_id
    FROM municipalities m
    LEFT JOIN provinces p ON m.province_id = p.id
    LEFT JOIN regions r   ON p.region_id = r.id
    WHERE m.id = ?
");
$location_stmt->bind_param("i", $session_muni_id);
$location_stmt->execute();
$location = $location_stmt->get_result()->fetch_assoc() ?: [];
$municipality_name = $location['municipality'] ?? 'Unknown';
$session_province_id = (int)($location['province_id'] ?? 0);
$location_stmt->close();

/* ---------- Right-rail stats (same as dashboard) ---------- */
$totalPatients = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM pregnant_women WHERE municipality_id = ? AND sex = 'Female'",
    [$session_muni_id],
    "i"
);
$totalBrgyCenters = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM barangays WHERE municipality_id = ?",
    [$session_muni_id],
    "i"
);
$totalPregnant = $totalPatients;
$handle = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

/* ---------- Load Midwife ---------- */
$midwife_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($midwife_id <= 0) { header("Location: manage_midwives.php"); exit(); }

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

if (!$midwife) { header("Location: manage_midwives.php"); exit(); }

/* ---------- Handle POST (Update) ---------- */
$error = null; $success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name   = trim($_POST['full_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $contact_no  = trim($_POST['contact_no'] ?? '');
    $username_in = trim($_POST['username'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    $new_pw      = (string)($_POST['new_password'] ?? '');
    $confirm_pw  = (string)($_POST['confirm_password'] ?? '');

    // Basic validations
    if ($full_name === '') {
        $error = "Full name is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($username_in === '' || strlen($username_in) < 3) {
        $error = "Username must be at least 3 characters.";
    } elseif ($new_pw !== '' && strlen($new_pw) < 6) {
        $error = "New password must be at least 6 characters.";
    } elseif ($new_pw !== '' && $new_pw !== $confirm_pw) {
        $error = "New password and confirmation do not match.";
    } else {
        // Duplicate username (exclude current)
        $checkU = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $checkU->bind_param("si", $username_in, $midwife_id);
        $checkU->execute();
        $dupeUser = (bool)$checkU->get_result()->fetch_row();
        $checkU->close();

        // Duplicate email (exclude current), allow NULL email duplicates if empty string becomes NULL
        $checkE = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $checkE->bind_param("si", $email, $midwife_id);
        $checkE->execute();
        $dupeEmail = (bool)$checkE->get_result()->fetch_row();
        $checkE->close();

        if ($dupeUser) {
            $error = "Username is already taken.";
        } elseif ($dupeEmail) {
            $error = "Email is already registered to another account.";
        } else {
            // Build update
            if ($new_pw !== '') {
                $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
                $sql = "UPDATE users 
                        SET username = ?, full_name = ?, email = ?, contact_no = ?, is_active = ?, 
                            password = ?, password_plain = ?
                        WHERE id = ? AND role = 'midwife' LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "ssssissi",
                    $username_in, $full_name, $email, $contact_no, $is_active,
                    $hashed, $new_pw,
                    $midwife_id
                );
            } else {
                $sql = "UPDATE users 
                        SET username = ?, full_name = ?, email = ?, contact_no = ?, is_active = ?
                        WHERE id = ? AND role = 'midwife' LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "ssssid",
                    $username_in, $full_name, $email, $contact_no, $is_active,
                    $midwife_id
                );
            }

            if (!$stmt) {
                $error = "Database error during update.";
            } else {
                if ($stmt->execute()) {
                    $success = "Midwife account updated successfully.";
                    // Refresh $midwife data after update
                    $stmt->close();
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
                    $midwife = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                } else {
                    $error = "Failed to update account: " . $stmt->error;
                    $stmt->close();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Midwife - RHU-MIS</title>
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
.center-wrap{ display:flex; justify-content:center; }
.card-form{
    background:#fff; border:1px solid #eef2f7; border-radius:18px; padding:22px;
    box-shadow:0 8px 24px rgba(24,39,75,.05); width:100%; max-width:720px;
}
.form-control{
    border-radius:10px; height:44px; border:1px solid #e5e7eb;
}
.form-check-input{ transform: scale(1.1); }
.btn-teal{
    background:linear-gradient(135deg,#1bb4a1,#0ea5a3);
    color:#fff; border:none; border-radius:999px; height:44px; padding:0 26px; font-weight:600;
}

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

        <div class="d-flex align-items-center justify-content-between mb-2">
            <h4 class="fw-bold m-0">✏️ Edit Midwife</h4>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="manage_midwives.php">← Back to Midwives</a>
                <a class="btn btn-sm btn-outline-primary" href="view_midwife.php?id=<?= (int)$midwife['id'] ?>">View</a>
            </div>
        </div>

        <div class="center-wrap">
            <div class="card-form">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= h($success) ?></div>
                <?php elseif ($error): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <!-- Core Info -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required
                                   value="<?= h($_POST['full_name'] ?? $midwife['full_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= h($_POST['email'] ?? $midwife['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact No.</label>
                            <input type="text" name="contact_no" class="form-control"
                                   value="<?= h($_POST['contact_no'] ?? $midwife['contact_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required
                                   value="<?= h($_POST['username'] ?? $midwife['username'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Role & Status -->
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="midwife" disabled>
                        </div>
                    </div>

                    <!-- Location (read-only) -->
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Municipality</label>
                            <input type="text" class="form-control" value="<?= h($midwife['municipality_name'] ?? '') ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Province</label>
                            <input type="text" class="form-control" value="<?= h($midwife['province_name'] ?? '') ?>" disabled>
                        </div>
                    </div>

                    <!-- Password Reset -->
                    <hr class="my-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password">
                        </div>
                    </div>

                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-teal px-5">Save Changes</button>
                    </div>
                </form>

                <div class="text-muted small mt-3">
                    <i class="bi bi-info-circle"></i>
                    Password fields are optional. If provided, the account’s password will be replaced and stored in both
                    <code>password</code> (hashed) and <code>password_plain</code> (plain) to match your current schema.
                </div>
            </div>
        </div>
    </main>

    <!-- ===== Right Rail ===== -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?= h($session_username) ?></div>
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
