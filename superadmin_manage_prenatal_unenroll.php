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
function table_exists(mysqli $conn, string $name): bool {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $q->bind_param("s", $name); $q->execute();
    $ok = (bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}
function full_name($row) {
    $parts = array_filter([$row['first_name']??'',$row['middle_name']??'',$row['last_name']??'',$row['suffix']??''], fn($x)=>$x!==null && trim($x)!=='');
    return trim(implode(' ', $parts));
}
function age_of($dob) {
    if (!$dob) return null;
    try { $d = new DateTime($dob); return (new DateTime())->diff($d)->y; }
    catch (Exception $e) { return null; }
}

/* ---------- Ensure enrollments table exists ---------- */
$ENROLL_TABLE = 'prenatal_enrollments';
if (!table_exists($conn, $ENROLL_TABLE)) {
    die("Table `{$ENROLL_TABLE}` does not exist. Please create it first:
<pre>
CREATE TABLE `prenatal_enrollments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `enrolled_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `enrolled_by` INT NULL,
  INDEX (`patient_id`),
  INDEX (`enrolled_by`)
);
</pre>");
}

/* ---------- Filters (GET) ---------- */
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
    $sp->bind_param("i", $region_id); $sp->execute(); $provinces = $sp->get_result(); $sp->close();
}
$municipalities = null;
if ($province_id > 0) {
    $sm = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
    $sm->bind_param("i", $province_id); $sm->execute(); $municipalities = $sm->get_result(); $sm->close();
}
$barangays_opts = null;
if ($municipality_id > 0) {
    $sb = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
    $sb->bind_param("i", $municipality_id); $sb->execute(); $barangays_opts = $sb->get_result(); $sb->close();
}

/* ---------- Right rail stats (respect filters) ---------- */
$types=''; $params=[]; $where=[];
$join_pw = " LEFT JOIN municipalities m ON m.id = pw.municipality_id
             LEFT JOIN provinces pr ON pr.id = m.province_id";
if     ($barangay_id>0){ $where[]="pw.barangay_id=?"; $types.='i'; $params[]=$barangay_id; }
elseif ($municipality_id>0){ $where[]="pw.municipality_id=?"; $types.='i'; $params[]=$municipality_id; }
elseif ($province_id>0){ $where[]="pr.id=?"; $types.='i'; $params[]=$province_id; }
elseif ($region_id>0){ $where[]="pr.region_id=?"; $types.='i'; $params[]=$region_id; }

$sql_tot = "SELECT COUNT(*) c FROM pregnant_women pw $join_pw";
if ($where) $sql_tot .= " WHERE ".implode(" AND ", $where);
$st = $conn->prepare($sql_tot);
if ($types) $st->bind_param($types, ...$params);
$st->execute(); $tot_patients = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
$tot_pregnant = $tot_patients;

/* ---------- Flash ---------- */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ================================================
   Unenroll actions (NO enrolling here)
   ================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_unenroll'], $_POST['patient_id'])) {
    $pid = (int)$_POST['patient_id'];
    $del = $conn->prepare("DELETE FROM {$ENROLL_TABLE} WHERE patient_id = ? LIMIT 1");
    $del->bind_param("i", $pid); $del->execute();
    $_SESSION['flash'] = ($del->affected_rows > 0)
        ? ['type' => 'success', 'msg' => 'Patient unenrolled from prenatal monitoring.']
        : ['type' => 'info',    'msg' => 'Patient was not enrolled.'];
    $redir = "superadmin_manage_prenatal_unenroll.php?".http_build_query([
        'q'=>$q,'region_id'=>$region_id,'province_id'=>$province_id,'municipality_id'=>$municipality_id,'barangay_id'=>$barangay_id
    ]);
    header("Location: $redir"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_unenroll']) && !empty($_POST['patient_ids']) && is_array($_POST['patient_ids'])) {
    $selected = array_map('intval', $_POST['patient_ids']);
    $removed = 0;
    $del = $conn->prepare("DELETE FROM {$ENROLL_TABLE} WHERE patient_id = ?");
    foreach ($selected as $pid) {
        $del->bind_param("i", $pid);
        if ($del->execute() && $del->affected_rows > 0) $removed++;
    }
    $_SESSION['flash'] = ['type'=>'success','msg'=>"Unenrollment complete — Removed: {$removed}."];
    $redir = "superadmin_manage_prenatal_unenroll.php?".http_build_query([
        'q'=>$q,'region_id'=>$region_id,'province_id'=>$province_id,'municipality_id'=>$municipality_id,'barangay_id'=>$barangay_id
    ]);
    header("Location: $redir"); exit();
}

