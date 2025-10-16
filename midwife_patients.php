<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ---------- Auth ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'midwife') {
    header("Location: index.php"); exit();
}

$midwife_id      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$username        = isset($_SESSION['username']) ? $_SESSION['username'] : 'midwife';
$municipality_id = isset($_SESSION['municipality_id']) ? (int)$_SESSION['municipality_id'] : 0;
$current_month_exact = date('Y-m-01'); // for DATE fields saved as first of month
$current_month_like  = date('Y-m');    // for VARCHAR 'YYYY-MM'

/* ---------- Helpers ---------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ints(array $a){ return array_values(array_filter(array_map('intval',$a), function($x){return $x>0;})); }

/* ---------- Block inactive accounts & get municipality if missing ---------- */
$st = $conn->prepare("SELECT is_active, municipality_id FROM users WHERE id = ? LIMIT 1");
$st->bind_param("i", $midwife_id);
$st->execute();
$u = $st->get_result()->fetch_assoc();
$st->close();

if (!$u || (int)$u['is_active'] !== 1) {
    session_destroy();
    echo "<script>alert('Your account is not yet activated. Please contact the administrator.');window.location.href='index.php';</script>";
    exit();
}
if ($municipality_id === 0) { $municipality_id = isset($u['municipality_id']) ? (int)$u['municipality_id'] : 0; }

