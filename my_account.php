<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','super_admin','midwife'], true)) {
    header("Location: index.php"); exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s",$name); $q->execute();
    $ok = (bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}

/* ---------- Identity ---------- */
$role              = $_SESSION['role'];
$uid               = (int)($_SESSION['user_id'] ?? 0);
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? '';

if (!$uid) { die("No user in session."); }

/* ---------- Load current user ---------- */
$u = null;
$st = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$st->bind_param("i", $uid); $st->execute();
$u = $st->get_result()->fetch_assoc(); $st->close();
if (!$u) { die("User not found."); }

/* Sync session role if needed */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== $u['role']) {
    $_SESSION['role'] = $u['role'];
    $role = $u['role'];
}

/* ---------- Municipalities (for super_admin change) ---------- */
$HAS_MUNI = table_exists($conn, 'municipalities');
$municipalities = [];
if ($HAS_MUNI) {
    $q = $conn->prepare("SELECT id, name FROM municipalities ORDER BY name ASC");
    $q->execute(); $res = $q->get_result();
    while ($row = $res->fetch_assoc()) { $municipalities[] = $row; }
    $q->close();

    // Fill $municipality_name if empty
    if ($municipality_name === '' && $u['municipality_id']) {
        foreach ($municipalities as $m) if ((int)$m['id'] === (int)$u['municipality_id']) { $municipality_name = $m['name']; break; }
    }
}

/* ---------- Right rail stats ---------- */
if ($role === 'super_admin') {
    $c1 = $conn->query("SELECT COUNT(*) c FROM pregnant_women");      $tot_patients = (int)($c1->fetch_assoc()['c'] ?? 0);
    $c2 = $conn->query("SELECT COUNT(*) c FROM barangays");           $tot_brgy    = (int)($c2->fetch_assoc()['c'] ?? 0);
} else {
    $ps = $conn->prepare("SELECT COUNT(*) c FROM pregnant_women WHERE municipality_id=?");
    $ps->bind_param("i", $u['municipality_id']); $ps->execute();
    $tot_patients = (int)($ps->get_result()->fetch_assoc()['c'] ?? 0); $ps->close();

    $bs = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id=?");
    $bs->bind_param("i", $u['municipality_id']); $bs->execute();
    $tot_brgy = (int)($bs->get_result()->fetch_assoc()['c'] ?? 0); $bs->close();
}
$tot_pregnant = $tot_patients;

/* ---------- Flash ---------- */
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

