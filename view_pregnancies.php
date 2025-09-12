<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

// helper like in admin_dashboard.php
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ✅ Auth: Admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$municipality_id   = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name = $_SESSION['municipality_name'] ?? '';
$username          = $_SESSION['username'] ?? 'admin';

// Fallback lookup for municipality name if not in session
if ($municipality_name === '' && $municipality_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM municipalities WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $municipality_name = $row['name'];
    $stmt->close();
}

$handle = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));

// 🔎 Search (optional)
$q = trim($_GET['q'] ?? '');

// =======================
// Data queries
// =======================

// Patients list (with optional search)
$baseSql = "
    SELECT 
        p.id,
        p.first_name, p.middle_name, p.last_name, p.suffix,
        p.dob, p.date_registered,
        b.name AS barangay
    FROM pregnant_women p
    LEFT JOIN barangays b ON b.id = p.barangay_id
    WHERE p.municipality_id = ?
";
$params = [$municipality_id];
$types  = "i";

if ($q !== '') {
    $baseSql .= " AND (
        CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name, p.suffix) LIKE ?
        OR b.name LIKE ?
    )";
    $like = '%' . $q . '%';
    $params[] = $like; 
    $params[] = $like;
    $types   .= "ss";
}

$baseSql .= " ORDER BY p.id DESC";

$stmt = $conn->prepare($baseSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$patients = $stmt->get_result();

// Totals (right rail)
$c1 = $conn->prepare("SELECT COUNT(*) AS c FROM pregnant_women WHERE municipality_id = ?");
$c1->bind_param("i", $municipality_id);
$c1->execute();
$tot_patients = (int)($c1->get_result()->fetch_assoc()['c'] ?? 0);

$c2 = $conn->prepare("SELECT COUNT(*) AS c FROM barangays WHERE municipality_id = ?");
$c2->bind_param("i", $municipality_id);
$c2->execute();
$tot_brgy = (int)($c2->get_result()->fetch_assoc()['c'] ?? 0);

// If you want this different from Total Patient Record, change logic
$tot_pregnant = $tot_patients;

// Helper to build clean full name
function full_name($row) {
    $parts = array_filter([
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? '',
        $row['suffix'] ?? ''
    ], fn($x) => $x !== null && trim($x) !== '');
    return trim(implode(' ', $parts));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Patients • RHU-MIS</title>
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
        /* ===== 3-column grid like admin_dashboard ===== */
        .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

        /* ==== LEFT PANEL (same as dashboard) ==== */
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

        /* ==== MAIN (center column) ==== */
        .main{ padding:24px; background:#ffffff; }
        .topbar{ display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; }
        .searchbar{ flex:1; max-width:640px; position:relative; }
        .searchbar input{
            padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0;
        }
        .searchbar i{
            position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b;
        }
        .add-link{ font-weight:700; color:#00a39a; text-decoration:none; }
        .add-link:hover{ color:var(--brand-dark); text-decoration:underline; }

        .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:18px; }
        .table > :not(caption) > * > * { vertical-align: middle; }
        .table thead th{
            color:#0f172a; font-weight:700; background:#f7fafc; border-bottom:1px solid #eef0f3 !important;
        }
        .pill{
            display:inline-block; padding:.25rem .6rem; border-radius:999px; background:#eef0f3; color:#334155; font-weight:700; font-size:.85rem;
        }

        /* ==== RIGHT RAIL (exactly like dashboard) ==== */
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

        /* Responsive: put rail below main on smaller screens */
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
            <a class="nav-link active" href="view_pregnancies.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- ===== Main (center column) ===== -->
    <main class="main">
        <!-- Top bar (search + add) -->
        <div class="topbar">
            <form class="searchbar me-3" method="get" action="">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="form-control" placeholder="Search here" value="<?= h($q) ?>">
            </form>
            <a class="add-link" href="add_pregnancy.php">+ Add Patient</a>
        </div>

        <!-- Patients table -->
        <div class="panel">
            <h5 class="mb-3">Patients</h5>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th class="text-start">Fullname</th>
                            <th class="text-center" style="width:110px">Age</th>
                            <th class="text-center" style="width:180px">Barangay</th>
                            <th class="text-center" style="width:170px">Date Registered</th>
                            <th class="text-center" style="width:100px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($patients && $patients->num_rows > 0): ?>
                        <?php while ($row = $patients->fetch_assoc()):
                            $name = full_name($row);
                            $age = '';
                            if (!empty($row['dob'])) {
                                try {
                                    $dob = new DateTime($row['dob']);
                                    $age = (new DateTime())->diff($dob)->y;
                                } catch (Exception $e) { $age = ''; }
                            }
                            $dateReg = $row['date_registered'] ? date('M d, Y', strtotime($row['date_registered'])) : '—';
                        ?>
                            <tr>
                                <td class="text-start"><?= h($name) ?></td>
                                <td class="text-center"><?= $age !== '' ? (int)$age : '—'; ?></td>
                                <td class="text-center"><?= h($row['barangay'] ?? '—') ?></td>
                                <td class="text-center"><span class="pill"><?= h($dateReg) ?></span></td>
                                <td class="text-center">
                                    <a href="view_pregnancy_detail.php?id=<?= (int)$row['id']; ?>" class="text-decoration-none">
                                        view
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- ===== Right rail (separate column, not floating) ===== -->
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

</body>
</html>
