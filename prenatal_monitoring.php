<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s", $name);
    $q->execute();
    $exists = (bool)$q->get_result()->fetch_row();
    $q->close();
    return $exists;
}
function first_existing_column(mysqli $conn, string $table, array $candidates): ?string {
    $q = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    foreach ($candidates as $col) {
        $q->bind_param("ss", $table, $col);
        $q->execute();
        if ($q->get_result()->fetch_row()) { $q->close(); return $col; }
    }
    $q->close();
    return null;
}

/* ---------- Session / Identity ---------- */
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';
$handle            = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

/* Fallback lookup for municipality name if not in session */
if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $municipality_name = $row['name'];
    $stmt->close();
}

if (!$municipality_id) { die("No municipality set for this admin."); }

/* ---------- Search ---------- */
$q = trim($_GET['q'] ?? '');

/* ---------- Right rail stats ---------- */
$c1 = $conn->prepare("SELECT COUNT(*) AS c FROM pregnant_women WHERE municipality_id = ?");
$c1->bind_param("i", $municipality_id);
$c1->execute();
$tot_patients = (int)($c1->get_result()->fetch_assoc()['c'] ?? 0);

$c2 = $conn->prepare("SELECT COUNT(*) AS c FROM barangays WHERE municipality_id = ?");
$c2->bind_param("i", $municipality_id);
$c2->execute();
$tot_brgy = (int)($c2->get_result()->fetch_assoc()['c'] ?? 0);

$tot_pregnant = $tot_patients;

/* ---------- Only show ENROLLED patients ---------- */
$ENROLL_TABLE = 'prenatal_enrollments';
$HAS_ENROLL   = table_exists($conn, $ENROLL_TABLE);
$HAS_CHECKUPS = table_exists($conn, 'prenatal_checkups');
$HAS_SCHED    = table_exists($conn, 'prenatal_schedules');

$rows = null;
$enroll_error = null;

if ($HAS_ENROLL) {
    // Auto-detect columns for optional tables
    $checkups_patient_col = $HAS_CHECKUPS ? first_existing_column($conn, 'prenatal_checkups',
        ['patient_id','pregnant_woman_id','pregnancy_id','woman_id','pw_id']) : null;

    $sched_patient_col = $HAS_SCHED ? first_existing_column($conn, 'prenatal_schedules',
        ['patient_id','pregnant_woman_id','pregnancy_id','woman_id','pw_id']) : null;

    $sched_date_col = $HAS_SCHED ? first_existing_column($conn, 'prenatal_schedules',
        ['schedule_date','scheduled_at','appointment_date','visit_date','next_schedule','date']) : null;

    // Build dynamic SELECT safely (column names come from a fixed allowlist above)
    $select = "
        SELECT 
            p.*,
            b.name AS barangay,
            e.enrolled_at
            " . (
                $HAS_CHECKUPS && $checkups_patient_col
                ? ", (SELECT COUNT(*) FROM prenatal_checkups c WHERE c.`{$checkups_patient_col}` = p.id) AS checkups_done"
                : ", 0 AS checkups_done"
            ) . "
            " . (
                $HAS_SCHED && $sched_patient_col && $sched_date_col
                ? ", (SELECT MIN(s.`{$sched_date_col}`) FROM prenatal_schedules s WHERE s.`{$sched_patient_col}` = p.id AND s.`{$sched_date_col}` >= CURDATE()) AS next_schedule"
                : ", NULL AS next_schedule"
            ) . "
        FROM {$ENROLL_TABLE} e
        INNER JOIN pregnant_women p ON p.id = e.patient_id
        LEFT JOIN barangays b ON b.id = p.barangay_id
        WHERE p.municipality_id = ?
    ";

    $params = [$municipality_id];
    $types  = "i";

    if ($q !== '') {
        $select .= " AND (CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ? OR b.name LIKE ?)";
        $like = "%$q%";
        $params[] = $like; $params[] = $like;
        $types   .= "ss";
    }

    $select .= " ORDER BY e.enrolled_at DESC, p.last_name IS NULL, p.last_name ASC, p.first_name ASC";

    $stmt = $conn->prepare($select);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result();
} else {
    $enroll_error = "No enrollment table found. Create table `prenatal_enrollments` and enroll patients first.";
}

