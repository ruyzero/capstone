<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---- Auth: Super Admin only ---- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ---- Helpers ---- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nice_status($s){
    if ($s === 'completed') return 'Completed';
    return 'Under Monitoring';
}

/* ---- Input ---- */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['error'] = "Invalid record ID.";
    header("Location: superadmin_add_patient.php");
    exit();
}

/* ---- Fetch record with joins ---- */
$sql = "
SELECT
  pw.*,
  b.name AS barangay_name,
  m.name AS municipality_name,
  p.name AS province_name,
  r.name AS region_name,
  u1.username AS registered_by_username,
  u2.username AS assigned_midwife_username
FROM pregnant_women pw
LEFT JOIN barangays b      ON b.id = pw.barangay_id
LEFT JOIN municipalities m ON m.id = pw.municipality_id
LEFT JOIN provinces p      ON p.id = m.province_id
LEFT JOIN regions r        ON r.id = p.region_id
LEFT JOIN users u1         ON u1.id = pw.registered_by
LEFT JOIN users u2         ON u2.id = pw.assigned_midwife_id
WHERE pw.id = ?
LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $_SESSION['error'] = "Record not found.";
    header("Location: superadmin_add_patient.php");
    exit();
}

/* ---- Name pieces ---- */
$name_parts = array_filter([$row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? '', $row['suffix'] ?? '']);
$full_name  = trim(implode(' ', $name_parts));

/* ---- Dates ---- */
$dob_disp = !empty($row['dob']) ? $row['dob'] : '—';

/* ---- Province display (prefer authoritative join over pw.province) ---- */
$province_display = $row['province_name'] ?: ($row['province'] ?? '—');

/* ---- PhilHealth category (SET) pretty print ---- */
$phil_cat = $row['philhealth_category'] ?? '';
$phil_cat_disp = $phil_cat ? str_replace(',', ', ', $phil_cat) : '—';

/* ---- Monitoring status ---- */
$monitoring_status = nice_status($row['status'] ?? 'under_monitoring');

/* ---- Registered by (username or ID fallback) ---- */
$registered_by_disp = $row['registered_by_username'] ?: ($row['registered_by'] ? 'User #'.$row['registered_by'] : '—');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Patient Information • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
  :root{
    --brand1:#2fd4c8; --brand2:#0fb5aa;
  }
  body{ background:#ffffff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial; }
  .wrap{ max-width:1000px; margin:28px auto 60px; padding:0 16px; }
  h1.title{
    text-align:center; font-family:'Merriweather',serif; font-weight:800;
    font-size:28px; margin:6px 0 22px; text-decoration:underline; text-underline-offset:5px;
  }
  .kv { margin:2px 0; }
  .kv .k{ font-weight:700; }
  .divider{ height:1px; background:#e5e7eb; margin:16px 0 18px; }
  .section-h{ font-weight:700; margin-bottom:8px; }
  .update-btn{
    border:none; border-radius:999px; padding:8px 18px; color:#fff; font-weight:700;
    background:linear-gradient(135deg, var(--brand1), var(--brand2));
    box-shadow:0 6px 18px rgba(16,185,129,.25);
  }
  .update-btn:hover{ opacity:.95; }
</style>
</head>
<body>

<div class="wrap">
  <h1 class="title">View Patient Information</h1>

  <div class="row">
    <div class="col-md-6">
      <p class="kv"><span class="k">Name:</span> <?= h($full_name) ?></p>
      <p class="kv"><span class="k">Sex:</span> <?= h($row['sex'] ?? '—') ?></p>
      <p class="kv"><span class="k">Birthdate:</span> <?= h($dob_disp) ?></p>
      <p class="kv"><span class="k">Birthplace:</span> <?= h($row['birthplace'] ?? '—') ?></p>
      <p class="kv"><span class="k">Blood Type:</span> <?= h($row['blood_type'] ?? '—') ?></p>
      <p class="kv"><span class="k">Civil Status:</span> <?= h($row['civil_status'] ?? '—') ?></p>
    </div>
    <div class="col-md-6">
      <p class="kv"><span class="k">Spouse’s Name:</span> <?= h($row['spouse_name'] ?? '—') ?></p>
      <p class="kv"><span class="k">Mother’s Name:</span> <?= h($row['mother_name'] ?? '—') ?></p>
      <p class="kv"><span class="k">Education:</span> <?= h($row['education'] ?? '—') ?></p>
      <p class="kv"><span class="k">Employment:</span> <?= h($row['employment'] ?? '—') ?></p>
      <p class="kv"><span class="k">Contact Number:</span> <?= h($row['contact'] ?? '—') ?></p>
    </div>
  </div>

  <div class="divider"></div>

  <div class="row">
    <div class="col-md-6">
      <div class="section-h">Residential Address</div>
      <p class="kv"><span class="k">Purok:</span> <?= h($row['purok_id'] ?? '—') ?></p>
      <p class="kv"><span class="k">Barangay:</span> <?= h($row['barangay_name'] ?? '—') ?></p>
      <p class="kv"><span class="k">Municipality:</span> <?= h($row['municipality_name'] ?? '—') ?></p>
      <p class="kv"><span class="k">Province:</span> <?= h($province_display) ?></p>
    </div>
    <div class="col-md-6">
      <div class="section-h">Monitoring Information</div>
      <p class="kv"><span class="k">Registered By:</span> <?= h($registered_by_disp) ?></p>
      <p class="kv"><span class="k">Monitoring Status:</span> <?= h($monitoring_status) ?></p>
    </div>
  </div>

  <div class="divider"></div>

  <div class="row">
    <div class="col-md-12">
      <div class="section-h">Socio–Economic Information</div>
    </div>
    <div class="col-md-6">
      <p class="kv"><span class="k">DSWD NHTS:</span> <?= h($row['dswd_nhts'] ?? '—') ?></p>
      <p class="kv"><span class="k">4Ps Member:</span> <?= h($row['four_ps'] ?? '—') ?></p>
      <p class="kv"><span class="k">Household Number:</span> <?= h($row['household_no'] ?? '—') ?></p>
    </div>
    <div class="col-md-6">
      <p class="kv"><span class="k">PhilHealth Member:</span> <?= h($row['philhealth_member'] ?? '—') ?></p>
      <p class="kv"><span class="k">PhilHealth Number:</span> <?= h($row['philhealth_no'] ?? '—') ?></p>
      <p class="kv"><span class="k">PCB Member:</span> <?= h($row['pcb_member'] ?? '—') ?></p>
      <p class="kv"><span class="k">PhilHealth Category:</span> <?= h($phil_cat_disp) ?></p>
    </div>
  </div>

  <div class="d-flex justify-content-end mt-3">
    <a class="update-btn text-decoration-none" href="superadmin_edit_pregnancy.php?id=<?= (int)$row['id'] ?>">
      Update
    </a>
  </div>
</div>

</body>
</html>
