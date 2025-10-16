<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'midwife') {
    header("Location: index.php"); exit();
}

$midwife_id      = (int)($_SESSION['user_id'] ?? 0);
$username        = $_SESSION['username'] ?? 'midwife';
$current_month   = date('Y-m-01');
$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ints(array $a){ return array_values(array_filter(array_map('intval',$a), function($x){return $x>0;})); }

/* mysqli bind helper without variadics */
function bind_params_no_variadics(mysqli_stmt $stmt, $types, array $params){
    $refs = array();
    $refs[] = $types;
    foreach ($params as $k => $v) { $refs[] = &$params[$k]; }
    call_user_func_array(array($stmt,'bind_param'), $refs);
}

/* Count helper without variadics */
function fetch_count(mysqli $conn, $sql, array $bind = array(), $types = ''){
    $stmt = $conn->prepare($sql);
    if (!$stmt){ return 0; }
    if ($types !== '' && !empty($bind)){ bind_params_no_variadics($stmt, $types, $bind); }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : array(0);
    $stmt->close();
    return (int)($row[0] ?? 0);
}

/* ---------- Block inactive accounts ---------- */
$st = $conn->prepare("SELECT is_active, municipality_id FROM users WHERE id = ? LIMIT 1");
$st->bind_param("i", $midwife_id);
$st->execute();
$u = $st->get_result()->fetch_assoc();
$st->close();

if (!$u || (int)$u['is_active'] !== 1) {
    session_destroy();
    echo "<script>alert('Your account is not yet activated. Please contact the administrator.');window.location.href='index.php';</script>";
    exit();
}
if ($municipality_id === 0) { $municipality_id = (int)($u['municipality_id'] ?? 0); }

