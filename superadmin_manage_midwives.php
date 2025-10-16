<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Manila');

/* ================= Auth: Super Admin only ================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

/* ================= Helpers ================= */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_out($data){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit(); }

/* ============================================================
   Inline AJAX endpoints (JSON)
   ------------------------------------------------------------
   - ?ajax=regions
   - ?ajax=provinces&region_id=ID
   - ?ajax=municipalities&province_id=ID
   - ?ajax=barangays&municipality_id=ID
   - ?ajax=midwives&q=&region_id=&province_id=&municipality_id=
   - ?ajax=list_access&midwife_id=ID&access_month=YYYY-MM
   - POST ?ajax=toggle_access (midwife_id, barangay_id, access_month, action=grant|revoke)
   ============================================================ */
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    $ajax = $_GET['ajax'] ?? $_POST['ajax'];

    if ($ajax === 'regions') {
        $res = $conn->query("SELECT id, name FROM regions ORDER BY name");
        json_out($res->fetch_all(MYSQLI_ASSOC));
    }

    if ($ajax === 'provinces') {
        $region_id = (int)($_GET['region_id'] ?? 0);
        if ($region_id <= 0) json_out([]);
        $stmt = $conn->prepare("SELECT id, name FROM provinces WHERE region_id = ? ORDER BY name");
        $stmt->bind_param("i", $region_id);
        $stmt->execute();
        json_out($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($ajax === 'municipalities') {
        $province_id = (int)($_GET['province_id'] ?? 0);
        if ($province_id <= 0) json_out([]);
        $stmt = $conn->prepare("SELECT id, name FROM municipalities WHERE province_id = ? ORDER BY name");
        $stmt->bind_param("i", $province_id);
        $stmt->execute();
        json_out($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($ajax === 'barangays') {
        $municipality_id = (int)($_GET['municipality_id'] ?? 0);
        if ($municipality_id <= 0) json_out([]);
        $stmt = $conn->prepare("SELECT id, name FROM barangays WHERE municipality_id = ? ORDER BY name");
        $stmt->bind_param("i", $municipality_id);
        $stmt->execute();
        json_out($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($ajax === 'midwives') {
        $q              = trim($_GET['q'] ?? '');
        $region_id      = (int)($_GET['region_id'] ?? 0);
        $province_id    = (int)($_GET['province_id'] ?? 0);
        $municipality_id= (int)($_GET['municipality_id'] ?? 0);

        // NOTE: we assume a users table with role='midwife'
        // and (optionally) municipality_id for their base assignment.
        // We join to location hierarchy for filtering and display.
        $sql = "
          SELECT u.id, u.username, u.full_name, u.is_active,
                 m.id AS municipality_id, m.name AS municipality_name,
                 p.id AS province_id, p.name AS province_name,
                 r.id AS region_id, r.name AS region_name
          FROM users u
          LEFT JOIN municipalities m ON m.id = u.municipality_id
          LEFT JOIN provinces p ON p.id = m.province_id
          LEFT JOIN regions r ON r.id = p.region_id
          WHERE u.role = 'midwife'
        ";
        $types = '';
        $params = [];

        if ($q !== '') {
            $sql .= " AND (u.username LIKE ? OR u.full_name LIKE ?)";
            $like = "%$q%";
            $types .= 'ss'; $params[] = $like; $params[] = $like;
        }
        if ($region_id > 0) {
            $sql .= " AND r.id = ?";
            $types .= 'i'; $params[] = $region_id;
        }
        if ($province_id > 0) {
            $sql .= " AND p.id = ?";
            $types .= 'i'; $params[] = $province_id;
        }
        if ($municipality_id > 0) {
            $sql .= " AND m.id = ?";
            $types .= 'i'; $params[] = $municipality_id;
        }

        $sql .= " ORDER BY r.name, p.name, m.name, u.username LIMIT 500";

        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        json_out($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    if ($ajax === 'list_access') {
        $midwife_id   = (int)($_GET['midwife_id'] ?? 0);
        $access_month = $_GET['access_month'] ?? date('Y-m'); // YYYY-MM

        if ($midwife_id <= 0) json_out(['ok'=>false, 'error'=>'Invalid midwife_id']);

        // normalize to first of month
        $month_first = $access_month . '-01';

        $stmt = $conn->prepare("
            SELECT ma.barangay_id, b.name AS barangay_name, b.municipality_id
            FROM midwife_access ma
            JOIN barangays b ON b.id = ma.barangay_id
            WHERE ma.midwife_id = ? AND ma.access_month = ?
            ORDER BY b.name
        ");
        $stmt->bind_param("is", $midwife_id, $month_first);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        json_out(['ok'=>true, 'rows'=>$rows, 'month'=>$month_first]);
    }

    if ($ajax === 'toggle_access' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $midwife_id   = (int)($_POST['midwife_id'] ?? 0);
        $barangay_id  = (int)($_POST['barangay_id'] ?? 0);
        $access_month = $_POST['access_month'] ?? date('Y-m'); // YYYY-MM
        $action       = $_POST['action'] ?? '';

        if ($midwife_id<=0 || $barangay_id<=0) json_out(['ok'=>false, 'error'=>'Missing required fields']);
        $month_first = $access_month . '-01';

        // safety check: barangay must exist
        $chk = $conn->prepare("SELECT COUNT(*) c FROM barangays WHERE id=?");
        $chk->bind_param("i", $barangay_id);
        $chk->execute(); $c = (int)$chk->get_result()->fetch_assoc()['c']; $chk->close();
        if ($c===0) json_out(['ok'=>false, 'error'=>'Barangay not found']);

        if ($action === 'grant') {
            // prevent duplicates
            $stmt = $conn->prepare("
                INSERT INTO midwife_access (midwife_id, barangay_id, access_month)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE barangay_id = VALUES(barangay_id)
            ");
            $stmt->bind_param("iis", $midwife_id, $barangay_id, $month_first);
            $ok = $stmt->execute();
            $err = $ok ? '' : $stmt->error;
            $stmt->close();
            json_out(['ok'=>$ok, 'error'=>$err]);
        } elseif ($action === 'revoke') {
            $stmt = $conn->prepare("
                DELETE FROM midwife_access
                WHERE midwife_id = ? AND barangay_id = ? AND access_month = ?
            ");
            $stmt->bind_param("iis", $midwife_id, $barangay_id, $month_first);
            $ok = $stmt->execute();
            $err = $ok ? '' : $stmt->error;
            $stmt->close();
            json_out(['ok'=>$ok, 'error'=>$err]);
        } else {
            json_out(['ok'=>false, 'error'=>'Unknown action']);
        }
    }

    json_out([]);
}

/* ================= Page mode ================= */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Midwives (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
  body{ background:#ffffff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto; }
  .wrap{ max-width:1200px; margin:26px auto; padding:0 16px; }
  .panel{ border:1px solid #eef0f3; border-radius:14px; padding:16px; background:#fff; }
  .filters .form-select, .filters .form-control{ min-width: 160px; }
  .pill{ display:inline-block; padding:.25rem .55rem; border-radius:999px; font-weight:700; font-size:.8rem; }
  .pill.on{ background:#e8fff7; color:#087f5b; }
  .pill.off{ background:#fff1f2; color:#b42318; }
  .btn-gradient{ background:linear-gradient(135deg,#2fd4c8,#0fb5aa); color:#fff; border:0; }
  .btn-gradient:hover{ opacity:.95; color:#fff; }
</style>
</head>
<body>
<div class="wrap">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 style="font-family:'Merriweather',serif;"><i class="bi bi-people"></i> Manage Midwives</h3>
    <div class="d-flex gap-2">
      <!-- Placeholder for future create page -->
      <!-- <a class="btn btn-outline-primary" href="superadmin_add_midwife.php"><i class="bi bi-person-plus"></i> Add Midwife</a> -->
    </div>
  </div>

  <div class="panel mb-3">
    <div class="row g-2 align-items-end filters">
      <div class="col-md-3">
        <label class="form-label">Search</label>
        <input type="text" id="q" class="form-control" placeholder="username or name">
      </div>
      <div class="col-md-3">
        <label class="form-label">Region</label>
        <select id="region_select" class="form-select">
          <option value="">All Regions</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Province</label>
        <select id="province_select" class="form-select" disabled>
          <option value="">All Provinces</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Municipality</label>
        <select id="municipality_select" class="form-select" disabled>
          <option value="">All Municipalities</option>
        </select>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="m-0">Midwives</h6>
      <small class="text-muted" id="count_lbl">0 result(s)</small>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:70px">ID</th>
            <th>Username</th>
            <th>Full Name</th>
            <th>Region</th>
            <th>Province</th>
            <th>Municipality</th>
            <th style="width:110px">Status</th>
            <th style="width:160px" class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody id="midwives_tbody">
          <tr><td colspan="8" class="text-center text-muted py-3">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ======= Manage Access Modal ======= -->
<div class="modal fade" id="accessModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key"></i> Manage Barangay Access</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="am_midwife_id">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Month</label>
            <input type="month" id="am_month" class="form-control" value="<?= date('Y-m') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Province</label>
            <select id="am_province" class="form-select"></select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Municipality</label>
            <select id="am_muni" class="form-select"></select>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label">Barangays</label>
          <div id="am_brgy_list" class="row g-2">
            <!-- checkboxes go here -->
          </div>
        </div>
        <small class="text-muted d-block mt-2">Changes are saved immediately when you check/uncheck a barangay.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-gradient" data-bs-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const qInp   = document.getElementById('q');
const rSel   = document.getElementById('region_select');
const pSel   = document.getElementById('province_select');
const mSel   = document.getElementById('municipality_select');
const tbody  = document.getElementById('midwives_tbody');
const countLbl = document.getElementById('count_lbl');

function reset(sel, placeholder, disable=true){ sel.innerHTML = `<option value="">${placeholder}</option>`; sel.disabled = !!disable; }

/* ===== Load Regions on start ===== */
function loadRegions(){
  fetch('superadmin_manage_midwives.php?ajax=regions')
    .then(r=>r.json()).then(list=>{
      reset(rSel, 'All Regions', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; rSel.appendChild(o); });
      // Then list midwives
      loadMidwives();
    });
}
function loadProvinces(regionId){
  reset(pSel, 'All Provinces');
  reset(mSel, 'All Municipalities');
  if (!regionId){ loadMidwives(); return; }
  fetch('superadmin_manage_midwives.php?ajax=provinces&region_id='+encodeURIComponent(regionId))
    .then(r=>r.json()).then(list=>{
      reset(pSel, 'All Provinces', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; pSel.appendChild(o); });
      loadMidwives();
    });
}
function loadMunicipalities(provinceId){
  reset(mSel, 'All Municipalities');
  if (!provinceId){ loadMidwives(); return; }
  fetch('superadmin_manage_midwives.php?ajax=municipalities&province_id='+encodeURIComponent(provinceId))
    .then(r=>r.json()).then(list=>{
      reset(mSel, 'All Municipalities', false);
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; mSel.appendChild(o); });
      loadMidwives();
    });
}

/* ===== List Midwives ===== */
function loadMidwives(){
  const url = new URL('superadmin_manage_midwives.php', location.href);
  url.searchParams.set('ajax','midwives');
  if (qInp.value.trim() !== '') url.searchParams.set('q', qInp.value.trim());
  if (rSel.value) url.searchParams.set('region_id', rSel.value);
  if (pSel.value) url.searchParams.set('province_id', pSel.value);
  if (mSel.value) url.searchParams.set('municipality_id', mSel.value);

  tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3">Loading…</td></tr>`;
  fetch(url).then(r=>r.json()).then(rows=>{
    countLbl.textContent = `${rows.length} result(s)`;
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3">No midwives found.</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(r => {
      const active = (String(r.is_active) === '1' || String(r.is_active).toLowerCase() === 'true');
      const name = r.full_name ? r.full_name : '—';
      return `
        <tr>
          <td>${r.id}</td>
          <td>${r.username ?? ''}</td>
          <td>${name}</td>
          <td>${r.region_name ?? '—'}</td>
          <td>${r.province_name ?? '—'}</td>
          <td>${r.municipality_name ?? '—'}</td>
          <td>${active ? '<span class="pill on">Active</span>' : '<span class="pill off">Inactive</span>'}</td>
          <td class="text-center">
            <button class="btn btn-sm btn-outline-secondary" onclick="openAccess(${r.id}, '${(r.province_id||'')}', '${(r.municipality_id||'')}')">
              <i class="bi bi-key"></i> Access
            </button>
          </td>
        </tr>
      `;
    }).join('');
  });
}

/* ===== Access Modal ===== */
const accessModal = new bootstrap.Modal(document.getElementById('accessModal'));
const amMidwife = document.getElementById('am_midwife_id');
const amMonth   = document.getElementById('am_month');
const amProv    = document.getElementById('am_province');
const amMuni    = document.getElementById('am_muni');
const amBrgyBox = document.getElementById('am_brgy_list');

function amReset(sel, label){ sel.innerHTML = `<option value="">${label}</option>`; }

function openAccess(midwifeId, preProvinceId='', preMunicipalityId=''){
  amMidwife.value = midwifeId;
  amMonth.value = amMonth.value || '<?= date('Y-m') ?>';

  // Load provinces (all), then preselect if available
  fetch('superadmin_manage_midwives.php?ajax=provinces&region_id=' + (rSel.value || 0))
    .then(r=>r.json()).then(list=>{
      amReset(amProv, 'Select Province');
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; amProv.appendChild(o); });
      if (preProvinceId) amProv.value = String(preProvinceId);
      // Load municipalities for chosen province
      loadAMMunicipalities(amProv.value || preProvinceId, preMunicipalityId);
    });

  // When modal opens, fetch current access list to pre-check brgys once municipalities/brgys are loaded
  accessModal.show();
}

function loadAMMunicipalities(provinceId, preMunicipalityId=''){
  amReset(amMuni, 'Select Municipality');
  amBrgyBox.innerHTML = '<div class="text-muted">Choose a municipality to load barangays…</div>';
  if (!provinceId) return;
  fetch('superadmin_manage_midwives.php?ajax=municipalities&province_id=' + encodeURIComponent(provinceId))
    .then(r=>r.json()).then(list=>{
      amReset(amMuni, 'Select Municipality');
      list.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name; amMuni.appendChild(o); });
      if (preMunicipalityId) { amMuni.value = String(preMunicipalityId); loadAMBarangays(amMuni.value); }
    });
}

function loadAMBarangays(muniId){
  amBrgyBox.innerHTML = 'Loading barangays…';
  if (!muniId) { amBrgyBox.innerHTML = '<div class="text-muted">Choose a municipality…</div>'; return; }

  Promise.all([
    fetch('superadmin_manage_midwives.php?ajax=barangays&municipality_id=' + encodeURIComponent(muniId)).then(r=>r.json()),
    fetch('superadmin_manage_midwives.php?ajax=list_access&midwife_id=' + encodeURIComponent(amMidwife.value) + '&access_month=' + encodeURIComponent(amMonth.value)).then(r=>r.json())
  ]).then(([brgys, access])=>{
    const granted = new Set((access.rows || []).map(r=> String(r.barangay_id)));
    amBrgyBox.innerHTML = brgys.map(b=>{
      const checked = granted.has(String(b.id)) ? 'checked' : '';
      return `
        <div class="col-md-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="brgy_${b.id}" ${checked}
                   onchange="toggleBrgy(${b.id}, this.checked)">
            <label class="form-check-label" for="brgy_${b.id}">${b.name}</label>
          </div>
        </div>
      `;
    }).join('') || '<div class="text-muted">No barangays for this municipality.</div>';
  });
}

function toggleBrgy(barangayId, isChecked){
  const fd = new FormData();
  fd.append('ajax','toggle_access');
  fd.append('midwife_id', amMidwife.value);
  fd.append('barangay_id', barangayId);
  fd.append('access_month', amMonth.value);
  fd.append('action', isChecked ? 'grant' : 'revoke');
  fetch('superadmin_manage_midwives.php', { method:'POST', body:fd })
    .then(r=>r.json())
    .then(res=>{
      if (!res.ok) {
        alert('Failed: ' + (res.error || 'Unknown error'));
        // revert checkbox UI
        const cb = document.getElementById('brgy_'+barangayId);
        if (cb) cb.checked = !isChecked;
      }
    });
}

/* ===== Wire filters ===== */
document.addEventListener('DOMContentLoaded', loadRegions);
rSel.addEventListener('change', ()=> loadProvinces(rSel.value));
pSel.addEventListener('change', ()=> loadMunicipalities(pSel.value));
mSel.addEventListener('change', loadMidwives);
qInp.addEventListener('input', ()=> { clearTimeout(qInp._t); qInp._t = setTimeout(loadMidwives, 300); });

/* ===== Modal selects ===== */
amProv.addEventListener('change', ()=> loadAMMunicipalities(amProv.value));
amMuni.addEventListener('change', ()=> loadAMBarangays(amMuni.value));
amMonth.addEventListener('change', ()=> {
  if (amMuni.value) loadAMBarangays(amMuni.value);
});
</script>
</body>
</html>
