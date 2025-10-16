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

$TABLE = 'announcements';

/* ---------- Discover columns (content/title/created/author) ---------- */
function detect_announcement_columns(mysqli $conn, string $table): array {
    $exists = [];
    $res = $conn->query("SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($r = $res->fetch_assoc()) $exists[$r['Field']] = true;
    }

    $pick = function(array $cands) use ($exists){
        foreach($cands as $c) if (isset($exists[$c])) return $c;
        return null;
    };

    return [
        'id'         => isset($exists['id']) ? 'id' : null, // required
        'title'      => $pick(['title','subject','heading']),
        'content'    => $pick(['content','message','body','details','text']),
        'created_at' => $pick(['created_at','created_on','date_created','posted_at','date','created']),
        'created_by' => $pick(['created_by','author_id','user_id']),
        'is_active'  => $pick(['is_active','active','status']), // optional
    ];
}
$cols = detect_announcement_columns($conn, $TABLE);
if (!$cols['id']) { die("Announcements table must have a primary key column named 'id'."); }

/* ================= Inline JSON endpoints =================
   GET  ?ajax=list&q=&limit=&offset=
   POST ?ajax=delete (ids[]=..,ids[]=..)
   ======================================================== */
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    $ajax = $_GET['ajax'] ?? $_POST['ajax'];

    if ($ajax === 'list') {
        $q      = trim($_GET['q'] ?? '');
        $limit  = max(1, min(500, (int)($_GET['limit'] ?? 200)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        // Build SELECT list based on detected columns
        $sel = ["a.`{$cols['id']}` AS id"];
        $sel[] = $cols['title']      ? "a.`{$cols['title']}` AS title"           : "'' AS title";
        $sel[] = $cols['content']    ? "a.`{$cols['content']}` AS content"       : "'' AS content";
        $sel[] = $cols['created_at'] ? "a.`{$cols['created_at']}` AS created_at" : "NULL AS created_at";
        $sel[] = $cols['is_active']  ? "a.`{$cols['is_active']}` AS is_active"   : "NULL AS is_active";

        $join = '';
        if ($cols['created_by']) {
            $sel[] = "a.`{$cols['created_by']}` AS created_by";
            // Try to pull username from users table (best effort)
            $join = "LEFT JOIN users u ON u.id = a.`{$cols['created_by']}`";
            $sel[] = "COALESCE(u.username, u.full_name, CONCAT('User #', a.`{$cols['created_by']}`)) AS author";
        } else {
            $sel[] = "NULL AS created_by";
            $sel[] = "NULL AS author";
        }

        $sql = "SELECT ".implode(', ', $sel)." FROM `$TABLE` a $join WHERE 1=1";
        $types = ''; $params = [];

        if ($q !== '' && $cols['title']) {
            $sql .= " AND a.`{$cols['title']}` LIKE ?";
            $types .= 's'; $params[] = '%'.$q.'%';
        }

        // Order: newest first (if created_at exists), else by id desc
        if ($cols['created_at']) $sql .= " ORDER BY a.`{$cols['created_at']}` DESC";
        else                     $sql .= " ORDER BY a.`{$cols['id']}` DESC";

        $sql .= " LIMIT ? OFFSET ?";
        $types .= 'ii'; $params[] = $limit; $params[] = $offset;

        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        json_out(['ok'=>true, 'rows'=>$rows]);
    }

    if ($ajax === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || !count($ids)) json_out(['ok'=>false, 'error'=>'No IDs provided.']);

        // Sanitize to integers
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
        if (!count($ids)) json_out(['ok'=>false, 'error'=>'No valid IDs.']);

        // Build placeholders
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $sql = "DELETE FROM `$TABLE` WHERE `{$cols['id']}` IN ($ph)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$ids);
        $ok = $stmt->execute();
        $err = $ok ? '' : $stmt->error;
        $stmt->close();

        json_out(['ok'=>$ok, 'error'=>$err, 'deleted'=>count($ids)]);
    }

    json_out(['ok'=>false, 'error'=>'Unknown action.']);
}

