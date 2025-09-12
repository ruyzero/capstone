<?php
session_start();
require 'db.php'; // Make sure you have db connection here

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $municipality_name = trim($_POST['municipality_name']);
    $province = 'Misamis Occidental';

    if (empty($municipality_name)) {
        $_SESSION['error'] = 'Please select a municipality.';
        header("Location: setup_municipality.php");
        exit();
    }

    // Check if the municipality already exists
    $check = $conn->prepare("SELECT id FROM municipalities WHERE name = ?");
    $check->bind_param("s", $municipality_name);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $_SESSION['error'] = 'This municipality is already registered.';
        header("Location: setup_municipality.php");
        exit();
    }

    // Insert new municipality
    $stmt = $conn->prepare("INSERT INTO municipalities (name, province) VALUES (?, ?)");
    $stmt->bind_param("ss", $municipality_name, $province);
    $stmt->execute();

    $municipality_id = $stmt->insert_id;

    // Optional: Create default admin for the municipality
    $default_admin_username = strtolower(str_replace(' ', '_', $municipality_name)) . '_admin';
    $default_password = password_hash('admin123', PASSWORD_DEFAULT);

    $admin = $conn->prepare("INSERT INTO users (username, password, role, municipality_id) VALUES (?, ?, 'admin', ?)");
    $admin->bind_param("ssi", $default_admin_username, $default_password, $municipality_id);
    $admin->execute();

    $_SESSION['success'] = "Municipality registered successfully. Default admin: $default_admin_username / admin123";
    header("Location: login_form.php");
    exit();
} else {
    header("Location: setup_municipality.php");
    exit();
}
