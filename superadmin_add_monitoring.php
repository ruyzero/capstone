<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth: SUPER ADMIN ONLY ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php"); exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- Filters & select options ---------- */
$region_id       = (int)($_GET['region_id'] ?? 0);
$province_id     = (int)($_GET['province_id'] ?? 0);
$municipality_id = (int)($_GET['municipality_id'] ?? 0);
$barangay_id     = (int)($_GET['barangay_id'] ?? 0);

$regions = $conn->query("SELECT id, name FROM regions ORDER BY name");

$provinces = null;
if ($region_id > 0) {
    $sp = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name");
    $sp->bind_param("i", $region_id); $sp->execute();
    $provinces = $sp->get_result(); $sp->close();
}

$municipalities = null;
if ($province_id > 0) {
    $sm = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
    $sm->bind_param("i", $province_id); $sm->execute();
    $municipalities = $sm->get_result(); $sm->close();
}

/* Barangays for the FILTER (top bar) */
$barangays_filter = null;
if ($municipality_id > 0) {
    $sb = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
    $sb->bind_param("i", $municipality_id); $sb->execute();
    $barangays_filter = $sb->get_result(); $sb->close();
}

/* ---------- Right-rail stats (respect filters) ---------- */
$types=''; $params=[]; $where=[];
$join_pw = " LEFT JOIN municipalities m ON m.id = pw.municipality_id
             LEFT JOIN provinces pr ON pr.id = m.province_id";
if ($barangay_id>0){ $where[]="pw.barangay_id=?"; $types.='i'; $params[]=$barangay_id; }
elseif ($municipality_id>0){ $where[]="pw.municipality_id=?"; $types.='i'; $params[]=$municipality_id; }
elseif ($province_id>0){ $where[]="pr.id=?"; $types.='i'; $params[]=$province_id; }
elseif ($region_id>0){ $where[]="pr.region_id=?"; $types.='i'; $params[]=$region_id; }

$sql_tot = "SELECT COUNT(*) c FROM pregnant_women pw $join_pw";
if ($where) $sql_tot .= " WHERE ".implode(" AND ", $where);
$st = $conn->prepare($sql_tot);
if ($types) $st->bind_param($types, ...$params);
$st->execute(); $tot_patients = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();

$tot_brgy = 0;
if ($municipality_id>0 || $province_id>0 || $region_id>0 || $barangay_id>0) {
    $types2=''; $params2=[]; $where2=[]; 
    $joinB = " JOIN municipalities m ON m.id = b.municipality_id
               JOIN provinces pr ON pr.id = m.province_id";
    if ($municipality_id>0){ $where2[]="b.municipality_id=?"; $types2.='i'; $params2[]=$municipality_id; }
    elseif ($province_id>0){ $where2[]="m.province_id=?"; $types2.='i'; $params2[]=$province_id; }
    elseif ($region_id>0){ $where2[]="pr.region_id=?"; $types2.='i'; $params2[]=$region_id; }
    elseif ($barangay_id>0){ $where2[]="b.id=?"; $types2.='i'; $params2[]=$barangay_id; }
    $qB = "SELECT COUNT(*) c FROM barangays b $joinB".($where2?" WHERE ".implode(" AND ",$where2):"");
    $sb2 = $conn->prepare($qB); if ($types2) $sb2->bind_param($types2, ...$params2);
    $sb2->execute(); $tot_brgy = (int)($sb2->get_result()->fetch_assoc()['c'] ?? 0); $sb2->close();
}
$tot_pregnant = $tot_patients;

