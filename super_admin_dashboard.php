<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ------------------------- INPUTS (search + filters) ------------------------- */
$q  = trim($_GET['q'] ?? '');
$rQ = isset($_GET['r']) ? (int)$_GET['r'] : 0;   // region id filter
$pQ = isset($_GET['p']) ? (int)$_GET['p'] : 0;   // province id filter
$qLike = '%' . $q . '%';
$keepQ = $q !== '' ? '&q=' . urlencode($q) : '';

/* ------------------------- Selected names for chips -------------------------- */
$selectedRegionName = '';
$selectedProvinceName = '';

if ($rQ > 0) {
    $rs = $conn->prepare("SELECT name FROM regions WHERE id = ? LIMIT 1");
    $rs->bind_param("i", $rQ);
    $rs->execute();
    $row = $rs->get_result()->fetch_assoc();
    $selectedRegionName = $row['name'] ?? '';
    $rs->close();
}
if ($pQ > 0) {
    $ps = $conn->prepare("SELECT name FROM provinces WHERE id = ? LIMIT 1");
    $ps->bind_param("i", $pQ);
    $ps->execute();
    $prow = $ps->get_result()->fetch_assoc();
    $selectedProvinceName = $prow['name'] ?? '';
    $ps->close();
}

/* -------------------- Reusable derived table for “Active” ------------------- */
/* DISTINCT municipalities that have at least one admin account */
$activeAdminMuniSQL = "SELECT DISTINCT municipality_id FROM users WHERE role='admin' AND municipality_id IS NOT NULL";

