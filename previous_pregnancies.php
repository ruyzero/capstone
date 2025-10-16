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

/* ---------- Right rail stats ---------- */
$c1 = $conn->prepare("SELECT COUNT(*) AS c FROM pregnant_women WHERE municipality_id=?");
$c1->bind_param("i",$municipality_id); $c1->execute();
$tot_patients = (int)($c1->get_result()->fetch_assoc()['c'] ?? 0);

$c2 = $conn->prepare("SELECT COUNT(*) AS c FROM barangays WHERE municipality_id=?");
$c2->bind_param("i",$municipality_id); $c2->execute();
$tot_brgy = (int)($c2->get_result()->fetch_assoc()['c'] ?? 0);

$tot_pregnant = $tot_patients;

/* ---------- Data for selects ---------- */
$barangays = [];
$bs = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id=? ORDER BY name");
$bs->bind_param("i",$municipality_id); $bs->execute();
$resB = $bs->get_result(); while ($row=$resB->fetch_assoc()) { $barangays[]=$row; } $bs->close();

$patients = []; // preload minimal info; filter on the client
$ps = $conn->prepare("SELECT id, barangay_id, first_name, middle_name, last_name, suffix FROM pregnant_women WHERE municipality_id=? ORDER BY last_name, first_name");
$ps->bind_param("i",$municipality_id); $ps->execute();
$resP = $ps->get_result();
while ($row=$resP->fetch_assoc()) {
    $row['full_name'] = full_name($row);
    $patients[] = $row;
}
$ps->close();

/* ---------- Ensure table exists (warn only) ---------- */
$HAS_PREV = table_exists($conn, 'previous_pregnancies');

/* ---------- Input selections ---------- */
$sel_barangay = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;
$sel_patient  = isset($_GET['patient_id'])  ? (int)$_GET['patient_id']  : 0;

/* ---------- Handle POST (save) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$HAS_PREV) { $_SESSION['error'] = "Table previous_pregnancies does not exist. See DDL note below."; header("Location: previous_pregnancies.php"); exit(); }

    $sel_barangay = (int)($_POST['barangay_id'] ?? 0); // keep selection after save

    $patient_id   = (int)($_POST['patient_id'] ?? 0);
    $preg_no      = ($_POST['pregnancy_no'] ?? '') === '' ? null : (int)$_POST['pregnancy_no'];
    $delivery_dt  = trim($_POST['delivery_date'] ?? '');
    $delivery_tp  = trim($_POST['delivery_type'] ?? '');
    $birth_out    = trim($_POST['birth_outcome'] ?? '');
    $children     = ($_POST['children_delivered'] ?? '') === '' ? null : (int)$_POST['children_delivered'];

    $pi_htn       = isset($_POST['pi_htn']) ? (int)$_POST['pi_htn'] : 0;
    $preeclamp    = isset($_POST['preeclampsia']) ? (int)$_POST['preeclampsia'] : 0;
    $bleeding     = isset($_POST['bleeding']) ? (int)$_POST['bleeding'] : 0;

    if (!$patient_id) {
        $_SESSION['error'] = "Please select a patient.";
        header("Location: previous_pregnancies.php"); exit();
    }

    // UPSERT by (patient_id, pregnancy_no)
    $sql = "INSERT INTO previous_pregnancies
            (patient_id, pregnancy_no, delivery_date, delivery_type, birth_outcome, children_delivered,
             preg_induced_htn, preeclampsia_eclampsia, bleeding_during_pregnancy,
             created_at, created_by, updated_at, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?, NOW(), ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
              delivery_date=VALUES(delivery_date),
              delivery_type=VALUES(delivery_type),
              birth_outcome=VALUES(birth_outcome),
              children_delivered=VALUES(children_delivered),
              preg_induced_htn=VALUES(preg_induced_htn),
              preeclampsia_eclampsia=VALUES(preeclampsia_eclampsia),
              bleeding_during_pregnancy=VALUES(bleeding_during_pregnancy),
              updated_at=NOW(), updated_by=VALUES(updated_by)";

    $st = $conn->prepare($sql);

    // IMPORTANT: bind_param requires variables by reference (no expressions)
    $delivery_date_v = ($delivery_dt === '') ? null : $delivery_dt;
    $delivery_type_v = ($delivery_tp !== '') ? $delivery_tp : null;
    $birth_out_v     = ($birth_out !== '')   ? $birth_out   : null;

    /* Types: patient(i), preg_no(i), date(s), type(s), outcome(s),
              children(i), pih(i), preeclamp(i), bleeding(i), created_by(i), updated_by(i)
              => 11 vars => 'iisssiiiiii' */
    $st->bind_param(
        'iisssiiiiii',
        $patient_id,
        $preg_no,
        $delivery_date_v,
        $delivery_type_v,
        $birth_out_v,
        $children,
        $pi_htn,
        $preeclamp,
        $bleeding,
        $user_id,
        $user_id
    );

    $st->execute(); $st->close();

    $_SESSION['success'] = "Previous pregnancy record saved.";
    // redirect keeping selections
    $redir_pid = $patient_id ?: 0;
    header("Location: previous_pregnancies.php?barangay_id={$sel_barangay}&patient_id={$redir_pid}"); exit();
}

