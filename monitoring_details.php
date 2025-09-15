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
function table_columns(mysqli $conn, string $table): array {
    $cols = [];
    $q = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?;");
    $q->bind_param("s", $table);
    $q->execute();
    $r = $q->get_result();
    while ($row = $r->fetch_assoc()) { $cols[] = $row['column_name']; }
    $q->close();
    return $cols;
}
function try_fetch_details(mysqli $conn, string $table, int $patient_id): ?array {
    if (!table_exists($conn, $table)) return null;
    // Prefer WHERE patient_id if present
    $cols = table_columns($conn, $table);
    if (in_array('patient_id', $cols, true)) {
        $s = $conn->prepare("SELECT * FROM `$table` WHERE patient_id = ? LIMIT 1");
        $s->bind_param("i", $patient_id);
    } else {
        // fallback: generic id match won't help; skip
        return null;
    }
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}
function pick_details_source(mysqli $conn, int $patient_id): array {
    // Ordered by likelihood. Add/rename candidates if your schema differs.
    $candidates = [
        'prenatal_monitoring_details',
        'prenatal_monitoring',
        'monitoring_details',
        'prenatal_monitorings',
        'prenatal_records',
    ];
    foreach ($candidates as $t) {
        if (!table_exists($conn, $t)) continue;
        $row = try_fetch_details($conn, $t, $patient_id);
        if ($row) return [$t, $row];
    }
    // Nothing found; still pick the first existing table (even if empty) to write into
    foreach ($candidates as $t) {
        if (table_exists($conn, $t)) return [$t, null];
    }
    // No table at all — use the canonical name, we’ll create on first save
    return ['prenatal_monitoring_details', null];
}
function get_val(array $row = null, array $keys = []) {
    if (!$row) return null;
    foreach ($keys as $k) if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
    return null;
}
function full_name($r){
    $parts = array_filter([$r['first_name']??'', $r['middle_name']??'', $r['last_name']??'', $r['suffix']??''], fn($x)=>$x!==null && trim($x)!=='');
    return trim(implode(' ', $parts));
}
function age_of($dob){
    if (!$dob) return null;
    try { $d=new DateTime($dob); return (new DateTime())->diff($d)->y; } catch(Exception $e){ return null; }
}

/* ---------- Session / Identity ---------- */
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';
$user_id           = (int)($_SESSION['user_id'] ?? 0);
$handle            = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    if ($r = $stmt->get_result()->fetch_assoc()) $municipality_name = $r['name'];
    $stmt->close();
}
if (!$municipality_id) { die("No municipality set for this admin."); }

/* ---------- Input ---------- */
$patient_id = (int)($_GET['patient_id'] ?? 0);
if ($patient_id <= 0) { die("Invalid patient."); }

/* ---------- Load patient (scoped) ---------- */
$ps = $conn->prepare("
    SELECT p.*, b.name AS barangay
    FROM pregnant_women p
    LEFT JOIN barangays b ON b.id = p.barangay_id
    WHERE p.id = ? AND p.municipality_id = ?
    LIMIT 1
");
$ps->bind_param("ii", $patient_id, $municipality_id);
$ps->execute();
$patient = $ps->get_result()->fetch_assoc();
$ps->close();
if (!$patient) { die("Patient not found in your municipality."); }

/* ---------- Check enrollment ---------- */
$en = $conn->prepare("SELECT id, enrolled_at FROM prenatal_enrollments WHERE patient_id = ? LIMIT 1");
$en->bind_param("i", $patient_id);
$en->execute();
$enrollment = $en->get_result()->fetch_assoc();
$en->close();

/* ---------- Pick details table & load row ---------- */
list($details_table, $details_row) = pick_details_source($conn, $patient_id);
$details_cols = table_columns($conn, $details_table);

/* ---------- Actions ---------- */
$flash = null;

/* Unenroll */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unenroll'])) {
    $del = $conn->prepare("DELETE FROM prenatal_enrollments WHERE patient_id = ? LIMIT 1");
    $del->bind_param("i", $patient_id);
    $del->execute();
    $ok = $del->affected_rows > 0;
    $del->close();
    $flash = $ok ? ['type'=>'success','msg'=>'Patient unenrolled from Prenatal Monitoring.']
                 : ['type'=>'info','msg'=>'Patient was not enrolled.'];
    // keep showing the same page
}