/* ---------- Location labels ---------- */
$loc_stmt = $conn->prepare("
    SELECT m.name AS municipality, p.name AS province, r.name AS region
    FROM municipalities m
    LEFT JOIN provinces p ON m.province_id = p.id
    LEFT JOIN regions r   ON p.region_id = r.id
    WHERE m.id = ?
");
$loc_stmt->bind_param("i", $municipality_id);
$loc_stmt->execute();
$location = $loc_stmt->get_result()->fetch_assoc();
$loc_stmt->close();

$municipality_name = $location ? $location['municipality'] : 'Unknown';

/* ---------- Assigned barangays for THIS month (flexible match) ---------- */
$access_ids = array();

/* Exact date match first */
$a1 = $conn->prepare("SELECT barangay_id FROM midwife_access WHERE midwife_id = ? AND access_month = ?");
$a1->bind_param("is", $midwife_id, $current_month_exact);
$a1->execute();
$r1 = $a1->get_result();
while ($row = $r1->fetch_assoc()) { $access_ids[] = (int)$row['barangay_id']; }
$a1->close();

/* If none, try LIKE 'YYYY-MM%' (covers VARCHAR month or any date within month) */
if (empty($access_ids)) {
    $like = $current_month_like . '%';
    $a2 = $conn->prepare("SELECT barangay_id FROM midwife_access WHERE midwife_id = ? AND access_month LIKE ?");
    $a2->bind_param("is", $midwife_id, $like);
    $a2->execute();
    $r2 = $a2->get_result();
    while ($row = $r2->fetch_assoc()) { $access_ids[] = (int)$row['barangay_id']; }
    $a2->close();
}
$access_ids = ints($access_ids);

/* ---------- Barangays + per-barangay patient counts (municipality-wide) ---------- */
$bar = $conn->prepare("
    SELECT b.id, b.name, 
           COUNT(p.id) AS total_patients
    FROM barangays b
    LEFT JOIN pregnant_women p
      ON p.barangay_id = b.id AND UPPER(p.sex)='FEMALE'
    WHERE b.municipality_id = ?
    GROUP BY b.id, b.name
    ORDER BY b.name ASC
");
$bar->bind_param("i", $municipality_id);
$bar->execute();
$barangays_rs = $bar->get_result();

/* ---------- Right-rail totals (municipality-wide) ---------- */
$tot_pat_stmt = $conn->prepare("
    SELECT COUNT(p.id)
    FROM pregnant_women p
    INNER JOIN barangays b ON b.id = p.barangay_id
    WHERE b.municipality_id = ? AND UPPER(p.sex)='FEMALE'
");
$tot_pat_stmt->bind_param("i", $municipality_id);
$tot_pat_stmt->execute();
$tot_pat = $tot_pat_stmt->get_result()->fetch_row();
$tot_patients = $tot_pat ? (int)$tot_pat[0] : 0;
$tot_pat_stmt->close();

$tot_brgy_stmt = $conn->prepare("SELECT COUNT(*) FROM barangays WHERE municipality_id = ?");
$tot_brgy_stmt->bind_param("i", $municipality_id);
$tot_brgy_stmt->execute();
$tot_b = $tot_brgy_stmt->get_result()->fetch_row();
$total_brgy_centers = $tot_b ? (int)$tot_b[0] : 0;
$tot_brgy_stmt->close();

$total_pregnant = $tot_patients;

/* ---------- UI helpers ---------- */
$handle = '@' . strtolower(preg_replace('/\s+/', '', "midwife{$municipality_name}"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patients - RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<style>
    :root{
        --teal-1:#20C4B2; --teal-2:#1A9E9D; --bg:#f5f7fa; --ring:#e5e7eb;
        --sidebar-w:260px;
    }
    *{ box-sizing:border-box }
    body{ margin:0; background:var(--bg); font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
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
        font-family:'Merriweather', serif; font-weight:700; color:#111;
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
    .main{ padding:24px; }
    .searchwrap{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--ring); border-radius:14px; padding:10px 14px; }
    .searchwrap input{ border:0; outline:0; width:100%; }
    .section-title{ font-weight:800; margin:20px 0 12px; }

    /* Barangay grid cards */
    .grid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:18px; }
    @media (max-width: 1100px){ .layout{ grid-template-columns: var(--sidebar-w) 1fr; } .rail{ grid-column:1 / -1; } .grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 640px){ .grid{ grid-template-columns: 1fr; } }

    .brgy-card{
        background:#fff; border:2px solid #e7ebef; border-radius:22px; padding:20px;
        display:flex; flex-direction:column; justify-content:center; align-items:center;
        transition:transform .15s ease, box-shadow .15s ease;
    }
    .brgy-card:hover{ transform: translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.06); }
    .brgy-name{ font-weight:800; color:#066f6a; font-size:1.2rem; }
    .brgy-sub{ color:#64748b; font-weight:600; font-size:.9rem; margin-top:4px; }
    .brgy-num{ font-size:42px; font-weight:800; line-height:1; color:#111827; margin-top:2px; }
    .brgy-lock{ margin-top:6px; color:#e11d48; } /* red lock */

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

    a.card-link{ text-decoration:none; color:inherit; }
    a.card-link.disabled{ pointer-events:none; cursor:default; }
</style>
</head>
<body>

<div class="layout">
    <!-- Left Sidebar -->
    <aside class="leftbar">
        <div class="brand">
            <div class="mark">R</div>
            <div>
                <div>RHU-MIS</div>
                <small class="text-muted"><?php echo h(strtoupper($municipality_name)); ?></small>
            </div>
        </div>

        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="midwife_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link active" href="midwife_patients.php"><i class="bi bi-files"></i> Patients</a>
            <a class="nav-link" href="barangay_health_centers.php"><i class="bi bi-hospital"></i> Brgy. Health Centers</a>
            <a class="nav-link" href="prenatal_monitoring.php"><i class="bi bi-clipboard2-pulse"></i> Prenatal Monitoring</a>
            <a class="nav-link" href="request_data_transfer.php"><i class="bi bi-arrow-left-right"></i> Request Data Transfer</a>
            <hr>
            <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="main">
        <!-- Search -->
        <div class="searchwrap mb-3">
            <i class="bi bi-search text-muted"></i>
            <input type="text" placeholder="Search Here" aria-label="Search" id="searchInput">
        </div>

        <h4 class="section-title">Patients</h4>

        <div class="grid" id="brgyGrid">
            <?php
            // Loop barangays with counts
            $barangays = array();
            while ($row = $barangays_rs->fetch_assoc()) { $barangays[] = $row; }

            foreach ($barangays as $b) {
                $bid   = (int)$b['id'];
                $name  = $b['name'];
                $count = (int)$b['total_patients'];
                $hasAccess = in_array($bid, $access_ids, true);

                if ($hasAccess) {
                    echo '<a class="card-link" href="midwife_barangay_records.php?barangay_id='.$bid.'">';
                } else {
                    echo '<a class="card-link disabled" href="#">';
                }
                echo '<div class="brgy-card">';
                echo '  <div class="brgy-name">'.h($name).'</div>';
                echo '  <div class="brgy-sub">Total Patient Record</div>';
                echo '  <div class="brgy-num">'.$count.'</div>';
                if (!$hasAccess) {
                    echo '  <div class="brgy-lock"><i class="bi bi-lock-fill"></i></div>';
                }
                echo '</div>';
                echo '</a>';
            }
            ?>
        </div>
    </main>

    <!-- Right rail -->
    <aside class="rail">
        <div class="profile">
            <div class="avatar"><i class="bi bi-person"></i></div>
            <div>
                <div class="fw-bold"><?php echo h($username); ?></div>
                <div class="text-muted small"><?php echo h('@'.strtolower(preg_replace('/\s+/', '', "midwife{$municipality_name}"))); ?></div>
            </div>
        </div>

        <div class="stat">
            <div class="label">Total Patient Record</div>
            <div class="big"><?php echo $tot_patients; ?></div>
        </div>

        <div class="stat gradient">
            <div class="label">Total Brgy. Health Center</div>
            <div class="big"><?php echo $total_brgy_centers; ?></div>
        </div>

        <div class="stat">
            <div class="label">Total Pregnant Patient</div>
            <div class="big"><?php echo $total_pregnant; ?></div>
        </div>
    </aside>
</div>

<script>
// simple client-side filter for the grid titles
const input = document.getElementById('searchInput');
const grid  = document.getElementById('brgyGrid');
if (input && grid){
    input.addEventListener('input', function(){
        const q = this.value.toLowerCase();
        [].slice.call(grid.querySelectorAll('.brgy-card')).forEach(function(card){
            const name = card.querySelector('.brgy-name').textContent.toLowerCase();
            card.parentElement.style.display = name.indexOf(q) !== -1 ? '' : 'none';
        });
    });
}
</script>
</body>
</html>
