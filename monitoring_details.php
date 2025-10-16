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
function as_date($v){ $v = trim($v ?? ''); return $v !== '' ? $v : null; }
function as_int_or_null($v){ return ($v === '' || $v === null) ? null : max(0, (int)$v); }
function as_float_or_null($v){ return ($v === '' || $v === null) ? null : (float)$v; }
function age_of($dob){
    if (!$dob) return null;
    try { $d = new DateTime($dob); return (new DateTime())->diff($d)->y; } catch (Exception $e){ return null; }
}

/* ---------- Identity / Session ---------- */
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';
$user_id           = (int)($_SESSION['user_id'] ?? 0);
$handle            = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

/* fallback: municipality name */
if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    if ($r = $stmt->get_result()->fetch_assoc()) $municipality_name = $r['name'];
    $stmt->close();
}

/* ---------- Input ---------- */
$patient_id = (int)($_GET['patient_id'] ?? 0);
if (!$municipality_id || !$patient_id) { die("Missing context."); }

/* ---------- Load patient + check enrollment ---------- */
$sqlP = "
    SELECT p.*, b.name AS barangay, e.enrolled_at
    FROM pregnant_women p
    LEFT JOIN barangays b ON b.id = p.barangay_id
    LEFT JOIN prenatal_enrollments e ON e.patient_id = p.id
    WHERE p.id = ? AND p.municipality_id = ?
    LIMIT 1
";
$sp = $conn->prepare($sqlP);
$sp->bind_param("ii", $patient_id, $municipality_id);
$sp->execute();
$patient = $sp->get_result()->fetch_assoc();
$sp->close();

if (!$patient) { die("Patient not found in your municipality."); }
if (empty($patient['enrolled_at'])) {
    // Not enrolled -> suggest going to add_monitoring.php (enrollment manager)
    die("This patient is not enrolled for Prenatal Monitoring yet. Enroll first via <a href='add_monitoring.php?show=not_enrolled&q=" . urlencode($patient['first_name'] . ' ' . $patient['last_name']) . "'>Add Monitoring</a>.");
}

/* ---------- Load existing monitoring details ---------- */
$details = [];
$sd = $conn->prepare("SELECT * FROM prenatal_monitoring_details WHERE patient_id = ? LIMIT 1");
$sd->bind_param("i", $patient_id);
$sd->execute();
$details = $sd->get_result()->fetch_assoc() ?: [];
$sd->close();

/* ---------- Save (insert/update) ---------- */
$msg = null; $msg_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lmp             = as_date($_POST['lmp'] ?? null);
    $edd             = as_date($_POST['edd'] ?? null);
    $gravida         = as_int_or_null($_POST['gravida'] ?? null);
    $para            = as_int_or_null($_POST['para'] ?? null);
    $abortions       = as_int_or_null($_POST['abortions'] ?? null);
    $height_cm       = as_float_or_null($_POST['height_cm'] ?? null);
    $weight_kg       = as_float_or_null($_POST['weight_kg'] ?? null);
    $risk_level_in   = strtolower(trim($_POST['risk_level'] ?? ''));
    $checkups_done   = as_int_or_null($_POST['checkups_done'] ?? 0);
    $next_schedule   = as_date($_POST['next_schedule'] ?? null);
    $notes           = trim($_POST['notes'] ?? '');

    $allowed_risk = ['normal','caution','high'];
    $risk_level   = in_array($risk_level_in, $allowed_risk, true) ? $risk_level_in : null;

    // Upsert (PRIMARY KEY = patient_id)
    $sqlU = "
        INSERT INTO prenatal_monitoring_details
        (patient_id, lmp, edd, gravida, para, abortions, height_cm, weight_kg, risk_level, checkups_done, next_schedule, notes, created_by, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          lmp=VALUES(lmp),
          edd=VALUES(edd),
          gravida=VALUES(gravida),
          para=VALUES(para),
          abortions=VALUES(abortions),
          height_cm=VALUES(height_cm),
          weight_kg=VALUES(weight_kg),
          risk_level=VALUES(risk_level),
          checkups_done=VALUES(checkups_done),
          next_schedule=VALUES(next_schedule),
          notes=VALUES(notes),
          updated_by=VALUES(updated_by)
    ";
    $st = $conn->prepare($sqlU);
    $types = "issiiiddsissii"; // i s s i i i d d s i s s i i  (14 items)
    $st->bind_param(
        $types,
        $patient_id, $lmp, $edd, $gravida, $para, $abortions, $height_cm, $weight_kg, $risk_level, $checkups_done, $next_schedule, $notes, $user_id, $user_id
    );

    if ($st->execute()) {
        $msg = "Monitoring details saved.";
        $msg_type = 'success';
        // reload fresh
        $st->close();
        $sd = $conn->prepare("SELECT * FROM prenatal_monitoring_details WHERE patient_id = ? LIMIT 1");
        $sd->bind_param("i", $patient_id);
        $sd->execute();
        $details = $sd->get_result()->fetch_assoc() ?: [];
        $sd->close();
    } else {
        $msg = "Failed to save: " . $st->error;
        $msg_type = 'danger';
        $st->close();
    }
}