/* ================= Page (HTML) ================= */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Announcements (Super Admin) • RHU-MIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
  body{ background:#ffffff; font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto; }
  .wrap{ max-width:1100px; margin:26px auto; padding:0 16px; }
  .panel{ border:1px solid #eef0f3; border-radius:14px; padding:16px; background:#fff; }
  .pill{ display:inline-block; padding:.2rem .55rem; border-radius:999px; font-weight:700; font-size:.8rem; }
  .pill.on{ background:#e8fff7; color:#087f5b; }
  .pill.off{ background:#fff1f2; color:#b42318; }
  .table > :not(caption) > * > * { vertical-align: middle; }
  .content-preview{ max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
</style>
</head>
<body>
<div class="wrap">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 style="font-family:'Merriweather',serif;"><i class="bi bi-megaphone"></i> Manage Announcements</h3>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-danger" id="bulkDeleteBtn" disabled>
        <i class="bi bi-trash"></i> Delete Selected
      </button>
    </div>
  </div>

  <div class="panel mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Search title</label>
        <input type="text" id="q" class="form-control" placeholder="Type to filter…">
      </div>
      <div class="col-md-6 text-end">
        <small class="text-muted" id="count_lbl">0 result(s)</small>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:34px"><input type="checkbox" id="check_all"></th>
            <th style="width:70px">ID</th>
            <th>Title</th>
            <th>Content</th>
            <th style="width:160px">Created</th>
            <th style="width:130px">Author</th>
            <th style="width:110px">Status</th>
            <th style="width:130px" class="text-center">Action</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <tr><td colspan="8" class="text-center text-muted py-3">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-megaphone"></i> Announcement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h5 id="vm_title" class="mb-2"></h5>
        <div class="text-muted small mb-3" id="vm_meta"></div>
        <div id="vm_content" style="white-space:pre-wrap;"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const qInp   = document.getElementById('q');
const tbody  = document.getElementById('tbody');
const countLbl = document.getElementById('count_lbl');
const checkAll = document.getElementById('check_all');
const bulkBtn  = document.getElementById('bulkDeleteBtn');

let rowsCache = [];

function fetchList(){
  const url = new URL('superadmin_manage_announcements.php', location.href);
  url.searchParams.set('ajax','list');
  const q = qInp.value.trim();
  if (q) url.searchParams.set('q', q);

  tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3">Loading…</td></tr>`;
  fetch(url).then(r=>r.json()).then(res=>{
    rowsCache = res.rows || [];
    render(rowsCache);
  });
}

function render(rows){
  countLbl.textContent = `${rows.length} result(s)`;
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3">No announcements found.</td></tr>`;
    bulkBtn.disabled = true;
    checkAll.checked = false;
    return;
  }
  tbody.innerHTML = rows.map(r=>{
    const created = r.created_at ? new Date(r.created_at).toLocaleString() : '—';
    const active  = (r.is_active === null || typeof r.is_active === 'undefined') ? null
                   : (String(r.is_active) === '1' || String(r.is_active).toLowerCase() === 'active' || String(r.is_active).toLowerCase() === 'true');
    const statusHtml = (active === null) ? '<span class="text-muted">—</span>'
                     : (active ? '<span class="pill on">Active</span>' : '<span class="pill off">Inactive</span>');
    const title = (r.title || '').toString();
    const content = (r.content || '').toString();

    return `
      <tr>
        <td><input type="checkbox" class="rowchk" value="${r.id}" onchange="toggleBulkBtn()"></td>
        <td>${r.id}</td>
        <td>${escapeHtml(title)}</td>
        <td class="content-preview">${escapeHtml(content)}</td>
        <td>${escapeHtml(created)}</td>
        <td>${escapeHtml(r.author || '')}</td>
        <td>${statusHtml}</td>
        <td class="text-center">
          <button class="btn btn-sm btn-outline-secondary me-1" onclick='openView(${r.id})'><i class="bi bi-eye"></i></button>
          <button class="btn btn-sm btn-outline-danger" onclick='confirmDelete([${r.id}])'><i class="bi bi-trash"></i></button>
        </td>
      </tr>
    `;
  }).join('');
  bulkBtn.disabled = true;
  checkAll.checked = false;
}

function escapeHtml(s){
  return s.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

/* ===== View modal ===== */
const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
const vmTitle = document.getElementById('vm_title');
const vmMeta  = document.getElementById('vm_meta');
const vmContent = document.getElementById('vm_content');

function openView(id){
  const r = rowsCache.find(x=>String(x.id)===String(id));
  if (!r) return;
  vmTitle.textContent = r.title || '(No title)';
  vmMeta.textContent  = (r.author ? 'By '+r.author+' • ' : '') + (r.created_at || '');
  vmContent.textContent = r.content || '';
  viewModal.show();
}

/* ===== Delete ===== */
function confirmDelete(ids){
  if (!ids || !ids.length) return;
  if (!confirm('Delete selected announcement(s)? This cannot be undone.')) return;

  const fd = new FormData();
  fd.append('ajax','delete');
  ids.forEach(id => fd.append('ids[]', id));

  fetch('superadmin_manage_announcements.php', { method:'POST', body:fd })
    .then(r=>r.json())
    .then(res=>{
      if (!res.ok) { alert('Failed to delete: ' + (res.error || 'Unknown error')); return; }
      fetchList();
    });
}

/* ===== Bulk select ===== */
checkAll.addEventListener('change', ()=>{
  document.querySelectorAll('.rowchk').forEach(cb => cb.checked = checkAll.checked);
  toggleBulkBtn();
});

function selectedIds(){
  const ids = [];
  document.querySelectorAll('.rowchk:checked').forEach(cb => ids.push(cb.value));
  return ids;
}
function toggleBulkBtn(){
  bulkBtn.disabled = selectedIds().length === 0;
}
bulkBtn.addEventListener('click', ()=> confirmDelete(selectedIds()));

/* ===== Search typing debounce ===== */
qInp.addEventListener('input', ()=>{
  clearTimeout(qInp._t);
  qInp._t = setTimeout(fetchList, 300);
});

/* ===== Init ===== */
document.addEventListener('DOMContentLoaded', fetchList);
</script>
</body>
</html>
