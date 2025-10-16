<?php
session_start();
require 'db.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
if ($municipality_id <= 0) { die("No municipality is set for this admin account."); }

$municipality_name = $_SESSION['municipality_name'] ?? '';
if ($municipality_name === '') {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    $stmt->bind_result($muni_name);
    if ($stmt->fetch()) { $municipality_name = $muni_name; }
    $stmt->close();
}

$q = trim($_GET['q'] ?? '');

/* ---------- Stats ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) FROM pregnant_women WHERE municipality_id = ?");
$stmt->bind_param("i", $municipality_id);
$stmt->execute(); $stmt->bind_result($tot_patients); $stmt->fetch(); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM barangays WHERE municipality_id = ?");
$stmt->bind_param("i", $municipality_id);
$stmt->execute(); $stmt->bind_result($tot_brgy); $stmt->fetch(); $stmt->close();

$tot_pregnant = (int)$tot_patients;

/* ---------- Barangays ---------- */
if ($q === '') {
    $sql = "
        SELECT b.id, b.name,
               (SELECT COUNT(*) FROM pregnant_women p WHERE p.barangay_id = b.id) AS rec_count
        FROM barangays b
        WHERE b.municipality_id = ?
        ORDER BY b.name ASC
    ";
    $stmtB = $conn->prepare($sql);
    $stmtB->bind_param("i", $municipality_id);
} else {
    $sql = "
        SELECT b.id, b.name,
               (SELECT COUNT(*) FROM pregnant_women p WHERE p.barangay_id = b.id) AS rec_count
        FROM barangays b
        WHERE b.municipality_id = ? AND b.name LIKE ?
        ORDER BY b.name ASC
    ";
    $stmtB = $conn->prepare($sql);
    $like = "%{$q}%";
    $stmtB->bind_param("is", $municipality_id, $like);
}

$stmtB->execute();
$stmtB->store_result();
$stmtB->bind_result($bid, $bname, $bcount);

$barangays_list = [];
$barangay_ids   = [];
while ($stmtB->fetch()) {
    $row = ['id'=>$bid, 'name'=>$bname, 'rec_count'=>(int)$bcount];
    $barangays_list[] = $row;
    $barangay_ids[]   = (int)$bid;
}
$stmtB->free_result();
$stmtB->close();

