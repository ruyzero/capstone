<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth: SUPER ADMIN ONLY ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php"); exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function full_name($r) {
    $parts = array_filter([$r['first_name']??'', $r['middle_name']??'', $r['last_name']??'', $r['suffix']??''], fn($x)=>$x!==null && trim($x)!=='');
    return trim(implode(' ', $parts));
}
function pretty_date_or_na(?string $s): string {
    if (!$s) return 'N/A';
    $t = strtotime($s);
    return $t ? date('M d, Y', $t) : 'N/A';
}
function calc_edd_display($d_edd, $d_lmp): string {
    if (!empty($d_edd)) return pretty_date_or_na($d_edd);
    if (!empty($d_lmp)) {
        $t = strtotime($d_lmp.' +280 days');
        return $t ? date('M d, Y', $t) : 'N/A';
    }
    return 'N/A';
}
function calc_age($dob) {
    if (!$dob) return null;
    try { $d = new DateTime($dob); return (new DateTime())->diff($d)->y; } catch(Exception $e){ return null; }
}
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

/* ---------- Filters & search (GET) ---------- */
$q              = trim($_GET['q'] ?? '');
$region_id      = (int)($_GET['region_id'] ?? 0);
$province_id    = (int)($_GET['province_id'] ?? 0);
$municipality_id= (int)($_GET['municipality_id'] ?? 0);
$barangay_id    = (int)($_GET['barangay_id'] ?? 0);

/* ---------- Select options ---------- */
$regions = $conn->query("SELECT id, name FROM regions ORDER BY name");
$provinces = null;
if ($region_id > 0) {
    $sp = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name");
    $sp->bind_param("i", $region_id);
    $sp->execute(); $provinces = $sp->get_result(); $sp->close();
}
$municipalities = null;
if ($province_id > 0) {
    $sm = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
    $sm->bind_param("i", $province_id);
    $sm->execute(); $municipalities = $sm->get_result(); $sm->close();
}
$barangays_opts = null;
if ($municipality_id > 0) {
    $sb = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
    $sb->bind_param("i", $municipality_id);
    $sb->execute(); $barangays_opts = $sb->get_result(); $sb->close();
}

/* ---------- Right-rail stats (respect filters) ---------- */
$types = ''; $params = [];
$where_pw = [];
$join_pw = " LEFT JOIN municipalities m ON m.id = pw.municipality_id
             LEFT JOIN provinces pr ON pr.id = m.province_id
             LEFT JOIN barangays b ON b.id = pw.barangay_id ";
if ($barangay_id > 0)      { $where_pw[]="pw.barangay_id = ?";    $types.='i'; $params[]=$barangay_id; }
elseif ($municipality_id>0){ $where_pw[]="pw.municipality_id = ?"; $types.='i'; $params[]=$municipality_id; }
elseif ($province_id > 0)  { $where_pw[]="pr.id = ?";              $types.='i'; $params[]=$province_id; }
elseif ($region_id > 0)    { $where_pw[]="pr.region_id = ?";       $types.='i'; $params[]=$region_id; }

$sql_tot = "SELECT COUNT(*) c FROM pregnant_women pw $join_pw";
if ($where_pw) $sql_tot .= " WHERE ".implode(" AND ", $where_pw);
$st = $conn->prepare($sql_tot);
if ($types) $st->bind_param($types, ...$params);
$st->execute(); $tot_patients = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
$tot_pregnant = $tot_patients;

$typesB=''; $paramsB=[]; $whereB=[];
$joinB = " JOIN municipalities m ON m.id = b.municipality_id
           JOIN provinces pr ON pr.id = m.province_id ";
if ($municipality_id>0) { $whereB[]="b.municipality_id = ?"; $typesB.='i'; $paramsB[]=$municipality_id; }
elseif ($province_id>0) { $whereB[]="m.province_id = ?";      $typesB.='i'; $paramsB[]=$province_id; }
elseif ($region_id>0)   { $whereB[]="pr.region_id = ?";       $typesB.='i'; $paramsB[]=$region_id; }
elseif ($barangay_id>0) { $whereB[]="b.id = ?";               $typesB.='i'; $paramsB[]=$barangay_id; }

