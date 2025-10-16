<?php
/**
 * RHU-MIS — Add Previous Pregnancy (Centered + Same Sidebars as Admin Dashboard)
 * File: add_previous_pregnancy.php
 */
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ----------------- AUTH ----------------- */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','midwife','barangay_midwife'])) {
  header("Location: index.php"); exit();
}

/* ----------------- CONFIG ----------------- */
$TABLE_BARANGAYS        = 'barangays';          // id, name, municipality_id
$TABLE_PATIENTS         = 'pregnant_women';     // id, first_name, last_name, barangay_id, municipality_id
$TABLE_PREV_PREG        = 'previous_pregnancies';
$CAN_SEE_ALL_BARANGAYS  = ($_SESSION['role'] === 'admin');
$LOGGED_USER_ID         = (int)($_SESSION['user_id'] ?? 0);

$MIDWIFE_BARANGAY_ID = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : null;

/* ----------------- DASHBOARD CONTEXT (for copied sidebars/rail) ----------------- */
$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$username        = $_SESSION['username'] ?? 'admin';

/* Helpers used by sidebar/rail */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fetch_count(mysqli $conn, string $sql, array $bind = [], string $types = ''): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return 0; }
    if ($types !== '' && !empty($bind)) { $stmt->bind_param($types, ...$bind); }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $stmt->close();
    return (int)($row[0] ?? 0);
}

/* Location for brand */
$location_stmt = $conn->prepare("
    SELECT m.name AS municipality, p.name AS province, r.name AS region
    FROM municipalities m
    LEFT JOIN provinces p ON m.province_id = p.id
    LEFT JOIN regions r   ON p.region_id = r.id
    WHERE m.id = ?
");
$location_stmt->bind_param("i", $municipality_id);
$location_stmt->execute();
$location = $location_stmt->get_result()->fetch_assoc() ?: [];
$municipality_name = $location['municipality'] ?? 'Unknown';
$location_stmt->close();

/* Right rail stats */
$totalPatients = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM pregnant_women WHERE municipality_id = ? AND sex = 'Female'",
    [$municipality_id],
    "i"
);
$totalBrgyCenters = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM barangays WHERE municipality_id = ?",
    [$municipality_id],
    "i"
);
$totalPregnant = $totalPatients; // same metric in your dashboard

$handle = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}") );

/* ----------------- PAGE HELPERS ----------------- */
function full_name_row(array $r): string {
  $parts = array_filter([
    $r['first_name'] ?? '',
    $r['middle_name'] ?? '',
    $r['last_name'] ?? '',
    $r['suffix'] ?? ''
  ], fn($x)=>trim((string)$x)!=='');
  if (!empty($parts)) return trim(implode(' ', $parts));
  return $r['name'] ?? ('Patient#'.$r['id']);
}
function okint($v){ return ($v!=='' && is_numeric($v)) ? (int)$v : null; }
function yesNoUnknown($v){
  $map = ['Yes'=>'Yes','No'=>'No','Unknown'=>'Unknown'];
  return $map[$v] ?? 'Unknown';
}

/* ----------------- AJAX: load patients by barangay ----------------- */
if (isset($_GET['ajax']) && $_GET['ajax']==='patients') {
  header('Content-Type: application/json; charset=utf-8');
  $barangay_id = (int)($_GET['barangay_id'] ?? 0);

  if (!$CAN_SEE_ALL_BARANGAYS && $MIDWIFE_BARANGAY_ID && $barangay_id !== $MIDWIFE_BARANGAY_ID) {
    echo json_encode(['ok'=>false, 'patients'=>[]]); exit;
  }

  $sql = "SELECT id, first_name, middle_name, last_name, suffix
          FROM {$GLOBALS['TABLE_PATIENTS']}
          WHERE barangay_id = ?
          ORDER BY last_name, first_name";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $barangay_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = ['id'=>$r['id'], 'text'=>full_name_row($r)];
  }
  $stmt->close();
  echo json_encode(['ok'=>true, 'patients'=>$rows]); exit;
}

