<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* Auth */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

/* Helpers */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Identity */
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';
$handle            = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if ($r) $municipality_name = $r['name'];
    $stmt->close();
}
if (!$municipality_id) { die("No municipality set for this admin."); }

/* Stats */
$tot_patients = 0; $tot_brgy = 0;
$s = $conn->prepare("SELECT COUNT(*) c FROM pregnant_women WHERE municipality_id = ?");
$s->bind_param("i", $municipality_id); $s->execute();
$tot_patients = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);

$s = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id = ?");
$s->bind_param("i", $municipality_id); $s->execute();
$tot_brgy = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);

/* Barangays */
$barangays = [];
$bs = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
$bs->bind_param("i", $municipality_id); $bs->execute();
$resB = $bs->get_result();
while ($row = $resB->fetch_assoc()) { $barangays[] = $row; }
$bs->close();

/* Flash */
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Add Patient for Monitoring • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root{ --teal-1:#20C4B2; --teal-2:#1A9E9D; --ring:#e5e7eb; --sidebar-w:260px; }
    *{ box-sizing:border-box }
    body{ margin:0; background:#fff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
    .leftbar{ width: var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; color:#111827; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; color:#111; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }
    .main{ padding:24px; background:#fff; }
    .form-card{ max-width:620px; margin:0 auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px; }
    .form-card h4{ text-align:center; font-family:'Merriweather',serif; font-weight:700; margin-bottom:18px; }
    .form-control, .form-select{ height:44px; }
    .submit-btn{ display:block; margin:12px auto 0; padding:10px 28px; border-radius:999px; border:none; background:linear-gradient(135deg,#20C4B2,#1A9E9D); color:#fff; font-weight:800; }
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; background:transparent; }
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
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link active" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <main class="main">
        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <div class="form-card">
            <h4>Add Patient for Monitoring</h4>
            <form method="POST" action="save_monitoring.php" id="monitoringForm" novalidate>
                <div class="mb-3">
                    <select class="form-select" id="barangay_id" name="barangay_id" required>
                        <option value="">Barangay</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <select class="form-select" id="patient_id" name="patient_id" required disabled>
                        <option value="">Select Patient</option>
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
                            <option value="0">0</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
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

    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?= h($username) ?></div>
                <div class="text-muted small"><?= h($handle) ?></div>
            </div>
        </div>

        <div class="stat"><div class="label">Total Patient Record</div><div class="big"><?= $tot_patients ?></div></div>
        <div class="stat"><div class="label">Total Brgy. Health Center</div><div class="big"><?= $tot_brgy ?></div></div>
    </aside>
</div>

<script>
const brgySel = document.getElementById('barangay_id');
const patSel  = document.getElementById('patient_id');
const ageOut  = document.getElementById('age_display');

brgySel.addEventListener('change', () => {
    const id = brgySel.value;
    patSel.innerHTML = '<option value="">Loading...</option>';
    patSel.disabled = true; ageOut.value = '';
    if (!id) { patSel.innerHTML = '<option value="">Select Patient</option>'; return; }

    fetch('get_unenrolled_patients.php?barangay_id=' + encodeURIComponent(id))
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
});

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

const lmp = document.getElementById('lmp');
const edd = document.getElementById('edd');
lmp.addEventListener('change', () => {
    if (!lmp.value) return;
    const d = new Date(lmp.value);
    d.setDate(d.getDate() + 280);
    const y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), day = String(d.getDate()).padStart(2,'0');
    edd.value = `${y}-${m}-${day}`;
});

/* Guard 0..3 */
document.getElementById('monitoringForm').addEventListener('submit', (e) => {
    const cd = document.getElementById('checkups_done').value;
    if (cd === '' || Number(cd) < 0 || Number(cd) > 3) {
        e.preventDefault(); alert('No. of Prenatal Checkups must be 0 to 3 only.');
    }
});
</script>
</body>
</html>
