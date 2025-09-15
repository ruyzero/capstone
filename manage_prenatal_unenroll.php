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
    $ok = (bool)$q->get_result()->fetch_row();
    $q->close();
    return $ok;
}
function full_name($row) {
    $parts = array_filter([
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? '',
        $row['suffix'] ?? ''
    ], fn($x) => $x !== null && trim($x) !== '');
    return trim(implode(' ', $parts));
}
function age_of($dob) {
    if (!$dob) return null;
    try { $d = new DateTime($dob); return (new DateTime())->diff($d)->y; }
    catch (Exception $e) { return null; }
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

/* ---------- Flash helper ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Filters (GET) ---------- */
$q       = trim($_GET['q'] ?? '');
$brgy_id = (int)($_GET['barangay_id'] ?? 0);

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

/* ---------- Barangay options (scoped) ---------- */
$brgystmt = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
$brgystmt->bind_param("i", $municipality_id);
$brgystmt->execute();
$barangays = $brgystmt->get_result();

/* ================================================
   Unenroll actions (NO ENROLLING HERE)
   ================================================ */

/* -- Single-row UNENROLL -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_unenroll'], $_POST['patient_id'])) {
    $pid = (int)$_POST['patient_id'];

    // Preserve filters on redirect
    $redir_q   = $_POST['f_q']   ?? $q;
    $redir_brg = (int)($_POST['f_barangay_id'] ?? $brgy_id);

    // Validate patient belongs to this municipality
    $chk = $conn->prepare("SELECT id FROM pregnant_women WHERE id = ? AND municipality_id = ? LIMIT 1");
    $chk->bind_param("ii", $pid, $municipality_id);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid patient or outside your municipality.'];
        header("Location: manage_prenatal_unenroll.php?" . http_build_query(['q'=>$redir_q,'barangay_id'=>$redir_brg]));
        exit();
    }

    // Delete enrollment
    $del = $conn->prepare("DELETE FROM {$ENROLL_TABLE} WHERE patient_id = ? LIMIT 1");
    $del->bind_param("i", $pid);
    $del->execute();

    $_SESSION['flash'] = ($del->affected_rows > 0)
        ? ['type' => 'success', 'msg' => 'Patient unenrolled from prenatal monitoring.']
        : ['type' => 'info',    'msg' => 'Patient was not enrolled.'];

    header("Location: manage_prenatal_unenroll.php?" . http_build_query(['q'=>$redir_q,'barangay_id'=>$redir_brg]));
    exit();
}

/* -- Bulk UNENROLL -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_unenroll']) && !empty($_POST['patient_ids']) && is_array($_POST['patient_ids'])) {
    $selected = array_map('intval', $_POST['patient_ids']);
    $removed = 0; $invalid = 0;

    $chk = $conn->prepare("SELECT id FROM pregnant_women WHERE id = ? AND municipality_id = ? LIMIT 1");
    $del = $conn->prepare("DELETE FROM {$ENROLL_TABLE} WHERE patient_id = ?");

    foreach ($selected as $pid) {
        $chk->bind_param("ii", $pid, $municipality_id);
        $chk->execute();
        if (!$chk->get_result()->fetch_row()) { $invalid++; continue; }

        $del->bind_param("i", $pid);
        if ($del->execute() && $del->affected_rows > 0) $removed++;
    }

    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => "Unenrollment complete — Removed: {$removed}, Invalid: {$invalid}."
    ];
    // Preserve filters
    $redir_q   = $_POST['f_q']   ?? $q;
    $redir_brg = (int)($_POST['f_barangay_id'] ?? $brgy_id);
    header("Location: manage_prenatal_unenroll.php?" . http_build_query(['q'=>$redir_q,'barangay_id'=>$redir_brg]));
    exit();
}

/* ---------- Fetch ONLY ENROLLED patients ---------- */
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
    WHERE p.municipality_id = ?
";
$params = [$municipality_id];
$types  = "i";

if ($brgy_id > 0) {
    $sql .= " AND p.barangay_id = ?";
    $params[] = $brgy_id; $types .= "i";
}
if ($q !== '') {
    $sql .= " AND (CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ? OR b.name LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $types .= "ss";
}

$sql .= " ORDER BY p.last_name IS NULL, p.last_name ASC, p.first_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$patients = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Manage Prenatal Enrollments (Unenroll) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{
        --teal-1:#20C4B2; --teal-2:#1A9E9D; --ring:#e5e7eb;
        --sidebar-w:260px;
    }
    body{ background:#fff; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

    /* Left sidebar */
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }

    /* Main */
    .main{ padding:24px; background:#fff; }
    .topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; gap:16px; }
    .searchbar{ flex:1; position:relative; }
    .searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
    .searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }
    .filters .form-select, .filters .form-control { height:44px; }

    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:18px; }
    .table > :not(caption) > * > *{ vertical-align:middle; }
    .table thead th{ color:#0f172a; font-weight:700; background:#f7fafc; border-bottom:1px solid #eef0f3 !important; }
    .pill{ display:inline-block; padding:.25rem .6rem; border-radius:999px; background:#eef0f3; color:#334155; font-weight:700; font-size:.85rem; }

    /* Right rail */
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg, var(--teal-1), var(--teal-2)); box-shadow:0 10px 28px rgba(16,185,129,.2); }

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
            <a class="nav-link active" href="manage_prenatal_unenroll.php"><i class="bi bi-clipboard2-x"></i> Unenroll (Prenatal)</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <h4 class="mb-0">Manage Prenatal Enrollments (Unenroll)</h4>
            <a href="add_monitoring.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-plus-circle"></i> Enroll Patients
            </a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mt-3"><?= h($flash['msg'] ?? '') ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <form class="row g-2 align-items-end mt-3 mb-3 filters" method="get" action="">
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <div class="searchbar">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="form-control" placeholder="Name or Barangay" value="<?= h($q) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Barangay</label>
                <select name="barangay_id" class="form-select">
                    <option value="0">All Barangays</option>
                    <?php if ($barangays): while ($b = $barangays->fetch_assoc()): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $brgy_id===(int)$b['id']?'selected':'' ?>>
                            <?= h($b['name']) ?>
                        </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100" type="submit">Filter</button>
            </div>
        </form>

        <!-- Bulk Unenroll form (hidden container gets filled by JS) -->
        <form id="bulkUnenrollForm" method="post" class="d-none">
            <input type="hidden" name="bulk_unenroll" value="1">
            <input type="hidden" name="f_q" value="<?= h($q) ?>">
            <input type="hidden" name="f_barangay_id" value="<?= (int)$brgy_id ?>">
            <div id="bulkContainer"></div>
        </form>

        <!-- Table + actions -->
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
                                    <input type="hidden" name="f_q" value="<?= h($q) ?>">
                                    <input type="hidden" name="f_barangay_id" value="<?= (int)$brgy_id ?>">
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

<script>
/* Select all */
document.getElementById('chkAll')?.addEventListener('change', function(){
    document.querySelectorAll('.selbox').forEach(cb => cb.checked = this.checked);
});

/* Bulk UNENROLL: copy selected IDs into hidden form and submit */
function submitBulkUnenroll(){
    const sel = Array.from(document.querySelectorAll('.selbox:checked')).map(cb => cb.value);
    if (sel.length === 0) {
        alert('Select at least one patient to unenroll.');
        return;
    }
    if (!confirm('Unenroll selected patient(s) from monitoring?')) return;

    const cont = document.getElementById('bulkContainer');
    cont.innerHTML = '';
    sel.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'patient_ids[]';
        input.value = id;
        cont.appendChild(input);
    });
    document.getElementById('bulkUnenrollForm').submit();
}
</script>
</body>
</html>
