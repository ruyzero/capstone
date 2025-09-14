<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- Identity / Session ---------- */
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

/* ---------- Right rail stats ---------- */
$tot_patients = 0; $tot_brgy = 0; $tot_pregnant = 0;

$s = $conn->prepare("SELECT COUNT(*) c FROM pregnant_women WHERE municipality_id = ?");
$s->bind_param("i", $municipality_id); $s->execute();
$tot_patients = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);

$s = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id = ?");
$s->bind_param("i", $municipality_id); $s->execute();
$tot_brgy = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);

$tot_pregnant = $tot_patients;

/* ---------- Barangays for this municipality ---------- */
$barangays = [];
$bs = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
$bs->bind_param("i", $municipality_id);
$bs->execute();
$resB = $bs->get_result();
while ($row = $resB->fetch_assoc()) { $barangays[] = $row; }
$bs->close();
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
    :root{
        --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb;
        --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6a;
    }
    *{ box-sizing:border-box }
    body{
        margin:0; background:#fff;
        font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans";
    }

    /* ===== 3-col grid ===== */
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

    /* Left bar (same style as other pages) */
    .leftbar{
        width: var(--sidebar-w);
        background:#ffffff;
        border-right: 1px solid #eef0f3;
        padding: 24px 16px;
        color:#111827;
    }
    .brand{
        display:flex; gap:10px; align-items:center; margin-bottom:24px;
        font-family: 'Merriweather', serif; font-weight:700; color:#111;
    }
    .brand .mark{
        width:36px; height:36px; border-radius:50%;
        background: linear-gradient(135deg, #25d3c7, #0fb5aa);
        display:grid; place-items:center; color:#fff; font-weight:800;
    }
    .nav-link{
        color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600;
    }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background: linear-gradient(135deg, #2fd4c8, #0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }

    /* Main */
    .main{ padding:24px; background:#ffffff; }
    .topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; }
    .searchbar{ flex:1; max-width:640px; position:relative; }
    .searchbar input{
        padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0;
    }
    .searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }

    .form-card{
        max-width:520px; margin:0 auto; background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:22px;
    }
    .form-card h4{ text-align:center; font-family:'Merriweather',serif; font-weight:700; margin-bottom:18px; }
    .form-control, .form-select{ height:44px; }
    .submit-btn{
        display:block; margin:12px auto 0; padding:10px 28px; border-radius:999px; border:none;
        background:linear-gradient(135deg, var(--teal-1), var(--teal-2)); color:#fff; font-weight:800;
    }
    .submit-btn:hover{ opacity:.95; }

    /* Right rail (same as dashboard) */
    .rail{ padding:24px 18px; display:flex; flex-direction:column; gap:18px; background:transparent; }
    .profile{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:14px 16px; display:flex; align-items:center; gap:12px;}
    .avatar{ width:44px; height:44px; border-radius:50%; background:#e6fffb; display:grid; place-items:center; color:#0f766e; font-weight:800; }
    .stat{ background:#fff; border:1px solid var(--ring); border-radius:16px; padding:16px; text-align:center; }
    .stat .label{ color:#6b7280; font-weight:600; }
    .stat .big{ font-size:64px; font-weight:800; line-height:1; color:#111827; }
    .stat.gradient{
        color:#fff; border:0; background:linear-gradient(160deg, var(--teal-1), var(--teal-2));
        box-shadow: 0 10px 28px rgba(16,185,129,.2);
    }
    .stat.gradient .label{ color:#e7fffb; }

    /* Responsive */
    @media (max-width: 1100px){
        .layout{ grid-template-columns: var(--sidebar-w) 1fr; }
        .rail{ grid-column: 1 / -1; }
    }
    @media (max-width: 992px){
        .leftbar{ width:100%; border-right:none; border-bottom:1px solid #eef0f3; }
    }
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
                <small class="text-muted"><?= h(strtoupper($municipality_name)) ?></small>
            </div>
        </div>

        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="view_pregnancies.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-building"></i> Brgy. Health Centers</a>
            <a class="nav-link active" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts.php"><i class="bi bi-people-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="topbar">
            <div class="searchbar w-100" style="max-width:720px;">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" placeholder="Search Here" disabled>
            </div>
        </div>

        <div class="form-card">
            <h4>Add Patient for Monitoring</h4>
            <form method="POST" action="save_monitoring.php" id="monitoringForm" novalidate>
                <!-- Barangay -->
                <div class="mb-3">
                    <select class="form-select" id="barangay_id" name="barangay_id" required>
                        <option value="">Barangay</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Patient (ajax) -->
                <div class="mb-3">
                    <select class="form-select" id="patient_id" name="patient_id" required disabled>
                        <option value="">Select Patient</option>
                    </select>
                </div>

                <!-- First Checkup Date -->
                <div class="mb-3">
                    <div>First Check-Up Date</div>
                    <input type="date" class="form-control" name="first_checkup_date" id="first_checkup_date" placeholder="First Checkup Date" required>
                </div>

                <!-- Age (auto from selected patient DOB) -->
                <div class="mb-3">
                    <input type="text" class="form-control" id="age_display" placeholder="Age" readonly>
                </div>

                <!-- Weight (kg) -->
                <div class="mb-3">
                    <input type="number" step="0.1" min="0" class="form-control" name="weight_kg" placeholder="Weight (kg)">
                </div>

                <!-- Height (ft) -->
                <div class="mb-3">
                    <input type="number" step="0.01" min="0" class="form-control" name="height_ft" placeholder="Height (Ft)">
                </div>

                <!-- Nutritional status -->
                <div class="mb-3">
                    <select class="form-select" name="nutritional_status">
                        <option value="">Nutritional Status</option>
                        <option>Normal</option>
                        <option>Underweight</option>
                        <option>Overweight</option>
                        <option>Obese</option>
                    </select>
                </div>

                <!-- LMP -->
                <div class="mb-3">
                    <div>Last Menstruation Date</div>
                    <input type="date" class="form-control" name="lmp" id="lmp" placeholder="Last Menstruation Date">
                </div>

                <!-- EDD (auto from LMP if provided) -->
                <div class="mb-3">
                    <div>Expected Delivery Date</div>
                    <input type="date" class="form-control" name="edd" id="edd" placeholder="Expected Delivery Date">
                </div>

                <!-- Pregnancy number -->
                <div class="mb-3">
                    <input type="number" min="1" class="form-control" name="pregnancy_number" placeholder="Pregnancy Number">
                </div>

                <!-- Remarks -->
                <div class="mb-3">
                    <input type="text" class="form-control" name="remarks" placeholder="Remarks">
                </div>

                <button class="submit-btn" type="submit">Submit</button>
            </form>
        </div>
    </main>

    <!-- Right rail -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?= h($username) ?></div>
                <div class="text-muted small"><?= h($handle) ?></div>
            </div>
        </div>

        <div class="stat">
            <div class="label">Total Patient Record</div>
            <div class="big"><?= $tot_patients ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?= $tot_brgy ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?= $tot_pregnant ?></div>
        </div>
    </aside>
</div>

<script>
/* Load patients (NOT yet enrolled) when barangay changes */
const brgySel = document.getElementById('barangay_id');
const patSel  = document.getElementById('patient_id');
const ageOut  = document.getElementById('age_display');

brgySel.addEventListener('change', () => {
    const id = brgySel.value;
    patSel.innerHTML = '<option value="">Loading...</option>';
    patSel.disabled = true;
    ageOut.value = '';

    if (!id) { patSel.innerHTML = '<option value="">Select Patient</option>'; return; }

    fetch('get_unenrolled_patients.php?barangay_id=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(rows => {
          patSel.innerHTML = '<option value="">Select Patient</option>';
          rows.forEach(p => {
              const opt = document.createElement('option');
              opt.value = p.id;
              opt.textContent = p.full_name;
              if (p.dob) opt.dataset.dob = p.dob; // for age compute
              patSel.appendChild(opt);
          });
          patSel.disabled = false;
      })
      .catch(() => {
          patSel.innerHTML = '<option value="">Failed to load</option>';
      });
});

/* Age auto-fill from selected patient */
patSel.addEventListener('change', () => {
    const opt = patSel.options[patSel.selectedIndex];
    const dob = opt ? opt.dataset.dob : null;
    if (!dob) { ageOut.value = ''; return; }
    try {
        const d = new Date(dob);
        const diff = new Date(Date.now() - d.getTime());
        const age = Math.abs(diff.getUTCFullYear() - 1970);
        ageOut.value = isFinite(age) ? age : '';
    } catch(e){ ageOut.value = ''; }
});

/* Auto-calc EDD from LMP (+280 days) */
const lmp = document.getElementById('lmp');
const edd = document.getElementById('edd');
lmp.addEventListener('change', () => {
    if (!lmp.value) return;
    const d = new Date(lmp.value);
    d.setDate(d.getDate() + 280);
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    edd.value = `${y}-${m}-${day}`;
});
</script>
</body>
</html>
