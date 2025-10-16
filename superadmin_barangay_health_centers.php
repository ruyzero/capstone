<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ============== Auth: SUPER ADMIN ONLY ============== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ============== Helpers ============== */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ============== Filters (GET) ============== */
$q            = trim($_GET['q'] ?? '');
$region_id    = (int)($_GET['region_id'] ?? 0);
$province_id  = (int)($_GET['province_id'] ?? 0);
$municipality_id = (int)($_GET['municipality_id'] ?? 0);

/* ============== Load select options ============== */
// Regions
$regions = $conn->query("SELECT id, name FROM regions ORDER BY name");
// Provinces (when region selected)
$provinces = null;
if ($region_id > 0) {
    $sp = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name");
    $sp->bind_param("i", $region_id);
    $sp->execute();
    $provinces = $sp->get_result();
    $sp->close();
}
// Municipalities (when province selected)
$municipalities = null;
if ($province_id > 0) {
    $sm = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
    $sm->bind_param("i", $province_id);
    $sm->execute();
    $municipalities = $sm->get_result();
    $sm->close();
}

/* ============== Stats (respect filters) ============== */
$wherePatients = [];
$typesP = ''; $paramsP = [];

if ($municipality_id > 0) {
    $wherePatients[] = "pw.municipality_id = ?";
    $typesP .= 'i'; $paramsP[] = $municipality_id;
} elseif ($province_id > 0) {
    $wherePatients[] = "m.province_id = ?";
    $typesP .= 'i'; $paramsP[] = $province_id;
} elseif ($region_id > 0) {
    $wherePatients[] = "p.region_id = ?";
    $typesP .= 'i'; $paramsP[] = $region_id;
}

$sqlPatients = "SELECT COUNT(*) c
                FROM pregnant_women pw
                LEFT JOIN municipalities m ON m.id = pw.municipality_id
                LEFT JOIN provinces p ON p.id = m.province_id";
if ($wherePatients) $sqlPatients .= " WHERE " . implode(" AND ", $wherePatients);

$stC = $conn->prepare($sqlPatients);
if ($typesP) $stC->bind_param($typesP, ...$paramsP);
$stC->execute();
$tot_patients = (int)($stC->get_result()->fetch_assoc()['c'] ?? 0);
$stC->close();

$tot_pregnant = $tot_patients; // same unless you track other statuses

// Barangay count (respect filters)
$whereB = [];
$typesB=''; $paramsB=[];
if ($municipality_id > 0) {
    $whereB[]="b.municipality_id = ?"; $typesB.='i'; $paramsB[]=$municipality_id;
} elseif ($province_id > 0) {
    $whereB[]="m.province_id = ?"; $typesB.='i'; $paramsB[]=$province_id;
} elseif ($region_id > 0) {
    $whereB[]="p.region_id = ?"; $typesB.='i'; $paramsB[]=$region_id;
}
$sqlBrgyCount = "SELECT COUNT(*) c
                 FROM barangays b
                 JOIN municipalities m ON m.id = b.municipality_id
                 JOIN provinces p ON p.id = m.province_id";
if ($whereB) $sqlBrgyCount .= " WHERE ".implode(" AND ", $whereB);
$stB = $conn->prepare($sqlBrgyCount);
if ($typesB) $stB->bind_param($typesB, ...$paramsB);
$stB->execute();
$tot_brgy = (int)($stB->get_result()->fetch_assoc()['c'] ?? 0);
$stB->close();

/* ============== Barangays list (with record count) ============== */
$sql = "
    SELECT 
        b.id,
        b.name,
        COUNT(pw.id) AS rec_count
    FROM barangays b
    JOIN municipalities m ON m.id = b.municipality_id
    JOIN provinces p ON p.id = m.province_id
    JOIN regions  r ON r.id = p.region_id
    LEFT JOIN pregnant_women pw ON pw.barangay_id = b.id
    WHERE 1=1
";
$types = ''; $params = [];

if ($municipality_id > 0) {
    $sql .= " AND b.municipality_id = ?";
    $types .= 'i'; $params[] = $municipality_id;
} elseif ($province_id > 0) {
    $sql .= " AND m.province_id = ?";
    $types .= 'i'; $params[] = $province_id;
} elseif ($region_id > 0) {
    $sql .= " AND p.region_id = ?";
    $types .= 'i'; $params[] = $region_id;
}
if ($q !== '') {
    $sql .= " AND b.name LIKE ?";
    $types .= 's'; $params[] = "%$q%";
}

$sql .= " GROUP BY b.id, b.name
          ORDER BY b.name ASC";

