<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* --------------------------- Helpers --------------------------- */
function validateRegionProvinceMunicipality(mysqli $conn, int $region_id, int $province_id, int $municipality_id): bool {
    $sql = "
        SELECT 1
        FROM provinces p
        JOIN municipalities m ON m.province_id = p.id
        WHERE p.id = ? AND p.region_id = ? AND m.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $province_id, $region_id, $municipality_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}
function looksHashed(string $pwd): bool {
    return (bool)preg_match('/^\$2[aby]\$|\$argon2(id|i|d)\$/', $pwd);
}

/* --------------------------- Actions --------------------------- */
/*
POST actions:

1) Approve a request (creates a NEW admin user):
   - approve_request_id

2) Toggle an existing admin ON/OFF:
   - toggle_user_id, desired_state (0|1)

3) Create admin manually (modal form):
   - create_admin_manual=1 plus form fields
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active_tab = $_POST['active_tab'] ?? 'requests';
    $q  = urlencode($_POST['q']  ?? '');
    $qa = urlencode($_POST['qa'] ?? '');

    /* Toggle an existing admin user */
    if (isset($_POST['toggle_user_id'])) {
        $user_id = (int)$_POST['toggle_user_id'];
        $desired = (int)($_POST['desired_state'] ?? 0);

        $u = $conn->prepare("UPDATE users SET is_active=? WHERE id=? AND role='admin'");
        $u->bind_param("ii", $desired, $user_id);
        if ($u->execute() && $u->affected_rows >= 0) {
            $_SESSION['success'] = $desired ? "Admin account activated." : "Admin account deactivated.";
        } else {
            $_SESSION['error'] = "Failed to update admin status.";
        }
        $u->close();

        header("Location: manage_accounts.php?tab={$active_tab}&q={$q}&qa={$qa}");
        exit();
    }

    /* Approve a pending request => create NEW admin */
    if (isset($_POST['approve_request_id'])) {
        $rid = (int)$_POST['approve_request_id'];

        $stmt = $conn->prepare("
            SELECT id, full_name, email, username, password, contact_no,
                   region_id, province_id, municipality_id, num_barangays, zip_code, status
            FROM pending_admin_requests
            WHERE id = ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->bind_param("i", $rid);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) {
            $_SESSION['error'] = "Pending request not found.";
            header("Location: manage_accounts.php?tab={$active_tab}&q={$q}&qa={$qa}");
            exit();
        }

        if (!validateRegionProvinceMunicipality($conn, (int)$req['region_id'], (int)$req['province_id'], (int)$req['municipality_id'])) {
            $_SESSION['error'] = "Invalid Region/Province/Municipality mapping.";
            header("Location: manage_accounts.php?tab={$active_tab}&q={$q}&qa={$qa}");
            exit();
        }

        // unique username/email across USERS
        $du = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
        $du->bind_param("ss", $req['username'], $req['email']);
        $du->execute();
        if ($du->get_result()->num_rows > 0) {
            $du->close();
            $_SESSION['error'] = "Username or email already exists.";
            header("Location: manage_accounts.php?tab={$active_tab}&q={$q}&qa={$qa}");
            exit();
        }
        $du->close();

        $password_plain = $req['password']; // plaintext saved in request
        $password_hash  = looksHashed($password_plain) ? $password_plain : password_hash($password_plain, PASSWORD_DEFAULT);

        // Create the admin (ACTIVE)
        $ins = $conn->prepare("
            INSERT INTO users
                (username, password, password_plain, role, is_active, municipality_id, province_id, full_name, email, contact_no)
            VALUES (?, ?, ?, 'admin', 1, ?, ?, ?, ?, ?)
        ");
        // 8 placeholders => "sssiisss"
        $ins->bind_param(
            "sssiisss",
            $req['username'],           // s
            $password_hash,             // s
            $password_plain,            // s
            $req['municipality_id'],    // i
            $req['province_id'],        // i
            $req['full_name'],          // s
            $req['email'],              // s
            $req['contact_no']          // s
        );
        if (!$ins->execute()) {
            $ins->close();
            $_SESSION['error'] = "Failed to create admin account (DB error).";
            header("Location: manage_accounts.php?tab={$active_tab}&q={$q}&qa={$qa}");
            exit();
        }
        $ins->close();

        // Update municipality meta
        $um = $conn->prepare("UPDATE municipalities SET num_barangays=?, zip_code=? WHERE id=?");
        $um->bind_param("isi", $req['num_barangays'], $req['zip_code'], $req['municipality_id']);
        $um->execute();
        $um->close();

        // Mark request approved
        $up = $conn->prepare("UPDATE pending_admin_requests SET status='approved' WHERE id=?");
        $up->bind_param("i", $rid);
        $up->execute();
        $up->close();

        $_SESSION['success'] = "Request approved. New admin account created.";
        header("Location: manage_accounts.php?tab={$active_tab}&q={$q}&qa={$qa}");
        exit();
    }

    /* Create Admin Manually (modal) */
    if (isset($_POST['create_admin_manual'])) {
        $full_name      = trim($_POST['full_name'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $username       = trim($_POST['username'] ?? '');
        $password_plain = trim($_POST['password_plain'] ?? '');
        $contact_no     = trim($_POST['contact_no'] ?? '');
        $region_id      = (int)($_POST['region_id'] ?? 0);
        $province_id    = (int)($_POST['province_id'] ?? 0);
        $municipality_id= (int)($_POST['municipality_id'] ?? 0);
        $num_barangays  = (int)($_POST['num_barangays'] ?? 0);
        $zip_code       = trim($_POST['zip_code'] ?? '');

        if ($full_name===''||$email===''||$username===''||$password_plain===''||
            !$region_id||!$province_id||!$municipality_id) {
            $_SESSION['error'] = "Please complete all required fields.";
            header("Location: manage_accounts.php?tab=admins&q={$q}&qa={$qa}");
            exit();
        }

        if (!validateRegionProvinceMunicipality($conn, $region_id, $province_id, $municipality_id)) {
            $_SESSION['error'] = "Invalid Region/Province/Municipality mapping.";
            header("Location: manage_accounts.php?tab=admins&q={$q}&qa={$qa}");
            exit();
        }

        // block duplicate username/email across USERS
        $du = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
        $du->bind_param("ss", $username, $email);
        $du->execute();
        if ($du->get_result()->num_rows > 0) {
            $du->close();
            $_SESSION['error'] = "Username or email already exists.";
            header("Location: manage_accounts.php?tab=admins&q={$q}&qa={$qa}");
            exit();
        }
        $du->close();

        // optional: also block against pending requests to avoid confusion
        $dr = $conn->prepare("SELECT id FROM pending_admin_requests WHERE (username=? OR email=?) AND status='pending' LIMIT 1");
        $dr->bind_param("ss", $username, $email);
        $dr->execute();
        if ($dr->get_result()->num_rows > 0) {
            $dr->close();
            $_SESSION['error'] = "A pending request with the same username/email exists.";
            header("Location: manage_accounts.php?tab=admins&q={$q}&qa={$qa}");
            exit();
        }
        $dr->close();

        $password_hash = password_hash($password_plain, PASSWORD_DEFAULT);

        // Insert user (ACTIVE)
        $ins = $conn->prepare("
            INSERT INTO users
                (username, password, password_plain, role, is_active, municipality_id, province_id, full_name, email, contact_no)
            VALUES (?, ?, ?, 'admin', 1, ?, ?, ?, ?, ?)
        ");
        // 8 placeholders
        $ins->bind_param(
            "sssiisss",
            $username,             // s
            $password_hash,        // s
            $password_plain,       // s
            $municipality_id,      // i
            $province_id,          // i
            $full_name,            // s
            $email,                // s
            $contact_no            // s
        );
        if (!$ins->execute()) {
            $ins->close();
            $_SESSION['error'] = "Failed to create admin (DB error).";
            header("Location: manage_accounts.php?tab=admins&q={$q}&qa={$qa}");
            exit();
        }
        $ins->close();

// Update municipality meta if provided
if ($num_barangays > 0 || $zip_code !== '') {
    $um = $conn->prepare("
        UPDATE municipalities
        SET
            num_barangays = CASE WHEN ? > 0 THEN ? ELSE num_barangays END,
            zip_code      = CASE WHEN ? <> '' THEN ? ELSE zip_code END
        WHERE id = ?
    ");
    // 5 placeholders => types "iissi"
    $um->bind_param("iissi", $num_barangays, $num_barangays, $zip_code, $zip_code, $municipality_id);
    $um->execute();
    $um->close();
}


        $_SESSION['success'] = "Admin created successfully.";
        header("Location: manage_accounts.php?tab=admins&q={$q}&qa={$qa}");
        exit();
    }
}

/* --------------------------- Fetch data (UI) --------------------------- */
$activeTab = $_GET['tab'] ?? 'requests';

/* Regions for the manual-create modal */
$regionsRes = $conn->query("SELECT id, name FROM regions ORDER BY name ASC");

/* Requests tab data */
$q  = trim($_GET['q'] ?? '');
$qLike = '%' . $q . '%';
$sqlReq = "
    SELECT 
        r.id, r.full_name, r.email, r.username, r.password, r.contact_no,
        r.num_barangays, r.zip_code, r.status, r.date_requested,
        r.municipality_id, m.name AS municipality_name
    FROM pending_admin_requests r
    JOIN municipalities m ON m.id = r.municipality_id
    WHERE r.status IN ('pending','declined')
";
$typesReq = ''; $paramsReq = [];
if ($q !== '') {
    $sqlReq .= " AND (r.full_name LIKE ? OR r.username LIKE ? OR r.email LIKE ? OR m.name LIKE ?) ";
    $typesReq .= 'ssss';
    $paramsReq = [$qLike, $qLike, $qLike, $qLike];
}
$sqlReq .= " ORDER BY r.date_requested DESC, r.id DESC ";
$stmtReq = $conn->prepare($sqlReq);
if ($typesReq !== '') { $stmtReq->bind_param($typesReq, ...$paramsReq); }
$stmtReq->execute();
$requests = $stmtReq->get_result();
$stmtReq->close();

/* Admins tab data – all admin users, per-user toggle */
$qa = trim($_GET['qa'] ?? '');
$qaLike = '%' . $qa . '%';
$sqlAdmins = "
    SELECT 
        u.id, u.username, u.full_name, u.email, u.contact_no, u.is_active, u.password_plain,
        m.id AS municipality_id, m.name AS municipality_name, m.zip_code, m.num_barangays,
        p.name AS province_name, rg.name AS region_name
    FROM users u
    LEFT JOIN municipalities m ON m.id = u.municipality_id
    LEFT JOIN provinces p ON p.id = u.province_id
    LEFT JOIN regions rg ON rg.id = p.region_id
    WHERE u.role='admin'
";
$typesA = ''; $paramsA = [];
if ($qa !== '') {
    $sqlAdmins .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR m.name LIKE ? OR p.name LIKE ? OR rg.name LIKE ?) ";
    $typesA .= 'ssssss';
    $paramsA = [$qaLike, $qaLike, $qaLike, $qaLike, $qaLike, $qaLike];
}
$sqlAdmins .= " ORDER BY u.is_active DESC, rg.name, p.name, m.name, u.username ";
$stmtA = $conn->prepare($sqlAdmins);
if ($typesA !== '') { $stmtA->bind_param($typesA, ...$paramsA); }
$stmtA->execute();
$admins = $stmtA->get_result();
$stmtA->close();

/* Header profile (optional) */
$username_display = htmlspecialchars($_SESSION['username'] ?? 'superadmin');
$handle_display   = '@' . preg_replace('/\s+/', '', strtolower($username_display));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Accounts - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
    :root{ --sidebar-bg:#0ea5a3; --sidebar-active:#0b8a89; --row-hover:#f8fafc; }
    body{ background:#f7f9fb; }
    .app{ display:grid; grid-template-columns: 260px 1fr; gap:24px; min-height:100vh; }
    .sidebar{ background:var(--sidebar-bg); color:#fff; padding:18px 14px; }
    .brand{ display:flex; align-items:center; gap:10px; margin-bottom:18px; font-weight:700; }
    .brand .logo{ width:36px;height:36px;border-radius:8px;background:#fff;display:grid;place-items:center;color:#0ea5a3; font-weight:800; }
    .navlink{ display:flex; align-items:center; gap:10px; color:#e6fffb; text-decoration:none; padding:10px 12px; border-radius:10px; margin:4px 6px; }
    .navlink:hover{ background:rgba(255,255,255,.15); color:#fff; }
    .navlink.active{ background:var(--sidebar-active); color:#fff; }

    .main{ padding:22px 0; }
    .page-title{ font-weight:800; letter-spacing:.2px; }

    .searchbar{ display:flex; gap:10px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:8px 12px; max-width:520px; }

    .table-wrap{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:12px; }
    .table thead th{ background:#f8fafc; color:#0f172a; font-weight:700; border-bottom:0; }
    .table tbody tr:hover{ background:var(--row-hover); }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size:12px; }

    .switch { position: relative; display: inline-block; width: 56px; height: 30px; }
    .switch input { display:none; }
    .slider { position: absolute; cursor: pointer; top:0; left:0; right:0; bottom:0; background: #d1d5db; transition: .3s; border-radius: 999px; box-shadow: inset 0 0 0 2px rgba(0,0,0,.06); }
    .slider:before { position: absolute; content: ""; height: 24px; width: 24px; left: 3px; top: 3px; background: #fff; transition: .3s; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,.12); }
    input:checked + .slider { background: #10b981; }
    input:checked + .slider:before { transform: translateX(26px); }

    .badge-status { border-radius:999px; padding:4px 10px; font-weight:600; }
    .badge-active { background:#e7f9ef; color:#065f46; border:1px solid #b6f4d2; }
    .badge-inactive { background:#fff1f2; color:#9f1239; border:1px solid #fecdd3; }

    .btn-primary-soft{ background:#0ea5a3; border:none; }
    .btn-primary-soft:hover{ background:#0c8f8e; }
</style>
</head>
<body>
<div class="app">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="brand"><div class="logo">✚</div><div>RHU-MIS</div></div>
        <a class="navlink" href="super_admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a class="navlink" href="#"><i class="bi bi-people"></i> Patients</a>
        <a class="navlink" href="#"><i class="bi bi-person-badge"></i> Midwives</a>
        <a class="navlink" href="#"><i class="bi bi-megaphone"></i> Announcements</a>
        <a class="navlink" href="#"><i class="bi bi-building"></i> Brgy. Health Centers</a>
        <a class="navlink" href="#"><i class="bi bi-heart-pulse"></i> Prenatal Monitoring</a>
        <a class="navlink" href="#"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
        <a class="navlink active" href="manage_accounts.php"><i class="bi bi-person-gear"></i> Manage Accounts</a>
        <div class="mt-4">
            <a class="navlink" href="#"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="navlink" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </div>
    </aside>

    <!-- Main -->
    <main class="main container-fluid">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="page-title mb-0"><i class="bi bi-people-gear me-2"></i>Manage Accounts</h3>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab==='requests'?'active':'' ?>" href="manage_accounts.php?tab=requests">Municipality/Admin Requests</a>
            </li>
            <li class="nav-item d-flex align-items-center">
                <a class="nav-link <?= $activeTab==='admins'?'active':'' ?>" href="manage_accounts.php?tab=admins">Existing Admins</a>
            </li>
        </ul>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php elseif (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if ($activeTab === 'admins'): ?>
            <!-- Existing Admins TAB -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <form method="get" class="searchbar">
                    <input type="hidden" name="tab" value="admins">
                    <i class="bi bi-search text-muted"></i>
                    <input type="text" class="form-control border-0 p-0" name="qa" placeholder="Search admins..." value="<?= htmlspecialchars($qa) ?>">
                    <?php if ($qa !== ''): ?><a href="manage_accounts.php?tab=admins" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                </form>
                <button class="btn btn-primary-soft" data-bs-toggle="modal" data-bs-target="#createAdminModal">
                    <i class="bi bi-person-plus me-1"></i> Create Admin Manually
                </button>
            </div>

            <div class="table-wrap">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="min-width:160px;">Full Name</th>
                                <th>Username</th>
                                <th>Password</th>
                                <th>Email</th>
                                <th>Contact No.</th>
                                <th>Region</th>
                                <th>Province</th>
                                <th>Municipality</th>
                                <th>Zip Code</th>
                                <th>Status</th>
                                <th class="text-center">Operation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($admins->num_rows === 0): ?>
                                <tr><td colspan="11" class="text-muted">No admins found<?= $qa ? " for “".htmlspecialchars($qa)."”" : "" ?>.</td></tr>
                            <?php else: ?>
                                <?php while ($u = $admins->fetch_assoc()): ?>
                                    <?php $isActive = (int)$u['is_active'] === 1; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($u['full_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($u['username']) ?></td>
                                        <td class="mono"><?= htmlspecialchars($u['password_plain'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($u['contact_no'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($u['region_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($u['province_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($u['municipality_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($u['zip_code'] ?? '-') ?></td>
                                        <td>
                                            <?= $isActive
                                                ? '<span class="badge-status badge-active">Active</span>'
                                                : '<span class="badge-status badge-inactive">Inactive</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <label class="switch mb-0">
                                                <input type="checkbox"
                                                       <?= $isActive ? 'checked' : '' ?>
                                                       onchange="submitToggleUser(<?= (int)$u['id'] ?>, this.checked ? 1 : 0, 'admins')">
                                                <span class="slider"></span>
                                            </label>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted">Toggling will activate/deactivate that admin user.</div>
            </div>

            <!-- Create Admin Manually Modal -->
            <div class="modal fade" id="createAdminModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <form method="post">
                            <input type="hidden" name="create_admin_manual" value="1">
                            <input type="hidden" name="active_tab" value="admins">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Create Admin Manually</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="full_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Username</label>
                                        <input type="text" name="username" class="form-control" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Password</label>
                                        <input type="text" name="password_plain" class="form-control" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Contact No.</label>
                                        <input type="text" name="contact_no" class="form-control">
                                    </div>

                                    <div class="col-12"><hr></div>

                                    <div class="col-md-4">
                                        <label class="form-label">Region</label>
                                        <select name="region_id" id="regionManual" class="form-select" required>
                                            <option value="">-- Select Region --</option>
                                            <?php while ($r = $regionsRes->fetch_assoc()): ?>
                                                <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Province</label>
                                        <select name="province_id" id="provinceManual" class="form-select" required>
                                            <option value="">-- Select Province --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Municipality</label>
                                        <select name="municipality_id" id="municipalityManual" class="form-select" required>
                                            <option value="">-- Select Municipality --</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">No. of Barangays (optional)</label>
                                        <input type="number" name="num_barangays" class="form-control" min="0" placeholder="leave blank to keep current">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">ZIP Code (optional)</label>
                                        <input type="text" name="zip_code" class="form-control" placeholder="leave blank to keep current">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-primary-soft" type="submit">Create Admin</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Requests TAB -->
            <div class="d-flex justify-content-end mb-2">
                <form method="get" class="searchbar">
                    <input type="hidden" name="tab" value="requests">
                    <i class="bi bi-search text-muted"></i>
                    <input type="text" class="form-control border-0 p-0" name="q" placeholder="Search requests..." value="<?= htmlspecialchars($q) ?>">
                    <?php if ($q !== ''): ?><a href="manage_accounts.php?tab=requests" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                </form>
            </div>

            <div class="table-wrap">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="min-width:160px;">Name</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Password</th>
                                <th>Contact No.</th>
                                <th>Municipality</th>
                                <th>Zip Code</th>
                                <th>No. of Brgy.</th>
                                <th>Status</th>
                                <th class="text-center">Operation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($requests->num_rows === 0): ?>
                                <tr><td colspan="10" class="text-muted">No requests found<?= $q ? " for “".htmlspecialchars($q)."”" : "" ?>.</td></tr>
                            <?php else: ?>
                                <?php while ($r = $requests->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><?= htmlspecialchars($r['email']) ?></td>
                                        <td><?= htmlspecialchars($r['username']) ?></td>
                                        <td class="mono"><?= htmlspecialchars($r['password']) ?></td>
                                        <td><?= htmlspecialchars($r['contact_no']) ?></td>
                                        <td><?= htmlspecialchars($r['municipality_name']) ?></td>
                                        <td><?= htmlspecialchars($r['zip_code']) ?></td>
                                        <td><?= (int)$r['num_barangays'] ?></td>
                                        <td><span class="badge-status badge-inactive">Pending</span></td>
                                        <td class="text-center">
                                            <label class="switch mb-0" title="Toggle ON to approve">
                                                <input type="checkbox" onchange="submitApprove(<?= (int)$r['id'] ?>, 'requests')">
                                                <span class="slider"></span>
                                            </label>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted">
                    Toggle ON approves the pending request (creates an additional admin for the same municipality if needed).
                </div>
            </div>
        <?php endif; ?>
    </main>

</div>

<script>
function submitToggleUser(userId, desired, tabName){
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = 'manage_accounts.php';
    const add = (n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; f.appendChild(i); };
    add('toggle_user_id', userId);
    add('desired_state', desired);
    add('active_tab', tabName);
    const url = new URL(window.location.href);
    add('q',  url.searchParams.get('q')  || '');
    add('qa', url.searchParams.get('qa') || '');
    document.body.appendChild(f);
    f.submit();
}
function submitApprove(requestId, tabName){
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = 'manage_accounts.php';
    const add = (n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; f.appendChild(i); };
    add('approve_request_id', requestId);
    add('active_tab', tabName);
    const url = new URL(window.location.href);
    add('q',  url.searchParams.get('q')  || '');
    add('qa', url.searchParams.get('qa') || '');
    document.body.appendChild(f);
    f.submit();
}

/* Dependent dropdowns for manual create */
const regionManual = document.getElementById('regionManual');
const provinceManual = document.getElementById('provinceManual');
const municipalityManual = document.getElementById('municipalityManual');

if (regionManual) {
    regionManual.addEventListener('change', function(){
        const regionId = this.value;
        provinceManual.innerHTML = '<option value="">Loading...</option>';
        municipalityManual.innerHTML = '<option value="">-- Select Municipality --</option>';
        if (!regionId) { provinceManual.innerHTML = '<option value="">-- Select Province --</option>'; return; }
        fetch('get_provinces_by_region.php?region_id=' + encodeURIComponent(regionId))
            .then(r => r.json())
            .then(data => {
                provinceManual.innerHTML = '<option value="">-- Select Province --</option>';
                data.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id; opt.textContent = p.name;
                    provinceManual.appendChild(opt);
                });
            })
            .catch(() => { provinceManual.innerHTML = '<option value="">Error loading provinces</option>'; });
    });

    provinceManual.addEventListener('change', function(){
        const provinceId = this.value;
        municipalityManual.innerHTML = '<option value="">Loading...</option>';
        if (!provinceId) { municipalityManual.innerHTML = '<option value="">-- Select Municipality --</option>'; return; }
        fetch('get_municipalities_by_province.php?province_id=' + encodeURIComponent(provinceId))
            .then(r => r.json())
            .then(data => {
                municipalityManual.innerHTML = '<option value="">-- Select Municipality --</option>';
                data.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.id; opt.textContent = m.name;
                    municipalityManual.appendChild(opt);
                });
            })
            .catch(() => { municipalityManual.innerHTML = '<option value="">Error loading municipalities</option>'; });
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
