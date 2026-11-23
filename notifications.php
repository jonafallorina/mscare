<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
require_permission('notifications');
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$filters = ['all' => 'All', 'unread' => 'Unread', 'booking' => 'Booking requests', 'cancelled' => 'Cancellations'];
$filter = isset($filters[$_GET['f'] ?? '']) ? $_GET['f'] : 'all';
$search = trim((string)($_GET['q'] ?? ''));
$per  = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$items = []; $total = 0; $db_error = null;
try {
    [$items, $total] = admin_notif_list($branch_id, $per, ($page - 1) * $per, $filter, $search);
} catch (Throwable $ex) {
    $db_error = $ex->getMessage();
}
$pages = max(1, (int)ceil($total / $per));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<script src="dialog.js?v=20261027"></script>
</head>
<body data-guard="admin">
<div class="shell">
  <div class="sidebar">
    <div class="brand">
      <?= admin_brand_mark() ?>
      <span>MSCare</span>
    </div>
    <div class="nav-group">
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item" href="treatments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Treatment History</span></a>
      <?php if (can('reports')): ?><a class="nav-item" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Revenue &amp; Reports</span></a><?php endif; ?>
      <a class="nav-item" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= htmlspecialchars($admin['display_name']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>Notifications</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <div class="card">
        <div class="toolbar">
          <div class="tabs">
            <?php foreach ($filters as $k => $label): ?>
              <a class="tab<?= $k === $filter ? ' active' : '' ?>" style="text-decoration:none;" href="notifications.php?<?= http_build_query(array_filter(['f' => $k === 'all' ? '' : $k, 'q' => $search], 'strlen')) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>
          <form method="get" class="search-box" style="margin:0;" role="search">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, service, message" aria-label="Search notifications">
          </form>
          <button type="button" class="btn btn-primary btn-sm" id="pageMarkAll">Mark all as read</button>
        </div>
        <?php if ($db_error): ?>
          <div class="notif-empty">Could not load notifications.</div>
        <?php elseif (!$items): ?>
          <div class="notif-empty" style="padding:40px;"><?= $search !== '' ? 'No notifications match &ldquo;' . e($search) . '&rdquo;.' : 'No notifications here yet.' ?></div>
        <?php else: ?>
          <div id="notifList">
            <?php foreach ($items as $it) echo admin_bell_item($it, true); ?>
          </div>
          <?php if ($pages > 1): ?>
            <div style="display:flex;justify-content:center;align-items:center;gap:14px;padding:16px;font-size:13px;">
              <?php $q = http_build_query(array_filter(['f' => $filter === 'all' ? '' : $filter, 'q' => $search], 'strlen')); $q = $q === '' ? '' : $q . '&'; ?>
              <?php if ($page > 1): ?><a class="row-link" href="notifications.php?<?= $q ?>page=<?= $page - 1 ?>">&larr; Newer</a><?php endif; ?>
              <span style="color:var(--muted);">Page <?= $page ?> of <?= $pages ?> &middot; <?= $total ?> notifications</span>
              <?php if ($page < $pages): ?><a class="row-link" href="notifications.php?<?= $q ?>page=<?= $page + 1 ?>">Older &rarr;</a><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  function post(body) {
    return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), keepalive: true }).then(function (r) { return r.json(); });
  }
  document.querySelectorAll('#notifList .notif-item[data-key]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var go = function () { location.href = a.getAttribute('href'); };
      if (!a.classList.contains('unread')) return go();
      post({ action: 'mark_notif_read', key: a.dataset.key }).then(go, go);
    });
  });
  var all = document.getElementById('pageMarkAll');
  if (all) all.addEventListener('click', function () {
    post({ action: 'mark_notif_read', all: true }).then(function () { location.reload(); });
  });
})();
</script>
</body>
</html>