/* ---------- Fetch ALL patients for ALL barangays ---------- */
$patients_by_brgy = [];
if (!empty($barangay_ids)) {
    $ids_str = implode(',', array_map('intval', $barangay_ids));
    $sqlP = "
        SELECT 
            p.id,
            p.barangay_id,
            p.first_name, p.middle_name, p.last_name, p.suffix,
            p.dob, p.status
        FROM pregnant_women p
        WHERE p.barangay_id IN ($ids_str)
        ORDER BY p.barangay_id, p.last_name, p.first_name
    ";
    $resP = $conn->query($sqlP);
    if ($resP) {
        while ($r = $resP->fetch_assoc()) {
            $brgy = (int)$r['barangay_id'];
            if (!isset($patients_by_brgy[$brgy])) $patients_by_brgy[$brgy] = [];
            $patients_by_brgy[$brgy][] = $r;
        }
        $resP->free();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Barangay Health Centers • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
:root { --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6f; }
body{ background:#fff; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial; }
.leftbar{ position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; overflow-y:auto; }
.brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; }
.brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
.nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
.nav-link:hover{ background:#f2f6f9; color:#0f172a; }
.nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
.nav-link i{ width:22px; text-align:center; margin-right:8px; }
.main-wrapper{ margin-left:var(--sidebar-w); padding:28px; display:flex; flex-wrap:wrap; gap:24px; }
.main-content{ flex:1 1 700px; min-width:0; }
.rightbar{ flex:0 0 300px; }
.topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.searchbar{ flex:1; max-width:640px; position:relative; }
.searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
.searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }
.add-link{ font-weight:700; color:var(--brand); text-decoration:none; }
.add-link:hover{ color:#0e6f6f; text-decoration:underline; }
.accordion-item{ border:1px solid #e6ebf0; border-radius:12px !important; overflow:hidden; margin-bottom:.5rem; }
.status-pill{ padding:.25rem .6rem; border-radius:999px; font-weight:700; font-size:.8rem; }
.status-under{ background:#fff7e6; color:#a16207; border:1px solid #fde68a; }
.status-complete{ background:#e8fff1; color:#0f766e; border:1px solid #99f6e4; }
.stat{ border-radius:18px; padding:20px; text-align:center; background:#f8fafc; border:1px solid #eef0f3; margin-bottom:16px; }
.stat h6{ color:#6b7280; font-weight:700; margin-bottom:8px; letter-spacing:.02em; }
.stat .num{ font-size:56px; font-weight:800; line-height:1; color:#0f172a; }
.stat.accent{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; border:none; }
.stat.accent h6,.stat.accent .num{ color:#fff; }
@media (max-width:992px){
  .main-wrapper{ margin-left:0; padding:16px; flex-direction:column; }
  .leftbar{ position:static; width:100%; border-right:none; border-bottom:1px solid #eef0f3; }
  .rightbar{ width:100%; order:-1; }
}
</style>
</head>
<body>

<!-- Sidebar -->
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
    <a class="nav-link active" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
    <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
    <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
    <a class="nav-link" href="manage_accounts.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
    <hr>
    <a class="nav-link" href="account.php"><i class="bi bi-person-circle"></i> My Account</a>
    <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
  </nav>
</aside>

<!-- Main Content Wrapper -->
<div class="main-wrapper">

  <!-- Main Left -->
  <div class="main-content">
    <div class="topbar">
      <form class="searchbar me-3" method="get" action="">
        <i class="bi bi-search"></i>
        <input type="text" name="q" class="form-control" placeholder="Search here" value="<?= h($q) ?>">
      </form>
      <a class="add-link" href="add_barangay.php">+ Add Barangay</a>
    </div>

    <h4 class="mb-3">Barangay Health Centers</h4>

    <?php if (empty($barangays_list)): ?>
      <div class="alert alert-warning">No barangays found for this municipality.</div>
    <?php endif; ?>

    <div class="accordion" id="brgyAccordion">
      <?php $idx=0; foreach ($barangays_list as $b): $idx++; ?>
      <div class="accordion-item">
        <h2 class="accordion-header" id="heading<?= $idx ?>">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#brgy<?= $idx ?>" aria-expanded="false" aria-controls="brgy<?= $idx ?>">
            <strong class="me-1"><?= h($b['name']) ?></strong>
            <span class="text-muted">— <?= (int)$b['rec_count'] ?> record<?= ((int)$b['rec_count']===1?'':'s'); ?></span>
          </button>
        </h2>
        <div id="brgy<?= $idx ?>" class="accordion-collapse collapse" aria-labelledby="heading<?= $idx ?>" data-bs-parent="#brgyAccordion">
          <div class="accordion-body">
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th class="text-start">Full Name</th>
                    <th class="text-center" style="width:180px;">Date of Birth</th>
                    <th class="text-center" style="width:160px;">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                    $patients = $patients_by_brgy[$b['id']] ?? [];
                    if (!empty($patients)):
                      foreach ($patients as $p):
                        $fullname = trim(implode(' ', array_filter([$p['first_name'],$p['middle_name'],$p['last_name'],$p['suffix']])));
                        $dob = !empty($p['dob']) ? date('Y-m-d', strtotime($p['dob'])) : '—';
                        $status = $p['status'] ? ucwords(str_replace('_',' ', $p['status'])) : '—';
                        $isUnder = (strtolower($p['status']) === 'under_monitoring');
                  ?>
                  <tr>
                    <td class="text-start"><?= h($fullname ?: '—') ?></td>
                    <td class="text-center"><?= h($dob) ?></td>
                    <td class="text-center">
                      <span class="status-pill <?= $isUnder ? 'status-under' : 'status-complete'; ?>">
                        <?= h($status) ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; else: ?>
                  <tr><td colspan="3" class="text-center text-muted py-3">No records found for this barangay.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Right Sidebar -->
  <div class="rightbar">
    <div class="stat">
      <h6>Total Patient Record</h6>
      <div class="num"><?= (int)$tot_patients ?></div>
    </div>
    <div class="stat accent">
      <h6>Total Brgy. Health Center</h6>
      <div class="num"><?= (int)$tot_brgy ?></div>
    </div>
    <div class="stat">
      <h6>Total Pregnant Patient</h6>
      <div class="num"><?= (int)$tot_pregnant ?></div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
