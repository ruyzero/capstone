<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','super_admin'], true)) {
    header("Location: index.php"); exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s", $name); $q->execute();
    $ok = (bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}
function first_existing_column(mysqli $conn, string $table, array $candidates): ?string {
    $q = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    foreach ($candidates as $col) {
        $q->bind_param("ss", $table, $col);
        $q->execute();
        if ($q->get_result()->fetch_row()) { $q->close(); return $col; }
    }
    $q->close(); return null;
}

/* ---------- Identity ---------- */
$role              = $_SESSION['role'];               // 'admin' or 'super_admin'
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';
$user_id           = (int)($_SESSION['user_id'] ?? 0);
$handle            = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

/* Municipality name fallback */
if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); if ($r) $municipality_name = $r['name']; $stmt->close();
}
if ($role !== 'super_admin' && !$municipality_id) { die("No municipality set for this admin."); }

/* ---------- Stats (right rail) ---------- */
$c1 = $conn->prepare("SELECT COUNT(*) AS c FROM pregnant_women".($role==='super_admin'?'':" WHERE municipality_id=?"));
if ($role==='super_admin') { $c1->execute(); } else { $c1->bind_param("i",$municipality_id); $c1->execute(); }
$tot_patients = (int)($c1->get_result()->fetch_assoc()['c'] ?? 0);

$c2 = $conn->prepare("SELECT COUNT(*) AS c FROM barangays".($role==='super_admin'?'':" WHERE municipality_id=?"));
if ($role==='super_admin') { $c2->execute(); } else { $c2->bind_param("i",$municipality_id); $c2->execute(); }
$tot_brgy = (int)($c2->get_result()->fetch_assoc()['c'] ?? 0);

$tot_pregnant = $tot_patients;

/* ---------- Users (your schema) ---------- */
$HAS_USERS   = table_exists($conn, 'users');
$created_col = $HAS_USERS ? first_existing_column($conn, 'users', ['created_at','created_on','date_created','registered_at','reg_date','created']) : null;

/* ---------- Flash ---------- */
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