/* ---------- Fetch existing records for selected patient ---------- */
$existing = [];
if ($HAS_PREV && $sel_patient) {
    $q = $conn->prepare("SELECT * FROM previous_pregnancies WHERE patient_id=? ORDER BY COALESCE(pregnancy_no, 9999) DESC, delivery_date DESC");
    $q->bind_param("i", $sel_patient); $q->execute();
    $existing = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
}

/* ---------- Flash ---------- */
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Add Previous Pregnancy • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{ --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb; --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6a; }
    *{ box-sizing:border-box } body{ margin:0; background:#fff; font-family:'Inter', system-ui,-apple-system, Segoe UI, Roboto, Arial; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .main{ padding:24px; background:#fff; }
    .form-card{ max-width:560px; margin:0 auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
    .form-card h4{ text-align:center; font-family:'Merriweather',serif; font-weight:700; margin-bottom:18px; }
    .form-control, .form-select{ height:44px; }
    .submit-btn{ display:block; margin:18px auto 0; padding:10px 28px; border-radius:999px; border:none; background:linear-gradient(135deg,#20C4B2,#1A9E9D); color:#fff; font-weight:800; }
    .panel{ max-width:900px; margin:20px auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:0; overflow:hidden; }
    .panel h5{ padding:16px 18px; }
    .table > :not(caption) > * > *{ vertical-align:middle; }
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{ color:#fff; border:0; background:linear-gradient(160deg,var(--teal-1),var(--teal-2)); box-shadow:0 10px 28px rgba(16,185,129,.2); }
    .stat.gradient .label{ color:#e7fffb; }
    @media (max-width:1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column:1/-1; } }
    @media (max-width:992px){ .leftbar{ width:100%; border-right:none; border-bottom:1px solid #eef0f3; } }
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
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-building"></i> Brgy. Health Centers</a>
            <a class="nav-link active" href="previous_pregnancies.php"><i class="bi bi-clipboard2-plus"></i> Previous Pregnancies</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts.php"><i class="bi bi-people-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <?php if (!$HAS_PREV): ?>
          <div class="alert alert-warning">
            <strong>Heads up:</strong> Table <code>previous_pregnancies</code> was not found. Create it using the DDL at the bottom of this page.
          </div>
        <?php endif; ?>

        <div class="form-card">
            <h4>Add Previous Pregnancy Record</h4>
            <form method="post" action="previous_pregnancies.php">
                <!-- barangay for filtering only -->
                <div class="mb-3">
                    <select class="form-select" id="barangay_id" name="barangay_id">
                        <option value="">Barangay</option>
                        <?php foreach ($barangays as $b): ?>
                          <option value="<?= (int)$b['id'] ?>" <?= $sel_barangay===(int)$b['id']?'selected':''; ?>><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Patient filtered client-side -->
                <div class="mb-3">
                    <select class="form-select" id="patient_id" name="patient_id" required>
                        <option value="">Select Patient</option>
                        <?php foreach ($patients as $p): ?>
                          <option value="<?= (int)$p['id'] ?>" data-brgy="<?= (int)$p['barangay_id'] ?>"
                            <?= $sel_patient===(int)$p['id']?'selected':''; ?>><?= h($p['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <input type="number" class="form-control" name="pregnancy_no" placeholder="Pregnancy Number" min="1">
                </div>

                <div class="mb-3">
                    <input type="date" class="form-control" name="delivery_date" placeholder="Delivery Date">
                </div>

                <div class="mb-3">
                    <input type="text" class="form-control" name="delivery_type" placeholder="Delivery Type">
                </div>

                <div class="mb-3">
                    <input type="text" class="form-control" name="birth_outcome" placeholder="Birth Outcome">
                </div>

                <div class="mb-3">
                    <select class="form-select" name="children_delivered">
                        <option value="">Children Delivered</option>
                        <?php for ($i=1;$i<=8;$i++): ?>
                          <option value="<?= $i ?>"><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <select class="form-select" name="pi_htn">
                        <option value="0">Pregnancy Induced Hypertension — No</option>
                        <option value="1">Pregnancy Induced Hypertension — Yes</option>
                    </select>
                </div>

                <div class="mb-3">
                    <select class="form-select" name="preeclampsia">
                        <option value="0">Preeclampsia/Eclampsia — No</option>
                        <option value="1">Preeclampsia/Eclampsia — Yes</option>
                    </select>
                </div>

                <div class="mb-3">
                    <select class="form-select" name="bleeding">
                        <option value="0">Bleeding during Pregnancy — No</option>
                        <option value="1">Bleeding during Pregnancy — Yes</option>
                    </select>
                </div>

                <button class="submit-btn" type="submit">Submit</button>
            </form>
        </div>

        <?php if ($sel_patient && $HAS_PREV): ?>
        <div class="panel">
            <h5 class="mb-0">Existing Previous Pregnancy Records</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center">Pregnancy #</th>
                            <th class="text-center">Delivery Date</th>
                            <th class="text-center">Delivery Type</th>
                            <th class="text-center">Outcome</th>
                            <th class="text-center">Children</th>
                            <th class="text-center">PIH</th>
                            <th class="text-center">Pre/Eclamp</th>
                            <th class="text-center">Bleeding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($existing) === 0): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No previous records yet.</td></tr>
                        <?php else: foreach ($existing as $x): ?>
                            <tr>
                                <td class="text-center"><?= h($x['pregnancy_no'] ?? '—') ?></td>
                                <td class="text-center"><?= !empty($x['delivery_date']) ? date('M d, Y', strtotime($x['delivery_date'])) : '—' ?></td>
                                <td class="text-center"><?= h($x['delivery_type'] ?? '—') ?></td>
                                <td class="text-center"><?= h($x['birth_outcome'] ?? '—') ?></td>
                                <td class="text-center"><?= h($x['children_delivered'] ?? '—') ?></td>
                                <td class="text-center"><?= !empty($x['preg_induced_htn']) ? 'Yes' : 'No' ?></td>
                                <td class="text-center"><?= !empty($x['preeclampsia_eclampsia']) ? 'Yes' : 'No' ?></td>
                                <td class="text-center"><?= !empty($x['bleeding_during_pregnancy']) ? 'Yes' : 'No' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$HAS_PREV): ?>
        <div class="panel">
            <h5>SQL to create <code>previous_pregnancies</code></h5>
<pre class="m-3" style="white-space:pre-wrap">
CREATE TABLE IF NOT EXISTS previous_pregnancies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  pregnancy_no INT NULL,
  delivery_date DATE NULL,
  delivery_type VARCHAR(50) NULL,
  birth_outcome VARCHAR(30) NULL,
  children_delivered TINYINT UNSIGNED NULL,
  preg_induced_htn TINYINT(1) NOT NULL DEFAULT 0,
  preeclampsia_eclampsia TINYINT(1) NOT NULL DEFAULT 0,
  bleeding_during_pregnancy TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT NULL,
  UNIQUE KEY uniq_prev_preg (patient_id, pregnancy_no),
  KEY idx_prev_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
</pre>
        </div>
        <?php endif; ?>
    </main>

    <!-- Right rail -->
    <aside class="rail">
        <div class="profile d-flex align-items-center gap-2">
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
/* Client-side filter: when barangay changes, show only patients from that barangay */
const brgySel = document.getElementById('barangay_id');
const patSel  = document.getElementById('patient_id');

function filterPatients(){
  const brgy = brgySel.value;
  for (const opt of patSel.options){
    if (!opt.value) continue; // placeholder
    const ok = (!brgy || opt.dataset.brgy === brgy);
    opt.hidden = !ok;
    if (!ok && opt.selected) opt.selected = false;
  }
}
brgySel.addEventListener('change', filterPatients);
/* run on load to apply any preselected brgy */
filterPatients();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
