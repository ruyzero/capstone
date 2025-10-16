<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function full_name($r) {
  $parts = array_filter([$r['first_name']??'', $r['middle_name']??'', $r['last_name']??'', $r['suffix']??''], fn($x)=>$x!==null && trim($x)!=='');
  return trim(implode(' ', $parts));
}

/* Identity */
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';
$handle            = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));
if ($municipality_name === '' && $municipality_id > 0) {
  $stmt=$conn->prepare("SELECT name FROM municipalities WHERE id=? LIMIT 1");
  $stmt->bind_param("i",$municipality_id); $stmt->execute();
  $r=$stmt->get_result()->fetch_assoc(); if($r) $municipality_name=$r['name']; $stmt->close();
}
if (!$municipality_id) die("No municipality set for this admin.");

/* Input */
$patient_id = (int)($_GET['patient_id'] ?? 0);
$checkup_no = max(1, min(3, (int)($_GET['checkup_no'] ?? 0)));
if (!$patient_id || !$checkup_no) die("Missing patient_id/checkup_no.");

/* Patient */
$ps = $conn->prepare("SELECT p.*, b.name AS barangay FROM pregnant_women p LEFT JOIN barangays b ON b.id=p.barangay_id WHERE p.id=? AND p.municipality_id=? LIMIT 1");
$ps->bind_param("ii", $patient_id, $municipality_id); $ps->execute();
$patient = $ps->get_result()->fetch_assoc(); $ps->close();
if (!$patient) die("Patient not found or not in your municipality.");
$patient_name = full_name($patient);

/* Existing record for this slot */
$q = $conn->prepare("SELECT * FROM prenatal_checkups WHERE patient_id=? AND checkup_no=? LIMIT 1");
$q->bind_param("ii", $patient_id, $checkup_no); $q->execute();
$editing = $q->get_result()->fetch_assoc(); $q->close();

