<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role'])) { header("Location: index.php"); exit(); }

$role              = $_SESSION['role'];                 // 'admin' | 'midwife'
$user_id           = (int)($_SESSION['user_id'] ?? 0);
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$current_month     = date('Y-m-01');

/* Barangays the user can see/select */
if ($role === 'admin') {
    $stmtB = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
    $stmtB->bind_param("i", $municipality_id);
    $stmtB->execute();
    $barangays = $stmtB->get_result();
} else {
    $stmtB = $conn->prepare("
        SELECT b.id, b.name
        FROM barangays b
        INNER JOIN midwife_access ma ON ma.barangay_id = b.id
        WHERE ma.midwife_id = ? AND ma.access_month = ?
        ORDER BY b.name
    ");
    $stmtB->bind_param("is", $user_id, $current_month);
    $stmtB->execute();
    $barangays = $stmtB->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Patient - RHU-MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
  body { background:#f4f6f9; font-family:'Inter',sans-serif; }
  h3, h4 { font-family:'Merriweather',serif; }
  .section-title { font-weight:600; font-size:1.1rem; margin:30px 0 12px; border-bottom:1px solid #ccc; padding-bottom:4px; }
  .form-label { font-weight:600; }
</style>
</head>
<body>

<div class="container bg-white p-4 shadow rounded mt-4" style="max-width: 1000px;">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3><i class="bi bi-person-plus-fill"></i> Add Patient</h3>
    <a href="<?= $role === 'admin' ? 'admin_dashboard.php' : 'midwife_dashboard.php'; ?>" class="btn btn-outline-secondary">
        <?php if (!empty($_SESSION['error'])): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['success'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>

      <i class="bi bi-arrow-left"></i> Back
    </a>
  </div>

  <form method="POST" action="save_pregnancy.php" novalidate>
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

    <div class="section-title">Address</div>
    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Barangay</label>
        <select name="barangay_id" id="barangay_id" class="form-select" required>
          <option value="">-- Select Barangay --</option>
          <?php while ($b = $barangays->fetch_assoc()): ?>
            <option value="<?= (int)$b['id']; ?>"><?= htmlspecialchars($b['name']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Purok</label>
        <select name="purok_id" id="purok_id" class="form-select" required>
          <option value="">-- Select Purok --</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Municipality</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars($municipality_name); ?>" readonly>
      </div>
      <div class="col-md-3">
        <label class="form-label">Province</label>
        <input type="text" class="form-control" value="Misamis Occidental" readonly>
      </div>

      <div class="col-md-3">
        <label class="form-label">Contact Number</label>
        <input type="text" name="contact" class="form-control" placeholder="09XXXXXXXXX" maxlength="11"
               inputmode="numeric" pattern="^09\d{9}$" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Household No.</label>
        <input type="text" name="household_no" class="form-control" inputmode="numeric" pattern="^\d+$" required>
      </div>
    </div>

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

    <div class="row g-3 mb-3">
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

    <!-- Registered By + Assigned midwife -->
    <input type="hidden" name="registered_by" value="<?= $user_id ?>">
    <div class="mb-3">
      <label class="form-label">Assigned Midwife (optional)</label>
      <select name="assigned_midwife_id" id="assigned_midwife_id" class="form-select" disabled>
        <option value="">-- Select Midwife with access to this barangay --</option>
      </select>
      <small class="text-muted">Defaults to the registering user in the database if left blank.</small>
    </div>

    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save2"></i> Save Record</button>
  </form>
</div>

<script>
// Age = today - dob
const dob = document.getElementById('dob');
const age = document.getElementById('age');
dob.addEventListener('change', () => {
  if (!dob.value) { age.value = ''; return; }
  const d = new Date(dob.value);
  const t = new Date();
  let a = t.getFullYear() - d.getFullYear();
  const m = t.getMonth() - d.getMonth();
  if (m < 0 || (m === 0 && t.getDate() < d.getDate())) a--;
  age.value = isFinite(a) ? a : '';
});

// load puroks
document.getElementById('barangay_id').addEventListener('change', function () {
  const barangayId = this.value;
  const purokDropdown = document.getElementById('purok_id');
  const midwifeSelect = document.getElementById('assigned_midwife_id');
  purokDropdown.innerHTML = '<option value="">-- Select Purok --</option>';
  midwifeSelect.innerHTML = '<option value="">-- Select Midwife with access to this barangay --</option>';
  midwifeSelect.disabled = true;

  if (!barangayId) return;

  fetch('get_puroks.php?barangay_id=' + encodeURIComponent(barangayId))
    .then(r => r.json())
    .then(data => {
      data.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id; opt.textContent = p.name;
        purokDropdown.appendChild(opt);
      });
    });

  // load midwives who have access to this barangay
  fetch('get_midwives_by_barangay.php?barangay_id=' + encodeURIComponent(barangayId))
    .then(r => r.json())
    .then(data => {
      if (Array.isArray(data) && data.length) {
        data.forEach(m => {
          const opt = document.createElement('option');
          opt.value = m.id; opt.textContent = m.username;
          midwifeSelect.appendChild(opt);
        });
        midwifeSelect.disabled = false;
      }
    });
});
</script>
</body>
</html>