/* ---------- View helpers ---------- */
function full_name($r) {
    $parts = array_filter([
        $r['first_name'] ?? '',
        $r['middle_name'] ?? '',
        $r['last_name'] ?? '',
        $r['suffix'] ?? ''
    ], fn($x) => $x !== null && trim($x) !== '');
    return trim(implode(' ', $parts));
}
function calc_edd($r) {
    if (!empty($r['edd'] ?? null))          return date('M d, Y', strtotime($r['edd']));
    if (!empty($r['lmp'] ?? null)) {
        $t = strtotime($r['lmp'] . ' +280 days');
        return $t ? date('M d, Y', $t) : 'N/A';
    }
    return 'N/A';
}
function calc_age($dob) {
    if (!$dob) return null;
    try { $d = new DateTime($dob); return (new DateTime())->diff($d)->y; }
    catch (Exception $e) { return null; }
}
function risk_level($r) {
    if (!empty($r['risk_level'] ?? null)) return strtolower($r['risk_level']);
    $age = calc_age($r['dob'] ?? null);
    if ($age !== null && ($age < 18 || $age > 35)) return 'high';
    if (isset($r['status']) && strtolower($r['status']) === 'under_monitoring') return 'caution';
    return 'normal';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Prenatal Monitoring • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{
        --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb;
        --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6a;
    }
    *{ box-sizing:border-box }
    body{ margin:0; background:#fff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial; }

    /* 3-column grid: leftbar | main | right rail */
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

    /* Left sidebar */
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }

    /* Main */
    .main{ padding:24px; background:#fff; }
    .topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; }
    .searchbar{ flex:1; max-width:640px; position:relative; }
    .searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
    .searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }
    .add-links a{ display:block; font-weight:700; color:var(--brand); text-decoration:none; }
    .add-links a:hover{ color:var(--brand-dark); text-decoration:underline; }

    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:0; overflow:hidden; }
    .panel h5{ padding:16px 18px; }
    .table > :not(caption) > * > *{ vertical-align:middle; }
    .table thead th{ color:#0f172a; font-weight:700; background:#f7fafc; border-bottom:1px solid #eef0f3 !important; }
    .pill{ display:inline-block; padding:.25rem .6rem; border-radius:999px; background:#eef0f3; color:#334155; font-weight:700; font-size:.85rem; }
    .status-under{ background:#fff7e6; color:#a16207; border:1px solid #fde68a; }
    .status-done{ background:#e8fff1; color:#0f766e; border:1px solid #99f6e4; }

    .name-risk{ font-weight:700; text-decoration:none; }
    .risk-normal{ color:#0ca678; } .risk-caution{ color:#f59f00; } .risk-high{ color:#e03131; }

    .legend{ margin-top:10px; font-size:.9rem; }
    .legend .dot{ width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:6px; }
    .legend .n{ background:#0ca678; } .legend .c{ background:#f59f00; } .legend .h{ background:#e03131; }

    /* Right rail */
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg,var(--teal-1),var(--teal-2)); box-shadow:0 10px 28px rgba(16,185,129,.2); }
    .stat.gradient .label{ color:#e7fffb; }

    /* Responsive */
    @media (max-width:1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column:1 / -1; } }
    @media (max-width:992px){ .leftbar{ width:100%; border-right:none; border-bottom:1px solid #eef0f3; } }
</style>
</head>
<body>

<div class="layout">
    <!-- Left Sidebar -->
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
            <a class="nav-link" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-building"></i> Brgy. Health Centers</a>
            <a class="nav-link active" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="topbar">
            <form class="searchbar me-3" method="get" action="">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="form-control" placeholder="Search patient or barangay" value="<?= h($q) ?>">
            </form>
            <div class="add-links text-end">
                <a href="add_monitoring.php">+ Add&nbsp;&nbsp;Patient for Monitoring</a>
                <a href="add_previous_pregnancy.php">+ Add Previous Pregnancy Record</a>
                <a href="manage_prenatal_unenroll.php">- Unenroll Patient</a>
            </div>
        </div>

        <h4 class="mb-3">Prenatal Monitoring</h4>

        <?php if ($enroll_error): ?>
            <div class="alert alert-warning"><?= h($enroll_error) ?></div>
        <?php endif; ?>

        <div class="panel">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-start">Patient Full name</th>
                            <th class="text-center">Barangay</th>
                            <th class="text-center">Expected Delivery Date</th>
                            <th class="text-center">View / Update<br>Patient Details</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">No. of<br>Prenatal Checkups</th>
                            <th class="text-center">Prenatal<br>Checkup</th>
                            <th class="text-center">View Previous<br>Pregnancy</th>
                            <th class="text-center">Upcoming Prenatal<br>Schedule</th>
                            <th class="text-center">Notify</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows && $rows->num_rows > 0): ?>
                        <?php while ($r = $rows->fetch_assoc()):
                            $name       = full_name($r);
                            $edd        = calc_edd($r);
                            $status     = strtolower($r['status'] ?? '');
                            $status_txt = $status ? ucwords(str_replace('_',' ', $status)) : 'Under Monitoring';
                            $status_cls = ($status === 'completed' || $status === 'done') ? 'status-done' : 'status-under';

                            $done       = isset($r['checkups_done']) && is_numeric($r['checkups_done']) ? (int)$r['checkups_done'] : 0;
                            $required   = 3;
                            $next_sched = !empty($r['next_schedule'] ?? null) ? date('M d, Y', strtotime($r['next_schedule'])) : 'N/A';

                            $age = null;
                            if (!empty($r['dob'])) { try { $d = new DateTime($r['dob']); $age = (new DateTime())->diff($d)->y; } catch(Exception $e){} }
                            $risk      = ($age !== null && ($age < 18 || $age > 35)) ? 'high' : (strtolower($r['status'] ?? '') === 'under_monitoring' ? 'caution' : 'normal');
                            $riskClass = $risk === 'high' ? 'risk-high' : ($risk === 'caution' ? 'risk-caution' : 'risk-normal');
                        ?>
                        <tr>
                            <td class="text-start">
                                <a class="name-risk <?= $riskClass ?>" href="view_pregnancy_detail.php?id=<?= (int)$r['id']; ?>">
                                    <?= h($name ?: '—') ?>
                                </a>
                            </td>
                            <td class="text-center"><?= h($r['barangay'] ?? '—') ?></td>
                            <td class="text-center"><?= $edd ?></td>
                            <td class="text-center"><a href="monitoring_details.php?patient_id=<?= (int)$r['id']; ?>">View / Update</a></td>
                            <td class="text-center"><span class="pill <?= $status_cls ?>"><?= h($status_txt) ?></span></td>
                            <td class="text-center"><?= $done . " of " . $required ?></td>
                            <td class="text-center"><a href="prenatal_checkup.php?patient_id=<?= (int)$r['id']; ?>">Checkup</a></td>
                            <td class="text-center"><a href="previous_pregnancies.php?patient_id=<?= (int)$r['id']; ?>">View</a></td>
                            <td class="text-center"><span class="pill"><?= $next_sched ?></span></td>
                            <td class="text-center">
                                <a href="notify_schedule.php?patient_id=<?= (int)$r['id']; ?>" class="d-block link-success">Upcoming Schedule</a>
                                <a href="risk_alerts.php?patient_id=<?= (int)$r['id']; ?>" class="d-block link-success">Risk Assessment &amp; Alerts</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No enrolled patients found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="legend">
            <span class="dot n"></span>Normal &nbsp;&nbsp;
            <span class="dot c"></span>Caution &nbsp;&nbsp;
            <span class="dot h"></span>High Risk
        </div>
    </main>

    <!-- Right rail -->
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
            <div class="big"><?= $tot_patients ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?= $tot_brgy ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?= $tot_pregnant ?></div>
        </div>
    </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
