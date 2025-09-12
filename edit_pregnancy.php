<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "Invalid request.";
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id                = (int) $_GET['id'];
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';

/* --- Fetch record + names for barangay/purok --- */
$stmt = $conn->prepare("
    SELECT p.*, b.name AS barangay_name, pr.name AS purok_name
    FROM pregnant_women p
    LEFT JOIN barangays b ON p.barangay_id = b.id
    LEFT JOIN puroks pr   ON p.purok_id    = pr.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) { echo "Record not found."; exit(); }
$pregnant = $res->fetch_assoc();

/* --- Barangays scoped to municipality --- */
$barangaysStmt = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
$barangaysStmt->bind_param("i", $municipality_id);
$barangaysStmt->execute();
$barangays = $barangaysStmt->get_result();

/* --- On POST: validate + update --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $P = $_POST;

    // Required fields (keep in sync with add_pregnancy)
    $required = [
        'first_name','last_name','sex','dob','birthplace','blood_type','civil_status',
        'spouse_name','mother_name','education','employment',
        'contact','household_no','barangay_id','purok_id',
        'dswd','four_ps','philhealth','status_type','pcb','status'
    ];
    $missing = [];
    foreach ($required as $k) {
        if (!isset($P[$k]) || trim((string)$P[$k]) === '') $missing[] = $k;
    }
    if ($missing) {
        $error = "Please fill out all required fields: " . implode(', ', $missing);
    } else {
        // Extract + sanitize
        $first_name   = trim($P['first_name']);
        $middle_name  = trim($P['middle_name'] ?? '');
        $last_name    = trim($P['last_name']);
        $suffix       = trim($P['suffix'] ?? '');
        $sex          = trim($P['sex']);
        $dob          = $P['dob'];
        $birthplace   = trim($P['birthplace']);
        $blood_type   = trim($P['blood_type']);
        $civil_status = trim($P['civil_status']);

        $spouse_name  = trim($P['spouse_name']);
        $mother_name  = trim($P['mother_name']);
        $education    = trim($P['education']);
        $employment   = trim($P['employment']);

        $contact      = trim($P['contact']);
        $household_no = trim($P['household_no']);

        $barangay_id  = (int)$P['barangay_id'];
        $purok_id     = (int)$P['purok_id'];

        $dswd_nhts          = trim($P['dswd']);
        $four_ps            = trim($P['four_ps']);
        $philhealth_member  = trim($P['philhealth']);
        $status_type        = trim($P['status_type']);
        $philhealth_no      = trim($P['philhealth_no'] ?? '');
        $pcb_member         = trim($P['pcb']);
        $philhealth_category = isset($P['phil_category']) ? implode(',', (array)$P['phil_category']) : '';

        $assigned_midwife_id = (isset($P['assigned_midwife_id']) && $P['assigned_midwife_id'] !== '')
            ? (int)$P['assigned_midwife_id'] : null;

        $status = trim($P['status']);

        // Regex/format validations
        $nameRe = '/^[A-Za-z.\- ]+$/';
        if (!preg_match($nameRe, $first_name) || !preg_match($nameRe, $last_name) ||
            ($middle_name !== '' && !preg_match($nameRe, $middle_name)) ||
            ($suffix !== '' && !preg_match($nameRe, $suffix)) ||
            !preg_match($nameRe, $birthplace)) {
            $error = "Names/Birthplace may only contain letters, spaces, periods (.), and hyphens (-).";
        } elseif (!preg_match('/^09\d{9}$/', $contact)) {
            $error = "Contact number must be 11 digits and start with 09.";
        } elseif (!preg_match('/^\d+$/', $household_no)) {
            $error = "Household number must contain digits only.";
        } else {
            // ENUM guards (match your DB)
            $sex_allowed         = ['Male','Female'];
            $civil_allowed       = ['Single','Married','Annulled','Widow/er','Separated','Co-habitation'];
            $yn                  = ['Yes','No'];
            $status_type_allowed = ['Member','Dependent'];
            $status_allowed      = ['under_monitoring','completed'];

            if (!in_array($sex, $sex_allowed, true))                $error = "Invalid sex selected.";
            elseif (!in_array($civil_status, $civil_allowed, true)) $error = "Invalid civil status.";
            elseif (!in_array($dswd_nhts, $yn, true) ||
                    !in_array($four_ps, $yn, true) ||
                    !in_array($philhealth_member, $yn, true) ||
                    !in_array($pcb_member, $yn, true))             $error = "YN fields must be Yes or No.";
            elseif (!in_array($status_type, $status_type_allowed, true)) $error = "Invalid PhilHealth Status Type.";
            elseif (!in_array($status, $status_allowed, true))      $error = "Invalid monitoring status.";
        }

        // Scope checks (barangay in municipality, purok in barangay)
        if (!isset($error)) {
            $chk = $conn->prepare("SELECT id FROM barangays WHERE id = ? AND municipality_id = ?");
            $chk->bind_param("ii", $barangay_id, $municipality_id);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $error = "Selected barangay is not in your municipality.";
            } else {
                $chk = $conn->prepare("SELECT id FROM puroks WHERE id = ? AND barangay_id = ?");
                $chk->bind_param("ii", $purok_id, $barangay_id);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $error = "Selected purok does not belong to the chosen barangay.";
                }
            }
        }

        // Assigned midwife validation (optional but must be valid if provided)
        if (!isset($error) && !is_null($assigned_midwife_id)) {
            $chk = $conn->prepare("
                SELECT u.id
                FROM users u
                WHERE u.id = ? AND u.role='midwife' AND u.is_active=1 AND u.municipality_id = ?
            ");
            $chk->bind_param("ii", $assigned_midwife_id, $municipality_id);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $error = "Assigned midwife is not valid for this municipality.";
            }
        }

        // UPDATE
        if (!isset($error)) {
            $sql = "
                UPDATE pregnant_women SET
                    first_name=?, middle_name=?, last_name=?, suffix=?, sex=?, dob=?, birthplace=?, blood_type=?, civil_status=?,
                    spouse_name=?, mother_name=?, education=?, employment=?, contact=?, purok_id=?,
                    dswd_nhts=?, four_ps=?, household_no=?, philhealth_member=?, status_type=?, philhealth_no=?, pcb_member=?, philhealth_category=?,
                    barangay_id=?, assigned_midwife_id=?, status=?
                WHERE id=?
            ";
            $stmtU = $conn->prepare($sql);

            // Types: 14s + i + 8s + i + i + s + i
            $types = str_repeat('s',14) . 'i' . str_repeat('s',8) . 'i' . 'i' . 's' . 'i';

            $stmtU->bind_param(
                $types,
                $first_name, $middle_name, $last_name, $suffix, $sex, $dob, $birthplace, $blood_type, $civil_status,
                $spouse_name, $mother_name, $education, $employment, $contact, $purok_id,
                $dswd_nhts, $four_ps, $household_no, $philhealth_member, $status_type, $philhealth_no, $pcb_member, $philhealth_category,
                $barangay_id, $assigned_midwife_id, $status, $id
            );

            if ($stmtU->execute()) {
                $_SESSION['success'] = "Record updated.";
                header("Location: view_pregnancies.php");
                exit();
            } else {
                $error = "Update failed: " . $stmtU->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Patient - RHU-MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
  body { background:#f4f6f9; font-family:'Inter',sans-serif; }
  h3 { font-family:'Merriweather',serif; }
  .section-title { font-weight:600; font-size:1.1rem; margin:30px 0 12px; border-bottom:1px solid #ccc; padding-bottom:4px; }
  .form-label { font-weight:600; }
</style>
</head>
<body>

<div class="container bg-white p-4 shadow rounded mt-4" style="max-width: 1000px;">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3><i class="bi bi-pencil-square"></i> Edit Patient</h3>
    <a href="view_pregnancies.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
  <?php endif; ?>

  <form method="POST" novalidate>
    <div class="section-title">Personal Information</div>
    <div class="row g-3 mb-3">
      <?php
      $inputs = [
        ['first_name','First Name'],['middle_name','Middle Name'],['last_name','Last Name'],['suffix','Suffix (optional)']
      ];
      foreach ($inputs as [$name,$label]): ?>
      <div class="col-md-3">
        <label class="form-label"><?= $label ?></label>
        <input type="text" name="<?= $name ?>" class="form-control"
               value="<?= htmlspecialchars($pregnant[$name] ?? '') ?>"
               <?= $name==='middle_name' || $name==='suffix' ? '' : 'required' ?>
               maxlength="50" pattern="^[A-Za-z.\- ]+$">
      </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Sex</label><br>
        <?php foreach (['Female','Male'] as $sx): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="sex" value="<?= $sx ?>" <?= ($pregnant['sex']===$sx?'checked':'') ?> required>
            <label class="form-check-label"><?= $sx ?></label>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="col-md-3">
        <label class="form-label">Date of Birth</label>
        <input type="date" id="dob" name="dob" class="form-control" value="<?= htmlspecialchars($pregnant['dob']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Age</label>
        <input type="text" id="age" class="form-control" readonly placeholder="Auto">
      </div>
      <div class="col-md-3">
        <label class="form-label">Birthplace</label>
        <input type="text" name="birthplace" class="form-control" value="<?= htmlspecialchars($pregnant['birthplace']) ?>"
               maxlength="50" pattern="^[A-Za-z.\- ]+$" required>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Blood Type</label>
        <select name="blood_type" class="form-select" required>
          <option value="">Select</option>
          <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-'] as $type): ?>
            <option value="<?= $type ?>" <?= ($pregnant['blood_type']===$type?'selected':'') ?>><?= $type ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-9">
        <label class="form-label d-block">Civil Status</label>
        <?php foreach (['Single','Married','Annulled','Widow/er','Separated','Co-habitation'] as $status): ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="civil_status" value="<?= $status ?>" <?= ($pregnant['civil_status']===$status?'checked':'') ?> required>
            <label class="form-check-label"><?= $status ?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="section-title">Family & Socioeconomic Information</div>
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">Spouse's Name</label>
        <input type="text" name="spouse_name" class="form-control" value="<?= htmlspecialchars($pregnant['spouse_name']) ?>"
               maxlength="50" pattern="^[A-Za-z.\- ]+$" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Mother's Name</label>
        <input type="text" name="mother_name" class="form-control" value="<?= htmlspecialchars($pregnant['mother_name']) ?>"
               maxlength="50" pattern="^[A-Za-z.\- ]+$" required>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">Educational Attainment</label>
        <select name="education" class="form-select" required>
          <option value="">Select</option>
          <?php foreach (['No Formal Education','Elementary','High School','College','Vocational','Post Graduate'] as $edu): ?>
            <option value="<?= $edu ?>" <?= ($pregnant['education']===$edu?'selected':'') ?>><?= $edu ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Employment Status</label>
        <select name="employment" class="form-select" required>
          <option value="">Select</option>
          <?php foreach (['Student','Employed','Unknown','Retired','None/Unemployed'] as $emp): ?>
            <option value="<?= $emp ?>" <?= ($pregnant['employment']===$emp?'selected':'') ?>><?= $emp ?></option>
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
            <option value="<?= (int)$b['id']; ?>" <?= ((int)$pregnant['barangay_id']===(int)$b['id']?'selected':'') ?>>
              <?= htmlspecialchars($b['name']); ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Purok</label>
        <select name="purok_id" id="purok_id" class="form-select" required>
          <option value="<?= (int)$pregnant['purok_id'] ?>" selected><?= htmlspecialchars($pregnant['purok_name']) ?></option>
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
        <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($pregnant['contact']) ?>"
               placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" pattern="^09\d{9}$" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Household No.</label>
        <input type="text" name="household_no" class="form-control" value="<?= htmlspecialchars($pregnant['household_no']) ?>"
               inputmode="numeric" pattern="^\d+$" required>
      </div>
    </div>

    <div class="section-title">Health & Welfare</div>
    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">DSWD NHTS?</label>
        <select name="dswd" class="form-select" required>
          <?php foreach (['No','Yes'] as $yn): ?>
            <option value="<?= $yn ?>" <?= ($pregnant['dswd_nhts']===$yn?'selected':'') ?>><?= $yn ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">4Ps Member?</label>
        <select name="four_ps" class="form-select" required>
          <?php foreach (['No','Yes'] as $yn): ?>
            <option value="<?= $yn ?>" <?= ($pregnant['four_ps']===$yn?'selected':'') ?>><?= $yn ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">PhilHealth Member?</label>
        <select name="philhealth" class="form-select" required>
          <?php foreach (['No','Yes'] as $yn): ?>
            <option value="<?= $yn ?>" <?= ($pregnant['philhealth_member']===$yn?'selected':'') ?>><?= $yn ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Status Type</label>
        <select name="status_type" class="form-select" required>
          <?php foreach (['','Member','Dependent'] as $st): ?>
            <option value="<?= $st ?>" <?= ($pregnant['status_type']===$st?'selected':'') ?>><?= $st?:'Select' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">PhilHealth No. (optional)</label>
        <input type="text" name="philhealth_no" class="form-control"
               value="<?= htmlspecialchars($pregnant['philhealth_no']) ?>" maxlength="12" inputmode="numeric" pattern="^\d{0,12}$">
      </div>
      <div class="col-md-4">
        <label class="form-label">PCB Member?</label>
        <select name="pcb" class="form-select" required>
          <?php foreach (['No','Yes'] as $yn): ?>
            <option value="<?= $yn ?>" <?= ($pregnant['pcb_member']===$yn?'selected':'') ?>><?= $yn ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label d-block">If Member, Choose Category</label>
        <?php
          $cats = ['FE - Private','FE - Government','IE','Others'];
          $selCats = array_filter(array_map('trim', explode(',', (string)$pregnant['philhealth_category'])));
          foreach ($cats as $cat):
        ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="phil_category[]" value="<?= $cat ?>"
                   <?= in_array($cat, $selCats, true) ? 'checked' : '' ?>>
            <label class="form-check-label"><?= $cat ?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="section-title">Monitoring</div>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Status</label>
        <select name="status" class="form-select" required>
          <option value="under_monitoring" <?= $pregnant['status']==='under_monitoring'?'selected':'' ?>>Under Monitoring</option>
          <option value="completed"        <?= $pregnant['status']==='completed'?'selected':'' ?>>Completed</option>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label">Assigned Midwife (optional)</label>
        <select name="assigned_midwife_id" id="assigned_midwife_id" class="form-select">
          <option value="">-- Select Midwife with access to this barangay --</option>
        </select>
        <small class="text-muted">Filtered by the selected barangay.</small>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save"></i> Save Changes</button>
  </form>
</div>

<script>
// Age auto-calc
const dob = document.getElementById('dob');
const age = document.getElementById('age');
function computeAge() {
  if (!dob.value) { age.value=''; return; }
  const d = new Date(dob.value), t = new Date();
  let a = t.getFullYear() - d.getFullYear();
  const m = t.getMonth() - d.getMonth();
  if (m < 0 || (m===0 && t.getDate() < d.getDate())) a--;
  age.value = isFinite(a) ? a : '';
}
dob.addEventListener('change', computeAge);
computeAge(); // initial

// Purok loader + midwife loader tied to barangay
const barangaySel = document.getElementById('barangay_id');
const purokSel    = document.getElementById('purok_id');
const midwifeSel  = document.getElementById('assigned_midwife_id');
const preBarangay = "<?= (int)$pregnant['barangay_id'] ?>";
const prePurok    = "<?= (int)$pregnant['purok_id'] ?>";
const preMidwife  = "<?= isset($pregnant['assigned_midwife_id']) ? (int)$pregnant['assigned_midwife_id'] : '' ?>";

function loadPuroks(barangayId) {
  if (!barangayId) { purokSel.innerHTML = '<option value="">-- Select Purok --</option>'; return; }
  purokSel.innerHTML = '<option value="">Loading...</option>';
  fetch('get_puroks.php?barangay_id=' + encodeURIComponent(barangayId))
    .then(r=>r.json())
    .then(data=>{
      purokSel.innerHTML = '<option value="">-- Select Purok --</option>';
      data.forEach(p=>{
        const opt = document.createElement('option');
        opt.value = p.id; opt.textContent = p.name;
        if (String(p.id) === String(prePurok)) opt.selected = true;
        purokSel.appendChild(opt);
      });
    })
    .catch(()=>{ purokSel.innerHTML = '<option value="">-- Error Loading --</option>'; });
}

function loadMidwives(barangayId) {
  midwifeSel.innerHTML = '<option value="">-- Select Midwife with access to this barangay --</option>';
  if (!barangayId) return;
  fetch('get_midwives_by_barangay.php?barangay_id=' + encodeURIComponent(barangayId))
    .then(r=>r.json())
    .then(data=>{
      if (Array.isArray(data) && data.length) {
        data.forEach(m=>{
          const opt = document.createElement('option');
          opt.value = m.id; opt.textContent = m.username;
          if (String(m.id) === String(preMidwife)) opt.selected = true;
          midwifeSel.appendChild(opt);
        });
      }
    });
}

barangaySel.addEventListener('change', () => {
  loadPuroks(barangaySel.value);
  // Reset pre-selected midwife on change
  window.setTimeout(()=>loadMidwives(barangaySel.value), 0);
});

// Initial population (for current barangay)
loadPuroks(preBarangay);
loadMidwives(preBarangay);
</script>
</body>
</html>
