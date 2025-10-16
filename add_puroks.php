<?php
session_start();
require 'db.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
if ($municipality_id <= 0) { die("No municipality is set for this admin account."); }

/* ---------- Expect data from add_barangay.php ---------- */
if (!isset($_SESSION['new_barangay_name']) || !isset($_SESSION['purok_count'])) {
    header("Location: add_barangay.php"); exit();
}
$barangay_name = trim($_SESSION['new_barangay_name']);
$purok_count   = (int)$_SESSION['purok_count'];

/* ---------- Municipality name (brand) ---------- */
$municipality_name = $_SESSION['municipality_name'] ?? '';
if ($municipality_name === '') {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    $stmt->bind_result($muni_name);
    if ($stmt->fetch()) { $municipality_name = $muni_name; }
    $stmt->close();
}

/* ---------- Right-rail stats (scoped) ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) FROM pregnant_women WHERE municipality_id = ?");
$stmt->bind_param("i", $municipality_id);
$stmt->execute(); $stmt->bind_result($tot_patients); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM barangays WHERE municipality_id = ?");
$stmt->bind_param("i", $municipality_id);
$stmt->execute(); $stmt->bind_result($tot_brgy); $stmt->fetch(); $stmt->close();

$tot_pregnant = (int)$tot_patients;

/* ---------- Handle POST (transaction) ---------- */
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate that all purok names are present
    $purok_names = [];
    for ($i=1; $i <= $purok_count; $i++) {
        $name = trim($_POST['purok_'.$i] ?? '');
        if ($name === '') { $error = "Please provide a name for Purok {$i}."; break; }
        $purok_names[$i] = $name;
    }

    // Check duplicate barangay in this municipality
    if ($error === '') {
        $dup = $conn->prepare("SELECT 1 FROM barangays WHERE municipality_id = ? AND name = ? LIMIT 1");
        $dup->bind_param("is", $municipality_id, $barangay_name);
        $dup->execute(); $dup->store_result();
        if ($dup->num_rows > 0) {
            $error = "Barangay '".h($barangay_name)."' already exists in this municipality.";
        }
        $dup->free_result(); $dup->close();
    }

    if ($error === '') {
        try {
            $conn->begin_transaction();

            // Insert barangay with municipality_id
            $stmtB = $conn->prepare("INSERT INTO barangays (name, municipality_id) VALUES (?, ?)");
            if (!$stmtB) { throw new Exception("Failed to prepare barangay insert."); }
            $stmtB->bind_param("si", $barangay_name, $municipality_id);
            if (!$stmtB->execute()) { throw new Exception("Failed to insert barangay: ".$stmtB->error); }
            $barangay_id = $stmtB->insert_id;
            $stmtB->close();

            // Insert puroks
            $stmtP = $conn->prepare("INSERT INTO puroks (barangay_id, name) VALUES (?, ?)");
            if (!$stmtP) { throw new Exception("Failed to prepare purok insert."); }

            foreach ($purok_names as $i => $pname) {
                $stmtP->bind_param("is", $barangay_id, $pname);
                if (!$stmtP->execute()) { throw new Exception("Failed to insert Purok {$i}: ".$stmtP->error); }
            }
            $stmtP->close();

            $conn->commit();

            // Clear session values
            unset($_SESSION['new_barangay_name'], $_SESSION['purok_count']);

            $success = "Barangay <strong>".h($barangay_name)."</strong> and its puroks were added successfully.";
        } catch (Exception $ex) {
            $conn->rollback();
            $error = $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Add Puroks • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
:root { --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6f; }
body{ background:#fff; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial; }

/* Sidebar */
.leftbar{ position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w); background:#fff;
  border-right:1px solid #eef0f3; padding:24px 16px; overflow-y:auto; }
.brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; }
.brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa);
  display:grid; place-items:center; color:#fff; font-weight:800; }
.nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
.nav-link:hover{ background:#f2f6f9; color:#0f172a; }
.nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
.nav-link i{ width:22px; text-align:center; margin-right:8px; }

/* Main wrapper with right rail */
.main-wrapper{ margin-left:var(--sidebar-w); padding:28px; display:flex; flex-wrap:wrap; gap:24px; }
.main-content{ flex:1 1 720px; min-width:0; }
.rightbar{ flex:0 0 300px; }

/* Topbar */
.topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.breadcrumbs a{ text-decoration:none; color:#0e6f6f; font-weight:700; }
.breadcrumbs a:hover{ text-decoration:underline; }

/* Card/form */
.cardx{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
.form-control{ height:44px; border-radius:10px; }
.btn-teal{ background:linear-gradient(135deg,#1bb4a1,#0ea5a3); color:#fff; border:none; border-radius:999px; height:44px; font-weight:600; }

/* Right rail stats */
.stat{ border-radius:18px; padding:20px; text-align:center; background:#f8fafc; border:1px solid #eef0f3; margin-bottom:16px; }
.stat h6{ color:#6b7280; font-weight:700; margin-bottom:8px; letter-spacing:.02em; }
.stat .num{ font-size:56px; font-weight:800; line-height:1; color:#0f172a; }
.stat.accent{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; border:none; }
.stat.accent h6,.stat.accent .num{ color:#fff; }

@media (max-width:992px){
  .main-wrapper{ margin-left:0; padding:16px; flex-direction:column; }
  .leftbar{ position:static; width:100%; border-right:none; border-bottom:1px solid #eef0f3; }
  .rightbar{ width:100%; order:-1; }
}
</style>
</head>
<body>

<!-- Sidebar -->
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
    <a class="nav-link active" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
    <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
    <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
    <a class="nav-link" href="manage_accounts.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
    <hr>
    <a class="nav-link" href="account.php"><i class="bi bi-person-circle"></i> My Account</a>
    <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
  </nav>
</aside>

<!-- Main Content Wrapper -->
<div class="main-wrapper">

  <!-- Main Left -->
  <div class="main-content">
    <div class="topbar">
      <div class="breadcrumbs">
        <a href="barangay_health_centers.php"><i class="bi bi-arrow-left"></i> Back to Barangays</a>
      </div>
    </div>

    <h4 class="mb-3">Enter Purok Names for <span class="text-primary"><?= h($barangay_name) ?></span></h4>

    <div class="cardx">
      <?php if ($success): ?>
        <div class="alert alert-success">
          <i class="bi bi-check-circle-fill"></i>
          <?= $success ?>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-secondary" href="add_barangay.php"><i class="bi bi-plus-circle"></i> Add Another Barangay</a>
          <a class="btn btn-teal" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Go to Barangay List</a>
        </div>
      <?php else: ?>
        <?php if ($error): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= h($error) ?>
          </div>
        <?php endif; ?>

        <form method="POST" class="needs-validation" novalidate>
          <?php for ($i=1; $i <= $purok_count; $i++): ?>
          <div class="mb-3">
            <label class="form-label">Purok <?= $i ?> Name</label>
            <input type="text" name="purok_<?= $i ?>" class="form-control" placeholder="e.g., Purok <?= $i ?>" required>
            <div class="invalid-feedback">Please enter a name for Purok <?= $i ?>.</div>
          </div>
          <?php endfor; ?>

          <button type="submit" class="btn btn-teal w-100">
            <i class="bi bi-save2"></i> Save Puroks
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right Sidebar -->
  <div class="rightbar">
    <div class="stat">
      <h6>Total Patient Record</h6>
      <div class="num"><?= (int)$tot_patients ?></div>
    </div>
    <div class="stat accent">
      <h6>Total Brgy. Health Center</h6>
      <div class="num"><?= (int)$tot_brgy ?></div>
    </div>
    <div class="stat">
      <h6>Total Pregnant Patient</h6>
      <div class="num"><?= (int)$tot_pregnant ?></div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  forms.forEach(form => {
    form.addEventListener('submit', e => {
      if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>
</body>
</html>
