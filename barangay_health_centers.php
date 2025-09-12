<?php
session_start();
require 'db.php';

// helper like in admin_dashboard.php
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// get municipality name for the brand
$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';

if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("
        SELECT m.name
        FROM municipalities m
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $municipality_name = $row['name'];
    $stmt->close();
}


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$municipality_id = $_SESSION['municipality_id'] ?? null;
if (!$municipality_id) {
    die("No municipality is set for this admin account.");
}

$q = trim($_GET['q'] ?? '');

// ===== Stats (right sidebar)
$tot_patients = 0;
$tot_brgy     = 0;
$tot_pregnant = 0;

$c1 = $conn->prepare("SELECT COUNT(*) c FROM pregnant_women WHERE municipality_id = ?");
$c1->bind_param("i", $municipality_id);
$c1->execute();
$tot_patients = ($c1->get_result()->fetch_assoc()['c'] ?? 0);

$c2 = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id = ?");
$c2->bind_param("i", $municipality_id);
$c2->execute();
$tot_brgy = ($c2->get_result()->fetch_assoc()['c'] ?? 0);

$tot_pregnant = $tot_patients; // adjust if you track statuses differently

// ===== Barangays list (scoped to municipality) + record counts
$sql = "
    SELECT 
        b.id,
        b.name,
        COUNT(p.id) AS rec_count
    FROM barangays b
    LEFT JOIN pregnant_women p ON p.barangay_id = b.id
    WHERE b.municipality_id = ?
";
$params = [$municipality_id];
$types  = "i";

if ($q !== '') {
    $sql .= " AND b.name LIKE ?";
    $params[] = "%$q%";
    $types   .= "s";
}

$sql .= " GROUP BY b.id, b.name
          ORDER BY b.name ASC";

$stmtList = $conn->prepare($sql);
$stmtList->bind_param($types, ...$params);
$stmtList->execute();
$barangays = $stmtList->get_result();

// helper: fetch patients per barangay
function getBarangayPatients(mysqli $conn, int $barangay_id) {
    $s = $conn->prepare("
        SELECT 
            p.id,
            p.first_name, p.middle_name, p.last_name, p.suffix,
            p.dob, p.status,
            u.username AS midwife
        FROM pregnant_women p
        LEFT JOIN users u ON u.id = p.midwife_id
        WHERE p.barangay_id = ?
        ORDER BY p.last_name, p.first_name
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
<title>Barangay Health Centers • RHU-MIS</title>
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
    .topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; }
    .searchbar{ flex:1; max-width:640px; position:relative; }
    .searchbar input{ padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0; }
    .searchbar i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b; }
    .add-link{ font-weight:700; color:var(--brand); text-decoration:none; }
    .add-link:hover{ color:var(--brand-dark); text-decoration:underline; }
    .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:18px; }
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

<!-- ===== Left Sidebar ===== -->
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
    <a class="nav-link" href="announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
    <a class="nav-link active" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
    <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
    <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
    <a class="nav-link" href="manage_accounts.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
    <hr>
    <a class="nav-link" href="account.php"><i class="bi bi-person-circle"></i> My Account</a>
    <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
  </nav>
</aside>

<!-- ===== Main Content ===== -->
<main class="content container-fluid">
  <div class="row">
    <div class="col-xl-8">

      <!-- Top bar -->
      <div class="topbar">
        <form class="searchbar me-3" method="get" action="">
          <i class="bi bi-search"></i>
          <input type="text" name="q" class="form-control" placeholder="Search here" value="<?php echo htmlspecialchars($q); ?>">
        </form>
        <a class="add-link" href="add_barangay.php">+ Add Barangay</a>
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
          <h2 class="accordion-header" id="<?php echo $headingId; ?>">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" aria-controls="<?php echo $collapseId; ?>">
              <strong class="me-1"><?php echo htmlspecialchars($b['name']); ?></strong>
              <span class="text-muted">— <?php echo (int)$b['rec_count']; ?> record<?php echo ((int)$b['rec_count']===1?'':'s'); ?></span>
            </button>
          </h2>
          <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse" aria-labelledby="<?php echo $headingId; ?>" data-bs-parent="#brgyAccordion">
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
                          $fullname  = htmlspecialchars(implode(' ', $nameParts));
                          $dob       = $p['dob'] ? date('Y-m-d', strtotime($p['dob'])) : '—';
                          $statusRaw = $p['status'] ?? '';
                          $isUnder   = (strtolower($statusRaw) === 'under_monitoring');
                          $statusTxt = $statusRaw ? ucwords(str_replace('_',' ', $statusRaw)) : '—';
                          $midwife   = $p['midwife'] ? htmlspecialchars($p['midwife']) : '—';
                    ?>
                    <tr>
                      <td class="text-start"><?php echo $fullname ?: '—'; ?></td>
                      <td class="text-center"><?php echo $dob; ?></td>
                      <td class="text-center">
                        <span class="status-pill <?php echo $isUnder ? 'status-under' : 'status-complete'; ?>">
                          <?php echo $statusTxt; ?>
                        </span>
                      </td>
                      <td class="text-center"><?php echo $midwife; ?></td>
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
          <div class="text-muted">No barangays found for this municipality.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right stats column -->
    <div class="col-xl-4">
      <div class="rightbar">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="flex-grow-1">
            <div class="fw-bold">admin</div>
            <small class="text-muted">@rhumissapangdalaga</small>
          </div>
          <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center" style="width:42px;height:42px;">
            <i class="bi bi-person"></i>
          </div>
        </div>

        <div class="stat">
          <h6>Total Patient Record</h6>
          <div class="num"><?php echo (int)$tot_patients; ?></div>
        </div>

        <div class="stat accent">
          <h6>Total Brgy. Health Center</h6>
          <div class="num"><?php echo (int)$tot_brgy; ?></div>
        </div>

        <div class="stat">
          <h6>Total Pregnant Patient</h6>
          <div class="num"><?php echo (int)$tot_pregnant; ?></div>
        </div>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