/* ---------- Flash ---------- */
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Add Patient for Monitoring (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{ --ring:#e5e7eb; --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6a;}
    *{ box-sizing:border-box }
    body{ margin:0; background:#fff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); color:#fff; display:grid; place-items:center; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .main{ padding:24px; }
    .filters{ display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
    .form-card{ max-width:720px; margin:0 auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
    .form-card h4{ text-align:center; font-family:'Merriweather',serif; font-weight:700; margin-bottom:18px; }
    .form-control, .form-select{ height:44px; }
    .submit-btn{ display:block; margin:12px auto 0; padding:10px 28px; border-radius:999px; border:none; background:linear-gradient(135deg,#20C4B2,#1A9E9D); color:#fff; font-weight:800; }
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:48px; font-weight:800; line-height:1; color:#111827; }
    @media (max-width:1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column: 1 / -1; } }
</style>
</head>
<body>

<div class="layout">
    <!-- Left -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div>RHU-MIS</div>
                <small class="text-muted">SUPER ADMIN</small>
            </div>
        </div>
        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="super_admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="superadmin_patients.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="superadmin_manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="superadmin_manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="superadmin_barangay_health_centers.php"><i class="bi bi-building"></i> Brgy. Health Centers</a>
            <a class="nav-link active" href="superadmin_prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <!-- Global Filters (GET) -->
        <form class="filters" method="get" action="">
            <div>
                <label class="form-label">Region</label>
                <select name="region_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">All Regions</option>
                    <?php if ($regions && $regions->num_rows): while($r=$regions->fetch_assoc()): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $region_id==(int)$r['id']?'selected':'' ?>><?= h($r['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Province</label>
                <select name="province_id" class="form-select" <?= $region_id? '' : 'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $region_id ? 'All Provinces' : '— Select Region —' ?></option>
                    <?php if ($provinces && $provinces->num_rows): while($p=$provinces->fetch_assoc()): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $province_id==(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Municipality</label>
                <select name="municipality_id" class="form-select" <?= $province_id? '' : 'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $province_id ? 'All Municipalities' : '— Select Province —' ?></option>
                    <?php if ($municipalities && $municipalities->num_rows): while($m=$municipalities->fetch_assoc()): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= $municipality_id==(int)$m['id']?'selected':'' ?>><?= h($m['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Barangay</label>
                <select name="barangay_id" class="form-select" <?= $municipality_id? '' : 'disabled' ?> onchange="this.form.submit()">
                    <option value="0"><?= $municipality_id ? 'All Barangays' : '— Select Municipality —' ?></option>
                    <?php if ($barangays_filter && $barangays_filter->num_rows): while($b=$barangays_filter->fetch_assoc()): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $barangay_id==(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="align-self-end">
                <a class="btn btn-outline-dark" href="superadmin_add_monitoring.php">Reset</a>
            </div>
        </form>

        <div class="form-card">
            <h4>Add Patient for Monitoring</h4>

            <?php
            /* Re-query barangays for the FORM (fresh result set so it isn't empty) */
            $barangays_form = null;
            if ($municipality_id > 0) {
                $sbForm = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
                $sbForm->bind_param("i", $municipality_id); $sbForm->execute();
                $barangays_form = $sbForm->get_result(); $sbForm->close();
            }
            ?>

            <form method="POST" action="superadmin_save_monitoring.php" id="monitoringForm" novalidate>
                <div class="mb-3">
                    <select class="form-select" id="barangay_id" name="barangay_id" required <?= $municipality_id? '' : 'disabled' ?>>
                        <option value=""><?= $municipality_id ? 'Barangay' : '— Select Municipality first —' ?></option>
                        <?php if ($barangays_form && $barangays_form->num_rows): while($b=$barangays_form->fetch_assoc()): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $barangay_id==(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <select class="form-select" id="patient_id" name="patient_id" required <?= $barangay_id? '' : 'disabled' ?>>
                        <option value=""><?= $barangay_id ? 'Loading patients…' : '— Select Barangay —' ?></option>
                    </select>
                </div>

                <div class="mb-3">
                    <input type="text" class="form-control" id="age_display" placeholder="Age" readonly>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">LMP</label>
                        <input type="date" class="form-control" name="lmp" id="lmp">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">EDD</label>
                        <input type="date" class="form-control" name="edd" id="edd">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4"><input type="number" min="0" class="form-control" name="gravida" placeholder="Gravida (G)"></div>
                    <div class="col-md-4"><input type="number" min="0" class="form-control" name="para" placeholder="Para (P)"></div>
                    <div class="col-md-4"><input type="number" min="0" class="form-control" name="abortions" placeholder="Abortions (A)"></div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6"><input type="number" step="0.01" min="0" class="form-control" name="height_cm" placeholder="Height (cm)"></div>
                    <div class="col-md-6"><input type="number" step="0.1"  min="0" class="form-control" name="weight_kg" placeholder="Weight (kg)"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Risk Level</label>
                    <select class="form-select" name="risk_level">
                        <option value="">—</option>
                        <option value="normal">Normal</option>
                        <option value="caution">Caution</option>
                        <option value="high">High</option>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">No. of Prenatal Checkups (0–3 only)</label>
                        <select class="form-select" name="checkups_done" id="checkups_done" required>
                            <option value="" selected>— Select —</option>
                            <option value="0">0</option><option value="1">1</option>
                            <option value="2">2</option><option value="3">3</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Upcoming Prenatal Schedule</label>
                        <input type="date" class="form-control" name="next_schedule" id="next_schedule">
                    </div>
                </div>

                <div class="mb-3">
                    <textarea class="form-control" name="notes" rows="2" placeholder="Notes / Remarks"></textarea>
                </div>

                <button class="submit-btn" type="submit">Submit</button>
            </form>
        </div>
    </main>

    <!-- Right -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?= h($_SESSION['username'] ?? 'superadmin') ?></div>
                <div class="text-muted small">@superadmin</div>
            </div>
        </div>
        <div class="stat"><div class="label">Total Patient Record</div><div class="big"><?= $tot_patients ?></div></div>
        <div class="stat"><div class="label">Total Brgy. Health Center</div><div class="big"><?= $tot_brgy ?></div></div>
        <div class="stat"><div class="label">Total Pregnant Patient</div><div class="big"><?= $tot_pregnant ?></div></div>
    </aside>
</div>

<script>
// Populate patients for selected barangay (uses existing endpoint that returns JSON)
const brgySel = document.getElementById('barangay_id');
const patSel  = document.getElementById('patient_id');
const ageOut  = document.getElementById('age_display');

function loadPatients() {
  const id = brgySel.value;
  patSel.innerHTML = '<option value="">Loading...</option>';
  patSel.disabled = true; ageOut.value = '';
  if (!id) { patSel.innerHTML = '<option value="">— Select Barangay —</option>'; return; }

  fetch('get_unenrolled_patients_superadmin.php?barangay_id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(rows => {
        patSel.innerHTML = '<option value="">Select Patient</option>';
        rows.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.full_name;
            if (p.dob) opt.dataset.dob = p.dob;
            patSel.appendChild(opt);
        });
        patSel.disabled = false;
    })
    .catch(() => { patSel.innerHTML = '<option value="">Failed to load</option>'; });
}

brgySel?.addEventListener('change', loadPatients);

// If a barangay is already selected via GET, load patients on page load.
<?php if ($barangay_id > 0): ?>
document.addEventListener('DOMContentLoaded', loadPatients);
<?php endif; ?>

// Show age when patient changes
patSel.addEventListener('change', () => {
    const opt = patSel.options[patSel.selectedIndex];
    const dob = opt ? opt.dataset.dob : null;
    if (!dob) { ageOut.value = ''; return; }
    try {
        const d = new Date(dob);
        const diff = new Date(Date.now() - d.getTime());
        const age = Math.abs(diff.getUTCFullYear() - 1970);
        ageOut.value = isFinite(age) ? age : '';
    } catch(e){ ageOut.value=''; }
});

// Auto-calc EDD from LMP (+280 days)
const lmp = document.getElementById('lmp');
const edd = document.getElementById('edd');
lmp.addEventListener('change', () => {
    if (!lmp.value) return;
    const d = new Date(lmp.value);
    d.setDate(d.getDate() + 280);
    const y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), day = String(d.getDate()).padStart(2,'0');
    edd.value = `${y}-${m}-${day}`;
});

// Guard 0..3
document.getElementById('monitoringForm').addEventListener('submit', (e) => {
    const cd = document.getElementById('checkups_done').value;
    if (cd === '' || Number(cd) < 0 || Number(cd) > 3) {
        e.preventDefault(); alert('No. of Prenatal Checkups must be 0 to 3 only.');
    }
});
</script>
</body>
</html>
