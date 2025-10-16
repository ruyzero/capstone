<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------------- Auth: SUPER ADMIN ONLY ---------------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ---------------- Inline AJAX endpoints (JSON) ----------------
   - ?ajax=regions
       -> [{id, name}]
   - ?ajax=provinces&region_id=ID
       -> [{id, name}]
   - ?ajax=municipalities&province_id=ID
       -> [{id, name}]
   - ?ajax=barangays&municipality_id=ID
       -> [{id, name}]
---------------------------------------------------------------- */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = $_GET['ajax'];

    if ($ajax === 'regions') {
        $res = $conn->query("SELECT id, name FROM regions ORDER BY name");
        echo json_encode($res->fetch_all(MYSQLI_ASSOC)); exit();
    }
    if ($ajax === 'provinces') {
        $region_id = (int)($_GET['region_id'] ?? 0);
        if ($region_id <= 0) { echo json_encode([]); exit(); }
        $stmt = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name");
        $stmt->bind_param("i", $region_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC)); exit();
    }
    if ($ajax === 'municipalities') {
        $province_id = (int)($_GET['province_id'] ?? 0);
        if ($province_id <= 0) { echo json_encode([]); exit(); }
        $stmt = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
        $stmt->bind_param("i", $province_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC)); exit();
    }
    if ($ajax === 'barangays') {
        $municipality_id = (int)($_GET['municipality_id'] ?? 0);
        if ($municipality_id <= 0) { echo json_encode([]); exit(); }
        $stmt = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
        $stmt->bind_param("i", $municipality_id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC)); exit();
    }
    echo json_encode([]); exit();
}

/* ---------------- Normal page mode ---------------- */
$user_id  = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'super_admin';

