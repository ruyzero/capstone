<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "Invalid request.";
    exit();
}

$pregnancy_id = (int) $_GET['id'];

$stmt = $conn->prepare("
    SELECT 
        p.*,
        b.name  AS barangay,
        pr.name AS purok,
        m.name  AS municipality,
        prov.name AS province,
        ru.username AS registered_by_name,
        au.username AS assigned_midwife_name
    FROM pregnant_women p
    LEFT JOIN barangays b       ON p.barangay_id = b.id
    LEFT JOIN puroks pr         ON p.purok_id = pr.id
    LEFT JOIN municipalities m  ON b.municipality_id = m.id
    LEFT JOIN provinces prov    ON m.province_id = prov.id
    LEFT JOIN users ru          ON p.registered_by = ru.id
    LEFT JOIN users au          ON p.assigned_midwife_id = au.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $pregnancy_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo "Record not found.";
    exit();
}

$r = $res->fetch_assoc();

function val($x, $fallback = 'N/A') {
    if ($x === null) return $fallback;
    $x = trim((string)$x);
    return $x === '' ? $fallback : htmlspecialchars($x);
}

$full_name = trim(implode(' ', array_filter([
    $r['first_name'] ?? '', $r['middle_name'] ?? '', $r['last_name'] ?? '', $r['suffix'] ?? ''
])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Patient Information • RHU-MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
    :root { --teal:#18b2a8; }
    body{ background:#ffffff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial; }
    .wrap{ max-width:980px; margin:48px auto; padding:0 16px; }
    .title{ font-family:'Merriweather', serif; font-weight:700; text-align:center; color:#2b2f36; margin-bottom:28px; }
    .mint-dot{ width:14px; height:14px; border-radius:50%; background:var(--teal); position:absolute; left:18px; top:22px; }
    .section{ padding:16px 6px; }
    .divider{ border-bottom:1px solid #dde3ea; margin:14px 0 8px; }
    .lbl{ font-weight:700; color:#111827; }
    .item{ margin-bottom:6px; }
    .card-like{ border:1px solid #e7ecf2; border-radius:14px; padding:18px 22px; background:#fff; box-shadow: 0 2px 0 rgba(16,24,40,.02); }
    .update-btn{ background:var(--teal); border:none; color:#fff; padding:.55rem 1.1rem; border-radius:999px; font-weight:700; }
    .update-btn:hover{ background:#0f8f88; color:#fff; }
</style>
</head>
<body>

<div class="wrap position-relative">
    <div class="mint-dot"></div>
    <h2 class="title">View Patient Information</h2>

    <div class="card-like">

        <!-- Personal info -->
        <div class="row">
            <div class="col-md-6 section">
                <div class="item"><span class="lbl">Name:</span> <?= val($full_name) ?></div>
                <div class="item"><span class="lbl">Sex:</span> <?= val($r['sex']) ?></div>
                <div class="item"><span class="lbl">Birthdate:</span> <?= val($r['dob']) ?></div>
                <div class="item"><span class="lbl">Birthplace:</span> <?= val($r['birthplace']) ?></div>
                <div class="item"><span class="lbl">Blood Type:</span> <?= val($r['blood_type']) ?></div>
                <div class="item"><span class="lbl">Civil Status:</span> <?= val($r['civil_status']) ?></div>
            </div>

            <div class="col-md-6 section">
                <div class="item"><span class="lbl">Spouse’s Name:</span> <?= val($r['spouse_name']) ?></div>
                <div class="item"><span class="lbl">Mother’s Name:</span> <?= val($r['mother_name']) ?></div>
                <div class="item"><span class="lbl">Education:</span> <?= val($r['education']) ?></div>
                <div class="item"><span class="lbl">Employment:</span> <?= val($r['employment']) ?></div>
                <div class="item"><span class="lbl">Contact Number:</span> <?= val($r['contact']) ?></div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Address & Monitoring -->
        <div class="row">
            <div class="col-md-6 section">
                <div class="mb-2 fw-semibold">Residential Address</div>
                <div class="item">Purok: <?= val($r['purok']) ?></div>
                <div class="item">Barangay: <?= val($r['barangay']) ?></div>
                <div class="item">Municipality: <?= val($r['municipality']) ?></div>
                <div class="item">Province: <?= val($r['province'] ?? 'Misamis Occidental') ?></div>
            </div>

            <div class="col-md-6 section">
                <div class="mb-2 fw-semibold">Monitoring Information</div>
                <div class="item">Registered By: <?= val($r['registered_by_name']) ?></div>
                <div class="item">Assigned Midwife: <?= val($r['assigned_midwife_name']) ?></div>
                <div class="item">Monitoring Status: <?= val($r['status']) ?></div>
                <div class="item">PhilHealth Type: <?= val($r['status_type']) ?></div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Socio-Economic -->
        <div class="section">
            <div class="mb-2 fw-semibold">Socio–Economic Information</div>
            <div class="item">DSWD NHTS: <?= val($r['dswd_nhts']) ?></div>
            <div class="item">4Ps Member: <?= val($r['four_ps']) ?></div>
            <div class="item">Household Number: <?= val($r['household_no']) ?></div>
            <div class="item">PhilHealth Member: <?= val($r['philhealth_member']) ?></div>
            <div class="item">PhilHealth Number: <?= val($r['philhealth_no']) ?></div>
            <div class="item">PCB Member: <?= val($r['pcb_member']) ?></div>
            <div class="item">PhilHealth Category: <?= val($r['philhealth_category']) ?></div>
        </div>

        <div class="text-end mt-2">
            <a href="edit_pregnancy.php?id=<?= (int)$r['id']; ?>" class="btn update-btn">Update</a>
        </div>

    </div>
</div>

</body>
</html>
