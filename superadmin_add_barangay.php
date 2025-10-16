<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ===== Auth: Super Admin only ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ===== Helpers ===== */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_out($d){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($d); exit(); }

/* ===== AJAX endpoints for cascading selects and counts ===== */
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    if ($ajax === 'regions') {
        $res = $conn->query("SELECT id, name FROM regions ORDER BY name");
        json_out($res->fetch_all(MYSQLI_ASSOC));
    }
    if ($ajax === 'provinces') {
        $region_id = (int)($_GET['region_id'] ?? 0);
        if ($region_id <= 0) json_out([]);
        $stmt = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name");
        $stmt->bind_param("i", $region_id);
        $stmt->execute();
        json_out($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    if ($ajax === 'municipalities') {
        $province_id = (int)($_GET['province_id'] ?? 0);
        if ($province_id <= 0) json_out([]);
        $stmt = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
        $stmt->bind_param("i", $province_id);
        $stmt->execute();
        json_out($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    if ($ajax === 'barangay_count') {
        $municipality_id = (int)($_GET['municipality_id'] ?? 0);
        if ($municipality_id <= 0) json_out(['count'=>0]);
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM barangays WHERE municipality_id = ?");
        $stmt->bind_param("i", $municipality_id);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        json_out(['count'=>$count]);
    }

    json_out([]);
}

/* ===== POST: move to purok setup ===== */
$flash_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barangay_name   = trim($_POST['barangay_name'] ?? '');
    $purok_count     = (int)($_POST['purok_count'] ?? 0);
    $municipality_id = (int)($_POST['municipality_id'] ?? 0);

    if ($barangay_name === '' || $purok_count < 1 || $purok_count > 20 || $municipality_id <= 0) {
        $flash_error = "Please complete the form correctly (1–20 puroks, select a municipality).";
    } else {
        // enforce 55 limit per municipality
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM barangays WHERE municipality_id = ?");
        $stmt->bind_param("i", $municipality_id);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        if ($count >= 55) {
            $flash_error = "Barangay limit of 55 for this municipality has been reached.";
        } else {
            // fetch municipality name for display next page
            $mn = '';
            $sm = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
            $sm->bind_param("i", $municipality_id);
            $sm->execute();
            $row = $sm->get_result()->fetch_assoc();
            if ($row) $mn = $row['name'];
            $sm->close();

            $_SESSION['new_barangay_name'] = $barangay_name;
            $_SESSION['purok_count'] = $purok_count;
            $_SESSION['municipality_id'] = $municipality_id;
            $_SESSION['municipality_name'] = $mn;

            // You can reuse add_puroks.php if it reads the same session vars,
            // or create a superadmin_add_puroks.php. Adjust redirect as needed.
            header("Location: superadmin_add_puroks.php");
            exit();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Barangay (Super Admin) • RHU MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body { background:#f4f6f9; font-family:'Segoe UI', system-ui, -apple-system; }
  .container { max-width: 720px; margin-top: 60px; }
  .card { border-radius: 10px; }
  .was-validated .form-control:invalid, .was-validated .form-select:invalid { border-color: #dc3545; }
  .muted { color:#6b7280; }
</style>
</head>
<body>

<div class="container">
  <div class="card shadow-sm border-0 p-4 bg-white">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="m-0"><i class="bi bi-house-door-fill text-primary"></i> Add New Barangay</h2>
      <a href="superadmin_barangay_health_centers.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back
      </a>
    </div>

    <?php if ($flash_error): ?>
      <div class="alert alert-danger"><?= h($flash_error) ?></div>
    <?php endif; ?>

    <form method="POST" class="needs-validation" novalidate id="form">
      <!-- Cascading: Region → Province → Municipality -->
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Region</label>
          <select id="region_select" class="form-select" required>
            <option value="">Select Region</option>
          </select>
          <div class="invalid-feedback">Please choose a region.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Province</label>
          <select id="province_select" class="form-select" required disabled>
            <option value="">Select Province</option>
          </select>
          <div class="invalid-feedback">Please choose a province.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Municipality</label>
          <select id="municipality_select" name="municipality_id" class="form-select" required disabled>
            <option value="">Select Municipality</option>
          </select>
          <div class="invalid-feedback">Please choose a municipality.</div>
        </div>
      </div>

      <div class="mt-3 muted" id="countWrap" style="display:none;">
        <strong>Current Count:</strong> <span id="brgyCount">0</span> of 55 barangays registered for this municipality.
      </div>

      <hr class="my-4">

      <div class="mb-3">
        <label class="form-label">Barangay Name</label>
        <input type="text" name="barangay_name" class="form-control" placeholder="Enter barangay name" required>
        <div class="invalid-feedback">Please enter a barangay name.</div>
      </div>

      <div class="mb-4">
        <label class="form-label">Number of Puroks</label>
        <input type="number" name="purok_count" class="form-control" min="1" max="20" placeholder="e.g. 5" required>
        <div class="invalid-feedback">Please enter a number between 1 and 20.</div>
        <small class="text-muted">Limit: 1–20 puroks</small>
      </div>

      <button type="submit" class="btn btn-success w-100" id="submitBtn" disabled>
        <i class="bi bi-arrow-right-circle"></i> Continue to Purok Setup
      </button>
    </form>
  </div>
</div>

<script>
const rSel = document.getElementById('region_select');
const pSel = document.getElementById('province_select');
const mSel = document.getElementById('municipality_select');
const submitBtn = document.getElementById('submitBtn');
const countWrap = document.getElementById('countWrap');
const brgyCount = document.getElementById('brgyCount');

function reset(sel, label, disable=true){ sel.innerHTML = `<option value="">${label}</option>`; sel.disabled = !!disable; }

function loadRegions(){
  fetch('superadmin_add_barangay.php?ajax=regions')
    .then(r=>r.json()).then(list=>{
      reset(rSel, 'Select Region', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; rSel.appendChild(o); });
    });
}
function loadProvinces(regionId){
  reset(pSel, 'Select Province');
  reset(mSel, 'Select Municipality');
  countWrap.style.display = 'none';
  submitBtn.disabled = true;
  if (!regionId) return;
  fetch('superadmin_add_barangay.php?ajax=provinces&region_id='+encodeURIComponent(regionId))
    .then(r=>r.json()).then(list=>{
      reset(pSel, 'Select Province', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; pSel.appendChild(o); });
    });
}
function loadMunicipalities(provinceId){
  reset(mSel, 'Select Municipality');
  countWrap.style.display = 'none';
  submitBtn.disabled = true;
  if (!provinceId) return;
  fetch('superadmin_add_barangay.php?ajax=municipalities&province_id='+encodeURIComponent(provinceId))
    .then(r=>r.json()).then(list=>{
      reset(mSel, 'Select Municipality', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; mSel.appendChild(o); });
    });
}
function refreshCount(muniId){
  countWrap.style.display = 'none';
  submitBtn.disabled = true;
  if (!muniId) return;
  fetch('superadmin_add_barangay.php?ajax=barangay_count&municipality_id='+encodeURIComponent(muniId))
    .then(r=>r.json()).then(res=>{
      brgyCount.textContent = res.count ?? 0;
      countWrap.style.display = 'block';
      submitBtn.disabled = (res.count >= 55);
    });
}

document.addEventListener('DOMContentLoaded', loadRegions);
rSel.addEventListener('change', ()=> loadProvinces(rSel.value));
pSel.addEventListener('change', ()=> loadMunicipalities(pSel.value));
mSel.addEventListener('change', ()=> refreshCount(mSel.value));

// Bootstrap validation
(() => {
  'use strict';
  const form = document.getElementById('form');
  form.addEventListener('submit', event => {
    if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
    form.classList.add('was-validated');
  }, false);
})();
</script>
</body>
</html>