/* Flash messages (optional) */
$flash_error = $_SESSION['error']   ?? null; unset($_SESSION['error']);
$flash_ok    = $_SESSION['success'] ?? null; unset($_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Patient (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
  body { background:#f4f6f9; font-family:'Inter',sans-serif; }
  h3, h4 { font-family:'Merriweather',serif; }
  .section-title { font-weight:600; font-size:1.1rem; margin:30px 0 12px; border-bottom:1px solid #e5e7eb; padding-bottom:4px; }
  .form-label { font-weight:600; }
  .spinner-sm { width: 1rem; height: 1rem; border-width: .15rem; }
</style>
</head>
<body>

<div class="container bg-white p-4 shadow rounded mt-4" style="max-width: 1000px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3><i class="bi bi-person-plus-fill"></i> Add Patient (Super Admin)</h3>
    <a href="super_admin_dashboard.php" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left"></i> Back
    </a>
  </div>

  <?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
  <?php endif; ?>
  <?php if ($flash_ok): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div>
  <?php endif; ?>

  <!-- IMPORTANT: point to superadmin_save_pregnancy.php -->
  <form id="superadminAddForm" method="POST" action="superadmin_save_pregnancy.php" novalidate>
    <!-- Posted to save script -->
    <input type="hidden" name="registered_by" value="<?= $user_id ?>">
    <input type="hidden" name="municipality_id" id="municipality_id_hidden" value="">

    <!-- ==================== Personal Information ==================== -->
    <div class="section-title">Personal Information</div>
    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">First Name</label>
        <input type="text" name="first_name" class="form-control" required maxlength="30" pattern="^[A-Za-z.\- ]+$">
      </div>
      <div class="col-md-3">
        <label class="form-label">Middle Name</label>
        <input type="text" name="middle_name" class="form-control" maxlength="30" pattern="^[A-Za-z.\- ]+$">
      </div>
      <div class="col-md-3">
        <label class="form-label">Last Name</label>
        <input type="text" name="last_name" class="form-control" required maxlength="30" pattern="^[A-Za-z.\- ]+$">
      </div>
      <div class="col-md-3">
        <label class="form-label">Suffix (optional)</label>
        <input type="text" name="suffix" class="form-control" maxlength="30" pattern="^[A-Za-z.\- ]+$">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Sex</label><br>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="sex" value="Female" checked>
          <label class="form-check-label">Female</label>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Date of Birth</label>
        <input type="date" id="dob" name="dob" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Age</label>
        <input type="text" id="age" class="form-control" readonly placeholder="Auto">
      </div>
      <div class="col-md-3">
        <label class="form-label">Birthplace</label>
        <input type="text" name="birthplace" class="form-control" maxlength="50" pattern="^[A-Za-z.\- ]+$" required>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Blood Type</label>
        <select name="blood_type" class="form-select" required>
          <option value="">Select</option>
          <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-'] as $type): ?>
            <option value="<?= $type ?>"><?= $type ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-9">
        <label class="form-label d-block">Civil Status</label>
        <?php foreach (['Single','Married','Annulled','Widow/er','Separated','Co-habitation'] as $status): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="civil_status" value="<?= $status ?>" required>
            <label class="form-check-label"><?= $status ?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ==================== Family & Socioeconomic ==================== -->
    <div class="section-title">Family & Socioeconomic Information</div>
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">Spouse's Name</label>
        <input type="text" name="spouse_name" class="form-control" maxlength="50" pattern="^[A-Za-z.\- ]+$" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Mother's Name</label>
        <input type="text" name="mother_name" class="form-control" maxlength="50" pattern="^[A-Za-z.\- ]+$" required>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">Educational Attainment</label>
        <select name="education" class="form-select" required>
          <option value="">Select</option>
          <?php foreach (['No Formal Education','Elementary','High School','College','Vocational','Post Graduate'] as $edu): ?>
            <option value="<?= $edu ?>"><?= $edu ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Employment Status</label>
        <select name="employment" class="form-select" required>
          <option value="">Select</option>
          <?php foreach (['Student','Employed','Unknown','Retired','None/Unemployed'] as $emp): ?>
            <option value="<?= $emp ?>"><?= $emp ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- ==================== Address (Region → Province → Municipality → Barangay) ==================== -->
    <div class="section-title">Address</div>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Region</label>
        <div class="input-group">
          <select id="region_select" class="form-select" required disabled>
            <option value="">Loading regions…</option>
          </select>
          <span class="input-group-text" id="region_loader" style="background:#fff;">
            <span class="spinner-border spinner-sm" role="status" aria-hidden="true"></span>
          </span>
        </div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Province</label>
        <select id="province_select" class="form-select" required disabled>
          <option value="">-- Select Province --</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Municipality</label>
        <select id="municipality_select" class="form-select" required disabled>
          <option value="">-- Select Municipality --</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Barangay</label>
        <select name="barangay_id" id="barangay_select" class="form-select" required disabled>
          <option value="">-- Select Barangay --</option>
        </select>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Region (auto)</label>
        <input type="text" id="region_name" class="form-control" readonly>
      </div>
      <div class="col-md-4">
        <label class="form-label">Province (auto)</label>
        <input type="text" id="province_name" class="form-control" readonly>
      </div>
      <div class="col-md-4">
        <label class="form-label">Municipality (auto)</label>
        <input type="text" id="municipality_name" class="form-control" readonly>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Contact Number</label>
        <input type="text" name="contact" class="form-control" placeholder="09XXXXXXXXX" maxlength="11"
               inputmode="numeric" pattern="^09\d{9}$" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Household No.</label>
        <input type="text" name="household_no" class="form-control" inputmode="numeric" pattern="^\d+$" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Purok</label>
        <select name="purok_id" id="purok_select" class="form-select" required>
          <option value="">-- Select Purok --</option>
        </select>
      </div>
    </div>

    <!-- ==================== Health & Welfare ==================== -->
    <div class="section-title">Health & Welfare</div>
    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">DSWD NHTS?</label>
        <select name="dswd" class="form-select" required>
          <option value="No">No</option><option value="Yes">Yes</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">4Ps Member?</label>
        <select name="four_ps" class="form-select" required>
          <option value="No">No</option><option value="Yes">Yes</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">PhilHealth Member?</label>
        <select name="philhealth" class="form-select" required>
          <option value="No">No</option><option value="Yes">Yes</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Status Type</label>
        <select name="status_type" class="form-select" required>
          <option value="">Select</option>
          <option value="Member">Member</option>
          <option value="Dependent">Dependent</option>
        </select>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label">PhilHealth No. (optional)</label>
        <input type="text" name="philhealth_no" class="form-control" maxlength="12" inputmode="numeric" pattern="^\d{0,12}$">
      </div>
      <div class="col-md-4">
        <label class="form-label">PCB Member?</label>
        <select name="pcb" class="form-select" required>
          <option value="No">No</option><option value="Yes">Yes</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label d-block">If Member, Choose Category</label>
        <?php foreach (['FE - Private','FE - Government','IE','Others'] as $cat): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="phil_category[]" value="<?= $cat ?>">
            <label class="form-check-label"><?= $cat ?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save2"></i> Save Record</button>
  </form>
</div>

<script>
// ===== Age auto-calc =====
const dob = document.getElementById('dob');
const age = document.getElementById('age');
if (dob) {
  dob.addEventListener('change', () => {
    if (!dob.value) { age.value = ''; return; }
    const d = new Date(dob.value);
    const t = new Date();
    let a = t.getFullYear() - d.getFullYear();
    const m = t.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && t.getDate() < d.getDate())) a--;
    age.value = isFinite(a) ? a : '';
  });
}

// ===== Elements =====
const form            = document.getElementById('superadminAddForm');
const municipalityHid = document.getElementById('municipality_id_hidden');

const regionSel  = document.getElementById('region_select');
const provSel    = document.getElementById('province_select');
const muniSel    = document.getElementById('municipality_select');
const brgySel    = document.getElementById('barangay_select');

const purokSel   = document.getElementById('purok_select');

const regionName = document.getElementById('region_name');
const provName   = document.getElementById('province_name');
const muniName   = document.getElementById('municipality_name');

const regionLoader = document.getElementById('region_loader');

function resetSelect(sel, placeholder, disable=true) {
  sel.innerHTML = `<option value="">${placeholder}</option>`;
  sel.disabled = !!disable;
}
function resetChain(fromLevel) {
  if (fromLevel <= 1) resetSelect(provSel, '-- Select Province --');
  if (fromLevel <= 2) resetSelect(muniSel, '-- Select Municipality --');
  if (fromLevel <= 3) resetSelect(brgySel, '-- Select Barangay --');
  if (fromLevel <= 4) {
    municipalityHid.value = '';
    muniName.value = '';
    provName.value = '';
  }
  resetSelect(purokSel, '-- Select Purok --', false);
}

// Load Regions
function loadRegions(){
  regionSel.disabled = true;
  if (regionLoader) regionLoader.style.visibility = 'visible';
  fetch('superadmin_add_patient.php?ajax=regions')
    .then(r => r.json())
    .then(list => {
      resetSelect(regionSel, '-- Select Region --', false);
      if (Array.isArray(list)) {
        list.forEach(r => {
          const opt = document.createElement('option');
          opt.value = r.id; opt.textContent = r.name;
          regionSel.appendChild(opt);
        });
      }
      regionSel.disabled = false;
      if (regionLoader) regionLoader.style.visibility = 'hidden';
    })
    .catch(err => {
      console.error('Regions load error:', err);
      regionSel.innerHTML = '<option value="">Failed to load regions</option>';
      regionSel.disabled = true;
      if (regionLoader) regionLoader.style.visibility = 'hidden';
    });
}

// Load Provinces by Region
function loadProvinces(region_id){
  resetChain(1);
  if (!region_id) { regionName.value = ''; return; }
  regionName.value = regionSel.selectedOptions[0]?.textContent || '';
  fetch('superadmin_add_patient.php?ajax=provinces&region_id=' + encodeURIComponent(region_id))
    .then(r => r.json())
    .then(list => {
      resetSelect(provSel, '-- Select Province --', false);
      if (Array.isArray(list)) {
        list.forEach(p => {
          const opt = document.createElement('option');
          opt.value = p.id; opt.textContent = p.name;
          provSel.appendChild(opt);
        });
      }
    })
    .catch(err => {
      console.error('Provinces load error:', err);
      provSel.innerHTML = '<option value="">Failed to load provinces</option>';
      provSel.disabled = true;
    });
}

// Load Municipalities by Province
function loadMunicipalities(province_id){
  resetChain(2);
  if (!province_id) { provName.value = ''; return; }
  provName.value = provSel.selectedOptions[0]?.textContent || '';
  fetch('superadmin_add_patient.php?ajax=municipalities&province_id=' + encodeURIComponent(province_id))
    .then(r => r.json())
    .then(list => {
      resetSelect(muniSel, '-- Select Municipality --', false);
      if (Array.isArray(list)) {
        list.forEach(m => {
          const opt = document.createElement('option');
          opt.value = m.id; opt.textContent = m.name;
          muniSel.appendChild(opt);
        });
      }
    })
    .catch(err => {
      console.error('Municipalities load error:', err);
      muniSel.innerHTML = '<option value="">Failed to load municipalities</option>';
      muniSel.disabled = true;
    });
}

// Load Barangays by Municipality
function loadBarangays(municipality_id){
  resetChain(3);
  if (!municipality_id) { muniName.value = ''; municipalityHid.value=''; return; }
  muniName.value = muniSel.selectedOptions[0]?.textContent || '';
  municipalityHid.value = municipality_id;

  fetch('superadmin_add_patient.php?ajax=barangays&municipality_id=' + encodeURIComponent(municipality_id))
    .then(r => r.json())
    .then(list => {
      resetSelect(brgySel, '-- Select Barangay --', false);
      if (Array.isArray(list)) {
        list.forEach(b => {
          const opt = document.createElement('option');
          opt.value = b.id; opt.textContent = b.name;
          brgySel.appendChild(opt);
        });
      }
    })
    .catch(err => {
      console.error('Barangays load error:', err);
      brgySel.innerHTML = '<option value="">Failed to load barangays</option>';
      brgySel.disabled = true;
    });
}

// Load Puroks when Barangay changes
function handleBarangayChange(){
  const barangayId = brgySel.value;
  resetSelect(purokSel, '-- Select Purok --', false);
  if (!barangayId) return;

  fetch('get_puroks.php?barangay_id=' + encodeURIComponent(barangayId))
    .then(r => r.json())
    .then(data => {
      if (!Array.isArray(data)) return;
      data.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id; opt.textContent = p.name;
        purokSel.appendChild(opt);
      });
    });
}

// Wire events
document.addEventListener('DOMContentLoaded', loadRegions);
regionSel.addEventListener('change', () => loadProvinces(regionSel.value));
provSel.addEventListener('change',   () => loadMunicipalities(provSel.value));
muniSel.addEventListener('change',   () => loadBarangays(muniSel.value));
brgySel.addEventListener('change',   handleBarangayChange);

// Guard: ensure municipality_id is set before submit
if (form) {
  form.addEventListener('submit', (e) => {
    if (!municipalityHid.value) {
      e.preventDefault();
      alert('Please select Region, Province, Municipality, and Barangay.');
      return false;
    }
  });
}
</script>
</body>
</html>
