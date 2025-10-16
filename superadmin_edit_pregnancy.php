<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth: SUPER ADMIN ONLY ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function clean($v){ return is_string($v) ? trim($v) : $v; }
function fail($msg, $back='superadmin_edit_pregnancy.php'){
    $_SESSION['error'] = $msg;
    header("Location: $back");
    exit();
}

/* ---------- Inline AJAX endpoints for cascading selects ---------- */
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

/* ---------- Get ID ---------- */
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['error'] = "Invalid record ID.";
    header("Location: superadmin_add_patient.php");
    exit();
}

/* =======================================================================
   POST: Update record
===========================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect & Validate
    $must_text    = ['first_name','last_name','birthplace'];
    $must_selects = ['sex','blood_type','civil_status','education','employment','dswd','four_ps','philhealth','status_type','pcb','status'];
    $must_misc    = ['dob','contact','household_no'];
    $must_loc     = ['municipality_id','barangay_id','purok_id'];

    foreach ($must_text as $k)    if (empty($_POST[$k])) fail("Missing required field: $k", "superadmin_edit_pregnancy.php?id=$id");
    foreach ($must_selects as $k) if (!isset($_POST[$k]) || $_POST[$k]==='') fail("Please select: $k", "superadmin_edit_pregnancy.php?id=$id");
    foreach ($must_misc as $k)    if (empty($_POST[$k])) fail("Missing required field: $k", "superadmin_edit_pregnancy.php?id=$id");
    foreach ($must_loc as $k)     if (empty($_POST[$k])) fail("Please complete location: $k", "superadmin_edit_pregnancy.php?id=$id");

    $first_name   = clean($_POST['first_name']);
    $middle_name  = clean($_POST['middle_name'] ?? null);
    $last_name    = clean($_POST['last_name']);
    $suffix       = clean($_POST['suffix'] ?? null);

    $sex          = clean($_POST['sex']); // enum
    $dob          = clean($_POST['dob']); // YYYY-MM-DD
    $birthplace   = clean($_POST['birthplace']);
    $blood_type   = clean($_POST['blood_type']);
    $civil_status = clean($_POST['civil_status']);

    $spouse_name  = clean($_POST['spouse_name'] ?? null);
    $mother_name  = clean($_POST['mother_name'] ?? null);

    $education    = clean($_POST['education']);
    $employment   = clean($_POST['employment']);

    $contact      = preg_replace('/\s+/', '', (string)($_POST['contact'] ?? ''));
    $household_no = clean($_POST['household_no']);

    $municipality_id = (int)($_POST['municipality_id'] ?? 0);
    $barangay_id     = (int)($_POST['barangay_id'] ?? 0);
    $purok_id        = (int)($_POST['purok_id'] ?? 0);

    $dswd_nhts         = clean($_POST['dswd']);        // Yes/No
    $four_ps           = clean($_POST['four_ps']);     // Yes/No
    $philhealth_member = clean($_POST['philhealth']);  // Yes/No
    $status_type       = clean($_POST['status_type']); // Member/Dependent
    $philhealth_no     = clean($_POST['philhealth_no'] ?? null);
    $pcb_member        = clean($_POST['pcb']);         // Yes/No

    $phil_category_arr = (isset($_POST['phil_category']) && is_array($_POST['phil_category'])) ? $_POST['phil_category'] : [];
    $philhealth_category = implode(',', array_map('clean', $phil_category_arr));

    $status = clean($_POST['status']); // under_monitoring | completed

    // Formats
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob))     fail("Invalid date format for DOB.", "superadmin_edit_pregnancy.php?id=$id");
    if (!preg_match('/^09\d{9}$/', $contact))           fail("Contact number must be 11 digits and start with 09.", "superadmin_edit_pregnancy.php?id=$id");
    if (!preg_match('/^\d+$/', $household_no))          fail("Household No. must be numeric.", "superadmin_edit_pregnancy.php?id=$id");
    if ($municipality_id<=0 || $barangay_id<=0 || $purok_id<=0) fail("Please complete Region → Province → Municipality → Barangay and Purok.", "superadmin_edit_pregnancy.php?id=$id");

    // Relationship checks
    $chk = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE id = ? AND municipality_id = ?");
    $chk->bind_param("ii", $barangay_id, $municipality_id);
    $chk->execute(); $ok = (int)$chk->get_result()->fetch_assoc()['c']; $chk->close();
    if ($ok===0) fail("Selected barangay does not belong to the chosen municipality.", "superadmin_edit_pregnancy.php?id=$id");

    $chk2 = $conn->prepare("SELECT COUNT(*) c FROM puroks WHERE id = ? AND barangay_id = ?");
    $chk2->bind_param("ii", $purok_id, $barangay_id);
    $chk2->execute(); $ok2 = (int)$chk2->get_result()->fetch_assoc()['c']; $chk2->close();
    if ($ok2===0) fail("Selected purok does not belong to the chosen barangay.", "superadmin_edit_pregnancy.php?id=$id");

    // Province by municipality (for `province` column)
    $province = null;
    $qp = $conn->prepare("
        SELECT p.name AS province_name
        FROM municipalities m
        JOIN provinces p ON p.id = m.province_id
        WHERE m.id = ? LIMIT 1
    ");
    $qp->bind_param("i", $municipality_id);
    $qp->execute();
    $prow = $qp->get_result()->fetch_assoc();
    $qp->close();
    if ($prow && isset($prow['province_name'])) $province = $prow['province_name'];

    // UPDATE
    $conn->begin_transaction();
    try {
        $sql = "
            UPDATE pregnant_women SET
                first_name = ?, middle_name = ?, last_name = ?, suffix = ?,
                dob = ?, sex = ?, birthplace = ?, blood_type = ?, civil_status = ?,
                spouse_name = ?, mother_name = ?,
                education = ?, employment = ?,
                contact = ?, dswd_nhts = ?, four_ps = ?, household_no = ?,
                philhealth_member = ?, status_type = ?, philhealth_no = ?, pcb_member = ?, philhealth_category = ?,
                barangay_id = ?, municipality_id = ?, purok_id = ?, province = ?,
                status = ?
            WHERE id = ?
        ";
        $stmt = $conn->prepare($sql);

        // EXACT types (28): 22s + 3i + 2s + 1i
        $types = "ssssssssssssssssssssssiiissi";

        $stmt->bind_param(
            $types,
            $first_name, $middle_name, $last_name, $suffix,
            $dob, $sex, $birthplace, $blood_type, $civil_status,
            $spouse_name, $mother_name,
            $education, $employment,
            $contact, $dswd_nhts, $four_ps, $household_no,
            $philhealth_member, $status_type, $philhealth_no, $pcb_member, $philhealth_category,
            $barangay_id, $municipality_id, $purok_id, $province,
            $status, $id
        );

        if (!$stmt->execute()) throw new Exception("Update failed: " . $stmt->error);
        $stmt->close();
        $conn->commit();

        $_SESSION['success'] = "Patient record updated.";
        header("Location: superadmin_view_pregnancy_detail.php?id=".$id);
        exit();

    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error updating record: " . $e->getMessage();
        header("Location: superadmin_edit_pregnancy.php?id=".$id);
        exit();
    }
}

/* =======================================================================
   GET: Load record for editing
===========================================================================*/
$sql = "
SELECT
  pw.*,
  b.name AS barangay_name, b.id AS barangay_id_join,
  m.name AS municipality_name, m.id AS municipality_id_join, m.province_id AS province_id_join,
  p.name AS province_name, p.id AS province_id,
  r.name AS region_name, r.id AS region_id