$sql_brgy = "SELECT COUNT(*) c FROM barangays b $joinB";
if ($whereB) $sql_brgy .= " WHERE ".implode(" AND ", $whereB);
$sb = $conn->prepare($sql_brgy);
if ($typesB) $sb->bind_param($typesB, ...$paramsB);
$sb->execute(); $tot_brgy = (int)($sb->get_result()->fetch_assoc()['c'] ?? 0); $sb->close();

/* ---------- Checkups table/column detection ---------- */
$HAS_CHECKUPS = table_exists($conn, 'prenatal_checkups');
$checkups_patient_col = null;
if ($HAS_CHECKUPS) {
    $checkups_patient_col = first_existing_column($conn, 'prenatal_checkups',
        ['patient_id','pregnant_woman_id','woman_id','pw_id','pregnancy_id']);
}

/* ---------- Enrolled patients (respect filters + search) ---------- */
$sql = "
    SELECT 
        p.*,
        b.name AS barangay,
        e.enrolled_at,
        d.lmp           AS d_lmp,
        d.edd           AS d_edd,
        d.next_schedule AS d_next_schedule,
        d.checkups_done AS d_checkups_done,
        d.risk_level    AS d_risk
";
if ($HAS_CHECKUPS && $checkups_patient_col) {
    $sql .= ",
        (SELECT COUNT(*) FROM prenatal_checkups c WHERE c.`{$checkups_patient_col}` = p.id) AS chk_count
    ";
} else {
    $sql .= ", NULL AS chk_count ";
}
$sql .= "
    FROM prenatal_enrollments e
    INNER JOIN pregnant_women p ON p.id = e.patient_id
    LEFT JOIN prenatal_monitoring_details d ON d.patient_id = e.patient_id
    LEFT JOIN barangays b ON b.id = p.barangay_id
    LEFT JOIN municipalities m ON m.id = p.municipality_id
    LEFT JOIN provinces pr ON pr.id = m.province_id
    LEFT JOIN regions r ON r.id = pr.region_id
    WHERE 1=1