/* ---------- Enable/Disable (uses is_active) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $HAS_USERS) {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    if (!$uid || !in_array($action, ['enable','disable'], true)) {
        $_SESSION['error'] = "Invalid action."; header("Location: manage_accounts_admin.php"); exit();
    }

    // Only allow toggling midwives; also restrict to same municipality unless super_admin
    $q = $conn->prepare("SELECT id, role, municipality_id FROM users WHERE id=? LIMIT 1");
    $q->bind_param("i", $uid); $q->execute();
    $u = $q->get_result()->fetch_assoc(); $q->close();

    if (!$u || $u['role'] !== 'midwife') {
        $_SESSION['error'] = "Only midwife accounts can be enabled/disabled here.";
        header("Location: manage_accounts_admin.php"); exit();
    }
    if ($role !== 'super_admin' && (int)$u['municipality_id'] !== $municipality_id) {
        $_SESSION['error'] = "User is outside your municipality.";
        header("Location: manage_accounts_admin.php"); exit();
    }

    $val = ($action === 'enable') ? 1 : 0;
    $up = $conn->prepare("UPDATE users SET is_active=? WHERE id=?");
    $up->bind_param("ii", $val, $uid); $up->execute(); $up->close();

    $_SESSION['success'] = "Account " . ($val ? "enabled" : "disabled") . ".";
    header("Location: manage_accounts_admin.php"); exit();
}

/* ---------- Load lists ---------- */
$midwives = $bhws = [];
if ($HAS_USERS) {
    $where = ($role==='super_admin') ? "" : "WHERE u.municipality_id = ?";
    $order = " ORDER BY (u.full_name IS NULL) ASC, u.full_name ASC, u.username ASC, u.id DESC";

    // MIDWIVES
    $sqlM = "SELECT u.id, u.username, u.full_name, u.email, u.is_active"
          . ($created_col ? ", u.`$created_col` AS created_at_ui" : ", NULL AS created_at_ui")
          . " FROM users u {$where} " . ($where ? "AND" : "WHERE") . " u.role='midwife' {$order}";
    $stm = $conn->prepare($sqlM);
    if ($role==='super_admin') { $stm->execute(); } else { $stm->bind_param("i", $municipality_id); $stm->execute(); }
    $midwives = $stm->get_result()->fetch_all(MYSQLI_ASSOC); $stm->close();

    // BHW (your enum doesn't have 'bhw' now; query will return empty; NO barangay join)
    $sqlB = "SELECT u.id, u.username, u.full_name, u.email, u.is_active"
          . ($created_col ? ", u.`$created_col` AS created_at_ui" : ", NULL AS created_at_ui")
          . " FROM users u {$where} " . ($where ? "AND" : "WHERE") . " u.role='bhw' {$order}";
    $stb = $conn->prepare($sqlB);
    if ($role==='super_admin') { $stb->execute(); } else { $stb->bind_param("i", $municipality_id); $stb->execute(); }
    $bhws = $stb->get_result()->fetch_all(MYSQLI_ASSOC); $stb->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Manage Accounts (Admin) • RHU-MIS</title>
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
    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; overflow:hidden; margin-bottom:24px; }
    .panel h5{ padding:16px 18px; margin:0; }
    .table > :not(caption) > * > *{ vertical-align:middle; }
    .pill-created{ display:inline-block; padding:.25rem .6rem; border-radius:9px; background:#f6f8fa; border:1px solid #e5e7eb; font-weight:700; font-size:.85rem; }
    .btn-enable{ background:#20C4B2; color:#fff; border:0; padding:.35rem .75rem; border-radius:999px; font-weight:700; }
    .btn-disable{ background:#dc3545; color:#fff; border:0; padding:.35rem .75rem; border-radius:999px; font-weight:700; }
    @media (max-width:1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column:1/-1; } }
</style>
</head>
<body>

<div class="layout">
    <!-- Left nav -->
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
            <a class="nav-link active" href="manage_accounts_admin.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?php if (!$HAS_USERS): ?>
            <div class="alert alert-warning"><strong>Heads up:</strong> Table <code>users</code> was not found.</div>
        <?php endif; ?>

        <h4 class="mb-3">Manage Accounts</h4>

        <!-- MIDWIFE -->
        <div class="panel">
            <h5>Midwife</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Password</th>
                            <th>Created At</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($HAS_USERS && count($midwives)>0): foreach ($midwives as $m): ?>
                        <?php
                            $created = $m['created_at_ui'] ? date('M j, Y', strtotime($m['created_at_ui'])) : '—';
                            $is_on   = (int)$m['is_active'] === 1;
                        ?>
                        <tr>
                            <td><?= h($m['full_name'] ?? '—') ?></td>
                            <td><?= h($m['username'] ?? '—') ?></td>
                            <td><?= h($m['email'] ?? '—') ?></td>
                            <td>••••••••</td>
                            <td><span class="pill-created"><?= h($created) ?></span></td>
                            <td class="text-center">
                                <?php if ($is_on): ?>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                                        <input type="hidden" name="action" value="disable">
                                        <button class="btn-disable" type="submit">Disable</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                                        <input type="hidden" name="action" value="enable">
                                        <button class="btn-enable" type="submit">Enable</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No midwife accounts.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- BHW (none in your enum; no barangay join; shows empty) -->
        <div class="panel">
            <h5>BHW</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Password</th>
                            <th>Created At</th>
                            <th>Barangay</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="7" class="text-center text-muted py-3">No BHW accounts.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Right rail -->
    <aside class="rail" style="padding:24px 18px; display:flex; flex-direction:column; gap:18px;">
        <div class="profile d-flex align-items-center gap-2" style="background:#fff;border:1px solid var(--ring);border-radius:16px;padding:14px 16px;">
            <div class="avatar" style="width:44px;height:44px;border-radius:50%;background:#e6fffb;display:grid;place-items:center;color:#0f766e;font-weight:800">
                <i class="bi bi-person"></i>
            </div>
            <div>
                <div class="fw-bold"><?= h($username) ?></div>
                <div class="text-muted small"><?= h($handle) ?></div>
            </div>
        </div>
        <div class="stat" style="background:#fff;border:1px solid var(--ring);border-radius:16px;padding:16px;text-align:center;">
            <div class="text-muted">Total Patient Record</div>
            <div style="font-size:48px;font-weight:800;"><?= $tot_patients ?></div>
        </div>
        <div class="stat" style="color:#fff;border:0;background:linear-gradient(160deg,#20C4B2,#1A9E9D);border-radius:16px;padding:16px;text-align:center;">
            <div class="text-light">Total Brgy. Health Center</div>
            <div style="font-size:48px;font-weight:800;"><?= $tot_brgy ?></div>
        </div>
        <div class="stat" style="background:#fff;border:1px solid var(--ring);border-radius:16px;padding:16px;text-align:center;">
            <div class="text-muted">Total Pregnant Patient</div>
            <div style="font-size:48px;font-weight:800;"><?= $tot_pregnant ?></div>
        </div>
    </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
