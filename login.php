<?php
session_start();
require 'db.php'; // Ensure this sets $conn = new mysqli(...)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Check if user exists
    $stmt = $conn->prepare("SELECT 
                                u.id, 
                                u.username, 
                                u.password, 
                                u.role, 
                                u.municipality_id, 
                                u.province_id,
                                m.name AS municipality_name,
                                p.name AS province_name
                            FROM users u
                            LEFT JOIN municipalities m ON u.municipality_id = m.id
                            LEFT JOIN provinces p ON u.province_id = p.id
                            WHERE u.username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // Login success
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['municipality_id'] = $user['municipality_id'];
            $_SESSION['municipality_name'] = $user['municipality_name'];
            $_SESSION['province_id'] = $user['province_id'];
            $_SESSION['province_name'] = $user['province_name'];

            // Role-based redirect
            if ($user['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } elseif ($user['role'] === 'midwife') {
                header("Location: midwife_dashboard.php");
            } elseif ($user['role'] === 'super_admin') {
                header("Location: super_admin_dashboard.php");
            } else {
                $_SESSION['error'] = "Unknown user role.";
                header("Location: login_form.php");
            }
            exit();
        } else {
            $_SESSION['error'] = "Incorrect password.";
            header("Location: login_form.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "User not found.";
        header("Location: login_form.php");
        exit();
    }
} else {
    header("Location: login_form.php");
    exit();
}
