<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ===== Auth: Super Admin only ===== */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ===== Helpers ===== */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fail($msg){ $_SESSION['error'] = $msg; header("Location: superadmin_add_puroks.php"); exit(); }
function go($url){ header("Location: $url"); exit(); }

/* ===== Must come from step 1 ===== */
$barangay_name    = trim($_SESSION['new_barangay_name'] ?? '');
$purok_count      = (int)($_SESSION['purok_count'] ?? 0);
$municipality_id  = (int)($_SESSION['municipality_id'] ?? 0);
$municipality_name= $_SESSION['municipality_name'] ?? '';

if ($barangay_name === '' || $purok_count < 1 || $purok_count > 20 || $municipality_id <= 0) {
    $_SESSION['error'] = "Missing setup info. Please start again.";
    go("superadmin_add_barangay.php");
}

/* ===== POST: Create barangay + puroks ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-validate session state
    if ($barangay_name === '' || $municipality_id <= 0) fail("Missing required data from previous step.");

    // 55 limit per municipality (recheck)
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id = ?");
    $stmt->bind_param("i", $municipality_id);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($count >= 55) fail("Barangay limit of 55 for this municipality has been reached.");

    // Uniqueness of barangay name within municipality
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE municipality_id = ? AND name = ?");
    $stmt->bind_param("is", $municipality_id, $barangay_name);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($exists > 0) fail("Barangay name already exists in {$municipality_name}.");

    // Collect purok names
    $purok_names = [];
    for ($i=1; $i<= $purok_count; $i++) {
        $key = "purok_$i";
        $val = trim($_POST[$key] ?? '');
        if ($val === '') fail("Please provide a name for Purok #$i.");
        $purok_names[] = $val;
    }
    // Ensure purok names unique (case-insensitive)
    $lower = array_map('mb_strtolower', $purok_names);
    if (count($lower) !== count(array_unique($lower))) {
        fail("Purok names must be unique.");
    }

    // Transaction: insert barangay then its puroks
    $conn->begin_transaction();
    try {
        $ib = $conn->prepare("INSERT INTO barangays (name, municipality_id) VALUES (?, ?)");
        $ib->bind_param("si", $barangay_name, $municipality_id);
        if (!$ib->execute()) throw new Exception("Barangay insert failed: ".$ib->error);
        $barangay_id = $ib->insert_id;
        $ib->close();

        $ip = $conn->prepare("INSERT INTO puroks (name, barangay_id) VALUES (?, ?)");
        foreach ($purok_names as $pn) {
            $ip->bind_param("si", $pn, $barangay_id);
            if (!$ip->execute()) throw new Exception("Purok insert failed: ".$ip->error);
        }
        $ip->close();

        $conn->commit();

        // Clear step state
        unset($_SESSION['new_barangay_name'], $_SESSION['purok_count'], $_SESSION['municipality_id'], $_SESSION['municipality_name']);

        $_SESSION['success'] = "Barangay '{$barangay_name}' created with {$purok_count} purok(s) in {$municipality_name}.";
        go("superadmin_barangay_health_centers.php?municipality_id={$municipality_id}");

    } catch (Throwable $e) {
        $conn->rollback();
        fail("Error saving: " . $e->getMessage());
    }
}

/* ===== Flash messages ===== */
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);
$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);

// Default names e.g. Purok 1..N
$defaults = [];
for ($i=1; $i<= $purok_count; $i++) $defaults[$i] = "Purok {$i}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup Puroks (Super Admin) • RHU MIS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body { background:#f4f6f9; font-family:'Segoe UI', system-ui, -apple-system; }
  .container { max-width: 760px; margin-top: 50px; }
  .card { border-radius: 10px; }
  .muted { color:#6b7280; }
  .was-validated .form-control:invalid { border-color:#dc3545; }
</style>
</head>
<body>

<div class="container">
  <div class="card shadow-sm border-0 p-4 bg-white">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="m-0"><i class="bi bi-diagram-3 text-success"></i> Setup Puroks</h2>
      <div class="d-flex gap-2">
        <a href="superadmin_add_barangay.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
        <a href="superadmin_barangay_health_centers.php" class="btn btn-outline-dark btn-sm">Cancel</a>
      </div>
    </div>

    <p class="mb-2 muted">
      <strong>Barangay:</strong> <?= h($barangay_name) ?> &nbsp;•&nbsp;
      <strong>Municipality:</strong> <?= h($municipality_name ?: '—') ?> &nbsp;•&nbsp;
      <strong>Puroks to create:</strong> <?= (int)$purok_count ?>
    </p>

    <?php if ($flash_error): ?>
      <div class="alert alert-danger"><?= h($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_success): ?>
      <div class="alert alert-success"><?= h($flash_success) ?></div>
    <?php endif; ?>

    <form method="POST" class="needs-validation" novalidate>
      <div class="row g-3">
        <?php for ($i=1; $i<= $purok_count; $i++): ?>
          <div class="col-md-6">
            <label class="form-label">Purok <?= $i ?> Name</label>
            <input type="text" name="purok_<?= $i ?>" class="form-control" value="<?= h($defaults[$i]) ?>" required maxlength="100">
            <div class="invalid-feedback">Please enter a name for Purok <?= $i ?>.</div>
          </div>
        <?php endfor; ?>
      </div>

      <hr class="my-4">

      <div class="text-end">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-check2-circle"></i> Create Barangay & Puroks
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Bootstrap validation
(() => {
  'use strict';
  const form = document.querySelector('.needs-validation');
  form.addEventListener('submit', event => {
    if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
    form.classList.add('was-validated');
  }, false);
})();
</script>

</body>
</html>
