<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function full_name($r){
    $parts = array_filter([$r['first_name']??'', $r['middle_name']??'', $r['last_name']??'', $r['suffix']??''], fn($x)=>$x!==null && trim($x)!=='');
    return trim(implode(' ', $parts));
}
function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s", $name); $q->execute();
    $ok = (bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}

/* ---------- Identity ---------- */
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';
$user_id           = (int)($_SESSION['user_id'] ?? 0);
$handle            = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); if ($r) $municipality_name = $r['name']; $stmt->close();
}
if (!$municipality_id) { die("No municipality set for this admin."); }

/* ---------- Stats for right rail ---------- */
$c1 = $conn->prepare("SELECT COUNT(*) AS c FROM pregnant_women WHERE municipality_id=?");
$c1->bind_param("i",$municipality_id); $c1->execute();
$tot_patients = (int)($c1->get_result()->fetch_assoc()['c'] ?? 0);

$c2 = $conn->prepare("SELECT COUNT(*) AS c FROM barangays WHERE municipality_id=?");
$c2->bind_param("i",$municipality_id); $c2->execute();
$tot_brgy = (int)($c2->get_result()->fetch_assoc()['c'] ?? 0);

$tot_pregnant = $tot_patients;

/* ---------- Existence of transfer_requests ---------- */
$HAS_TR = table_exists($conn, 'transfer_requests');

/* ---------- Flash ---------- */
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

/* ---------- Actions (Approve / Decline) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $HAS_TR) {
    $action     = $_POST['action'] ?? '';
    $req_id     = (int)($_POST['request_id'] ?? 0);
    $admin_note = trim($_POST['admin_note'] ?? '');

    if (!$req_id || !in_array($action, ['approve','decline'], true)) {
        $_SESSION['error'] = "Invalid action."; header("Location: request_data_transfer.php"); exit();
    }

    // Load request, ensure same municipality
    $sql = "SELECT tr.*, p.id AS patient_id, p.municipality_id AS patient_mun, p.barangay_id AS curr_brgy,
                   p.first_name, p.middle_name, p.last_name, p.suffix,
                   bfrom.name AS from_brgy_name, bto.name AS to_brgy_name
            FROM transfer_requests tr
            INNER JOIN pregnant_women p ON p.id = tr.patient_id
            INNER JOIN barangays bfrom ON bfrom.id = tr.from_barangay_id
            INNER JOIN barangays bto   ON bto.id   = tr.to_barangay_id
            WHERE tr.id = ? AND tr.status = 'pending' LIMIT 1";
    $q = $conn->prepare($sql);
    $q->bind_param("i", $req_id); $q->execute();
    $req = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$req) { $_SESSION['error'] = "Request not found or already processed."; header("Location: request_data_transfer.php"); exit(); }
    if ((int)$req['patient_mun'] !== $municipality_id) {
        $_SESSION['error'] = "Patient does not belong to your municipality."; header("Location: request_data_transfer.php"); exit();
    }

    if ($action === 'approve') {
        // Make sure destination barangay is within this municipality
        $chk = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE id=? AND municipality_id=?");
        $chk->bind_param("ii", $req['to_barangay_id'], $municipality_id); $chk->execute();
        $ok = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0) > 0; $chk->close();
        if (!$ok) { $_SESSION['error'] = "Destination barangay is outside your municipality."; header("Location: request_data_transfer.php"); exit(); }

        $conn->begin_transaction();
        try {
            // Update patient barangay (and address if provided)
            $new_addr = $req['new_address']; if ($new_addr !== null && $new_addr === '') $new_addr = null;
            if ($new_addr !== null) {
                $u = $conn->prepare("UPDATE pregnant_women SET barangay_id=?, address_line=? WHERE id=?");
                $u->bind_param("isi", $req['to_barangay_id'], $new_addr, $req['patient_id']);
            } else {
                $u = $conn->prepare("UPDATE pregnant_women SET barangay_id=? WHERE id=?");
                $u->bind_param("ii", $req['to_barangay_id'], $req['patient_id']);
            }
            $u->execute(); $u->close();

            // Mark request approved
            $a = $conn->prepare("UPDATE transfer_requests SET status='approved', acted_by=?, acted_at=NOW(), admin_note=? WHERE id=?");
            $a->bind_param("isi", $user_id, $admin_note, $req_id);
            $a->execute(); $a->close();

            $conn->commit();
            $_SESSION['success'] = "Transfer approved. Patient moved to {$req['to_brgy_name']}.";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = "Failed to approve transfer. " . $e->getMessage();
        }
    } else {
        // decline
        $d = $conn->prepare("UPDATE transfer_requests SET status='declined', acted_by=?, acted_at=NOW(), admin_note=? WHERE id=? AND status='pending'");
        $d->bind_param("isi", $user_id, $admin_note, $req_id);
        $d->execute(); $d->close();
        $_SESSION['success'] = "Transfer declined.";
    }

    header("Location: request_data_transfer.php"); exit();
}

/* ---------- Fetch rows for table (pending + processed) ---------- */
$rows = [];
$pending_count = 0;
if ($HAS_TR) {
    // We attempt to join users (requester). If no 'users' table, name will be 'User #ID'
    $HAS_USERS = table_exists($conn, 'users');

    $sql = "SELECT tr.*,
                   p.first_name, p.middle_name, p.last_name, p.suffix, p.id AS patient_id,
                   b1.name AS from_barangay, b2.name AS to_barangay,
                   ".($HAS_USERS ? "u.username AS requester_username" : "NULL AS requester_username").",
                   /* count checkups */
                   (SELECT COUNT(*) FROM prenatal_checkups pc WHERE pc.patient_id = p.id) AS chk_cnt
            FROM transfer_requests tr
            INNER JOIN pregnant_women p ON p.id = tr.patient_id
            INNER JOIN barangays b1 ON b1.id = tr.from_barangay_id
            INNER JOIN barangays b2 ON b2.id = tr.to_barangay_id
            ".($HAS_USERS ? "LEFT JOIN users u ON u.id = tr.requested_by" : "")."
            WHERE p.municipality_id = ?
            ORDER BY tr.created_at DESC";
    $s = $conn->prepare($sql);
    $s->bind_param("i", $municipality_id); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    foreach ($rows as $r) if ($r['status'] === 'pending') $pending_count++;
}