/* ----------------- FETCH BARANGAYS ----------------- */
$barangays = [];
if ($CAN_SEE_ALL_BARANGAYS) {
  $q = $conn->prepare("SELECT id, name FROM {$TABLE_BARANGAYS} WHERE municipality_id = ? ORDER BY name");
  $q->bind_param("i", $municipality_id);
  $q->execute();
  $res = $q->get_result();
  while($r=$res->fetch_assoc()) $barangays[]=$r;
  $q->close();
} else {
  if ($MIDWIFE_BARANGAY_ID) {
    $stmt = $conn->prepare("SELECT id, name FROM {$TABLE_BARANGAYS} WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $MIDWIFE_BARANGAY_ID);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row=$res->fetch_assoc()) $barangays[]=$row;
    $stmt->close();
  }
}

/* ----------------- HANDLE POST ----------------- */
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $barangay_id   = okint($_POST['barangay_id'] ?? '');
  $patient_id    = okint($_POST['patient_id'] ?? '');
  $preg_no       = okint($_POST['pregnancy_number'] ?? '');
  $delivery_date = trim($_POST['delivery_date'] ?? '');
  $delivery_type = trim($_POST['delivery_type'] ?? '');
  $birth_outcome = trim($_POST['birth_outcome'] ?? '');
  $children      = okint($_POST['children_delivered'] ?? '');
  $pih           = yesNoUnknown($_POST['pregnancy_induced_htn'] ?? 'Unknown');
  $pec           = yesNoUnknown($_POST['preeclampsia_eclampsia'] ?? 'Unknown');
  $bleeding      = yesNoUnknown($_POST['bleeding_during_pregnancy'] ?? 'Unknown');

  if (!$barangay_id) $errors[] = "Please select a barangay.";
  if (!$patient_id)  $errors[] = "Please select a patient.";
  if (!$preg_no)     $errors[] = "Pregnancy number is required.";

  if (!$CAN_SEE_ALL_BARANGAYS && $MIDWIFE_BARANGAY_ID && $barangay_id !== $MIDWIFE_BARANGAY_ID) {
    $errors[] = "You are not allowed to submit for another barangay.";
  }

  if ($barangay_id && $patient_id) {
    $stmt = $conn->prepare("SELECT 1 FROM {$TABLE_PATIENTS} WHERE id=? AND barangay_id=? LIMIT 1");
    $stmt->bind_param("ii", $patient_id, $barangay_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$ok) $errors[] = "Selected patient does not belong to the chosen barangay.";
  }

  if ($delivery_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
    $errors[] = "Delivery date must be in YYYY-MM-DD format.";
  }

  if (empty($errors)) {
    $sql = "INSERT INTO {$TABLE_PREV_PREG}
      (barangay_id, patient_id, pregnancy_number, delivery_date, delivery_type, birth_outcome, children_delivered,
       induced_hypertension, preeclampsia_eclampsia, bleeding_during_pregnancy, created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);

    // ensure empty date becomes NULL
    $delivery_date_param = ($delivery_date==='') ? null : $delivery_date;

    $stmt->bind_param(
      "iiiississsi",
      $barangay_id, $patient_id, $preg_no, $delivery_date_param, $delivery_type, $birth_outcome, $children,
      $pih, $pec, $bleeding, $LOGGED_USER_ID
    );

    if ($stmt->execute()) {
      $success = true;
      $_POST = [];
    } else {
      $errors[] = "Database error: ".$stmt->error;
    }
    $stmt->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Previous Pregnancy - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{
        --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb;
        --sidebar-w:260px;
    }
    *{ box-sizing:border-box }
    body{ margin:0; background:var(--bg); font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

    /* LEFT SIDEBAR (copied) */
    .leftbar{
        width: var(--sidebar-w);
        background:#ffffff;
        border-right: 1px solid #eef0f3;
        padding: 24px 16px;
        color:#111827;
    }
    .brand{
        display:flex; gap:10px; align-items:center; margin-bottom:24px;
        font-family: 'Merriweather', serif; font-weight:700; color:#111;
    }
    .brand .mark{
        width:36px; height:36px; border-radius:50%;
        background: linear-gradient(135deg, #25d3c7, #0fb5aa);
        display:grid; place-items:center; color:#fff; font-weight:800;
    }
    .nav-link{
        color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600;
    }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background: linear-gradient(135deg, #2fd4c8, #0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }

    /* MAIN */
    .main{ padding:24px; }
    .searchwrap{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--ring); border-radius:14px; padding:10px 14px; max-width:560px; }
    .searchwrap input{ border:0; outline:0; width:100%; }
    .section-title{ font-weight:800; margin:20px 0 12px; }

    /* Centered Card Form */
    .center-wrap{ display:flex; justify-content:center; }
    .card-form{
        background:#fff; border:1px solid #eef2f7; border-radius:18px; padding:22px;
        box-shadow:0 8px 24px rgba(24,39,75,.05); width:100%; max-width:560px;
    }
    .form-control, .form-select{
        border-radius:10px; height:44px; border:1px solid #e5e7eb;
    }
    .btn-teal{
      background:linear-gradient(135deg,#1bb4a1,#0ea5a3);
      color:#fff; border:none; border-radius:999px; height:44px; padding:0 26px; font-weight:600;
    }

    /* RIGHT RAIL (copied) */
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; background:transparent; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{
        color:#fff; border:0; background:linear-gradient(160deg, var(--teal-1), var(--teal-2));
        box-shadow: 0 10px 28px rgba(16,185,129,.2);
    }
    .stat.gradient .label{ color:#e7fffb; }

    @media (max-width: 1100px){
        .layout{ grid-template-columns: var(--sidebar-w) 1fr; }
        .rail{ grid-column: 1 / -1; }
    }
</style>
</head>
<body>

<div class="layout">
    <!-- ===== Left Sidebar (copied from admin_dashboard.php) ===== -->
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
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link active" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts_admin.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- ===== Main (center column) ===== -->
    <main class="main">
        <!-- Search -->
        <div class="searchwrap mb-3">
            <i class="bi bi-search text-muted"></i>
            <input type="text" placeholder="Search Here" aria-label="Search">
        </div>

        <h4 class="section-title">Add Previous Pregnancy Record</h4>

        <div class="center-wrap">
            <div class="card-form">
                <!-- Alerts -->
                <?php if ($success): ?>
                  <div class="alert alert-success">Previous pregnancy record has been saved.</div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                  <div class="alert alert-danger">
                    <div class="fw-semibold mb-1">Please fix the following:</div>
                    <ul class="mb-0">
                      <?php foreach($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>

                <form method="post" id="prevPregForm" novalidate>
                  <!-- Barangay -->
                  <div class="mb-3">
                    <label class="form-label">Barangay</label>
                    <select class="form-select" name="barangay_id" id="barangay_id" required <?= !$CAN_SEE_ALL_BARANGAYS ? 'disabled' : '' ?>>
                      <option value="">Select Barangay</option>
                      <?php foreach($barangays as $b): ?>
                        <option value="<?=$b['id']?>" <?=(isset($_POST['barangay_id']) && (int)$_POST['barangay_id']===(int)$b['id']) || (!$CAN_SEE_ALL_BARANGAYS && $MIDWIFE_BARANGAY_ID===$b['id']) ? 'selected':''?>>
                          <?=h($b['name'])?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if(!$CAN_SEE_ALL_BARANGAYS && $MIDWIFE_BARANGAY_ID): ?>
                      <input type="hidden" name="barangay_id" value="<?=$MIDWIFE_BARANGAY_ID?>">
                    <?php endif; ?>
                  </div>

                  <!-- Patient -->
                  <div class="mb-3">
                    <label class="form-label">Select Patient</label>
                    <select class="form-select" name="patient_id" id="patient_id" required>
                      <option value="">Select Patient</option>
                    </select>
                  </div>

                  <!-- Pregnancy Number -->
                  <div class="mb-3">
                    <label class="form-label">Pregnancy Number</label>
                    <input type="number" min="1" class="form-control" name="pregnancy_number" value="<?=h($_POST['pregnancy_number'] ?? '')?>" required>
                  </div>

                  <!-- Delivery Date -->
                  <div class="mb-3">
                    <label class="form-label">Delivery Date</label>
                    <input type="date" class="form-control" name="delivery_date" value="<?=h($_POST['delivery_date'] ?? '')?>">
                  </div>

                  <!-- Delivery Type -->
                  <div class="mb-3">
                    <label class="form-label">Delivery Type</label>
                    <input type="text" class="form-control" name="delivery_type" placeholder="e.g., NSD, CS, Assisted" value="<?=h($_POST['delivery_type'] ?? '')?>">
                  </div>

                  <!-- Birth Outcome -->
                  <div class="mb-3">
                    <label class="form-label">Birth Outcome</label>
                    <input type="text" class="form-control" name="birth_outcome" placeholder="e.g., Live birth, Stillbirth, Miscarriage" value="<?=h($_POST['birth_outcome'] ?? '')?>">
                  </div>

                  <!-- Children Delivered -->
                  <div class="mb-3">
                    <label class="form-label">Children Delivered</label>
                    <select class="form-select" name="children_delivered">
                      <option value="">Select</option>
                      <?php for($i=1;$i<=6;$i++): ?>
                        <option value="<?=$i?>" <?=(isset($_POST['children_delivered']) && (int)$_POST['children_delivered']===$i)?'selected':''?>><?=$i?></option>
                      <?php endfor; ?>
                    </select>
                  </div>

                  <!-- Pregnancy Induced Hypertension -->
                  <div class="mb-3">
                    <label class="form-label">Pregnancy Induced Hypertension</label>
                    <select class="form-select" name="pregnancy_induced_htn">
                      <?php foreach(['Unknown','No','Yes'] as $opt): ?>
                        <option value="<?=$opt?>" <?=(($_POST['pregnancy_induced_htn'] ?? 'Unknown')===$opt)?'selected':''?>><?=$opt?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <!-- Preeclampsia/Eclampsia -->
                  <div class="mb-3">
                    <label class="form-label">Preeclampsia/Eclampsia</label>
                    <select class="form-select" name="preeclampsia_eclampsia">
                      <?php foreach(['Unknown','No','Yes'] as $opt): ?>
                        <option value="<?=$opt?>" <?=(($_POST['preeclampsia_eclampsia'] ?? 'Unknown')===$opt)?'selected':''?>><?=$opt?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <!-- Bleeding during Pregnancy -->
                  <div class="mb-4">
                    <label class="form-label">Bleeding during Pregnancy</label>
                    <select class="form-select" name="bleeding_during_pregnancy">
                      <?php foreach(['Unknown','No','Yes'] as $opt): ?>
                        <option value="<?=$opt?>" <?=(($_POST['bleeding_during_pregnancy'] ?? 'Unknown')===$opt)?'selected':''?>><?=$opt?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="text-center">
                    <button type="submit" class="btn btn-teal px-5">Submit</button>
                  </div>
                </form>
            </div>
        </div>
    </main>

    <!-- ===== Right Rail (copied from admin_dashboard.php) ===== -->
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
            <div class="big"><?= $totalPatients ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?= $totalBrgyCenters ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?= $totalPregnant ?></div>
        </div>
    </aside>
</div>

<script>
(function(){
  const barangaySelect = document.getElementById('barangay_id');
  const patientSelect  = document.getElementById('patient_id');

  function clearPatients(){
    patientSelect.innerHTML = '<option value="">Select Patient</option>';
  }
  function addPatientOpt(id, text){
    const o = document.createElement('option');
    o.value = id; o.textContent = text;
    patientSelect.appendChild(o);
  }

  async function loadPatients(bid, preselected){
    clearPatients();
    if(!bid) return;
    try{
      const res = await fetch('add_previous_pregnancy.php?ajax=patients&barangay_id=' + encodeURIComponent(bid));
      const data = await res.json();
      if(data.ok){
        data.patients.forEach(p=> addPatientOpt(p.id, p.text));
        if(preselected){ patientSelect.value = preselected; }
      }
    }catch(e){
      console.error(e);
    }
  }

  const initialBarangay = barangaySelect ? barangaySelect.value : document.querySelector('input[name="barangay_id"]')?.value;
  const preselectedPatient = "<?= isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : '' ?>";
  if(initialBarangay){ loadPatients(initialBarangay, preselectedPatient); }

  if(barangaySelect){
    barangaySelect.addEventListener('change', e=> loadPatients(e.target.value, null));
  }
})();
</script>
</body>
</html>
