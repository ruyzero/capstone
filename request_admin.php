<?php
session_start();
require 'db.php';

/* ---------- Fetch data for dropdown ---------- */
$municipalities = $conn->query("
    SELECT m.id, m.name AS muni_name, p.name AS prov_name
    FROM municipalities m
    LEFT JOIN provinces p ON p.id = m.province_id
    ORDER BY p.name, m.name
");

/* ---------- Handle submit ---------- */
$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name      = trim($_POST['name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $username       = trim($_POST['username'] ?? '');
    $password_plain = trim($_POST['password'] ?? '');
    $contact_no     = trim($_POST['contact_no'] ?? '');
    $municipality_id= (int)($_POST['municipality_id'] ?? 0);
    $num_barangays  = (int)($_POST['num_barangays'] ?? 0);
    $zip_code       = trim($_POST['zip_code'] ?? '');

    if ($full_name===''||$email===''||$username===''||$password_plain===''||
        !$municipality_id||$num_barangays<=0||$zip_code==='') {
        $error = "Please complete all required fields.";
    } else {
        // Derive province_id & region_id from the chosen municipality
        $map = $conn->prepare("
            SELECT p.id AS province_id, p.region_id AS region_id
            FROM municipalities m
            JOIN provinces p ON p.id = m.province_id
            WHERE m.id = ?
            LIMIT 1
        ");
        $map->bind_param("i", $municipality_id);
        $map->execute();
        $region_province = $map->get_result()->fetch_assoc();
        $map->close();

        if (!$region_province) {
            $error = "Invalid municipality selected.";
        } else {
            $province_id = (int)$region_province['province_id'];
            $region_id   = (int)$region_province['region_id'];

            // Block duplicate username/email against USERS
            $duUsers = $conn->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
            $duUsers->bind_param("ss", $username, $email);
            $duUsers->execute();
            if ($duUsers->get_result()->num_rows > 0) {
                $duUsers->close();
                $error = "That username or email already exists.";
            } else {
                $duUsers->close();
                // Block recent requests with same username/email
                $dup = $conn->prepare("
                    SELECT id FROM pending_admin_requests
                    WHERE (username=? OR email=?) AND status IN ('pending','approved')
                    LIMIT 1
                ");
                $dup->bind_param("ss", $username, $email);
                $dup->execute();
                if ($dup->get_result()->num_rows > 0) {
                    $dup->close();
                    $error = "That username or email already has a recent request.";
                } else {
                    $dup->close();
                    // Store PLAINTEXT password here (hashing happens when approved)
                    $ins = $conn->prepare("
                        INSERT INTO pending_admin_requests
                            (municipality_id, region_id, province_id, full_name, email, username, password, contact_no, num_barangays, zip_code, status, date_requested)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    $ins->bind_param(
                        "iiisssssis",
                        $municipality_id,
                        $region_id,
                        $province_id,
                        $full_name,
                        $email,
                        $username,
                        $password_plain,
                        $contact_no,
                        $num_barangays,
                        $zip_code
                    );
                    if ($ins->execute()) {
                        $success = true;
                        $_POST = []; // clear form
                    } else {
                        $error = "Database error while saving your request.";
                    }
                    $ins->close();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Request for Admin Account</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
    :root{
        --ink:#0b0b0b; --muted:#6b7280; --accent:#0ea5a3; --border:#d1d5db; --field:#fff;
    }
    *{ box-sizing:border-box; }
    body{ background:#f6f7f8; font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, 'Noto Sans', 'Apple Color Emoji','Segoe UI Emoji'; color:var(--ink); }
    .wrap{ max-width:980px; margin:56px auto 72px; padding:0 16px; }
    .hero{ text-align:center; margin-bottom:28px; }
    .hero .icon{ width:44px; height:44px; border-radius:50%; background:#e9ecef; display:grid; place-items:center; margin:0 auto 8px; }
    .hero h1{ font-weight:800; letter-spacing:.3px; font-size:40px; line-height:1.1; margin:6px 0 6px; }
    .hero a{ color:var(--muted); text-decoration:none; font-weight:600; }
    .hero a:hover{ color:#111; text-decoration:underline; }

    .sheet{ background:#fff; border:1px solid var(--border); border-radius:16px; padding:28px; }
    .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:18px 28px; }
    @media (max-width: 820px){ .grid-2{ grid-template-columns:1fr; } }

    .label{ font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:#374151; margin-bottom:6px; font-weight:700; }
    .field{ width:100%; background:var(--field); border:1px solid #111; border-radius:6px; padding:14px 14px; font-size:15px; }
    select.field{ padding-right:36px; background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width=\"16\" height=\"16\" viewBox=\"0 0 16 16\" fill=\"none\"><path d=\"M4 6l4 4 4-4\" stroke=\"%23000\" stroke-width=\"1.6\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/></svg>'); background-repeat:no-repeat; background-position:right 12px center; appearance:none; }
    .hint{ color:var(--muted); font-size:12px; margin-top:4px; }

    .cta-wrap{ text-align:center; margin-top:26px; }
    .btn-cta{ background:#0b0b0b; border:none; color:#8be7e3; padding:14px 24px; border-radius:8px; font-weight:800; letter-spacing:.02em; min-width:220px; }
    .btn-cta:hover{ filter:brightness(1.08); }

    .alert{ border-radius:12px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div class="icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4 0-8 2-8 6v1h16v-1c0-4-4-6-8-6z" fill="#777"/></svg>
        </div>
        <h1>Request for Admin<br>Account</h1>
        <div><a href="index.php">Already Registered? Login</a></div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">Your request has been submitted. Please wait for approval.</div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="sheet" novalidate>
        <div class="grid-2">
            <!-- Left column -->
            <div>
                <label class="label">Name</label>
                <input type="text" class="field" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
            <div>
                <label class="label">Contact No.</label>
                <input type="text" class="field" name="contact_no" required value="<?= htmlspecialchars($_POST['contact_no'] ?? '') ?>">
            </div>

            <div>
                <label class="label">Email</label>
                <input type="email" class="field" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div>
                <label class="label">Municipality</label>
                <select class="field" name="municipality_id" required>
                    <option value="">Select</option>
                    <?php while ($m = $municipalities->fetch_assoc()): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= (!empty($_POST['municipality_id']) && (int)$_POST['municipality_id']===(int)$m['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['muni_name'] . ($m['prov_name'] ? " – " . $m['prov_name'] : '')) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label class="label">Username</label>
                <input type="text" class="field" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div>
                <label class="label">No. of Barangays</label>
                <input type="number" min="1" class="field" name="num_barangays" required value="<?= htmlspecialchars($_POST['num_barangays'] ?? '') ?>">
            </div>

            <div>
                <label class="label">Password</label>
                <input type="password" class="field" name="password" required value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">
                <div class="hint">This will be visible to the superadmin when approving.</div>
            </div>
            <div>
                <label class="label">ZIP Code</label>
                <input type="text" class="field" name="zip_code" required value="<?= htmlspecialchars($_POST['zip_code'] ?? '') ?>">
            </div>
        </div>

        <div class="cta-wrap">
            <button type="submit" class="btn-cta">sign up</button>
        </div>
    </form>
</div>
</body>
</html>
