<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* --- Auth --- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

/* --- Helpers --- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function full_name($r) {
  $parts = array_filter([$r['first_name']??'', $r['middle_name']??'', $r['last_name']??'', $r['suffix']??''], fn($x)=>$x!==null && trim($x)!=='');
  return trim(implode(' ', $parts));
}
function table_exists(mysqli $conn, string $name): bool {
  $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
  $q->bind_param("s", $name); $q->execute();
  $ok = (bool)$q->get_result()->fetch_row(); $q->close(); return $ok;
}

/* --- Identity --- */
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

/* --- Input --- */
$patient_id = (int)($_GET['patient_id'] ?? 0);
if (!$patient_id) die("Missing patient_id.");

/* --- Validate patient belongs to this municipality --- */
$ps = $conn->prepare("SELECT p.*, b.name AS barangay FROM pregnant_women p LEFT JOIN barangays b ON b.id=p.barangay_id WHERE p.id=? AND p.municipality_id=? LIMIT 1");
$ps->bind_param("ii", $patient_id, $municipality_id); $ps->execute();
$patient = $ps->get_result()->fetch_assoc(); $ps->close();
if (!$patient) die("Patient not found or not in your municipality.");
$patient_name = full_name($patient);

/* --- Pull existing checkups (1..3) --- */
$existing = [1=>null,2=>null,3=>null];
$q = $conn->prepare("SELECT checkup_no, id FROM prenatal_checkups WHERE patient_id=?");
$q->bind_param("i", $patient_id); $q->execute();
$res = $q->get_result(); while($row=$res->fetch_assoc()){ $n=(int)$row['checkup_no']; if($n>=1 && $n<=3) $existing[$n]=$row['id']; }
$q->close();

/* --- Optional: per-slot schedules to mark 'missed' --- */
$HAS_PLANS = table_exists($conn, 'prenatal_checkup_plans'); // patient_id, checkup_no, scheduled_date
$plan = [1=>null,2=>null,3=>null];
if ($HAS_PLANS) {
  $qq = $conn->prepare("SELECT checkup_no, scheduled_date FROM prenatal_checkup_plans WHERE patient_id=?");
  $qq->bind_param("i", $patient_id); $qq->execute();
  $rs = $qq->get_result(); while($r=$rs->fetch_assoc()){ $n=(int)$r['checkup_no']; if($n>=1&&$n<=3) $plan[$n]=$r['scheduled_date']; }
  $qq->close();
}
$today = date('Y-m-d');
$tiles = [];
for ($n=1;$n<=3;$n++){
  if ($existing[$n]) $tiles[$n]='done';
  else $tiles[$n] = ($plan[$n] && $plan[$n] < $today) ? 'missed' : 'upcoming';
}

/* --- Flash --- */
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
.tile-grid{ display:flex; flex-direction:column; align-items:center; gap:18px; margin:24px 0; }
.tile{ width:140px; height:64px; border-radius:10px; display:grid; place-items:center; font-weight:800; font-size:22px; text-decoration:none; }
.tile.done{ background:#16a34a; color:#fff; }     /* green */
.tile.upcoming{ background:#fff; color:#111; border:1px solid #e5e7eb; }
.tile.missed{ background:#ef4444; color:#fff; }   /* red */
.legend{ position:fixed; left:20px; bottom:18px; font-size:.9rem; }
.legend .dot{ width:10px; height:10px; border-radius:50%; display:inline-block; margin:0 6px 0 12px; }
.dot.done{ background:#16a34a; } .dot.upcoming{ background:#fff; border:1px solid #999; } .dot.missed{ background:#ef4444; }
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
      <a class="nav-link" href="view_pregnancies.php"><i class="bi bi-people"></i> Patients</a>
      <a class="nav-link" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
      <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
      <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-building"></i> Brgy. Health Centers</a>
      <a class="nav-link active" href="#"><i class="bi bi-activity"></i> Prenatal Checkup</a>
      <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
      <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
      <a class="nav-link" href="manage_accounts.php"><i class="bi bi-people-gear"></i> Manage Accounts</a>
      <hr>
      <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
      <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
    </nav>
  </aside>

  <main class="main">
    <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <h4 class="text-center mb-2">Prenatal Checkup for <?= h($patient_name) ?></h4>

    <div class="tile-grid">
      <?php for ($n=1;$n<=3;$n++): ?>
        <a class="tile <?= h($tiles[$n]) ?>" href="prenatal_checkup_form.php?patient_id=<?= (int)$patient_id ?>&checkup_no=<?= $n ?>"><?= $n ?></a>
      <?php endfor; ?>
    </div>

    <div class="legend">
      <span class="dot done"></span>Done
      <span class="dot upcoming"></span>Upcoming
      <span class="dot missed"></span>Missed
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