/* ---------- View helpers ---------- */
$full_name = trim(implode(' ', array_filter([$patient['first_name'] ?? '', $patient['middle_name'] ?? '', $patient['last_name'] ?? '', $patient['suffix'] ?? ''])));
$age = age_of($patient['dob'] ?? null);
$barangay = $patient['barangay'] ?? '—';

$lmp_v  = $details['lmp'] ?? '';
$edd_v  = $details['edd'] ?? '';
$g_v    = $details['gravida'] ?? '';
$p_v    = $details['para'] ?? '';
$a_v    = $details['abortions'] ?? '';
$h_v    = $details['height_cm'] ?? '';
$w_v    = $details['weight_kg'] ?? '';
$risk_v = $details['risk_level'] ?? '';
$chk_v  = $details['checkups_done'] ?? 0;
$nxt_v  = $details['next_schedule'] ?? '';
$notes_v= $details['notes'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Monitoring Details • <?= h($full_name) ?> • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{
        --teal-1:#20C4B2; --teal-2:#1A9E9D; --ring:#e5e7eb; --sidebar-w:260px;
    }
    *{ box-sizing:border-box }
    body{ margin:0; background:#fff; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial; }

    /* 3-col grid: leftbar | main | rail */
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

    /* Left sidebar (like other pages) */
    .leftbar{ width:var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }

    /* Main */
    .main{ padding:24px; background:#fff; }
    .cardish{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:18px; }
    .hdr{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
    .hdr .title{ font-family:'Merriweather',serif; font-weight:700; font-size:1.15rem; }
    .muted{ color:#6b7280; }
    .form-control, .form-select{ height:44px; }

    /* Right rail (same style as dashboard) */
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:48px; font-weight:800; line-height:1; color:#111827; }

    @media (max-width:1100px){
        .layout{ grid-template-columns: var(--sidebar-w) 1fr; }
        .rail{ grid-column: 1 / -1; }
    }
    @media (max-width:992px){
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
            <a class="nav-link" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-building"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="add_monitoring.php?show=enrolled&q=<?= urlencode($full_name) ?>"><i class="bi bi-person-check"></i> Enrollment Manager</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="cardish mb-3">
            <div class="hdr">
                <div>
                    <div class="title"><?= h($full_name) ?></div>
                    <div class="muted">
                        Barangay: <?= h($barangay) ?> •
                        Age: <?= $age !== null ? (int)$age : '—' ?> •
                        Enrolled: <?= !empty($patient['enrolled_at']) ? h(date('M d, Y', strtotime($patient['enrolled_at']))) : '—' ?>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="prenatal_monitoring.php">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <a class="btn btn-outline-danger btn-sm" href="add_monitoring.php?show=enrolled&q=<?= urlencode($full_name) ?>">
                        Unenroll / Manage
                    </a>
                </div>
            </div>

            <?php if ($msg): ?>
                <div class="alert alert-<?= h($msg_type) ?> py-2 px-3 mb-3"><?= h($msg) ?></div>
            <?php endif; ?>

            <form method="POST" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">LMP</label>
                    <input type="date" class="form-control" name="lmp" id="lmp" value="<?= h($lmp_v) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">EDD</label>
                    <input type="date" class="form-control" name="edd" id="edd" value="<?= h($edd_v) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Gravida (G)</label>
                    <input type="number" min="0" class="form-control" name="gravida" value="<?= h($g_v) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Para (P)</label>
                    <input type="number" min="0" class="form-control" name="para" value="<?= h($p_v) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Abortions (A)</label>
                    <input type="number" min="0" class="form-control" name="abortions" value="<?= h($a_v) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Height (cm)</label>
                    <input type="number" step="0.1" min="0" class="form-control" name="height_cm" id="height_cm" value="<?= h($h_v) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Weight (kg)</label>
                    <input type="number" step="0.1" min="0" class="form-control" name="weight_kg" id="weight_kg" value="<?= h($w_v) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">BMI (auto)</label>
                    <input type="text" class="form-control" id="bmi" value="" readonly>
                    <small class="text-muted" id="bmi_note"></small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Risk Level</label>
                    <select class="form-select" name="risk_level">
                        <option value="">—</option>
                        <option value="normal"  <?= $risk_v==='normal'?'selected':'' ?>>Normal</option>
                        <option value="caution" <?= $risk_v==='caution'?'selected':'' ?>>Caution</option>
                        <option value="high"    <?= $risk_v==='high'?'selected':'' ?>>High</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Checkups Done</label>
                    <input type="number" min="0" class="form-control" name="checkups_done" value="<?= h((string)$chk_v) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Next Schedule</label>
                    <input type="date" class="form-control" name="next_schedule" value="<?= h($nxt_v) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notes / Remarks</label>
                    <textarea class="form-control" name="notes" rows="3" style="height:auto"><?= h($notes_v) ?></textarea>
                </div>

                <div class="col-12">
                    <button class="btn btn-primary"><i class="bi bi-save2"></i> Save Changes</button>
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

        <?php
        // small local stats for context (optional)
        $tot_patients = 0; $tot_brgy = 0; $tot_pregnant = 0;
        $s = $conn->prepare("SELECT COUNT(*) c FROM pregnant_women WHERE municipality_id = ?");
        $s->bind_param("i", $municipality_id); $s->execute();
        $tot_patients = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
        $s = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id = ?");
        $s->bind_param("i", $municipality_id); $s->execute();
        $tot_brgy = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
        $tot_pregnant = $tot_patients;
        ?>

        <div class="stat">
            <div class="label">Total Patient Record</div>
            <div class="big"><?= $tot_patients ?></div>
        </div>

        <div class="stat">
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
/* Auto EDD from LMP (+280 days) */
const lmp = document.getElementById('lmp');
const edd = document.getElementById('edd');
if (lmp && edd) {
  lmp.addEventListener('change', () => {
    if (!lmp.value) return;
    const d = new Date(lmp.value);
    d.setDate(d.getDate() + 280);
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    edd.value = `${y}-${m}-${day}`;
  });
}

/* BMI auto compute from height(cm) + weight(kg) */
const hEl = document.getElementById('height_cm');
const wEl = document.getElementById('weight_kg');
const bmiEl = document.getElementById('bmi');
const bmiNote = document.getElementById('bmi_note');

function updateBMI(){
  const h = parseFloat(hEl?.value || '');
  const w = parseFloat(wEl?.value || '');
  if (!isFinite(h) || !isFinite(w) || h <= 0) { bmiEl.value = ''; bmiNote.textContent = ''; return; }
  const m = h / 100.0;
  const bmi = w / (m*m);
  bmiEl.value = (Math.round(bmi * 10) / 10).toFixed(1);
  let cat = '';
  if (bmi < 18.5) cat = 'Underweight';
  else if (bmi < 25) cat = 'Normal';
  else if (bmi < 30) cat = 'Overweight';
  else cat = 'Obese';
  bmiNote.textContent = cat;
}
hEl?.addEventListener('input', updateBMI);
wEl?.addEventListener('input', updateBMI);
updateBMI(); // initialize with existing values
</script>
</body>
</html>
