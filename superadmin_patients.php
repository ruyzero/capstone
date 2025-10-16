<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function full_name($row) {
    $parts = array_filter([
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? '',
        $row['suffix'] ?? ''
    ], fn($x) => $x !== null && trim($x) !== '');
    return trim(implode(' ', $parts));
}
function qAll(mysqli $conn, string $sql, array $params = [], string $types = ''){
    $stmt = $conn->prepare($sql);
    if(!$stmt){ die("SQL Error: ".$conn->error); }
    if ($params) {
        if ($types==='') $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}
function qOne(mysqli $conn, string $sql, array $params = [], string $types = ''){
    $rows = qAll($conn, $sql, $params, $types);
    return $rows[0] ?? null;
}

/* ---------- Auth: Super Admin only (see-all) ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ---------- Top info (right rail identity) ---------- */
$username = $_SESSION['username'] ?? 'super_admin';

/* ---------- Filters + search ---------- */
$region_id       = (int)($_GET['region_id'] ?? 0);
$province_id     = (int)($_GET['province_id'] ?? 0);
$municipality_id = (int)($_GET['municipality_id'] ?? 0);
$barangay_id     = (int)($_GET['barangay_id'] ?? 0);
$q               = trim($_GET['q'] ?? '');

/* ---------- Dropdown options (cascading) ---------- */
$regions = qAll($conn, "SELECT id, name FROM regions ORDER BY name");

$provinces = [];
if ($region_id) {
    $provinces = qAll($conn, "SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name", [$region_id], "i");
}

$municipalities = [];
if ($province_id) {
    $municipalities = qAll($conn, "SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name", [$province_id], "i");
}

$barangays = [];
if ($municipality_id) {
    $barangays = qAll($conn, "SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name", [$municipality_id], "i");
}

/* ---------- WHERE builder for main queries ---------- */
$where = [];
$params = [];
$types  = '';

if ($region_id)       { $where[] = "r.id = ?"; $params[] = $region_id; $types .= 'i'; }
if ($province_id)     { $where[] = "p.id = ?"; $params[] = $province_id; $types .= 'i'; }
if ($municipality_id) { $where[] = "m.id = ?"; $params[] = $municipality_id; $types .= 'i'; }
if ($barangay_id)     { $where[] = "b.id = ?"; $params[] = $barangay_id; $types .= 'i'; }

if ($q !== '') {
    $where[] = "(
        CONCAT_WS(' ', w.first_name, w.middle_name, w.last_name, w.suffix) LIKE ?
        OR b.name LIKE ?
        OR m.name LIKE ?
    )";
    $like = '%'.$q.'%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- Patients list (like view_pregnancies but global) ---------- */
/*
   Joins assume:
   pregnant_women w (municipality_id, barangay_id)
   barangays b (municipality_id)
   municipalities m (province_id)
   provinces p (region_id)
   regions r
*/
$sqlList = "
    SELECT 
        w.id,
        w.first_name, w.middle_name, w.last_name, w.suffix,
        w.dob, w.date_registered,
        b.name AS barangay,
        m.name AS municipality,
        p.name AS province,
        r.name AS region
    FROM pregnant_women w
    LEFT JOIN barangays b       ON b.id = w.barangay_id
    LEFT JOIN municipalities m  ON m.id = w.municipality_id
    LEFT JOIN provinces p       ON p.id = m.province_id
    LEFT JOIN regions r         ON r.id = p.region_id
    $whereSql
    ORDER BY w.id DESC
";

$stmt = $conn->prepare($sqlList);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$patients = $stmt->get_result();

/* ---------- Right-rail totals (respect filters) ---------- */
$rowCount = qOne($conn, "
    SELECT COUNT(*) AS c
    FROM pregnant_women w
    LEFT JOIN barangays b       ON b.id = w.barangay_id
    LEFT JOIN municipalities m  ON m.id = w.municipality_id
    LEFT JOIN provinces p       ON p.id = m.province_id
    LEFT JOIN regions r         ON r.id = p.region_id
    $whereSql
", $params, $types);
$tot_patients = (int)($rowCount['c'] ?? 0);

$rowBrgy = qOne($conn, "
    SELECT COUNT(DISTINCT b.id) AS c
    FROM pregnant_women w
    LEFT JOIN barangays b       ON b.id = w.barangay_id
    LEFT JOIN municipalities m  ON m.id = w.municipality_id
    LEFT JOIN provinces p       ON p.id = m.province_id
    LEFT JOIN regions r         ON r.id = p.region_id
    $whereSql
", $params, $types);
$tot_brgy = (int)($rowBrgy['c'] ?? 0);

/* If you have a separate flag for “currently pregnant”, change this count accordingly. */
$tot_pregnant = $tot_patients;

/* ---------- Leftbar tag (for consistency) ---------- */
$brand_tag = 'RHU-MIS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Patients • RHU-MIS (Super Admin)</title>
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
        .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }
        /* Leftbar */
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
        .topbar{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
        .searchbar{ flex:1; min-width:260px; position:relative; }
        .searchbar input{
            padding-left:40px; height:44px; border-radius:999px; background:#f7f9fb; border:1px solid #e6ebf0;
        }
        .searchbar i{
            position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#64748b;
        }
        .add-link{ font-weight:700; color:#00a39a; text-decoration:none; white-space:nowrap; }
        .add-link:hover{ color:var(--brand-dark); text-decoration:underline; }

        .panel{ background:#fff; border:1px solid #eef0f3; border-radius:16px; padding:18px; }
        .table > :not(caption) > * > * { vertical-align: middle; }
        .table thead th{
            color:#0f172a; font-weight:700; background:#f7fafc; border-bottom:1px solid #eef0f3 !important;
        }
        .pill{
            display:inline-block; padding:.25rem .6rem; border-radius:999px; background:#eef0f3; color:#334155; font-weight:700; font-size:.85rem;
        }

        /* Filters row */
        .filters .form-select{ background:#f7f9fb; border:1px solid #e6ebf0; }

        /* Right rail */
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
    <!-- ===== Left Sidebar (same style) ===== -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div><?= h($brand_tag) ?></div>
                <small class="text-muted">SUPER ADMIN</small>
            </div>
        </div>

        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="super_admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link active" href="superadmin_patients.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="superadmin_manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="superadmin_manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="superadmin_barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="superadmin_prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="superadmin_request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <a class="nav-link" href="manage_accounts_admin.php"><i class="bi bi-person-fill-gear"></i> Manage Accounts</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- ===== Main ===== -->
    <main class="main">

        <!-- Top: search + Add Patient -->
        <div class="topbar">
            <form class="searchbar" method="get" action="">
                <!-- preserve current filters in search -->
                <input type="hidden" name="region_id" value="<?= (int)$region_id ?>">
                <input type="hidden" name="province_id" value="<?= (int)$province_id ?>">
                <input type="hidden" name="municipality_id" value="<?= (int)$municipality_id ?>">
                <input type="hidden" name="barangay_id" value="<?= (int)$barangay_id ?>">

                <i class="bi bi-search"></i>
                <input type="text" name="q" class="form-control" placeholder="Search name or barangay/municipality..." value="<?= h($q) ?>">
            </form>
            <a class="add-link" href="add_pregnancy.php">+ Add Patient</a>
        </div>

        <!-- Filters: Region → Province → Municipality → Barangay -->
        <div class="panel mb-3">
            <form class="row g-2 filters" method="get">
                <div class="col-md-3">
                    <label class="form-label">Region</label>
                    <select name="region_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">All Regions</option>
                        <?php foreach ($regions as $r): ?>
                            <option value="<?= (int)$r['id'] ?>" <?= $region_id==$r['id']?'selected':''; ?>>
                                <?= h($r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Province</label>
                    <select name="province_id" class="form-select" onchange="this.form.submit()">
                        <option value="0"><?= $region_id ? 'All Provinces in Region' : 'Choose Region first' ?></option>
                        <?php foreach ($provinces as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $province_id==$p['id']?'selected':''; ?>>
                                <?= h($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Municipality</label>
                    <select name="municipality_id" class="form-select" onchange="this.form.submit()">
                        <option value="0"><?= $province_id ? 'All Municipalities in Province' : 'Choose Province first' ?></option>
                        <?php foreach ($municipalities as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= $municipality_id==$m['id']?'selected':''; ?>>
                                <?= h($m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Barangay</label>
                    <select name="barangay_id" class="form-select" onchange="this.form.submit()">
                        <option value="0"><?= $municipality_id ? 'All Barangays in Municipality' : 'Choose Municipality first' ?></option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $barangay_id==$b['id']?'selected':''; ?>>
                                <?= h($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Keep the search when filters change -->
                <input type="hidden" name="q" value="<?= h($q) ?>">
            </form>
        </div>

        <!-- Patients table (like view_pregnancies, but global + location columns) -->
        <div class="panel">
            <h5 class="mb-3">Patients</h5>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th class="text-start">Fullname</th>
                            <th class="text-center" style="width:110px">Age</th>
                            <th class="text-center" style="width:160px">Barangay</th>
                            <th class="text-center" style="width:160px">Municipality</th>
                            <th class="text-center" style="width:160px">Province</th>
                            <th class="text-center" style="width:160px">Region</th>
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
                                <td class="text-center"><?= ($age !== '' ? (int)$age : '—') ?></td>
                                <td class="text-center"><?= h($row['barangay'] ?? '—') ?></td>
                                <td class="text-center"><?= h($row['municipality'] ?? '—') ?></td>
                                <td class="text-center"><?= h($row['province'] ?? '—') ?></td>
                                <td class="text-center"><?= h($row['region'] ?? '—') ?></td>
                                <td class="text-center"><span class="pill"><?= h($dateReg) ?></span></td>
                                <td class="text-center">
                                    <a href="superadmin_view_pregnancy_detail.php?id=<?= (int)$row['id']; ?>" class="text-decoration-none">
                                        view
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- ===== Right rail ===== -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?= h($username) ?></div>
                <div class="text-muted small">@superadmin</div>
            </div>
        </div>

        <div class="stat">
            <div class="label">Total Patient Record</div>
            <div class="big"><?= number_format($tot_patients) ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?= number_format($tot_brgy) ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?= number_format($tot_pregnant) ?></div>
        </div>
    </aside>
</div>

</body>
</html>