$stmtList = $conn->prepare($sql);
if ($types) $stmtList->bind_param($types, ...$params);
$stmtList->execute();
$barangays = $stmtList->get_result();
$stmtList->close();

/* ============== Helper: list patients by barangay ============== */
function getBarangayPatients(mysqli $conn, int $barangay_id) {
    $s = $conn->prepare("
        SELECT 
            pw.id,
            pw.first_name, pw.middle_name, pw.last_name, pw.suffix,
            pw.dob, pw.status,
            u.username AS midwife_username
        FROM pregnant_women pw
        LEFT JOIN users u ON u.id = pw.assigned_midwife_id
        WHERE pw.barangay_id = ?
        ORDER BY pw.last_name, pw.first_name
    ");
    $s->bind_param("i", $barangay_id);
    $s->execute();
    return $s->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Barangay Health Centers (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root { --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6a; }
    body{ background:#fff; font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial; }
    .leftbar{ position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w); background:#fff; border-right:1px solid #eef0f3; padding:24px 16px; }
    .brand{ display:flex; gap:10px; align-items:center; margin-bottom:24px; font-family:'Merriweather',serif; font-weight:700; }
    .brand .mark{ width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#25d3c7,#0fb5aa); display:grid; place-items:center; color:#fff; font-weight:800; }
    .nav-link{ color:#6b7280; border-radius:10px; padding:.6rem .8rem; font-weight:600; }
    .nav-link:hover{ background:#f2f6f9; color:#0f172a; }
    .nav-link.active{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; }
    .nav-link i{ width:22px; text-align:center; margin-right:8px; }
    .content{ margin-left:var(--sidebar-w); padding:28px; }
    .topbar{ display:flex; gap:10px; align-items:end; margin-bottom:18px; flex-wrap:wrap; }
    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:18px; }
    .searchbar{ position:relative; }
    .searchbar input{ padding-left:36px; height:42px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
    .searchbar i{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#64748b; }
    .accordion-button{ background:#fff; }
    .accordion-button:focus{ box-shadow:none; }
    .accordion-item{ border:1px solid #e6ebf0; border-radius:12px !important; overflow:hidden; }
    .pill{ display:inline-block; padding:.25rem .6rem; border-radius:999px; background:#eef0f3; color:#334155; font-weight:700; font-size:.85rem; }
    .status-pill{ padding:.25rem .6rem; border-radius:999px; font-weight:700; font-size:.8rem; }
    .status-under{ background:#fff7e6; color:#a16207; border:1px solid #fde68a; }
    .status-complete{ background:#e8fff1; color:#0f766e; border:1px solid #99f6e4; }
    .rightbar{ position:fixed; right:24px; top:24px; width:300px; }
    .stat{ border-radius:18px; padding:20px; text-align:center; background:#f8fafc; border:1px solid #eef0f3; margin-bottom:16px; }
    .stat h6{ color:#6b7280; font-weight:700; margin-bottom:8px; letter-spacing:.02em; }
    .stat .num{ font-size:56px; font-weight:800; line-height:1; color:#0f172a; }
    .stat.accent{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; border:none; }
    .stat.accent h6,.stat.accent .num{ color:#fff; }
    @media (max-width:1200px){ .rightbar{ position:static; width:auto; margin-top:16px; } }
    @media (max-width:992px){ .leftbar{ position:static; width:100%; border-right:none; border-bottom:1px solid #eef0f3; } .content{ margin:0; padding:16px; } }
</style>
</head>
<body>

<!-- ===== Left Sidebar (Super Admin) ===== -->
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
    <a class="nav-link active" href="superadmin_barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
    <hr>
    <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
    <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
  </nav>
</aside>

<!-- ===== Main Content ===== -->
<main class="content container-fluid">
  <div class="row">
    <div class="col-xl-8">

      <!-- Filters + Search -->
      <div class="panel mb-3">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label">Region</label>
            <select name="region_id" id="region_id" class="form-select" onchange="this.form.submit()">
              <option value="0">All Regions</option>
              <?php if ($regions && $regions->num_rows): while($r=$regions->fetch_assoc()): ?>
                <option value="<?= (int)$r['id'] ?>" <?= $region_id==(int)$r['id']?'selected':'' ?>><?= h($r['name']) ?></option>
              <?php endwhile; endif; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Province</label>
            <select name="province_id" id="province_id" class="form-select" onchange="this.form.submit()" <?= $region_id? '':'disabled' ?>>
              <option value="0"><?= $region_id? 'All Provinces' : '— Select Region first —' ?></option>
              <?php if ($provinces && $provinces->num_rows): while($p=$provinces->fetch_assoc()): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $province_id==(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
              <?php endwhile; endif; ?>
            </select>
          </div>
          <div class="col-md-4">
              <label class="form-label">Municipality</label>
              <select name="municipality_id" id="municipality_id" class="form-select" onchange="this.form.submit()" <?= $province_id? '':'disabled' ?>>
                  <option value="0"><?= $province_id? 'All Municipalities' : '— Select Province first —' ?></option>
                  <?php if ($municipalities && $municipalities->num_rows): while($m=$municipalities->fetch_assoc()): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= $municipality_id==(int)$m['id']?'selected':'' ?>><?= h($m['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <a class="add-link" href="superadmin_add_barangay.php">+ Add Barangay</a>


          <div class="col-md-8 mt-2">
            <div class="searchbar">
              <i class="bi bi-search"></i>
              <input type="text" name="q" class="form-control" placeholder="Search barangay name…" value="<?= h($q) ?>">
            </div>
          </div>
          <div class="col-md-4 mt-2 text-end">
            <button class="btn btn-outline-secondary">Apply</button>
            <a class="btn btn-outline-dark" href="superadmin_barangay_health_centers.php">Reset</a>
          </div>
        </form>
      </div>

      <h4 class="mb-3">Barangay Health Centers</h4>

      <!-- Accordion list -->
      <div class="accordion" id="brgyAccordion">
        <?php
        $idx = 0;
        if ($barangays && $barangays->num_rows > 0):
          while ($b = $barangays->fetch_assoc()):
            $idx++;
            $bid = (int)$b['id'];
            $collapseId = "brgy".$idx;
            $headingId  = "heading".$idx;
        ?>
        <div class="accordion-item mb-2">
          <h2 class="accordion-header" id="<?= $headingId ?>">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="false" aria-controls="<?= $collapseId ?>">
              <strong class="me-1"><?= h($b['name']) ?></strong>
              <span class="text-muted">— <?= (int)$b['rec_count'] ?> record<?= ((int)$b['rec_count']===1?'':'s') ?></span>
            </button>
          </h2>
          <div id="<?= $collapseId ?>" class="accordion-collapse collapse" aria-labelledby="<?= $headingId ?>" data-bs-parent="#brgyAccordion">
            <div class="accordion-body">
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead class="table-light">
                    <tr>
                      <th class="text-start">Full Name</th>
                      <th class="text-center" style="width:180px;">Date of Birth</th>
                      <th class="text-center" style="width:160px;">Status</th>
                      <th class="text-center" style="width:200px;">Assigned Midwife</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      $rows = getBarangayPatients($conn, $bid);
                      if ($rows && $rows->num_rows > 0):
                        while ($p = $rows->fetch_assoc()):
                          $nameParts = array_filter([$p['first_name'] ?? '', $p['middle_name'] ?? '', $p['last_name'] ?? '', $p['suffix'] ?? ''], fn($x) => trim((string)$x) !== '');
                          $fullname  = implode(' ', $nameParts);
                          $dob       = $p['dob'] ? date('Y-m-d', strtotime($p['dob'])) : '—';
                          $statusRaw = $p['status'] ?? '';
                          $isUnder   = (strtolower($statusRaw) === 'under_monitoring');
                          $statusTxt = $statusRaw ? ucwords(str_replace('_',' ', $statusRaw)) : '—';
                          $midwife   = $p['midwife_username'] ? $p['midwife_username'] : '—';
                    ?>
                    <tr>
                      <td class="text-start"><?= h($fullname ?: '—') ?></td>
                      <td class="text-center"><?= h($dob) ?></td>
                      <td class="text-center">
                        <span class="status-pill <?= $isUnder ? 'status-under' : 'status-complete'; ?>">
                          <?= h($statusTxt) ?>
                        </span>
                      </td>
                      <td class="text-center"><?= h($midwife) ?></td>
                    </tr>
                    <?php
                        endwhile;
                      else:
                    ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No records found for this barangay.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <?php
          endwhile;
        else:
        ?>
          <div class="text-muted">No barangays found for the selected filters.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right stats column -->
    <div class="col-xl-4">
      <div class="rightbar">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="flex-grow-1">
            <div class="fw-bold"><?= h($_SESSION['username'] ?? 'superadmin') ?></div>
            <small class="text-muted">@superadmin</small>
          </div>
          <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center" style="width:42px;height:42px;">
            <i class="bi bi-person"></i>
          </div>
        </div>

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
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