/* ---------- Update (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username  = trim($_POST['username'] ?? '');
    $new_full_name = trim($_POST['full_name'] ?? '');
    $new_email     = trim($_POST['email'] ?? '');
    $new_contact   = trim($_POST['contact_no'] ?? '');
    $new_muni_id   = ($role === 'super_admin') ? (int)($_POST['municipality_id'] ?? 0) : (int)$u['municipality_id'];

    $pass1 = trim($_POST['password_new'] ?? '');
    $pass2 = trim($_POST['password_confirm'] ?? '');

    // Basic validation
    if ($new_username === '') { $error = "Username is required."; }
    if (!$error && $pass1 !== '' && $pass1 !== $pass2) { $error = "Passwords do not match."; }

    // Unique username check
    if (!$error) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
        $chk->bind_param("si", $new_username, $uid);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) { $error = "Username is already taken."; }
        $chk->close();
    }

    if (!$error) {
        if ($pass1 !== '') {
            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET username=?, full_name=?, email=?, contact_no=?, municipality_id=?, password=?, password_plain=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssissi", $new_username, $new_full_name, $new_email, $new_contact, $new_muni_id, $hash, $pass1, $uid);
        } else {
            $sql = "UPDATE users SET username=?, full_name=?, email=?, contact_no=?, municipality_id=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssii", $new_username, $new_full_name, $new_email, $new_contact, $new_muni_id, $uid);
        }
        $stmt->execute(); $stmt->close();

        // Refresh current user data & session
        $st = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
        $st->bind_param("i",$uid); $st->execute();
        $u = $st->get_result()->fetch_assoc(); $st->close();

        $_SESSION['username'] = $u['username'];
        $_SESSION['user_id']  = (int)$u['id'];
        $_SESSION['role']     = $u['role'];
        $_SESSION['municipality_id'] = (int)$u['municipality_id'];

        // Update municipality_name in session
        if ($HAS_MUNI && $u['municipality_id']) {
            $mn = '';
            foreach ($municipalities as $m) if ((int)$m['id'] === (int)$u['municipality_id']) { $mn = $m['name']; break; }
            $_SESSION['municipality_name'] = $mn;
            $municipality_name = $mn;
        }

        $_SESSION['success'] = "Account updated successfully.";
        header("Location: my_account.php"); exit();
    } else {
        $_SESSION['error'] = $error;
        header("Location: my_account.php?edit=1"); exit();
    }
}

/* ---------- View/Mode ---------- */
$edit_mode = isset($_GET['edit']) && $_GET['edit'] == '1';
$handle    = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>My Account • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{ --teal-1:#20C4B2; --teal-2:#1A9E9D; --ring:#e5e7eb; --sidebar-w:260px; }
    *{ box-sizing:border-box } body{ margin:0; background:#fff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }
    .main{ padding:24px; background:#fff; }
    .cardx{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; position:relative; overflow:hidden; }
    .big-fade{ position:absolute; inset:0; opacity:.06; display:grid; place-items:center; pointer-events:none; font-size:180px; }
    .edit-link{ font-weight:700; color:#0f766e; text-decoration:underline; }
    .submit-btn{ display:inline-block; padding:10px 24px; border-radius:999px; border:0; background:linear-gradient(135deg,#20C4B2,#1A9E9D); color:#fff; font-weight:800; }
    .right-rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .big{ font-size:48px; font-weight:800; }
</style>
</head>
<body>

<div class="layout">
    <!-- Left Sidebar -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div><div>RHU-MIS</div><small class="text-muted"><?= h(strtoupper($municipality_name)) ?></small></div>
        </div>
        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="view_pregnancies.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts_admin.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link active" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">My Account <i class="bi bi-person-fill"></i></h4>
            <?php if (!$edit_mode): ?>
                <a class="edit-link" href="my_account.php?edit=1">Edit Account <i class="bi bi-pencil"></i></a>
            <?php endif; ?>
        </div>

        <div class="cardx">
            <div class="big-fade"><i class="bi bi-activity"></i></div>

            <?php if (!$edit_mode): ?>
                <p class="fs-5 mb-2"><strong>Username:</strong> <?= h($u['username']) ?></p>
                <p class="fs-5 mb-2"><strong>Password:</strong> <?= h($u['password_plain'] ?? '••••••••') ?></p>
                <p class="fs-5 mb-2"><strong>Email:</strong> <?= h($u['email'] ?? '—') ?></p>
                <p class="fs-5 mb-2"><strong>Full Name:</strong> <?= h($u['full_name'] ?? '—') ?></p>
                <p class="fs-5 mb-2"><strong>Contact No.:</strong> <?= h($u['contact_no'] ?? '—') ?></p>
                <p class="fs-5 mb-0"><strong>Municipality:</strong> <?= h($municipality_name ?: '—') ?></p>
            <?php else: ?>
                <form method="post" action="my_account.php">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?= h($u['username']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= h($u['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?= h($u['full_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact No.</label>
                            <input type="text" name="contact_no" class="form-control" value="<?= h($u['contact_no'] ?? '') ?>">
                        </div>

                        <?php if ($role === 'super_admin' && $HAS_MUNI): ?>
                        <div class="col-md-6">
                            <label class="form-label">Municipality</label>
                            <select name="municipality_id" class="form-select">
                                <option value="0">—</option>
                                <?php foreach ($municipalities as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>" <?= ((int)$u['municipality_id']===(int)$m['id'])?'selected':''; ?>><?= h($m['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <div class="col-md-6">
                            <label class="form-label">Municipality</label>
                            <input type="text" class="form-control" value="<?= h($municipality_name ?: '—') ?>" readonly>
                        </div>
                        <?php endif; ?>

                        <div class="col-12"><hr></div>

                        <div class="col-md-6">
                            <label class="form-label">New Password <small class="text-muted">(leave blank to keep current)</small></label>
                            <input type="password" name="password_new" class="form-control" autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button class="submit-btn" type="submit">Save Changes</button>
                        <a class="btn btn-outline-secondary" href="my_account.php">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <!-- Right rail -->
    <aside class="right-rail">
        <div class="profile d-flex align-items-center gap-2" style="background:#fff;border:1px solid var(--ring);border-radius:16px;padding:14px 16px;">
            <div class="avatar" style="width:44px;height:44px;border-radius:50%;background:#e6fffb;display:grid;place-items:center;color:#0f766e;font-weight:800">
                <i class="bi bi-person"></i>
            </div>
            <div>
                <div class="fw-bold"><?= h($_SESSION['username'] ?? $u['username']) ?></div>
                <div class="text-muted small"><?= h('@'.strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"))) ?></div>
            </div>
        </div>

        <div class="stat">
            <div class="text-muted">Total Patient Record</div>
            <div class="big"><?= $tot_patients ?></div>
        </div>
        <div class="stat" style="color:#fff;border:0;background:linear-gradient(160deg,#20C4B2,#1A9E9D);">
            <div class="text-light">Total Brgy. Health Center</div>
            <div class="big"><?= $tot_brgy ?></div>
        </div>
        <div class="stat">
            <div class="text-muted">Total Pregnant Patient</div>
            <div class="big"><?= $tot_pregnant ?></div>
        </div>
    </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
