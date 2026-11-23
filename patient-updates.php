<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$items = []; $db_error = null;
try {
    $s = db()->prepare("SELECT id, title, message, is_read, created_at FROM notifications WHERE patient_id = :p ORDER BY created_at DESC, id DESC LIMIT 100");
    $s->execute([':p' => $pid]);
    $items = $s->fetchAll();
} catch (PDOException $ex) {
    error_log('MSCare DB (updates): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your updates right now.');
}
$unread = 0; foreach ($items as $n) { if (!$n['is_read'] || $n['is_read'] === 'f') $unread++; }

$ptitle = 'Updates';
$psub   = 'Messages from the clinic about your bookings and records';
$pactive = 'updates';
include __DIR__ . '/includes/portal_head.php';
?>
<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>

<div class="p-card">
  <div class="p-card-head">
    <h2>Updates</h2>
    <?php if ($unread): ?><button type="button" class="btn btn-outline btn-pill btn-sm" id="markAllBtn">Mark all as read</button><?php endif; ?>
  </div>
  <?php if (!$items): ?>
    <div class="p-empty"><strong>You&rsquo;re all caught up</strong>Updates about appointments, signatures and messages will appear here.</div>
  <?php else: foreach ($items as $n):
      $isUnread = !$n['is_read'] || $n['is_read'] === 'f';
      $href = stripos((string)$n['title'], 'signature') !== false || stripos((string)$n['title'], 'consent') !== false ? 'patient-record.php#consent' : 'patient-appointments.php'; ?>
    <a class="upd-row<?= $isUnread ? ' unread' : '' ?>" href="<?= $href ?>" data-id="<?= (int)$n['id'] ?>">
      <span class="upd-dot"></span>
      <span class="upd-main"><strong><?= e($n['title']) ?></strong><span><?= e($n['message']) ?></span></span>
      <small><?= e(time_ago_label($n['created_at'])) ?></small>
    </a>
  <?php endforeach; endif; ?>
</div>

<script>
(function () {
  function api(body, keep) {
    return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), keepalive: !!keep })
      .then(function (r) { return r.json(); });
  }
  document.querySelectorAll('.upd-row.unread').forEach(function (a) {
    a.addEventListener('click', function () { api({ action: 'mark_notifications_read', id: Number(a.dataset.id) }, true); });
  });
  var all = document.getElementById('markAllBtn');
  if (all) all.addEventListener('click', function () {
    all.disabled = true;
    api({ action: 'mark_notifications_read' }).then(function (d) {
      if (d.ok) location.reload(); else { all.disabled = false; msToast(d.error || 'Could not update.', 'error'); }
    }).catch(function (e) { all.disabled = false; msToast('Network error: ' + e.message, 'error'); });
  });
})();
</script>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
