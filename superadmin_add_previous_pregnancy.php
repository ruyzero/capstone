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
$region_id      = (int)($_GET['region_id'] ?? 0);
$province_id    = (int)($_GET['province_id'] ?? 0);
$municipality_id= (int)($_GET['municipality_id'] ?? 0);
$barangay_id    = (int)($_GET['barangay_id'] ?? 0);

$regions = $conn->query("SELECT id, name FROM regions ORDER BY name");

$provinces = null;
if ($region_id > 0) {
    $sp = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name");
    $sp->bind_param("i", $region_id); $sp->execute(); $provinces = $sp->get_result(); $sp->close();
}
$municipalities = null;
if ($province_id > 0) {
    $sm = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
    $sm->bind_param("i", $province_id); $sm->execute(); $municipalities = $sm->get_result(); $sm->close();
}
$barangays_opts = null;
if ($municipality_id > 0) {
    $sb = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
    $sb->bind_param("i", $municipality_id); $sb->execute(); $barangays_opts = $sb->get_result(); $sb->close();
}

/* ---------- Right Rail Stats ---------- */
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
<title>Add Previous Pregnancy (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
    :root{ --ring:#e5e7eb; --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6f;}
    body{ font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#fff; margin:0; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ padding:24px 16px; border-right:1px solid #eef0f3; background:#fff; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-weight:700; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); color:#fff; display:grid; place-items:center; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .main{ padding:24px; }
    .filters{ display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
    .form-card{ max-width:760px; margin:0 auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
    .form-card h4{ font-weight:700; text-align:center; margin-bottom:16px; }
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:48px; font-weight:800; }
</style>
</head>
<body>
<div class="layout">
    <!-- Left -->
    <aside class="leftbar">
        <div class="brand"><div class="mark">R</div><div><div>RHU-MIS</div><small class="text-muted">SUPER ADMIN</small></div></div>
        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="super_admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="superadmin_prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Monitoring</a>
            <a class="nav-link active" href="#"><i class="bi bi-journal-plus"></i> Add Previous Pregnancy</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <!-- Filters -->
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
                    <?php if ($barangays_opts && $barangays_opts->num_rows): while($b=$barangays_opts->fetch_assoc()): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $barangay_id==(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="align-self-end">
                <a class="btn btn-outline-dark" href="superadmin_add_previous_pregnancy.php">Reset</a>
            </div>
        </form>

        <div class="form-card">
            <h4>Add Previous Pregnancy Record</h4>
            <form method="POST" action="superadmin_save_previous_pregnancy.php" id="prevForm" novalidate>
                <div class="mb-3">
                    <select class="form-select" id="barangay_id" name="barangay_id" required <?= $municipality_id? '' : 'disabled' ?>>
                        <option value=""><?= $municipality_id ? 'Barangay' : '— Select Municipality first —' ?></option>
                        <?php
                        // re-query barangays for select (if not already looped above)
                        if ($municipality_id > 0) {
                            $sb2 = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
                            $sb2->bind_param("i", $municipality_id); $sb2->execute();
                            $resB = $sb2->get_result();
                            while($bb = $resB->fetch_assoc()):
                        ?>
                            <option value="<?= (int)$bb['id'] ?>" <?= $barangay_id==(int)$bb['id']?'selected':'' ?>><?= h($bb['name']) ?></option>
                        <?php endwhile; $sb2->close(); } ?>
                    </select>
                </div>

                <div class="mb-3">
                    <select class="form-select" id="patient_id" name="patient_id" required <?= $barangay_id? '' : 'disabled' ?>>
                        <option value=""><?= $barangay_id ? 'Loading patients…' : '— Select Barangay —' ?></option>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Pregnancy Year</label>
                        <input type="number" class="form-control" name="pregnancy_year" min="1950" max="<?= date('Y') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Outcome</label>
                        <select class="form-select" name="outcome" required>
                            <option value="">—</option>
                            <option value="Live Birth">Live Birth</option>
                            <option value="Stillbirth">Stillbirth</option>
                            <option value="Miscarriage">Miscarriage</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Delivery Place</label>
                        <input type="text" class="form-control" name="delivery_place" maxlength="100" placeholder="e.g., RHU / Hospital / Home">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Birth Weight (kg)</label>
                        <input type="number" class="form-control" step="0.01" min="0" name="birth_weight_kg" placeholder="e.g., 3.2">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Complications</label>
                        <input type="text" class="form-control" name="complications" maxlength="150" placeholder="Optional">
                    </div>
                </div>

                <div class="mb-3">
                    <textarea class="form-control" name="notes" rows="2" placeholder="Notes / Remarks"></textarea>
                </div>

                <div class="text-end">
                    <button class="btn btn-success">Save Record</button>
                </div>
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
        <div class="stat"><div class="label">Total Pregnant Patient</div><div class="big"><?= $tot_pregnant ?></div></div>
    </aside>
</div>

<script>
// Load patients for selected barangay (you can reuse get_unenrolled_patients.php *or* create a dedicated endpoint to list all patients by barangay)
const brgySel = document.getElementById('barangay_id');
const patSel  = document.getElementById('patient_id');

function loadPatients() {
  const id = brgySel.value;
  patSel.innerHTML = '<option value="">Loading...</option>';
  patSel.disabled = true;
  if (!id) { patSel.innerHTML = '<option value="">— Select Barangay —</option>'; return; }

  // If you want ALL patients (not just unenrolled), create a get_patients_by_barangay.php
  fetch('get_unenrolled_patients.php?barangay_id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(rows => {
        patSel.innerHTML = '<option value="">Select Patient</option>';
        rows.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.full_name;
            patSel.appendChild(opt);
        });
        patSel.disabled = false;
    })
    .catch(() => { patSel.innerHTML = '<option value="">Failed to load</option>'; });
}

brgySel?.addEventListener('change', loadPatients);
if (brgySel && brgySel.value) loadPatients();
</script>
</body>
</html>
