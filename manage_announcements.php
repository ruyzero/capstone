<?php
session_start();
require 'db.php';
require 'vendor/autoload.php';

use Spatie\Browsershot\Browsershot;

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Handle new announcement form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $message = $_POST['message'];
    $municipality_id = $_SESSION['municipality_id'];

    $stmt = $conn->prepare("INSERT INTO announcements (title, message, municipality_id) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $title, $message, $municipality_id);
    $stmt->execute();
    $announcement_id = $stmt->insert_id;
    $stmt->close(); 


    // Save preview as image
    $html = '
        <html>
        <head>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background-color: #ffffff;
                    margin: 0;
                    padding: 0;
                }
                .announcement-box {
                    max-width: 600px;
                    margin: 30px auto;
                    border: 5px solid #004085;
                    padding: 20px;
                    text-align: center;
                    background-color: #e9f5ff;
                    box-shadow: 0 0 10px rgba(0,0,0,0.1);
                }
                .announcement-title {
                    font-size: 24px;
                    font-weight: bold;
                    color: #004085;
                    margin-bottom: 15px;
                    text-transform: uppercase;
                }
                .announcement-body {
                    font-size: 18px;
                    color: #212529;
                    white-space: pre-wrap;
                }
                .footer {
                    margin-top: 20px;
                    font-size: 14px;
                    color: #6c757d;
                }
            </style>
        </head>
        <body>
            <div class="announcement-box">
                <div class="announcement-title">'.htmlspecialchars($title).'</div>
                <div class="announcement-body">'.nl2br(htmlspecialchars($message)).'</div>
                <div class="footer">Republic of the Philippines — RHU MIS</div>
            </div>
        </body>
        </html>
    ';

    $outputPath = __DIR__ . "/announcement_images/announcement_$announcement_id.png";

    Browsershot::html($html)
        ->setNodeBinary('C:/Program Files/nodejs/node.exe')
        ->windowSize(800, 600)
        ->save($outputPath);
}

// Fetch all announcements
$result = $conn->query("SELECT * FROM announcements ORDER BY date_created DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Announcements - RHU-MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Inter', sans-serif;
        }
        .container {
            max-width: 900px;
            margin-top: 40px;
        }
        .card-header strong {
            font-weight: 600;
        }
        .form-label {
            font-weight: 600;
        }
        .preview-box {
            border: 4px solid #004085;
            background: #e9f5ff;
            padding: 20px;
            margin-top: 20px;
        }
        .preview-title {
            font-size: 22px;
            font-weight: bold;
            color: #004085;
            text-align: center;
            text-transform: uppercase;
        }
        .preview-message {
            margin-top: 15px;
            font-size: 17px;
            white-space: pre-wrap;
            text-align: center;
        }
        .preview-footer {
            font-size: 14px;
            color: #6c757d;
            margin-top: 20px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-megaphone-fill"></i> Manage Announcements</h2>
        <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="bi bi-plus-circle-fill"></i> Create New Announcement
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" id="titleInput" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Message</label>
                    <textarea name="message" id="messageInput" rows="4" class="form-control" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send-fill"></i> Publish</button>
            </form>
        </div>
    </div>

    <div class="preview-box">
        <div class="preview-title" id="previewTitle">Preview Title</div>
        <div class="preview-message" id="previewMessage">Your message will appear here.</div>
        <div class="preview-footer">Republic of the Philippines — RHU MIS</div>
    </div>

    <h4 class="mt-5 mb-3"><i class="bi bi-journals"></i> All Announcements</h4>

    <?php if ($result->num_rows > 0): ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($row['title']); ?></strong>
                    <span class="text-muted" style="font-size: 14px;">
                        <i class="bi bi-clock"></i> <?= date('F j, Y g:i A', strtotime($row['date_created'])); ?>
                    </span>
                </div>
                <div class="card-body">
                    <p><?= nl2br(htmlspecialchars($row['message'])); ?></p>
                    <img src="announcement_images/announcement_<?= $row['id']; ?>.png" class="img-fluid border mt-2" alt="Announcement Image">
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="alert alert-info"><i class="bi bi-info-circle-fill"></i> No announcements have been posted yet.</div>
    <?php endif; ?>
</div>

<script>
    const titleInput = document.getElementById('titleInput');
    const messageInput = document.getElementById('messageInput');
    const previewTitle = document.getElementById('previewTitle');
    const previewMessage = document.getElementById('previewMessage');

    titleInput.addEventListener('input', () => {
        previewTitle.textContent = titleInput.value || "Preview Title";
    });

    messageInput.addEventListener('input', () => {
        previewMessage.textContent = messageInput.value || "Your message will appear here.";
    });
</script>

</body>
</html>
