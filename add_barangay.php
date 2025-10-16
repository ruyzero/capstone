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

/* ---------- Right-rail stats (scoped to municipality) ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) FROM pregnant_women WHERE municipality_id = ?");
$stmt->bind_param("i", $municipality_id);
$stmt->execute(); $stmt->bind_result($tot_patients); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM barangays WHERE municipality_id = ?");
$stmt->bind_param("i", $municipality_id);
$stmt->execute(); $stmt->bind_result($tot_brgy); $stmt->fetch(); $stmt->close();

$tot_pregnant = (int)$tot_patients; // adjust if you separate statuses

/* ---------- Barangay limit & post ---------- */
$LIMIT_BARANGAYS = 55;
$stmt = $conn->prepare("SELECT COUNT(*) FROM barangays WHERE municipality_id = ?");
$stmt->bind_param("i", $municipality_id);
$stmt->execute(); $stmt->bind_result($barangay_count_in_muni); $stmt->fetch(); $stmt->close();

$can_add = ($barangay_count_in_muni < $LIMIT_BARANGAYS);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_add) {
    $barangay_name = trim($_POST['barangay_name'] ?? '');
    $purok_count   = (int)($_POST['purok_count'] ?? 0);

    if ($barangay_name === '' || $purok_count < 1 || $purok_count > 20) {
        $err = "Please provide a valid barangay name and a purok count between 1 and 20.";
    } else {
        // Optionally: store municipality_id too, if add_puroks.php needs it
        $_SESSION['new_barangay_name'] = $barangay_name;
        $_SESSION['purok_count']       = $purok_count;
        header("Location: add_puroks.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Add Barangay • RHU-MIS</title>
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
.searchbar{ flex:1; max-width:640px; position:relative; }
.searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
.searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }
.add-link{ font-weight:700; color:var(--brand); text-decoration:none; }
.add-link:hover{ color:#0e6f6f; text-decoration:underline; }

/* Card/form */
.cardx{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
.form-control, .form-select{ height:44px; border-radius:10px; }
.btn-teal{ background:linear-gradient(135deg,#1bb4a1,#0ea5a3); color:#fff; border:none; border-radius:999px; height:44px; font-weight:600; }
.limit-note{ color:#6b7280; }

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
    <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
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
      <div class="searchbar me-3">
        <i class="bi bi-search"></i>
        <input type="text" class="form-control" placeholder="Search here" disabled>
      </div>
      <a class="add-link" href="barangay_health_centers.php"><i class="bi bi-arrow-left"></i> Back to Barangays</a>
    </div>

    <h4 class="mb-3">Add New Barangay</h4>

    <div class="cardx">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="limit-note">
          <strong>Current Count:</strong> <?= (int)$barangay_count_in_muni; ?> of <?= (int)$LIMIT_BARANGAYS; ?> barangays in this municipality
        </div>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-danger py-2"><?= h($err) ?></div>
      <?php endif; ?>

      <?php if ($can_add): ?>
        <form method="POST" class="needs-validation" novalidate>
          <div class="mb-3">
            <label class="form-label">Barangay Name</label>
            <input type="text" name="barangay_name" class="form-control" placeholder="Enter barangay name" required>
            <div class="invalid-feedback">Please enter a barangay name.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Number of Puroks</label>
            <input type="number" name="purok_count" class="form-control" min="1" max="20" placeholder="e.g. 5" required>
            <div class="invalid-feedback">Please enter a number between 1 and 20.</div>
            <small class="text-muted">Limit: 1–20 puroks</small>
          </div>

          <button type="submit" class="btn btn-teal w-100">
            <i class="bi bi-arrow-right-circle"></i> Continue to Purok Setup
          </button>
        </form>
      <?php else: ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle-fill"></i>
          Barangay limit of <strong><?= (int)$LIMIT_BARANGAYS; ?></strong> has been reached for this municipality.
          Please remove or edit an existing barangay to add a new one.
        </div>
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