/* ---------- UI ---------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Request Data Transfer • RHU-MIS</title>
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
    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; overflow:hidden; }
    .panel h5{ padding:16px 18px; margin:0; }
    .table > :not(caption) > * > *{ vertical-align:middle; }
    .pill{ display:inline-block; padding:.25rem .6rem; border-radius:999px; font-weight:700; font-size:.85rem; }
    .pill-pending  { background:#fff7e6; color:#a16207; border:1px solid #fde68a; }
    .pill-success  { background:#e8fff1; color:#0f766e; border:1px solid #99f6e4; }
    .pill-declined { background:#ffe4e6; color:#b91c1c; border:1px solid #fecdd3; }
    .btn-approve   { background:#20C4B2; color:#fff; border:0; padding:.35rem .75rem; border-radius:999px; font-weight:700; }
    .btn-disapprove{ background:#dc3545; color:#fff; border:0; padding:.35rem .75rem; border-radius:999px; font-weight:700; }
    .searchbar{ flex:1; max-width:640px; position:relative; }
    .searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
    .searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }
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
            <a class="nav-link active" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts_admin.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
        </nav>
    </aside>
    

    <!-- Main -->
    <main class="main">
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?php if (!$HAS_TR): ?>
            <div class="alert alert-warning"><strong>Heads up:</strong> Table <code>transfer_requests</code> not found. Create it using the DDL at the bottom.</div>
        <?php endif; ?>

        <h4 class="mb-3">Patient Data Transfer</h4>

        <div class="panel">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th class="text-center">Barangay</th>
                            <th class="text-center">Request Transfer To Barangay</th>
                            <th class="text-center">Midwife</th>
                            <th class="text-center">Prenatal Status</th>
                            <th class="text-center">Transfer Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($HAS_TR && count($rows)>0): foreach ($rows as $r): ?>
                        <?php
                            $pname = full_name($r);
                            $pren_done = ((int)($r['chk_cnt'] ?? 0)) >= 3;
                            $pren_txt  = $pren_done ? 'Done' : 'Under Monitoring';

                            $status = strtolower($r['status']);
                            $status_txt = $status === 'approved' ? 'Success' : ($status === 'declined' ? 'Declined' : 'Pending');
                            $status_cls = $status === 'approved' ? 'pill-success' : ($status === 'declined' ? 'pill-declined' : 'pill-pending');

                            $midwife = $r['requester_username'] ? '@'.$r['requester_username'] : ('User #'.$r['requested_by']);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= h($pname ?: '—') ?></div>
                                <div class="text-muted small">#<?= (int)$r['patient_id'] ?></div>
                            </td>
                            <td class="text-center"><?= h($r['from_barangay']) ?></td>
                            <td class="text-center"><?= h($r['to_barangay']) ?></td>
                            <td class="text-center"><?= h($midwife) ?></td>
                            <td class="text-center"><?= h($pren_txt) ?></td>
                            <td class="text-center"><span class="pill <?= $status_cls ?>"><?= h($status_txt) ?></span></td>
                            <td class="text-center">
                                <?php if ($status === 'pending'): ?>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button class="btn-approve" type="submit">Approve</button>
                                    </form>
                                    <form method="post" action="" class="d-inline ms-1">
                                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                        <input type="hidden" name="action" value="decline">
                                        <button class="btn-disapprove" type="submit">Disapprove</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No transfer requests.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!$HAS_TR): ?>
        <div class="panel mt-4">
            <h5>SQL to create <code>transfer_requests</code></h5>
<pre class="m-3" style="white-space:pre-wrap">
CREATE TABLE IF NOT EXISTS transfer_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  from_barangay_id INT NOT NULL,
  to_barangay_id INT NOT NULL,
  new_address VARCHAR(255) NULL,
  reason TEXT NULL,
  status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  requested_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acted_by INT NULL,
  acted_at DATETIME NULL,
  admin_note TEXT NULL,
  KEY idx_tr_patient (patient_id),
  KEY idx_tr_status (status),
  KEY idx_tr_from (from_barangay_id),
  KEY idx_tr_to (to_barangay_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
</pre>
        </div>
        <?php endif; ?>
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