/* Save/Update */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_details'])) {
    $first_checkup_date = $_POST['first_checkup_date'] ?: null;
    $weight_kg          = $_POST['weight_kg'] !== '' ? $_POST['weight_kg'] : null;
    $height_ft          = $_POST['height_ft'] !== '' ? $_POST['height_ft'] : null;
    $nutritional_status = $_POST['nutritional_status'] ?: null;
    $lmp                = $_POST['lmp'] ?: null;
    $edd                = $_POST['edd'] ?: null;
    $pregnancy_number   = $_POST['pregnancy_number'] !== '' ? $_POST['pregnancy_number'] : null;
    $remarks            = $_POST['remarks'] ?? null;

    if (!$edd && $lmp) { $t=strtotime($lmp.' +280 days'); if($t) $edd=date('Y-m-d',$t); }

    // Map desired fields to actual columns in $details_table
    $writeMap = [
        'first_checkup_date' => ['first_checkup_date','first_checkup','first_visit_date'],
        'weight_kg'          => ['weight_kg','weight','weightkg'],
        'height_ft'          => ['height_ft','height','heightft','height_feet'],
        'nutritional_status' => ['nutritional_status','nutrition_status'],
        'lmp'                => ['lmp','last_menstruation','last_menstrual_period'],
        'edd'                => ['edd','expected_delivery_date'],
        'pregnancy_number'   => ['pregnancy_number','gravida','preg_no'],
        'remarks'            => ['remarks','notes'],
        'updated_by'         => ['updated_by','updatedby','modified_by'],
        'created_by'         => ['created_by','createdby'],
    ];

    // Build the column=>value map that actually exists in table
    $data = [];
    $inputValues = compact('first_checkup_date','weight_kg','height_ft','nutritional_status','lmp','edd','pregnancy_number','remarks');
    foreach ($writeMap as $logical => $alts) {
        if ($logical === 'created_by' || $logical === 'updated_by') continue; // add separately later
        foreach ($alts as $col) {
            if (in_array($col, $details_cols, true)) {
                $data[$col] = $inputValues[$logical] ?? null;
                break;
            }
        }
    }
    // add updated_by / created_by if present
    foreach (['updated_by','created_by'] as $who) {
        foreach ($writeMap[$who] as $col) {
            if (in_array($col, $details_cols, true)) {
                $data[$col] = $user_id;
                break;
            }
        }
    }

    $has_patient_id = in_array('patient_id', $details_cols, true);

    // If table doesn't exist at all (very first time), create canonical table and switch to it
    if (!table_exists($conn, $details_table)) {
        $details_table = 'prenatal_monitoring_details';
        $conn->query("
            CREATE TABLE IF NOT EXISTS `prenatal_monitoring_details` (
              `patient_id` INT NOT NULL PRIMARY KEY,
              `first_checkup_date` DATE NULL,
              `weight_kg` DECIMAL(5,2) NULL,
              `height_ft` DECIMAL(4,2) NULL,
              `nutritional_status` VARCHAR(30) NULL,
              `lmp` DATE NULL,
              `edd` DATE NULL,
              `pregnancy_number` INT NULL,
              `remarks` VARCHAR(255) NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `created_by` INT NULL,
              `updated_by` INT NULL,
              INDEX (`created_by`), INDEX (`updated_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $details_cols = table_columns($conn, $details_table);
        $has_patient_id = true;
        // refresh data map for this canonical table
        $data = [
            'first_checkup_date' => $first_checkup_date,
            'weight_kg'          => $weight_kg,
            'height_ft'          => $height_ft,
            'nutritional_status' => $nutritional_status,
            'lmp'                => $lmp,
            'edd'                => $edd,
            'pregnancy_number'   => $pregnancy_number,
            'remarks'            => $remarks,
            'created_by'         => $user_id,
            'updated_by'         => $user_id,
        ];
    }

    $ok = false;
    if ($has_patient_id) {
        // Does a row exist?
        $exists = try_fetch_details($conn, $details_table, $patient_id) ? true : false;

        if ($exists) {
            // UPDATE
            $sets = [];
            $vals = [];
            foreach ($data as $col=>$val) { $sets[]="`$col`=?"; $vals[]=$val; }
            $vals[] = $patient_id;
            $sql = "UPDATE `$details_table` SET ".implode(',', $sets)." WHERE patient_id = ?";
            $stmt = $conn->prepare($sql);
            $types = str_repeat('s', count($vals)-1) . 'i';
            $stmt->bind_param($types, ...$vals);
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            // INSERT
            $cols = array_keys($data);
            $place = implode(',', array_fill(0, count($cols)+1, '?')); // + patient_id
            $sql = "INSERT INTO `$details_table` (`patient_id`, ".implode(',', array_map(fn($c)=>"`$c`",$cols)).") VALUES ($place)";
            $stmt = $conn->prepare($sql);
            $vals = array_merge([$patient_id], array_values($data));
            $types = 'i' . str_repeat('s', count($vals)-1);
            $stmt->bind_param($types, ...$vals);
            $ok = $stmt->execute();
            $stmt->close();
        }
    } else {
        // Table can't be updated safely; fallback to canonical table with upsert
        $details_table = 'prenatal_monitoring_details';
        $conn->query("
            CREATE TABLE IF NOT EXISTS `prenatal_monitoring_details` (
              `patient_id` INT NOT NULL PRIMARY KEY,
              `first_checkup_date` DATE NULL,
              `weight_kg` DECIMAL(5,2) NULL,
              `height_ft` DECIMAL(4,2) NULL,
              `nutritional_status` VARCHAR(30) NULL,
              `lmp` DATE NULL,
              `edd` DATE NULL,
              `pregnancy_number` INT NULL,
              `remarks` VARCHAR(255) NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `created_by` INT NULL,
              `updated_by` INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $up = $conn->prepare("
            INSERT INTO prenatal_monitoring_details
            (patient_id, first_checkup_date, weight_kg, height_ft, nutritional_status, lmp, edd, pregnancy_number, remarks, created_by, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              first_checkup_date=VALUES(first_checkup_date),
              weight_kg=VALUES(weight_kg),
              height_ft=VALUES(height_ft),
              nutritional_status=VALUES(nutritional_status),
              lmp=VALUES(lmp),
              edd=VALUES(edd),
              pregnancy_number=VALUES(pregnancy_number),
              remarks=VALUES(remarks),
              updated_by=VALUES(updated_by)
        ");
        $up->bind_param("isddssssssi", $patient_id,$first_checkup_date,$weight_kg,$height_ft,$nutritional_status,$lmp,$edd,$pregnancy_number,$remarks,$user_id,$user_id);
        $ok = $up->execute();
        $up->close();
    }

    $flash = $ok ? ['type'=>'success','msg'=>'Monitoring details saved.'] : ['type'=>'danger','msg'=>'Save failed.'];
    // refresh active row after save
    $details_row = try_fetch_details($conn, $details_table, $patient_id);
}

/* ---------- View model ---------- */
$full     = full_name($patient);
$age      = age_of($patient['dob'] ?? null);
$barangay = $patient['barangay'] ?? '—';

$first_checkup_date = get_val($details_row, ['first_checkup_date','first_checkup','first_visit_date']) ?? '';
$weight_kg          = get_val($details_row, ['weight_kg','weight','weightkg']) ?? '';
$height_ft          = get_val($details_row, ['height_ft','height','heightft','height_feet']) ?? '';
$nutritional_status = get_val($details_row, ['nutritional_status','nutrition_status']) ?? '';
$lmp                = get_val($details_row, ['lmp','last_menstruation','last_menstrual_period']) ?? '';
$edd                = get_val($details_row, ['edd','expected_delivery_date']) ?? '';
$pregnancy_number   = get_val($details_row, ['pregnancy_number','gravida','preg_no']) ?? '';
$remarks            = get_val($details_row, ['remarks','notes']) ?? '';

/* ---------- Right rail stats ---------- */
$tot_patients = $tot_brgy = $tot_pregnant = 0;
$s = $conn->prepare("SELECT COUNT(*) c FROM pregnant_women WHERE municipality_id = ?");
$s->bind_param("i", $municipality_id); $s->execute();
$tot_patients = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
$s = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id = ?");
$s->bind_param("i", $municipality_id); $s->execute();
$tot_brgy = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
$tot_pregnant = $tot_patients;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>View / Update Monitoring • RHU-MIS</title>
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
    body{ margin:0; background:#fff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans"; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

    .leftbar{ width: var(--sidebar-w); background:#ffffff; border-right: 1px solid #eef0f3; padding: 24px 16px; color:#111827; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather', serif; font-weight:700; color:#111; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background: linear-gradient(135deg, #25d3c7, #0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background: linear-gradient(135deg, #2fd4c8, #0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }

    .main{ padding:24px; background:#ffffff; }
    .topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; }
    .searchbar{ flex:1; max-width:640px; position:relative; }
    .searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
    .searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }

    .form-card{ max-width:520px; margin:0 auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
    .form-card h4{ text-align:center; font-family:'Merriweather',serif; font-weight:700; margin-bottom:18px; }
    .form-control, .form-select{ height:44px; }
    .pill{ display:inline-block; padding:.25rem .6rem; border-radius:999px; background:#eef0f3; color:#334155; font-weight:700; font-size:.85rem; }

    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; background:transparent; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg, var(--teal-1), var(--teal-2)); box-shadow: 0 10px 28px rgba(16,185,129,.2); }
    .stat.gradient .label{ color:#e7fffb; }

    @media (max-width: 1100px){
        .layout{ grid-template-columns: var(--sidebar-w) 1fr; }
        .rail{ grid-column: 1 / -1; }
    }
    @media (max-width: 992px){
        .leftbar{ width:100%; border-right:none; border-bottom:1px solid #eef0f3; }
    }
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
                <small class="text-muted"><?= h(strtoupper($municipality_name)) ?></small>
            </div>
        </div>

        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="view_pregnancies.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link active" href="#"><i class="bi bi-journal-medical"></i> View / Update Monitoring</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="topbar">
            <div class="searchbar w-100" style="max-width:720px;">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" placeholder="Search Here" disabled>
            </div>
            <div class="ms-3">
                <a href="prenatal_monitoring.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
        <?php endif; ?>

        <!-- Patient summary -->
        <div class="form-card mb-3" style="max-width:100%;">
            <div class="row g-3">
                <div class="col-md-4"><strong>Patient:</strong> <?= h($full) ?></div>
                <div class="col-md-2"><strong>Age:</strong> <?= $age!==null ? (int)$age : '—' ?></div>
                <div class="col-md-3"><strong>Barangay:</strong> <?= h($barangay) ?></div>
                <div class="col-md-3"><strong>Enrollment:</strong>
                    <span class="pill"><?= $enrollment ? date('M d, Y g:i A', strtotime($enrollment['enrolled_at'])) : 'Not enrolled' ?></span>
                </div>
            </div>
        </div>

        <!-- Update form -->
        <div class="form-card">
            <h4>View / Update Monitoring</h4>
            <?php if (!$enrollment): ?>
                <div class="alert alert-warning mb-3">
                    This patient isn’t enrolled in Prenatal Monitoring yet.
                    <a href="add_monitoring.php" class="alert-link">Enroll now</a>.
                </div>
            <?php endif; ?>

            <form method="POST" id="monitoringForm" novalidate>
                <input type="hidden" name="save_details" value="1">

                <div class="mb-3">
                    <div>First Check-Up Date</div>
                    <input type="date" class="form-control" name="first_checkup_date" id="first_checkup_date"
                           value="<?= h($first_checkup_date) ?>">
                </div>

                <div class="mb-3">
                    <input type="text" class="form-control" id="age_display" value="<?= $age!==null ? (int)$age : '' ?>" placeholder="Age" readonly>
                </div>

                <div class="mb-3">
                    <input type="number" step="0.1" min="0" class="form-control" name="weight_kg" placeholder="Weight (kg)"
                           value="<?= h($weight_kg) ?>">
                </div>

                <div class="mb-3">
                    <input type="number" step="0.01" min="0" class="form-control" name="height_ft" placeholder="Height (Ft)"
                           value="<?= h($height_ft) ?>">
                </div>

                <div class="mb-3">
                    <select class="form-select" name="nutritional_status">
                        <option value="">Nutritional Status</option>
                        <?php foreach (['Normal','Underweight','Overweight','Obese'] as $ns): ?>
                            <option value="<?= $ns ?>" <?= $nutritional_status===$ns?'selected':'' ?>><?= $ns ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <div>Last Menstruation Date</div>
                    <input type="date" class="form-control" name="lmp" id="lmp" value="<?= h($lmp) ?>">
                </div>

                <div class="mb-3">
                    <div>Expected Delivery Date</div>
                    <input type="date" class="form-control" name="edd" id="edd" value="<?= h($edd) ?>">
                </div>

                <div class="mb-3">
                    <input type="number" min="1" class="form-control" name="pregnancy_number" placeholder="Pregnancy Number"
                           value="<?= h($pregnancy_number) ?>">
                </div>

                <div class="mb-3">
                    <input type="text" class="form-control" name="remarks" placeholder="Remarks"
                           value="<?= h($remarks) ?>">
                </div>

                <div class="d-flex justify-content-between">
                    <button class="btn btn-danger" name="unenroll" value="1" type="submit"
                            onclick="return confirm('Unenroll this patient from Prenatal Monitoring?');">
                        <i class="bi bi-x-circle"></i> Unenroll
                    </button>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save2"></i> Save
                    </button>
                </div>
            </form>
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
/* Auto-calc EDD from LMP (+280 days) */
const lmp = document.getElementById('lmp');
const edd = document.getElementById('edd');
lmp?.addEventListener('change', () => {
    if (!lmp.value) return;
    const d = new Date(lmp.value);
    d.setDate(d.getDate() + 280);
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    edd.value = `${y}-${m}-${day}`;
});
</script>
</body>
</html>