/* ---------- Fetch ONLY ENROLLED patients (respect filters + search) ---------- */
$sql = "
    SELECT 
        p.id,
        p.first_name, p.middle_name, p.last_name, p.suffix,
        p.dob, p.date_registered,
        b.name AS barangay,
        e.enrolled_at,
        e.enrolled_by
    FROM {$ENROLL_TABLE} e
    JOIN pregnant_women p ON p.id = e.patient_id
    LEFT JOIN barangays b ON b.id = p.barangay_id
    LEFT JOIN municipalities m ON m.id = p.municipality_id
    LEFT JOIN provinces pr ON pr.id = m.province_id
    LEFT JOIN regions r ON r.id = pr.region_id
    WHERE 1=1
";
$typesL=''; $paramsL=[];
if     ($barangay_id>0){ $sql.=" AND p.barangay_id=?"; $typesL.='i'; $paramsL[]=$barangay_id; }
elseif ($municipality_id>0){ $sql.=" AND p.municipality_id=?"; $typesL.='i'; $paramsL[]=$municipality_id; }
elseif ($province_id>0){ $sql.=" AND pr.id=?"; $typesL.='i'; $paramsL[]=$province_id; }
elseif ($region_id>0){ $sql.=" AND r.id=?"; $typesL.='i'; $paramsL[]=$region_id; }
if ($q !== '') {
    $sql .= " AND (CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ? OR b.name LIKE ?)";
    $like = "%$q%"; $typesL.='ss'; $paramsL[]=$like; $paramsL[]=$like;
}
$sql .= " ORDER BY p.last_name IS NULL, p.last_name ASC, p.first_name ASC";
$stmt = $conn->prepare($sql);
if ($typesL) $stmt->bind_param($typesL, ...$paramsL);
$stmt->execute(); $patients = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Unenroll (Prenatal) — Super Admin • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
    :root{ --teal-1:#20C4B2; --teal-2:#1A9E9D; --ring:#e5e7eb; --sidebar-w:260px;}
    body{ background:#fff; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-weight:700; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .main{ padding:24px; }
    .filters{ display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:18px; }
    .table > :not(caption) > * > *{ vertical-align:middle; }
    .table thead th{ color:#0f172a; font-weight:700; background:#f7fafc; border-bottom:1px solid #eef0f3 !important; }
    .pill{ display:inline-block; padding:.25rem .6rem; border-radius:999px; background:#eef0f3; color:#334155; font-weight:700; font-size:.85rem; }
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; } .stat .big{ font-size:64px; font-weight:800; }
    .stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg,var(--teal-1),var(--teal-2)); }
</style>
</head>
<body>

<div class="layout">
    <!-- Left -->
    <aside class="leftbar">
        <div class="brand"><div class="mark">R</div><div><div>RHU-MIS</div><small class="text-muted">SUPER ADMIN</small></div></div>
        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="super_admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="superadmin_prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Monitoring</a>
            <a class="nav-link active" href="#"><i class="bi bi-clipboard2-x"></i> Unenroll (Prenatal)</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h4 class="mb-0">Manage Prenatal Enrollments (Unenroll)</h4>
            <a href="superadmin_add_monitoring.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-plus-circle"></i> Enroll Patients
            </a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mt-3"><?= h($flash['msg'] ?? '') ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <form class="filters" method="get" action="">
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
                <select name="province_id" class="form-select" <?= $region_id? '' : 'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $region_id ? 'All Provinces' : '— Select Region —' ?></option>
                    <?php if ($provinces && $provinces->num_rows): while($p=$provinces->fetch_assoc()): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $province_id==(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Municipality</label>
                <select name="municipality_id" class="form-select" <?= $province_id? '' : 'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $province_id ? 'All Municipalities' : '— Select Province —' ?></option>
                    <?php if ($municipalities && $municipalities->num_rows): while($m=$municipalities->fetch_assoc()): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= $municipality_id==(int)$m['id']?'selected':'' ?>><?= h($m['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Barangay</label>
                <select name="barangay_id" class="form-select" <?= $municipality_id? '' : 'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $municipality_id ? 'All Barangays' : '— Select Municipality —' ?></option>
                    <?php if ($barangays_opts && $barangays_opts->num_rows): while($b=$barangays_opts->fetch_assoc()): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $barangay_id==(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="ms-auto" style="min-width:280px;">
                <label class="form-label">Search</label>
                <div class="position-relative">
                    <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#64748b"></i>
                    <input type="text" name="q" class="form-control" style="padding-left:36px" placeholder="Name or Barangay" value="<?= h($q) ?>">
                </div>
            </div>
            <div class="align-self-end">
                <button class="btn btn-outline-secondary">Apply</button>
                <a class="btn btn-outline-dark" href="superadmin_manage_prenatal_unenroll.php">Reset</a>
            </div>
        </form>

        <!-- Bulk Unenroll form (hidden) -->
        <form id="bulkUnenrollForm" method="post" class="d-none">
            <input type="hidden" name="bulk_unenroll" value="1">
            <div id="bulkContainer"></div>
        </form>

        <!-- Table -->
        <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Currently Enrolled Patients</h6>
                <button type="button" class="btn btn-danger btn-sm" onclick="submitBulkUnenroll()">
                    <i class="bi bi-x-circle"></i> Unenroll Selected
                </button>
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="width:42px"><input type="checkbox" id="chkAll" /></th>
                            <th class="text-start">Fullname</th>
                            <th class="text-center" style="width:100px">Age</th>
                            <th class="text-center" style="width:180px">Barangay</th>
                            <th class="text-center" style="width:170px">Date Registered</th>
                            <th class="text-center" style="width:180px">Enrolled At</th>
                            <th class="text-center" style="width:150px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($patients && $patients->num_rows > 0): ?>
                        <?php while ($row = $patients->fetch_assoc()):
                            $name    = full_name($row);
                            $age     = $row['dob'] ? age_of($row['dob']) : null;
                            $dateReg = $row['date_registered'] ? date('M d, Y', strtotime($row['date_registered'])) : '—';
                            $enAt    = $row['enrolled_at'] ? date('M d, Y g:i A', strtotime($row['enrolled_at'])) : '—';
                        ?>
                        <tr>
                            <td class="text-center">
                                <input type="checkbox" class="selbox" value="<?= (int)$row['id'] ?>">
                            </td>
                            <td class="text-start"><?= h($name ?: '—') ?></td>
                            <td class="text-center"><?= $age !== null ? (int)$age : '—' ?></td>
                            <td class="text-center"><?= h($row['barangay'] ?? '—') ?></td>
                            <td class="text-center"><span class="pill"><?= $dateReg ?></span></td>
                            <td class="text-center"><span class="pill"><?= $enAt ?></span></td>
                            <td class="text-center">
                                <form method="post" class="d-inline" onsubmit="return confirm('Unenroll this patient from monitoring?');">
                                    <input type="hidden" name="single_unenroll" value="1">
                                    <input type="hidden" name="patient_id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">
                                        <i class="bi bi-x-circle"></i> Unenroll
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No enrolled patients found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-end mt-2">
                <button type="button" class="btn btn-danger" onclick="submitBulkUnenroll()">
                    <i class="bi bi-x-circle"></i> Unenroll Selected
                </button>
            </div>
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
        <div class="stat"><div class="label">Total Patient Record</div><div class="big"><?= $tot_patients ?></div></div>
        <div class="stat gradient"><div class="label">Total Pregnant Patient</div><div class="big"><?= $tot_pregnant ?></div></div>
    </aside>
</div>

<script>
document.getElementById('chkAll')?.addEventListener('change', function(){
    document.querySelectorAll('.selbox').forEach(cb => cb.checked = this.checked);
});

function submitBulkUnenroll(){
    const sel = Array.from(document.querySelectorAll('.selbox:checked')).map(cb => cb.value);
    if (sel.length === 0) { alert('Select at least one patient to unenroll.'); return; }
    if (!confirm('Unenroll selected patient(s) from monitoring?')) return;

    const form = document.getElementById('bulkUnenrollForm');
    const cont = document.getElementById('bulkContainer');
    cont.innerHTML = '';
    sel.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'patient_ids[]';
        input.value = id;
        cont.appendChild(input);
    });
    form.submit();
}
</script>
</body>
</html>