/* ------------------------------ CATEGORY DATA ------------------------------- */
/* Regions: count of active municipalities */
$regionCats = $conn->query("
    SELECT rg.id, rg.name,
           COUNT(DISTINCT CASE WHEN am.municipality_id IS NOT NULL THEN m.id END) AS active_munis
    FROM regions rg
    LEFT JOIN provinces p ON p.region_id = rg.id
    LEFT JOIN municipalities m ON m.province_id = p.id
    LEFT JOIN ( $activeAdminMuniSQL ) am ON am.municipality_id = m.id
    GROUP BY rg.id, rg.name
    ORDER BY rg.name ASC
");

/* Provinces list (only when a region is chosen) + region active count for “All Provinces” badge */
$provinceCats = null;
$selectedRegionActiveCount = 0;
if ($rQ > 0) {
    $provStmt = $conn->prepare("
        SELECT p.id, p.name, p.region_id,
               COUNT(DISTINCT CASE WHEN am.municipality_id IS NOT NULL THEN m.id END) AS active_munis
        FROM provinces p
        LEFT JOIN municipalities m ON m.province_id = p.id
        LEFT JOIN ( $activeAdminMuniSQL ) am ON am.municipality_id = m.id
        WHERE p.region_id = ?
        GROUP BY p.id, p.name, p.region_id
        ORDER BY active_munis DESC, p.name ASC
    ");
    $provStmt->bind_param("i", $rQ);
    $provStmt->execute();
    $provinceCats = $provStmt->get_result();

    $rc = $conn->prepare("
        SELECT COUNT(DISTINCT m.id) AS c
        FROM municipalities m
        JOIN provinces p ON p.id = m.province_id
        JOIN ( $activeAdminMuniSQL ) am ON am.municipality_id = m.id
        WHERE p.region_id = ?
    ");
    $rc->bind_param("i", $rQ);
    $rc->execute();
    $selectedRegionActiveCount = (int)($rc->get_result()->fetch_assoc()['c'] ?? 0);
    $rc->close();
    $provStmt->close();
}

/* ----------------------------- MUNICIPALITIES ------------------------------- */
$muniSql = "
    SELECT 
        m.id, m.name,
        COALESCE(m.num_barangays, 0) AS num_barangays,
        p.id  AS province_id,
        rg.id AS region_id,
        CASE WHEN am.municipality_id IS NOT NULL THEN 1 ELSE 0 END AS is_active
    FROM municipalities m
    JOIN provinces p ON p.id = m.province_id
    JOIN regions  rg ON rg.id = p.region_id
    LEFT JOIN ( $activeAdminMuniSQL ) am ON am.municipality_id = m.id
    WHERE 1=1
";
$types = '';
$params = [];
if ($q !== '') { $muniSql .= " AND m.name LIKE ? "; $types .= 's'; $params[] = $qLike; }
if ($rQ > 0)   { $muniSql .= " AND rg.id = ? ";    $types .= 'i'; $params[] = $rQ;   }
if ($pQ > 0)   { $muniSql .= " AND p.id  = ? ";    $types .= 'i'; $params[] = $pQ;   }
$muniSql .= " ORDER BY is_active DESC, m.name ASC ";

$muniStmt = $conn->prepare($muniSql);
if ($types !== '') { $muniStmt->bind_param($types, ...$params); }
$muniStmt->execute();
$municipalities = $muniStmt->get_result();
$muniStmt->close();

/* ------------------------------- REQUESTS ----------------------------------- */
$reqSql = "
    SELECT 
        r.id,
        m.id   AS municipality_id,
        m.name AS municipality_name,
        COALESCE(m.num_barangays, 0) AS num_barangays,
        CASE WHEN am.municipality_id IS NOT NULL THEN 'Active' ELSE 'Inactive' END AS status_text,
        rg.id AS region_id, p.id AS province_id
    FROM pending_admin_requests r
    JOIN municipalities m ON m.id = r.municipality_id
    JOIN provinces     p ON p.id = m.province_id
    JOIN regions       rg ON rg.id = p.region_id
    LEFT JOIN ( $activeAdminMuniSQL ) am ON am.municipality_id = m.id
    WHERE r.status = 'pending'
";
$typesR = '';
$paramsR = [];
if ($q !== '')  { $reqSql .= " AND m.name LIKE ? "; $typesR .= 's'; $paramsR[] = $qLike; }
if ($rQ > 0)    { $reqSql .= " AND rg.id = ? ";     $typesR .= 'i'; $paramsR[] = $rQ;   }
if ($pQ > 0)    { $reqSql .= " AND p.id  = ? ";     $typesR .= 'i'; $paramsR[] = $pQ;   }
$reqSql .= " ORDER BY r.date_requested DESC, r.id DESC ";
$reqStmt = $conn->prepare($reqSql);
if ($typesR !== '') { $reqStmt->bind_param($typesR, ...$paramsR); }
$reqStmt->execute();
$requests = $reqStmt->get_result();
$reqStmt->close();

/* ------------------------------- STATS -------------------------------------- */
$cntSql = "
    SELECT COUNT(DISTINCT m.id) AS c
    FROM municipalities m
    JOIN provinces p ON p.id = m.province_id
    JOIN regions  rg ON rg.id = p.region_id
    JOIN ( $activeAdminMuniSQL ) am ON am.municipality_id = m.id
    WHERE 1=1
";
$typesS = '';
$paramsS = [];
if ($rQ > 0) { $cntSql .= " AND rg.id = ? "; $typesS .= 'i'; $paramsS[] = $rQ; }
if ($pQ > 0) { $cntSql .= " AND p.id  = ? "; $typesS .= 'i'; $paramsS[] = $pQ; }
$cntStmt = $conn->prepare($cntSql);
if ($typesS !== '') { $cntStmt->bind_param($typesS, ...$paramsS); }
$cntStmt->execute();
$countMuniWithAdmin = (int)($cntStmt->get_result()->fetch_assoc()['c'] ?? 0);
$cntStmt->close();

$cntReqSql = "
    SELECT COUNT(*) AS c
    FROM pending_admin_requests r
    JOIN municipalities m ON m.id = r.municipality_id
    JOIN provinces p ON p.id = m.province_id
    JOIN regions  rg ON rg.id = p.region_id
    WHERE r.status='pending'
";
$typesP = '';
$paramsP = [];
if ($rQ > 0) { $cntReqSql .= " AND rg.id = ? "; $typesP .= 'i'; $paramsP[] = $rQ; }
if ($pQ > 0) { $cntReqSql .= " AND p.id  = ? "; $typesP .= 'i'; $paramsP[] = $pQ; }
$cntReqStmt = $conn->prepare($cntReqSql);
if ($typesP !== '') { $cntReqStmt->bind_param($typesP, ...$paramsP); }
$cntReqStmt->execute();
$countPendingRequests = (int)($cntReqStmt->get_result()->fetch_assoc()['c'] ?? 0);
$cntReqStmt->close();

/* Header profile */
$username_display = htmlspecialchars($_SESSION['username'] ?? 'superadmin');
$handle_display   = '@' . preg_replace('/\s+/', '', strtolower($username_display));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Super Admin Dashboard - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
    :root{
        --sidebar-bg:#0ea5a3;
        --sidebar-active:#0b8a89;
        --chip-green:#16a34a;
        --chip-red:#ef4444;
        --selected:#e6fffb;
        --selected-border:#9be7e0;
    }
    body{ background:#f7f9fb; }
    .app{ display:grid; grid-template-columns: 260px 1fr 360px; gap:24px; min-height:100vh; }
    .sidebar{ background:var(--sidebar-bg); color:#fff; padding:18px 14px; }
    .brand{ display:flex; align-items:center; gap:10px; margin-bottom:18px; font-weight:700; }
    .brand .logo{ width:36px;height:36px;border-radius:8px;background:#fff;display:grid;place-items:center;color:var(--sidebar-bg); font-weight:800; }
    .navlink{ display:flex; align-items:center; gap:10px; color:#e6fffb; text-decoration:none; padding:10px 12px; border-radius:10px; margin:4px 6px; }
    .navlink:hover{ background:rgba(255,255,255,.15); color:#fff; }
    .navlink.active{ background:var(--sidebar-active); color:#fff; }
    .bottom-links{ margin-top:auto; }

    .main{ padding:22px 0; }
    .searchbar{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:10px 14px; margin-bottom:18px; }
    .section-title{ font-weight:700; margin:8px 0 10px; }
    .municipality-card{ background:#fff; border:1px solid #eceff3; border-radius:16px; padding:16px; display:flex; justify-content:space-between; align-items:flex-start; transition:box-shadow .2s ease; }
    .municipality-card:hover{ box-shadow:0 6px 16px rgba(15, 23, 42, .06); }
    .m-name{ font-weight:600; }
    .status-dot{ width:10px;height:10px;border-radius:50%; display:inline-block; margin-right:8px; }
    .status-active{ background:var(--chip-green);}
    .status-inactive{ background:var(--chip-red);}
    .gear{ color:#9aa4ad; }
    .table-card{ background:#fff; border:1px solid #eceff3; border-radius:16px; padding:14px; }
    .table thead th{ background:#f8fafc; color:#0f172a; }
    .link-form{ color:#0b6bff; text-decoration:none; }
    .link-form:hover{ text-decoration:underline; }

    .rail{ padding:22px 0; }
    .profile{ background:#fff; border:1px solid #eceff3; border-radius:16px; padding:16px; display:flex; align-items:center; gap:12px; }
    .avatar{ width:42px;height:42px;border-radius:50%; background:#f0f4f8; display:grid; place-items:center; font-size:18px; }
    .stat{ background:#fff; border:1px solid #eceff3; border-radius:16px; padding:18px; text-align:center; }
    .stat h5{ font-weight:700; color:#475569; }
    .stat .big{ font-size:44px; font-weight:800; line-height:1; color:#0f172a; }
    .stat.gradient{ background: linear-gradient(180deg, #09c6ab 0%, #0ea5a3 100%); color:#fff; border:0; }
    .stat.gradient h5{ color:#e7fffb; }

    .cat-card{ background:#fff; border:1px solid #eceff3; border-radius:16px; padding:12px; }
    .cat-title{ font-weight:700; color:#0f172a; }
    .cat-list{ list-style:none; padding-left:0; margin:10px 0 0; max-height:260px; overflow:auto; }
    .cat-list li{ display:flex; justify-content:space-between; align-items:center; padding:8px 10px; border-radius:10px; border:1px solid transparent; }
    .cat-list li a{ text-decoration:none; color:#0f172a; flex:1; }
    .cat-list li small.badge{ margin-left:10px; }
    .cat-list li:hover{ background:#f8fafc; }
    .cat-list li.selected{ background:var(--selected); border-color:var(--selected-border); }
    .active-chip{ background:#e8fff4; border:1px solid #b6f4d2; color:#065f46; padding:2px 8px; border-radius:999px; font-size:12px; margin-left:6px; }
    .clear-filter{ text-decoration:none; }
    @media (max-width: 1200px){ .app{ grid-template-columns: 240px 1fr; } .rail{ display:none; } }
</style>
</head>
<body>
<div class="app">

    <!-- SIDEBAR -->
    <aside class="sidebar d-flex flex-column">
        <div class="brand">
            <div class="logo">✚</div>
            <div>RHU-MIS</div>
        </div>

        <a class="navlink active" href="#"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a class="navlink" href="#"><i class="bi bi-people"></i> Patients</a>
        <a class="navlink" href="#"><i class="bi bi-person-badge"></i> Midwives</a>
        <a class="navlink" href="#"><i class="bi bi-megaphone"></i> Announcements</a>
        <a class="navlink" href="#"><i class="bi bi-building"></i> Brgy. Health Centers</a>
        <a class="navlink" href="#"><i class="bi bi-heart-pulse"></i> Prenatal Monitoring</a>
        <a class="navlink" href="#"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
        <a class="navlink" href="manage_accounts.php"><i class="bi bi-person-gear"></i> Manage Accounts</a>

        <div class="bottom-links mt-auto">
            <a class="navlink" href="#"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="navlink" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <!-- Search + chips -->
        <form class="searchbar" method="get">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control border-0 p-0" placeholder="Search Here" value="<?= htmlspecialchars($q) ?>">
            <?php if ($rQ>0 || $pQ>0): ?>
                <span class="ms-2 small">
                    <?php if ($rQ>0): ?><span class="active-chip"><?= htmlspecialchars($selectedRegionName ?: "Region #$rQ") ?></span><?php endif; ?>
                    <?php if ($pQ>0): ?><span class="active-chip"><?= htmlspecialchars($selectedProvinceName ?: "Province #$pQ") ?></span><?php endif; ?>
                </span>
            <?php endif; ?>
            <?php if ($q !== '' || $rQ>0 || $pQ>0): ?>
                <a href="super_admin_dashboard.php" class="btn btn-sm btn-outline-secondary ms-2 clear-filter">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Municipalities cards (Active first) -->
        <h5 class="section-title">Municipalities</h5>
        <div class="row g-3">
            <?php if ($municipalities->num_rows === 0): ?>
                <div class="col-12"><div class="alert alert-light border">No municipalities found<?= $q ? " for “".htmlspecialchars($q)."”" : "" ?>.</div></div>
            <?php endif; ?>
            <?php while ($m = $municipalities->fetch_assoc()): ?>
                <div class="col-md-4 col-lg-3">
                    <div class="municipality-card">
                        <div>
                            <div class="m-name"><?= htmlspecialchars($m['name']) ?></div>
                            <div class="mt-2 small text-muted">
                                <?php if ((int)$m['is_active'] === 1): ?>
                                    <span class="status-dot status-active"></span> Active
                                <?php else: ?>
                                    <span class="status-dot status-inactive"></span> Inactive
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="manage_accounts.php?municipality_id=<?= (int)$m['id'] ?>" class="gear" title="Manage"><i class="bi bi-gear"></i></a>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <!-- Requests -->
        <h5 class="section-title mt-4">Requests</h5>
        <div class="table-card">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Municipalities</th>
                            <th>No. of Brgy.</th>
                            <th>Request Form</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($requests->num_rows === 0): ?>
                        <tr><td colspan="4" class="text-muted">No pending requests for current filter<?= $q ? " and search “".htmlspecialchars($q)."”" : "" ?>.</td></tr>
                    <?php else: ?>
                        <?php while ($r = $requests->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['municipality_name']) ?></td>
                                <td><?= (int)$r['num_barangays'] ?></td>
                                <td><a class="link-form" href="request_admin.php?municipality_id=<?= (int)$r['municipality_id'] ?>">Form</a></td>
                                <td><?= htmlspecialchars($r['status_text']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- RIGHT RAIL (Profile, Stats, Categories) -->
    <aside class="rail">
        <div class="profile mb-3">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-semibold"><?= $username_display ?></div>
                <div class="text-muted small"><?= htmlspecialchars($handle_display) ?></div>
            </div>
        </div>

        <div class="stat mb-3">
            <h5>Municipalities (Active)</h5>
            <div class="big"><?= (int)$countMuniWithAdmin ?></div>
        </div>

        <div class="stat gradient mb-3">
            <h5>Requests</h5>
            <div class="big"><?= (int)$countPendingRequests ?></div>
        </div>

        <!-- CATEGORIES: Regions -->
        <div class="cat-card mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="cat-title">Regions (Active)</div>
                <?php if ($rQ>0): ?>
                    <a class="small clear-filter" href="super_admin_dashboard.php?<?= ($pQ>0 ? 'p='.$pQ.'&' : '') . ltrim($keepQ,'&') ?>">Clear Region</a>
                <?php endif; ?>
            </div>
            <ul class="cat-list">
                <?php while ($rg = $regionCats->fetch_assoc()): ?>
                    <?php $rgSel = ((int)$rg['id'] === $rQ); ?>
                    <li class="<?= $rgSel ? 'selected' : '' ?>">
                        <a href="super_admin_dashboard.php?r=<?= (int)$rg['id'] . ($pQ>0 ? '&p='.$pQ : '') . $keepQ ?>">
                            <?= htmlspecialchars($rg['name']) ?>
                        </a>
                        <small class="badge bg-success-subtle text-success-emphasis"><?= (int)$rg['active_munis'] ?></small>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>

        <!-- CATEGORIES: Provinces (only after choosing a region) -->
        <div class="cat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div class="cat-title">Provinces (Active)</div>
                <?php if ($pQ>0): ?>
                    <a class="small clear-filter" href="super_admin_dashboard.php?r=<?= $rQ . $keepQ ?>">Clear Province</a>
                <?php endif; ?>
            </div>

            <?php if ($rQ === 0): ?>
                <div class="small text-muted">Pick a region to view its provinces.</div>
            <?php else: ?>
                <ul class="cat-list">
                    <!-- All Provinces in selected Region -->
                    <li class="<?= ($pQ === 0 ? 'selected' : '') ?>">
                        <a href="super_admin_dashboard.php?r=<?= $rQ . $keepQ ?>">
                            All Provinces in <?= htmlspecialchars($selectedRegionName ?: "Region #$rQ") ?>
                        </a>
                        <small class="badge bg-success-subtle text-success-emphasis"><?= (int)$selectedRegionActiveCount ?></small>
                    </li>

                    <?php while ($pv = $provinceCats->fetch_assoc()): ?>
                        <?php $pvSel = ((int)$pv['id'] === $pQ); ?>
                        <li class="<?= $pvSel ? 'selected' : '' ?>">
                            <a href="super_admin_dashboard.php?r=<?= (int)$pv['region_id'] ?>&p=<?= (int)$pv['id'] . $keepQ ?>">
                                <?= htmlspecialchars($pv['name']) ?>
                            </a>
                            <small class="badge bg-success-subtle text-success-emphasis"><?= (int)$pv['active_munis'] ?></small>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php endif; ?>
        </div>
    </aside>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
