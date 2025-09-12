<?php
require 'db.php';

// Fetch data
$regions = $conn->query("SELECT * FROM regions ORDER BY name");
$provinces = $conn->query("SELECT p.*, r.name AS region_name FROM provinces p LEFT JOIN regions r ON p.region_id = r.id ORDER BY p.name");
$municipalities = $conn->query("SELECT m.*, p.name AS province_name, r.name AS region_name FROM municipalities m LEFT JOIN provinces p ON m.province_id = p.id LEFT JOIN regions r ON m.region_id = r.id ORDER BY m.name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Locations - RHU MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }
        .section-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 8px rgba(0,0,0,0.05);
            padding: 20px 25px;
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: #34495e;
        }
        .edit-btn {
            font-size: 0.85rem;
        }
    </style>
</head>
<body>

<div class="container mt-4">

    <h2 class="mb-4"><i class="bi bi-geo-alt-fill text-primary"></i> Manage Locations</h2>

    <!-- Regions -->
    <div class="section-card">
        <div class="section-title"><i class="bi bi-globe-americas"></i> Regions</div>
        <ul class="list-group">
            <?php while ($r = $regions->fetch_assoc()): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= htmlspecialchars($r['name']) ?>
                    <button class="btn btn-sm btn-outline-primary edit-btn" data-bs-toggle="modal" data-bs-target="#editRegionModal<?= $r['id'] ?>">Edit</button>
                </li>

                <!-- Modal: Edit Region -->
                <div class="modal fade" id="editRegionModal<?= $r['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form class="modal-content" method="POST" action="update_region.php">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Region</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="region_id" value="<?= $r['id'] ?>">
                                <label class="form-label">Region Name</label>
                                <input type="text" name="region_name" class="form-control" value="<?= htmlspecialchars($r['name']) ?>" required>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-success">Save</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        </ul>
    </div>

    <!-- Provinces -->
    <div class="section-card">
        <div class="section-title"><i class="bi bi-signpost-split"></i> Provinces</div>
        <ul class="list-group">
            <?php while ($p = $provinces->fetch_assoc()): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= htmlspecialchars($p['name']) ?> <span class="text-muted small">[Region: <?= htmlspecialchars($p['region_name']) ?>]</span>
                    <button class="btn btn-sm btn-outline-primary edit-btn" data-bs-toggle="modal" data-bs-target="#editProvinceModal<?= $p['id'] ?>">Edit</button>
                </li>

                <!-- Modal: Edit Province -->
                <div class="modal fade" id="editProvinceModal<?= $p['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form class="modal-content" method="POST" action="update_province.php">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Province</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="province_id" value="<?= $p['id'] ?>">
                                <label class="form-label">Province Name</label>
                                <input type="text" name="province_name" class="form-control mb-2" value="<?= htmlspecialchars($p['name']) ?>" required>
                                <label class="form-label">Region</label>
                                <select name="region_id" class="form-select" required>
                                    <?php
                                    $region_opts = $conn->query("SELECT * FROM regions ORDER BY name");
                                    while ($r = $region_opts->fetch_assoc()):
                                    ?>
                                        <option value="<?= $r['id'] ?>" <?= $r['id'] == $p['region_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($r['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-success">Save</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        </ul>
    </div>

    <!-- Municipalities -->
    <div class="section-card">
        <div class="section-title"><i class="bi bi-buildings"></i> Municipalities</div>
        <ul class="list-group">
            <?php while ($m = $municipalities->fetch_assoc()): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                        <strong><?= htmlspecialchars($m['name']) ?></strong><br>
                        <small class="text-muted">Province: <?= htmlspecialchars($m['province_name']) ?> | Region: <?= htmlspecialchars($m['region_name']) ?></small>
                    </div>
                    <button class="btn btn-sm btn-outline-primary edit-btn" data-bs-toggle="modal" data-bs-target="#editMunicipalityModal<?= $m['id'] ?>">Edit</button>
                </li>

                <!-- Modal: Edit Municipality -->
                <div class="modal fade" id="editMunicipalityModal<?= $m['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form class="modal-content" method="POST" action="update_municipality.php">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Municipality</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="municipality_id" value="<?= $m['id'] ?>">
                                <label class="form-label">Municipality Name</label>
                                <input type="text" name="municipality_name" class="form-control mb-2" value="<?= htmlspecialchars($m['name']) ?>" required>
                                <label class="form-label">Province</label>
                                <select name="province_id" class="form-select mb-2" required>
                                    <?php
                                    $province_opts = $conn->query("SELECT * FROM provinces ORDER BY name");
                                    while ($p = $province_opts->fetch_assoc()):
                                    ?>
                                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $m['province_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <label class="form-label">Region</label>
                                <select name="region_id" class="form-select" required>
                                    <?php
                                    $region_opts2 = $conn->query("SELECT * FROM regions ORDER BY name");
                                    while ($r = $region_opts2->fetch_assoc()):
                                    ?>
                                        <option value="<?= $r['id'] ?>" <?= $r['id'] == $m['region_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($r['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-success">Save</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        </ul>
    </div>

</div>

</body>
</html>