/* ---------- Location labels ---------- */
$loc_stmt = $conn->prepare("
    SELECT m.name AS municipality, p.name AS province, r.name AS region
    FROM municipalities m
    LEFT JOIN provinces p ON m.province_id = p.id
    LEFT JOIN regions r   ON p.region_id = r.id
    WHERE m.id = ?
");
$loc_stmt->bind_param("i", $municipality_id);
$loc_stmt->execute();
$location = $loc_stmt->get_result()->fetch_assoc() ?: array();
$municipality_name = $location['municipality'] ?? 'Unknown';
$loc_stmt->close();

/* ---------- Assigned barangays for this month ---------- */
$acc = $conn->prepare("SELECT barangay_id FROM midwife_access WHERE midwife_id = ? AND access_month = ?");
$acc->bind_param("is", $midwife_id, $current_month);
$acc->execute();
$acc_res = $acc->get_result();
$access_ids = array();
while ($r = $acc_res->fetch_assoc()){ $access_ids[] = (int)$r['barangay_id']; }
$acc->close();
$access_ids = ints($access_ids);

/* ---------- Announcements ---------- */
$ann_stmt = $conn->prepare("
    SELECT id, title, message, date_created
    FROM announcements
    WHERE municipality_id = ?
    ORDER BY date_created DESC, id DESC
    LIMIT 1
");
$ann_stmt->bind_param("i", $municipality_id);
$ann_stmt->execute();
$announcement = $ann_stmt->get_result()->fetch_assoc();
$ann_stmt->close();

$a_title      = $announcement['title']        ?? 'No announcements yet';
$a_text       = $announcement['message']      ?? '';
$a_created_at = $announcement['date_created'] ?? null;
$ann_image    = isset($announcement['id']) ? "announcement_images/announcement_{$announcement['id']}.png" : null;

/* ---------- Stats & age bands (scoped to assigned barangays) ---------- */
$totalPatients = 0; $totalBrgyCenters = 0; $age10_14 = 0; $age15_19 = 0; $age20_49 = 0;

if (!empty($access_ids)) {
    /* Build IN clause safely (ints only) */
    $in_list = implode(',', $access_ids);

    $totalPatients = fetch_count($conn,
        "SELECT COUNT(*) FROM pregnant_women WHERE sex='Female' AND barangay_id IN ($in_list)"
    );
    $totalBrgyCenters = fetch_count($conn,
        "SELECT COUNT(*) FROM barangays WHERE id IN ($in_list)"
    );

    $age10_14 = fetch_count($conn,
        "SELECT COUNT(*) FROM pregnant_women 
         WHERE sex='Female' AND dob IS NOT NULL AND barangay_id IN ($in_list)
         AND TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 10 AND 14"
    );
    $age15_19 = fetch_count($conn,
        "SELECT COUNT(*) FROM pregnant_women 
         WHERE sex='Female' AND dob IS NOT NULL AND barangay_id IN ($in_list)
         AND TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 15 AND 19"
    );
    $age20_49 = fetch_count($conn,
        "SELECT COUNT(*) FROM pregnant_women 
         WHERE sex='Female' AND dob IS NOT NULL AND barangay_id IN ($in_list)
         AND TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 20 AND 49"
    );
}
$totalPregnant = $totalPatients;

/* ---------- UI helpers ---------- */
$handle = '@' . strtolower(preg_replace('/\s+/', '', "midwife{$municipality_name}"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Midwife Dashboard - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{ --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb; --sidebar-w:260px; }
    *{ box-sizing:border-box } body{ margin:0; background:var(--bg); font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; } .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }
    .main{ padding:24px; }
    .searchwrap{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--ring); border-radius:14px; padding:10px 14px; max-width:560px; }
    .searchwrap input{ border:0; outline:0; width:100%; }
    .section-title{ font-weight:800; margin:20px 0 12px; }
    .announce{ background:#fff; border:1px solid var(--ring); border-radius:20px; padding:18px; }
    .announce .bubble{ border:2px solid #e7ebef; border-radius:18px; padding:18px; }
    .report-grid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:18px; }
    .tile{ color:#fff; border-radius:22px; padding:22px; min-height:140px; background:linear-gradient(135deg,var(--teal-1),var(--teal-2)); display:flex; flex-direction:column; align-items:center; justify-content:center; box-shadow:0 8px 24px rgba(16,185,129,.15); text-align:center; }
    .tile .num{ font-size:44px; font-weight:800; line-height:1; } .tile .sub{ opacity:.95; margin-top:8px; font-weight:600; } .tile .date{ opacity:.85; margin-top:4px; font-size:12px; }
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px; }
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; } .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg,#20C4B2,#1A9E9D); box-shadow:0 10px 28px rgba(16,185,129,.2); }
    .stat.gradient .label{ color:#e7fffb; }
    .badge-list .badge{ background:#eef8f7; color:#0f5f57; border:1px solid #d6efed; padding:.55rem .75rem; border-radius:999px; font-weight:600; }
    @media (max-width:1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column:1 / -1; } }
</style>
</head>
<body>

<div class="layout">
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div>RHU-MIS</div>
                <small class="text-muted"><?php echo h(strtoupper($municipality_name)); ?></small>
            </div>
        </div>

        <nav class="nav flex-column gap-1">
            <a class="nav-link active" href="midwife_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="midwife_patients.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <main class="main">
        <div class="searchwrap mb-3">
            <i class="bi bi-search text-muted"></i>
            <input type="text" placeholder="Search Here" aria-label="Search">
        </div>

        <h4 class="section-title">Announcements</h4>
        <div class="announce mb-4">
            <?php if (!empty($announcement)) { ?>
                <div class="bubble">
                    <div class="fw-bold"><?php echo h($a_title); ?></div>
                    <?php if ($a_text !== '') { ?>
                        <div class="mt-2 text-secondary" style="white-space:pre-line;"><?php echo nl2br(h($a_text)); ?></div>
                    <?php } ?>
                    <?php if ($a_created_at) { ?>
                        <div class="mt-2 small text-muted"><em>Posted <?php echo h(date('M d, Y g:i A', strtotime($a_created_at))); ?></em></div>
                    <?php } ?>
                    <?php if ($ann_image && file_exists($ann_image)) { ?>
                        <div class="mt-3 text-center">
                            <img src="<?php echo h($ann_image); ?>" alt="Announcement Image" class="img-fluid rounded shadow-sm border">
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <div class="text-muted">No announcements posted yet for this municipality.</div>
            <?php } ?>
        </div>

        <div class="d-flex align-items-center justify-content-between">
            <h4 class="section-title mb-2">Report</h4>
            <button class="btn btn-sm btn-outline-secondary px-3" disabled>Filter</button>
        </div>

        <div class="report-grid mb-4">
            <div class="tile">
                <div class="num"><?php echo $age10_14; ?></div>
                <div class="sub">10–14 Year Old</div>
                <div class="date">As of <?php echo date('D, M j, Y'); ?></div>
            </div>
            <div class="tile">
                <div class="num"><?php echo $age15_19; ?></div>
                <div class="sub">15–19 Year Old</div>
                <div class="date">As of <?php echo date('D, M j, Y'); ?></div>
            </div>
            <div class="tile">
                <div class="num"><?php echo $age20_49; ?></div>
                <div class="sub">20–49 Year Old</div>
                <div class="date">As of <?php echo date('D, M j, Y'); ?></div>
            </div>
        </div>

        <h6 class="text-muted mb-2">Your assigned barangays this month</h6>
        <div class="badge-list d-flex flex-wrap gap-2 mb-3">
            <?php
            if (!empty($access_ids)) {
                $in_list = implode(',', $access_ids);
                $q = $conn->query("SELECT id, name FROM barangays WHERE id IN ($in_list) ORDER BY name");
                if ($q) {
                    while ($b = $q->fetch_assoc()) {
                        echo '<span class="badge">'.h($b['name']).'</span>';
                    }
                }
            } else {
                echo '<span class="text-muted">No assigned barangays for '.h(date('F Y')).'.</span>';
            }
            ?>
        </div>

        <a href="view_pregnancies.php" class="btn btn-outline-success btn-sm">
            <i class="bi bi-folder2-open"></i> Browse Assigned Records
        </a>
    </main>

    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?php echo h($username); ?></div>
                <div class="text-muted small"><?php echo h($handle); ?></div>
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