FROM pregnant_women pw
LEFT JOIN barangays b      ON b.id = pw.barangay_id
LEFT JOIN municipalities m ON m.id = pw.municipality_id
LEFT JOIN provinces p      ON p.id = m.province_id
LEFT JOIN regions r        ON r.id = p.region_id
WHERE pw.id = ?
LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rec) {
    $_SESSION['error'] = "Record not found.";
    header("Location: superadmin_add_patient.php");
    exit();
}

/* Pre-select IDs for cascading */
$pre_region_id      = (int)($rec['region_id'] ?? 0);
$pre_province_id    = (int)($rec['province_id'] ?? $rec['province_id_join'] ?? 0);
$pre_municipality_id= (int)($rec['municipality_id'] ?? $rec['municipality_id_join'] ?? 0);
$pre_barangay_id    = (int)($rec['barangay_id'] ?? $rec['barangay_id_join'] ?? 0);
$pre_purok_id       = (int)($rec['purok_id'] ?? 0);

/* Flash messages */
$flash_error = $_SESSION['error']   ?? null; unset($_SESSION['error']);
$flash_ok    = $_SESSION['success'] ?? null; unset($_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Patient (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
  body { background:#f7fafc; font-family:'Inter',sans-serif; }
  .wrap{ max-width: 1000px; margin:28px auto; }
  .section-title { font-weight:700; font-size:1.05rem; margin:24px 0 10px; border-bottom:1px solid #e5e7eb; padding-bottom:6px; }
</style>
</head>
<body>
<div class="wrap bg-white p-4 shadow-sm rounded">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 style="font-family:'Merriweather',serif;"><i class="bi bi-pencil-square"></i> Edit Patient</h3>
    <div>
      <a class="btn btn-outline-secondary" href="superadmin_view_pregnancy_detail.php?id=<?= (int)$id ?>">
        <i class="bi bi-eye"></i> View
      </a>
      <a class="btn btn-outline-secondary" href="javascript:history.back()"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= h($flash_error) ?></div>
  <?php endif; ?>
  <?php if ($flash_ok): ?>
    <div class="alert alert-success"><?= h($flash_ok) ?></div>
  <?php endif; ?>

  <form method="POST" action="superadmin_edit_pregnancy.php" id="editForm" novalidate>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="municipality_id" id="municipality_id_hidden" value="<?= (int)$pre_municipality_id ?>">

    <!-- Personal -->
    <div class="section-title">Personal Information</div>
    <div class="row g-3 mb-2">
      <div class="col-md-3">
        <label class="form-label">First Name</label>
        <input name="first_name" class="form-control" required maxlength="50" value="<?= h($rec['first_name']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Middle Name</label>
        <input name="middle_name" class="form-control" maxlength="50" value="<?= h($rec['middle_name']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Last Name</label>
        <input name="last_name" class="form-control" required maxlength="50" value="<?= h($rec['last_name']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Suffix</label>
        <input name="suffix" class="form-control" maxlength="10" value="<?= h($rec['suffix']) ?>">
      </div>
    </div>

    <div class="row g-3 mb-2">
      <div class="col-md-3">
        <label class="form-label">Sex</label>
        <select name="sex" class="form-select" required>
          <option value="Female" <?= ($rec['sex']==='Female'?'selected':'') ?>>Female</option>
          <option value="Male"   <?= ($rec['sex']==='Male'  ?'selected':'') ?>>Male</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Date of Birth</label>
        <input type="date" name="dob" class="form-control" required value="<?= h($rec['dob']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Birthplace</label>
        <input name="birthplace" class="form-control" maxlength="100" value="<?= h($rec['birthplace']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Blood Type</label>
        <input name="blood_type" class="form-control" maxlength="5" value="<?= h($rec['blood_type']) ?>">
      </div>
    </div>

    <div class="row g-3 mb-2">
      <div class="col-md-6">
        <label class="form-label">Civil Status</label>
        <select name="civil_status" class="form-select" required>
          <?php foreach (['Single','Married','Annulled','Widow/er','Separated','Co-habitation'] as $cs): ?>
            <option value="<?= $cs ?>" <?= ($rec['civil_status']===$cs?'selected':'') ?>><?= $cs ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Family & Socio -->
    <div class="section-title">Family & Socioeconomic Information</div>
    <div class="row g-3 mb-2">
      <div class="col-md-6">
        <label class="form-label">Spouse's Name</label>
        <input name="spouse_name" class="form-control" maxlength="100" value="<?= h($rec['spouse_name']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Mother's Name</label>
        <input name="mother_name" class="form-control" maxlength="100" value="<?= h($rec['mother_name']) ?>">
      </div>
    </div>
    <div class="row g-3 mb-2">
      <div class="col-md-6">
        <label class="form-label">Educational Attainment</label>
        <select name="education" class="form-select" required>
          <?php foreach (['No Formal Education','Elementary','High School','College','Vocational','Post Graduate'] as $ed): ?>
            <option value="<?= $ed ?>" <?= ($rec['education']===$ed?'selected':'') ?>><?= $ed ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Employment Status</label>
        <select name="employment" class="form-select" required>
          <?php foreach (['Student','Employed','Unknown','Retired','None/Unemployed'] as $em): ?>
            <option value="<?= $em ?>" <?= ($rec['employment']===$em?'selected':'') ?>><?= $em ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Address -->
    <div class="section-title">Address</div>
    <div class="row g-3 mb-2">
      <div class="col-md-3">
        <label class="form-label">Region</label>
        <select id="region_select" class="form-select" required></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Province</label>
        <select id="province_select" class="form-select" required></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Municipality</label>
        <select id="municipality_select" class="form-select" required></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Barangay</label>
        <select name="barangay_id" id="barangay_select" class="form-select" required></select>
      </div>
    </div>
    <div class="row g-3 mb-2">
      <div class="col-md-3">
        <label class="form-label">Purok</label>
        <select name="purok_id" id="purok_select" class="form-select" required>
          <option value="">-- Select Purok --</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Contact Number</label>
        <input name="contact" class="form-control" maxlength="11" placeholder="09XXXXXXXXX" value="<?= h($rec['contact']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Household No.</label>
        <input name="household_no" class="form-control" value="<?= h($rec['household_no']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Monitoring Status</label>
        <select name="status" class="form-select" required>
          <option value="under_monitoring" <?= ($rec['status']==='under_monitoring'?'selected':'') ?>>Under Monitoring</option>
          <option value="completed" <?= ($rec['status']==='completed'?'selected':'') ?>>Completed</option>
        </select>
      </div>
    </div>

    <!-- Programs -->
    <div class="section-title">Programs & PhilHealth</div>
    <div class="row g-3 mb-2">
      <div class="col-md-3">
        <label class="form-label">DSWD NHTS</label>
        <select name="dswd" class="form-select" required>
          <option value="No"  <?= ($rec['dswd_nhts']==='No'?'selected':'') ?>>No</option>
          <option value="Yes" <?= ($rec['dswd_nhts']==='Yes'?'selected':'') ?>>Yes</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">4Ps Member</label>
        <select name="four_ps" class="form-select" required>
          <option value="No"  <?= ($rec['four_ps']==='No'?'selected':'') ?>>No</option>
          <option value="Yes" <?= ($rec['four_ps']==='Yes'?'selected':'') ?>>Yes</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">PhilHealth Member</label>
        <select name="philhealth" class="form-select" required>
          <option value="No"  <?= ($rec['philhealth_member']==='No'?'selected':'') ?>>No</option>
          <option value="Yes" <?= ($rec['philhealth_member']==='Yes'?'selected':'') ?>>Yes</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Status Type</label>
        <select name="status_type" class="form-select" required>
          <option value="Member"    <?= ($rec['status_type']==='Member'?'selected':'') ?>>Member</option>
          <option value="Dependent" <?= ($rec['status_type']==='Dependent'?'selected':'') ?>>Dependent</option>
        </select>
      </div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">PhilHealth No.</label>
        <input name="philhealth_no" class="form-control" maxlength="30" value="<?= h($rec['philhealth_no']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">PCB Member</label>
        <select name="pcb" class="form-select" required>
          <option value="No"  <?= ($rec['pcb_member']==='No'?'selected':'') ?>>No</option>
          <option value="Yes" <?= ($rec['pcb_member']==='Yes'?'selected':'') ?>>Yes</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label d-block">PhilHealth Category</label>
        <?php
          $setVals = array_flip(array_map('trim', explode(',', (string)$rec['philhealth_category'])));
          foreach (['FE - Private','FE - Government','IE','Others'] as $cat):
        ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="phil_category[]" value="<?= $cat ?>"
              <?= isset($setVals[$cat]) ? 'checked' : '' ?>>
            <label class="form-check-label"><?= $cat ?></label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="text-end">
      <button class="btn btn-primary"><i class="bi bi-save2"></i> Save Changes</button>
    </div>
  </form>
</div>

<script>
// Preselected IDs from PHP
const PRE_REGION_ID       = <?= (int)$pre_region_id ?>;
const PRE_PROVINCE_ID     = <?= (int)$pre_province_id ?>;
const PRE_MUNICIPALITY_ID = <?= (int)$pre_municipality_id ?>;
const PRE_BARANGAY_ID     = <?= (int)$pre_barangay_id ?>;
const PRE_PUROK_ID        = <?= (int)$pre_purok_id ?>;

const muniHidden  = document.getElementById('municipality_id_hidden');
const regionSel   = document.getElementById('region_select');
const provSel     = document.getElementById('province_select');
const muniSel     = document.getElementById('municipality_select');
const brgySel     = document.getElementById('barangay_select');
const purokSel    = document.getElementById('purok_select');

function reset(sel, label, disable=true){ sel.innerHTML = `<option value="">${label}</option>`; sel.disabled = !!disable; }

function loadRegions(){
  reset(regionSel, '-- Select Region --', true);
  fetch('superadmin_edit_pregnancy.php?ajax=regions')
    .then(r=>r.json()).then(list=>{
      reset(regionSel, '-- Select Region --', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; regionSel.appendChild(o); });
      if (PRE_REGION_ID) { regionSel.value = String(PRE_REGION_ID); loadProvinces(PRE_REGION_ID, true); }
    });
}

function loadProvinces(regionId, prefill=false){
  reset(provSel, '-- Select Province --');
  reset(muniSel, '-- Select Municipality --');
  reset(brgySel, '-- Select Barangay --');
  if (!regionId) return;
  fetch('superadmin_edit_pregnancy.php?ajax=provinces&region_id='+encodeURIComponent(regionId))
    .then(r=>r.json()).then(list=>{
      reset(provSel, '-- Select Province --', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; provSel.appendChild(o); });
      if (prefill && PRE_PROVINCE_ID){ provSel.value = String(PRE_PROVINCE_ID); loadMunicipalities(PRE_PROVINCE_ID, true); }
    });
}

function loadMunicipalities(provinceId, prefill=false){
  reset(muniSel, '-- Select Municipality --');
  reset(brgySel, '-- Select Barangay --');
  if (!provinceId) return;
  fetch('superadmin_edit_pregnancy.php?ajax=municipalities&province_id='+encodeURIComponent(provinceId))
    .then(r=>r.json()).then(list=>{
      reset(muniSel, '-- Select Municipality --', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; muniSel.appendChild(o); });
      if (prefill && PRE_MUNICIPALITY_ID){ muniSel.value = String(PRE_MUNICIPALITY_ID); muniHidden.value = PRE_MUNICIPALITY_ID; loadBarangays(PRE_MUNICIPALITY_ID, true); }
    });
}

function loadBarangays(muniId, prefill=false){
  reset(brgySel, '-- Select Barangay --');
  if (!muniId) return;
  muniHidden.value = muniId;
  fetch('superadmin_edit_pregnancy.php?ajax=barangays&municipality_id='+encodeURIComponent(muniId))
    .then(r=>r.json()).then(list=>{
      reset(brgySel, '-- Select Barangay --', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; brgySel.appendChild(o); });
      if (prefill && PRE_BARANGAY_ID){
        brgySel.value = String(PRE_BARANGAY_ID);
        loadPuroks(PRE_BARANGAY_ID, true);
      }
    });
}

function loadPuroks(barangayId, prefill=false){
  reset(purokSel, '-- Select Purok --', false);
  if (!barangayId) return;
  fetch('get_puroks.php?barangay_id=' + encodeURIComponent(barangayId))
    .then(r=>r.json()).then(list=>{
      reset(purokSel, '-- Select Purok --', false);
      if (Array.isArray(list)) {
        list.forEach(p => { const o=document.createElement('option'); o.value=p.id; o.textContent=p.name; purokSel.appendChild(o); });
      }
      if (prefill && PRE_PUROK_ID) purokSel.value = String(PRE_PUROK_ID);
    });
}

// Event wiring
document.addEventListener('DOMContentLoaded', loadRegions);
regionSel.addEventListener('change', ()=> loadProvinces(regionSel.value, false));
provSel.addEventListener('change',   ()=> loadMunicipalities(provSel.value, false));
muniSel.addEventListener('change',   ()=> loadBarangays(muniSel.value, false));
brgySel.addEventListener('change',   ()=> loadPuroks(brgySel.value, false));
</script>
</body>
</html>
