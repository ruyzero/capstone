<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | RHU-MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            width: 100%;
            max-width: 400px;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .logo {
            display: block;
            margin: 0 auto 20px;
            width: 80px;
            height: 80px;
        }
    </style>
</head>
<body>

<div class="login-box">
    <div class="text-center">
        <img src="logo.png" alt="RHU Logo" class="logo">
        <h4 class="mb-4">RHU-MIS System</h4>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="d-grid gap-2">
        <a href="login_form.php" class="btn btn-primary">Login</a>
        <a href="request_admin.php" class="btn btn-outline-secondary">Request</a>
    </div>
</div>

</body>
</html>
