<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
$stmt->execute([':id' => $pid]);
$p = $stmt->fetch();
if (!$p) { unset($_SESSION['mscare_patient']); redirect_to('login.php'); }

$pwError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $cur = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $con = (string)($_POST['confirm_password'] ?? '');
    if ($cur === '' || $new === '' || $con === '') {
        $pwError = 'Please fill in your current password, the new password and its confirmation.';
    } elseif (!password_verify($cur, (string)$p['password_hash'])) {
        $pwError = 'Your current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $pwError = 'The new password must be at least 6 characters.';
    } elseif ($new === $cur) {
        $pwError = 'The new password must be different from your current password.';
    } elseif ($new !== $con) {
        $pwError = 'The new password and its confirmation do not match.';
    } else {
        try {
            $pdo->prepare("UPDATE patients SET password_hash = :h, updated_at = now() WHERE id = :id")
                ->execute([':h' => password_hash($new, PASSWORD_BCRYPT), ':id' => $pid]);
            flash('Your password was changed.');
            redirect_to('patient-settings.php');
        } catch (PDOException $ex) {
            error_log('MSCare DB (change password): ' . $ex->getMessage());
            $pwError = db_fail_text($ex, 'We could not change your password. Please try again.');
        }
    }
}

$unread = 0; $dbBranches = [];
try {
    $q = $pdo->prepare("SELECT count(*) FROM notifications WHERE patient_id = :p AND is_read = FALSE");
    $q->execute([':p' => $pid]);
    $unread = (int)$q->fetchColumn();
    $dbBranches = $pdo->query("SELECT name, address, phone FROM branches ORDER BY id")->fetchAll();
} catch (PDOException $ex) { /* settings still usable */ }

$ptitle = 'Settings';
$psub   = 'Account, security and help';
$pactive = 'settings';
include __DIR__ . '/includes/portal_head.php';
?>

<div class="prof-grid">
  <div style="display:grid;gap:20px;min-width:0;align-content:start;">
    <div class="p-card" id="change-password">
      <div class="p-card-head"><h2>Change password</h2></div>
      <?php if ($pwError): ?>
        <div class="banner err" role="alert"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($pwError) ?></strong></div></div>
      <?php endif; ?>
      <form method="post" action="patient-settings.php#change-password" novalidate>
        <input type="hidden" name="action" value="password">
        <div class="form-grid">
          <div class="field full"><label for="current_password">Current password</label>
            <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
              <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
              <button type="button" class="pass-toggle" data-toggle-pass="current_password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
            </span></div>
          <div class="field"><label for="new_password">New password</label>
            <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
              <input id="new_password" name="new_password" type="password" autocomplete="new-password" placeholder="At least 6 characters" required>
              <button type="button" class="pass-toggle" data-toggle-pass="new_password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
            </span></div>
          <div class="field"><label for="confirm_password">Confirm new password</label>
            <span class="input-icon has-toggle"><?= icon($ui['lock'], 17) ?>
              <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" placeholder="Re-enter new password" required>
              <button type="button" class="pass-toggle" data-toggle-pass="confirm_password" aria-label="Show password"><?= icon($ui['eye'], 18) ?></button>
            </span></div>
        </div>
        <button type="submit" class="btn btn-primary btn-pill" style="margin-top:8px;">Change password</button>
      </form>
    </div>

    <div class="p-card">
      <div class="p-card-head"><h2>Notifications</h2></div>
      <p class="set-text"><?= $unread ? 'You have <b>' . $unread . '</b> unread update' . ($unread > 1 ? 's' : '') . ' from the clinic.' : 'You&rsquo;re all caught up. There are no unread updates.' ?></p>
      <div class="prof-actions">
        <a class="btn btn-outline btn-pill btn-sm" href="patient-updates.php"><?= icon($ui['bell'], 16) ?> Open updates</a>
        <?php if ($unread): ?><button type="button" class="btn btn-outline btn-pill btn-sm" id="markAllBtn">Mark all as read</button><?php endif; ?>
      </div>
    </div>
  </div>

  <div style="display:grid;gap:20px;min-width:0;align-content:start;">
    <div class="p-card">
      <div class="p-card-head"><h2>Account</h2></div>
      <ul class="info-list">
        <li><span class="il-ic"><?= icon($ui['user'], 18) ?></span><span class="il-k">Name</span><span class="il-v"><?= e(patient_full_name($p)) ?></span></li>
        <li><span class="il-ic"><?= icon($ui['mail'], 18) ?></span><span class="il-k">Email</span><span class="il-v"><?= e($p['email']) ?></span></li>
        <li><span class="il-ic"><?= icon($ui['phone'], 18) ?></span><span class="il-k">Mobile</span><span class="il-v"><?= e($p['phone']) ?></span></li>
      </ul>
      <div class="prof-actions"><a class="btn btn-outline btn-pill btn-sm" href="patient-profile.php"><?= icon($ui['pen'], 16) ?> Edit profile</a></div>
    </div>

    <div class="p-card">
      <div class="p-card-head"><h2>Help &amp; contact</h2></div>
      <ul class="info-list">
        <li><span class="il-ic"><?= icon($ui['phone'], 18) ?></span><span class="il-k">MSCare</span><span class="il-v"><a href="tel:<?= e($clinic['phone_tel']) ?>"><?= e($clinic['phone']) ?></a></span></li>
        <?php foreach ($dbBranches as $b): ?>
          <li><span class="il-ic"><?= icon($ui['pin'], 18) ?></span><span class="il-k"><?= e($b['name']) ?></span><span class="il-v"><?= e($b['address']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="p-card">
      <div class="p-card-head"><h2>Session</h2></div>
      <p class="set-text">Signed in as <b><?= e($p['email']) ?></b>.</p>
      <div class="prof-actions"><a class="btn btn-danger btn-pill btn-sm" href="login.php?logout=1"><?= icon($ui['logout'], 16) ?> Log out</a></div>
    </div>
  </div>
</div>

<script>
(function () {
  var all = document.getElementById('markAllBtn');
  if (!all) return;
  all.addEventListener('click', function () {
    all.disabled = true;
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mark_notifications_read' }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else { all.disabled = false; msToast(d.error || 'Could not update.', 'error'); } })
      .catch(function (e) { all.disabled = false; msToast('Network error: ' + e.message, 'error'); });
  });
})();
</script>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