/* Flash */
$success = $_SESSION['success'] ?? null; $error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Prenatal Checkup • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
:root{ --teal-1:#20C4B2; --teal-2:#1A9E9D; --ring:#e5e7eb; --sidebar-w:260px; }
*{ box-sizing:border-box } body{ margin:0; background:#fff; font-family:'Inter',system-ui,-apple-system, Segoe UI, Roboto, Arial; }
.layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
.leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
.brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
.brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
.nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
.nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
.main{ padding:24px; background:#fff; }
.form-card{ max-width:980px; margin:18px auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
.form-card h4{ text-align:center; font-family:'Merriweather',serif; font-weight:700; margin-bottom:18px; }
.form-control, .form-select{ height:44px; }
.submit-btn{ display:block; margin:18px auto 0; padding:10px 28px; border-radius:999px; border:none; background:linear-gradient(135deg,#20C4B2,#1A9E9D); color:#fff; font-weight:800; }
.right-rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
.stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
.stat .big{ font-size:48px; font-weight:800; }
</style>
</head>
<body>

<div class="layout">
  <aside class="leftbar">
    <div class="brand">
      <div class="mark">R</div>
      <div><div>RHU-MIS</div><small class="text-muted"><?= h(strtoupper($municipality_name)) ?></small></div>
    </div>
    <nav class="nav flex-column gap-1">
      <a class="nav-link" href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
      <a class="nav-link" href="prenatal_checkup.php?patient_id=<?= (int)$patient_id ?>"><i class="bi bi-activity"></i> Prenatal Checkup</a>
      <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
      <hr>
      <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
    </nav>
  </aside>

  <main class="main">
    <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <h4 class="text-center mb-3">Prenatal Checkup (<?= $checkup_no ?>) for <?= h($patient_name) ?></h4>

    <div class="form-card">
      <h4>Prenatal Checkup</h4>
      <form method="post" action="save_prenatal_checkup.php">
        <input type="hidden" name="patient_id" value="<?= (int)$patient_id ?>">
        <input type="hidden" name="checkup_no" value="<?= (int)$checkup_no ?>">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Checkup Date</label>
            <input type="date" name="checkup_date" class="form-control" value="<?= h($editing['checkup_date'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Nutritional Status</label>
            <select class="form-select" name="nutritional_status">
              <?php $ns=$editing['nutritional_status']??''; foreach([''=>'—','normal'=>'Normal','underweight'=>'Underweight','overweight'=>'Overweight','obese'=>'Obese'] as $k=>$v){$sel=$ns===$k?'selected':'';echo"<option value=\"$k\" $sel>$v</option>";} ?>
            </select>
          </div>

          <div class="col-md-6"><input type="number" step="0.1" min="0" class="form-control" name="weight_kg" placeholder="Weight (KG)" value="<?= h($editing['weight_kg'] ?? '') ?>"></div>
          <div class="col-md-6"><input type="number" step="0.01" min="0" class="form-control" name="height_ft" placeholder="Height (FT)" value="<?= h($editing['height_ft'] ?? '') ?>"></div>

          <div class="col-md-6"><input type="text" class="form-control" name="gest_age" placeholder="Age of Gestation (weeks)" value="<?= h($editing['gest_age_weeks'] ?? '') ?>"></div>
          <div class="col-md-6"><input type="text" class="form-control" name="blood_pressure" placeholder="Blood Pressure" value="<?= h($editing['blood_pressure'] ?? '') ?>"></div>

          <div class="col-md-6">
            <select class="form-select" name="urinalysis">
              <?php $ua=$editing['urinalysis']??''; foreach([''=>'Urinalysis','normal'=>'Normal','abnormal'=>'Abnormal','not_done'=>'Not Done'] as $k=>$v){$sel=$ua===$k?'selected':'';echo"<option value=\"$k\" $sel>$v</option>";} ?>
            </select>
          </div>
          <div class="col-md-6">
            <select class="form-select" name="cbc">
              <?php $cbc=$editing['cbc']??''; foreach([''=>'CBC','normal'=>'Normal','abnormal'=>'Abnormal','not_done'=>'Not Done'] as $k=>$v){$sel=$cbc===$k?'selected':'';echo"<option value=\"$k\" $sel>$v</option>";} ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">STIs Syphilis (Date)</label>
            <input type="date" class="form-control" name="sti_syphilis_date" value="<?= h($editing['stis_syphilis_date'] ?? '') ?>">
          </div>
          <div class="col-md-6"><input type="text" class="form-control" name="sti_hiv" placeholder="STIs HIV" value="<?= h($editing['stis_hiv'] ?? '') ?>"></div>

          <div class="col-md-6"><input type="text" class="form-control" name="sti_hbsag" placeholder="STIs Hepatitis B (HBsAg)" value="<?= h($editing['stis_hbsag'] ?? '') ?>"></div>
          <div class="col-md-6"><input type="text" class="form-control" name="stool_exam" placeholder="Stool Examination" value="<?= h($editing['stool_exam'] ?? '') ?>"></div>

          <div class="col-md-6">
            <select class="form-select" name="acetic_acid_wash">
              <?php $aaw=$editing['acetic_acid_wash']??''; foreach([''=>'Acetic Acid Wash','positive'=>'Positive','negative'=>'Negative','not_done'=>'Not Done'] as $k=>$v){$sel=$aaw===$k?'selected':'';echo"<option value=\"$k\" $sel>$v</option>";} ?>
            </select>
          </div>
          <div class="col-md-6"><input type="text" class="form-control" name="birth_plan" placeholder="Birth Plan" value="<?= h($editing['birth_plan'] ?? '') ?>"></div>

          <div class="col-md-6"><input type="text" class="form-control" name="dental_checkup" placeholder="Dental Checkup" value="<?= h($editing['dental_checkup'] ?? '') ?>"></div>
          <div class="col-md-6"><input type="text" class="form-control" name="hemoglobin_count" placeholder="Hemoglobin Count" value="<?= h($editing['hemoglobin_count'] ?? '') ?>"></div>

          <div class="col-md-6"><input type="text" class="form-control" name="lab_tests_done" placeholder="Laboratory Tests Done" value="<?= h($editing['lab_tests_done'] ?? '') ?>"></div>
          <div class="col-md-6">
            <label class="form-label">Tetanus-containing Vaccine Date Given</label>
            <input type="date" class="form-control" name="tetanus_vax_date" value="<?= h($editing['tetanus_vaccine_date'] ?? '') ?>">
          </div>

          <div class="col-md-12">
            <label class="form-label">Treatments:</label><br>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="treat_syphilis" id="t1" <?= !empty($editing['treat_syphilis'])?'checked':''; ?>><label class="form-check-label" for="t1">Syphilis</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="treat_arv" id="t2" <?= !empty($editing['treat_arv'])?'checked':''; ?>><label class="form-check-label" for="t2">Antiretroviral (ARV)</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="treat_bacteriuria" id="t3" <?= !empty($editing['treat_bacteriuria'])?'checked':''; ?>><label class="form-check-label" for="t3">Bacteriuria</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="treat_anemia" id="t4" <?= !empty($editing['treat_anemia'])?'checked':''; ?>><label class="form-check-label" for="t4">Anemia</label></div>
          </div>
        </div>

        <button class="submit-btn" type="submit">Submit</button>
      </form>
    </div>
  </main>

  <aside class="right-rail">
    <div class="stat">
      <div class="text-muted">Patient</div>
      <div class="big"><?= h($patient_name) ?></div>
      <div class="text-muted small"><?= h($patient['barangay'] ?? '—') ?></div>
    </div>
  </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