";
$typesL=''; $paramsL=[];
if ($barangay_id > 0)       { $sql .= " AND p.barangay_id = ?";      $typesL.='i'; $paramsL[]=$barangay_id; }
elseif ($municipality_id>0) { $sql .= " AND p.municipality_id = ?";  $typesL.='i'; $paramsL[]=$municipality_id; }
elseif ($province_id > 0)   { $sql .= " AND pr.id = ?";              $typesL.='i'; $paramsL[]=$province_id; }
elseif ($region_id > 0)     { $sql .= " AND r.id = ?";               $typesL.='i'; $paramsL[]=$region_id; }
if ($q !== '') {
    $sql .= " AND (CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ? OR b.name LIKE ?)";
    $like = "%$q%"; $typesL .= "ss"; $paramsL[]=$like; $paramsL[]=$like;
}
$sql .= " ORDER BY e.enrolled_at DESC, p.last_name IS NULL, p.last_name ASC, p.first_name ASC";
$stmt = $conn->prepare($sql);
if ($typesL) $stmt->bind_param($typesL, ...$paramsL);
$stmt->execute();
$rows = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Prenatal Monitoring (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{ --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb; --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6a; }
    *{ box-sizing:border-box }
    body{ margin:0; background:#fff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }
    .main{ padding:24px; background:#fff; }
    .filters{ display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
    .topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px; flex-wrap:wrap; }
    .searchbar{ position:relative; }
    .searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
    .searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }
    .add-links a{ display:block; font-weight:700; color:var(--brand); text-decoration:none; }
    .add-links a:hover{ color:var(--brand-dark); text-decoration:underline; }

    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:0; overflow:hidden; }
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

    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg,var(--teal-1),var(--teal-2)); box-shadow:0 10px 28px rgba(16,185,129,.2); }
    .stat.gradient .label{ color:#e7fffb; }

    @media (max-width:1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column:1 / -1; } }
    @media (max-width:992px){ .leftbar{ width:100%; border-right:none; border-bottom:1px solid #eef0f3; } }
</style>
</head>
<body>

<div class="layout">
    <!-- Left -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div>RHU-MIS</div>
                <small class="text-muted">SUPER ADMIN</small>
            </div>
        </div>

        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="super_admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="superadmin_patients.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="superadmin_manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="superadmin_manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="superadmin_barangay_health_centers.php"><i class="bi bi-building"></i> Brgy. Health Centers</a>
            <a class="nav-link active" href="superadmin_prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <!-- Filters -->
        <form method="get" class="filters">
            <div>
                <label class="form-label">Region</label>
                <select name="region_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">All Regions</option>
                    <?php if ($regions && $regions->num_rows): while($r=$regions->fetch_assoc()): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $region_id==(int)$r['id']?'selected':'' ?>><?= h($r['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Province</label>
                <select name="province_id" class="form-select" <?= $region_id? '':'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $region_id? 'All Provinces' : '— Select Region —' ?></option>
                    <?php if ($provinces && $provinces->num_rows): while($p=$provinces->fetch_assoc()): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $province_id==(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Municipality</label>
                <select name="municipality_id" class="form-select" <?= $province_id? '':'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $province_id? 'All Municipalities' : '— Select Province —' ?></option>
                    <?php if ($municipalities && $municipalities->num_rows): while($m=$municipalities->fetch_assoc()): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= $municipality_id==(int)$m['id']?'selected':'' ?>><?= h($m['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Barangay</label>
                <select name="barangay_id" class="form-select" <?= $municipality_id? '':'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $municipality_id? 'All Barangays' : '— Select Municipality —' ?></option>
                    <?php if ($barangays_opts && $barangays_opts->num_rows): while($b=$barangays_opts->fetch_assoc()): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $barangay_id==(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="ms-auto" style="min-width:280px;">
                <label class="form-label">Search</label>
                <div class="searchbar">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search patient or barangay" value="<?= h($q) ?>">
                </div>
            </div>
            <div class="align-self-end">
                <button class="btn btn-outline-secondary">Apply</button>
                <a class="btn btn-outline-dark" href="superadmin_prenatal_monitoring.php">Reset</a>
            </div>
        </form>

        <!-- Topbar Quick Actions (added) -->
        <div class="topbar">
            <div></div>
            <div class="add-links text-end">
                <a href="superadmin_add_monitoring.php">+ Add&nbsp;&nbsp;Patient for Monitoring</a>
                <a href="superadmin_add_previous_pregnancy.php">+ Add Previous Pregnancy Record</a>
                <a href="superadmin_manage_prenatal_unenroll.php">- Unenroll Patient</a>
            </div>
        </div>

        <h4 class="mb-3">Prenatal Monitoring</h4>

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
                            $name        = full_name($r);
                            $edd_display = calc_edd_display($r['d_edd'] ?? null, $r['d_lmp'] ?? null);

                            // PRIMARY: count from prenatal_checkups; fallback to details.checkups_done; clamp to 3
                            if (isset($r['chk_count']) && is_numeric($r['chk_count'])) {
                                $done = (int)$r['chk_count'];
                            } elseif (isset($r['d_checkups_done']) && is_numeric($r['d_checkups_done'])) {
                                $done = (int)$r['d_checkups_done'];
                            } else {
                                $done = 0;
                            }
                            $required = 3;
                            if ($done > $required) $done = $required;

                            $next_sched  = pretty_date_or_na($r['d_next_schedule'] ?? null);

                            // status by checkups_done (simple)
                            $is_done     = ($done >= $required);
                            $status_txt  = $is_done ? 'Completed' : 'Under Monitoring';
                            $status_cls  = $is_done ? 'status-done' : 'status-under';

                            // risk color: prefer stored risk_level, else age heuristic
                            $risk        = strtolower($r['d_risk'] ?? '');
                            if (!in_array($risk, ['normal','caution','high'], true)) {
                                $age = calc_age($r['dob'] ?? null);
                                if ($age !== null && ($age < 18 || $age > 35)) $risk = 'high';
                                else $risk = 'normal';
                            }
                            $riskClass = $risk === 'high' ? 'risk-high' : ($risk === 'caution' ? 'risk-caution' : 'risk-normal');
                        ?>
                        <tr>
                            <td class="text-start">
                                <a class="name-risk <?= $riskClass ?>" href="superadmin_view_pregnancy_detail.php?id=<?= (int)$r['id']; ?>">
                                    <?= h($name ?: '—') ?>
                                </a>
                            </td>
                            <td class="text-center"><?= h($r['barangay'] ?? '—') ?></td>
                            <td class="text-center"><?= $edd_display ?></td>
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

    <!-- Right -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?= h($_SESSION['username'] ?? 'superadmin') ?></div>
                <div class="text-muted small">@superadmin</div>
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
