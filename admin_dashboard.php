<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$municipality_id = (int)($_SESSION['municipality_id'] ?? 0);
$username        = $_SESSION['username'] ?? 'admin';

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fetch_count(mysqli $conn, string $sql, array $bind = [], string $types = ''): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return 0; }
    if ($types !== '' && !empty($bind)) { $stmt->bind_param($types, ...$bind); }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $stmt->close();
    return (int)($row[0] ?? 0);
}

function age_band(mysqli $conn, int $muni_id, int $min, int $max): int {
    $sql = "
        SELECT COUNT(*)
        FROM pregnant_women
        WHERE municipality_id = ?
          AND sex = 'Female'
          AND dob IS NOT NULL
          AND TIMESTAMPDIFF(YEAR, `dob`, CURDATE()) BETWEEN ? AND ?
    ";
    return fetch_count($conn, $sql, [$muni_id, $min, $max], "iii");
}

/* ---------- Location info ---------- */
$location_stmt = $conn->prepare("
    SELECT m.name AS municipality, p.name AS province, r.name AS region
    FROM municipalities m
    LEFT JOIN provinces p ON m.province_id = p.id
    LEFT JOIN regions r   ON p.region_id = r.id
    WHERE m.id = ?
");
$location_stmt->bind_param("i", $municipality_id);
$location_stmt->execute();
$location = $location_stmt->get_result()->fetch_assoc() ?: [];
$municipality_name = $location['municipality'] ?? 'Unknown';
$province_name     = $location['province'] ?? 'Unknown';
$region_name       = $location['region'] ?? 'Unknown';
$location_stmt->close();

/* ---------- Announcements (latest for this municipality) ---------- */
$ann_stmt = $conn->prepare("
    SELECT id, title, message, date_created
    FROM announcements
    WHERE municipality_id = ?
    ORDER BY date_created DESC, id DESC
    LIMIT 1
");
$ann_stmt->bind_param("i", $municipality_id);
$ann_stmt->execute();
$announcement = $ann_stmt->get_result()->fetch_assoc();
$ann_stmt->close();

$a_title      = $announcement['title']        ?? 'No announcements yet';
$a_text       = $announcement['message']      ?? '';
$a_created_at = $announcement['date_created'] ?? null;
$ann_image    = isset($announcement['id']) ? "announcement_images/announcement_{$announcement['id']}.png" : null;

/* ---------- Stats ---------- */
$totalPatients = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM pregnant_women WHERE municipality_id = ? AND sex = 'Female'",
    [$municipality_id],
    "i"
);
$totalBrgyCenters = fetch_count(
    $conn,
    "SELECT COUNT(*) FROM barangays WHERE municipality_id = ?",
    [$municipality_id],
    "i"
);
$totalPregnant = $totalPatients;

/* Age bands */
$age10_14 = age_band($conn, $municipality_id, 10, 14);
$age15_19 = age_band($conn, $municipality_id, 15, 19);
$age20_49 = age_band($conn, $municipality_id, 20, 49);

/* ---------- UI helpers ---------- */
$handle = '@' . strtolower(preg_replace('/\s+/', '', "rhu{$municipality_name}"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<style>
    :root{
        --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb;
        --sidebar-w:260px; --brand:#00a39a; --brand-dark:#0e6f6a;
    }
    *{ box-sizing:border-box }
    body{ margin:0; background:var(--bg); font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .layout{ display:grid; grid-template-columns: var(--sidebar-w) 1fr 320px; min-height:100vh; }

    /* ==== LEFT PANEL (copied style from view_pregnancies.php) ==== */
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

    /* ==== MAIN (unchanged layout) ==== */
    .main{ padding:24px; }
    .searchwrap{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--ring); border-radius:14px; padding:10px 14px; max-width:560px; }
    .searchwrap input{ border:0; outline:0; width:100%; }
    .section-title{ font-weight:800; margin:20px 0 12px; }

    .announce{ background:#fff; border:1px solid var(--ring); border-radius:20px; padding:18px; }
    .announce .bubble{ border:2px solid #e7ebef; border-radius:18px; padding:18px; }

    .report-grid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:18px; }
    .tile{
        color:#fff; border-radius:22px; padding:22px; min-height:140px;
        background: linear-gradient(135deg, var(--teal-1), var(--teal-2));
        display:flex; flex-direction:column; align-items:center; justify-content:center;
        box-shadow: 0 8px 24px rgba(16,185,129,.15);
        text-align:center;
    }
    .tile .num{ font-size:44px; font-weight:800; line-height:1; }
    .tile .sub{ opacity:.95; margin-top:8px; font-weight:600; }
    .tile .date{ opacity:.85; margin-top:4px; font-size:12px; }

    /* ==== RIGHT RAIL (unchanged) ==== */
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
</style>
</head>
<body>

<div class="layout">
    <!-- ===== Left Sidebar (now matches view_pregnancies.php) ===== -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div>RHU-MIS</div>
                <small class="text-muted"><?= h(strtoupper($municipality_name)) ?></small>
            </div>
        </div>

        <nav class="nav flex-column gap-1">
            <a class="nav-link active" href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="view_pregnancies.php"><i class="bi bi-people"></i> Patients</a>
            <a class="nav-link" href="manage_midwives.php"><i class="bi bi-person-heart"></i> Midwives</a>
            <a class="nav-link" href="manage_announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="nav-link" href="barangay_pregnancies.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
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
        <!-- Search -->
        <div class="searchwrap mb-3">
            <i class="bi bi-search text-muted"></i>
            <input type="text" placeholder="Search Here" aria-label="Search">
        </div>

        <!-- Announcements -->
        <h4 class="section-title">Announcements</h4>
        <div class="announce mb-4">
            <?php if (!empty($announcement)): ?>
                <div class="bubble">
                    <div class="fw-bold"><?= h($a_title) ?></div>

                    <?php if ($a_text !== ''): ?>
                        <div class="mt-2 text-secondary" style="white-space:pre-line;"><?= nl2br(h($a_text)) ?></div>
                    <?php endif; ?>

                    <?php if ($a_created_at): ?>
                        <div class="mt-2 small text-muted">
                            <em>Posted <?= h(date('M d, Y g:i A', strtotime($a_created_at))) ?></em>
                        </div>
                    <?php endif; ?>

                    <?php if ($ann_image && file_exists($ann_image)): ?>
                        <div class="mt-3 text-center">
                            <img src="<?= h($ann_image) ?>" alt="Announcement Image" class="img-fluid rounded shadow-sm border">
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-muted">No announcements posted yet for this municipality.</div>
            <?php endif; ?>
        </div>

        <!-- Report -->
        <div class="d-flex align-items-center justify-content-between">
            <h4 class="section-title mb-2">Report</h4>
            <button class="btn btn-sm btn-outline-secondary px-3" disabled>Filter</button>
        </div>
        <div class="report-grid">
            <div class="tile">
                <div class="num"><?= $age10_14 ?></div>
                <div class="sub">10–14 Year Old</div>
                <div class="date">As of <?= date('D, M j, Y') ?></div>
            </div>
            <div class="tile">
                <div class="num"><?= $age15_19 ?></div>
                <div class="sub">15–19 Year Old</div>
                <div class="date">As of <?= date('D, M j, Y') ?></div>
            </div>
            <div class="tile">
                <div class="num"><?= $age20_49 ?></div>
                <div class="sub">20–49 Year Old</div>
                <div class="date">As of <?= date('D, M j, Y') ?></div>
            </div>
        </div>
    </main>

    <!-- ===== Right rail (unchanged) ===== -->
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
            <div class="big"><?= $totalPatients ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?= $totalBrgyCenters ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?= $totalPregnant ?></div>
        </div>
    </aside>
</div>

</body>
</html>
