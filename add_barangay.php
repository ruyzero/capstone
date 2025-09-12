<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$count_result = $conn->query("SELECT COUNT(*) AS total FROM barangays");
$total = $count_result->fetch_assoc()['total'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $total < 55) {
    $_SESSION['new_barangay_name'] = $_POST['barangay_name'];
    $_SESSION['purok_count'] = $_POST['purok_count'];
    header("Location: add_puroks.php");
    exit();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Barangay - RHU MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }
        .container {
            max-width: 600px;
            margin-top: 60px;
        }
        .card {
            border-radius: 10px;
        }
        h2 {
            font-weight: 600;
            font-size: 1.5rem;
        }
        .form-label {
            font-weight: 500;
        }
        .was-validated .form-control:invalid {
            border-color: #dc3545;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card shadow-sm border-0 p-4 bg-white">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2><i class="bi bi-house-door-fill text-primary"></i> Add New Barangay</h2>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <p class="mb-4 text-muted">
            <strong>Current Count:</strong> <?= $total; ?> of 55 barangays registered.
        </p>

        <?php if ($total < 55): ?>
        <form method="POST" class="needs-validation" novalidate>
            <div class="mb-3">
                <label class="form-label">Barangay Name</label>
                <input type="text" name="barangay_name" class="form-control" placeholder="Enter barangay name" required>
                <div class="invalid-feedback">Please enter a barangay name.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Number of Puroks</label>
                <input type="number" name="purok_count" class="form-control" min="1" max="20" placeholder="e.g. 5" required>
                <div class="invalid-feedback">Please enter a number between 1 and 20.</div>
                <small class="text-muted">Limit: 1–20 puroks</small>
            </div>

            <button type="submit" class="btn btn-success w-100">
                <i class="bi bi-arrow-right-circle"></i> Continue to Purok Setup
            </button>
        </form>
        <?php else: ?>
            <div class="alert alert-warning mt-4">
                <i class="bi bi-exclamation-triangle-fill"></i> Barangay limit of <strong>55</strong> has been reached.
                Please remove or edit an existing barangay to add a new one.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Bootstrap validation
    (() => {
        'use strict';
        const forms = document.querySelectorAll('.needs-validation');
        forms.forEach(form => {
            form.addEventListener('submit', event => {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();
</script>

</body>
</html>

</html>
