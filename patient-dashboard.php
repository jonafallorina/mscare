<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$next_appt = null; $upcoming_count = 0; $completed_count = 0;
$upcoming_list = []; $recent_treatments = [];
$gate = ['consent' => 'none', 'can_book' => false];
$db_error = null;

try {
    $pdo = db();
    require_once __DIR__ . '/includes/blocked_times.php';
    blocktime_ensure_schema($pdo);
    $base = "FROM appointments a
             JOIN services s ON s.id = a.service_id AND s.name NOT ILIKE '%brace%'
             JOIN branches b ON b.id = a.branch_id
             WHERE a.patient_id = :pid AND a.status IN ('Pending', 'Confirmed') AND a.appointment_date >= CURRENT_DATE
             ORDER BY a.appointment_date ASC, a.appointment_time ASC";
    $stmt = $pdo->prepare("SELECT a.id, a.ref, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
                                  s.name AS service_name, b.name AS branch_name, b.address AS branch_address, a.status $base LIMIT 5");
    $stmt->execute([':pid' => $pid]);
    $upcoming_list = $stmt->fetchAll();
    $next_appt = $upcoming_list[0] ?? null;

    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE patient_id = :pid AND service_id NOT IN (SELECT id FROM services WHERE name ILIKE '%brace%') AND status IN ('Pending', 'Confirmed') AND appointment_date >= CURRENT_DATE");
    $stmt->execute([':pid' => $pid]);
    $upcoming_count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT count(*) FROM appointments WHERE patient_id = :pid AND service_id NOT IN (SELECT id FROM services WHERE name ILIKE '%brace%') AND status = 'Completed'");
    $stmt->execute([':pid' => $pid]);
    $completed_count = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT procedure_name, tooth, treatment_date, to_char(treatment_date, 'Mon DD') AS date_formatted
                           FROM treatments WHERE patient_id = :pid AND procedure_name NOT ILIKE '%brace%' ORDER BY treatment_date DESC LIMIT 5");
    $stmt->execute([':pid' => $pid]);
    $recent_treatments = $stmt->fetchAll();

    $gate = booking_status($pid);
} catch (PDOException $ex) {
    error_log('MSCare DB (dashboard): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your information right now.');
}

$has_pending = false;
foreach ($upcoming_list as $u) { if ($u['status'] === 'Pending') { $has_pending = true; break; } }
$approved = !empty($gate['can_book']);
$done1 = $approved;
$done2 = $done1 && ($upcoming_count > 0 || $completed_count > 0);
$done3 = $done2 && ($completed_count > 0 || ($upcoming_count > 0 && !$has_pending));
$done4 = $done3 && $completed_count > 0;
$done5 = $done4 && count($recent_treatments) > 0;
$consent_wait = ($gate['consent'] ?? 'none') === 'pending';
$roadmap = [
    ['Complete your dental record', $consent_wait ? 'Submitted. The clinic is reviewing it and will approve it soon.' : '', $done1, 'patient-record.php', 'Open my record', $ui['file']],
    ['Book an appointment', '', $done2, 'patient-book.php', 'Book now', $ui['calendar']],
    ['Wait for clinic confirmation', '', $done3, 'patient-appointments.php', 'Check status', $ui['history']],
    ['Visit the clinic', '', $done4, 'patient-appointments.php', 'View my visit', $ui['pin']],
    ['See your treatment history', '', $done5, 'patient-history.php', 'View history', $ui['check']],
];
$rm_current = null;
foreach ($roadmap as $i => $st) { if (!$st[2]) { $rm_current = $i; break; } }
$rm_done = count(array_filter(array_column($roadmap, 2)));

$badge = ['Confirmed' => 'b-confirmed', 'Pending' => 'b-pending', 'Completed' => 'b-completed', 'Cancelled' => 'b-cancelled', 'No-show' => 'b-noshow'];

$hr = (int)date('G');
$greet = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening');
$ptitle   = $greet . ', ' . ucwords(strtolower($patient['first_name'])) . '.';
$psub     = '';
$peyebrow = date('l, F j');
$pbg      = '';
$body_class = 'portal-dash';
$pactive = 'dashboard';
include __DIR__ . '/includes/portal_head.php';
?>

<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>

<?php if (!$gate['can_book']): ?>
<?php endif; ?>

<?php

$rm_total = count($roadmap);
$rm_at    = $rm_current === null ? $rm_total : $rm_current + 1;
?>

<?php if ($next_appt):
  $ts    = strtotime($next_appt['appointment_date']);
  $t_in  = strtotime($next_appt['appointment_date'] . ' ' . $next_appt['time_formatted']);
  $range = date('g:i A', $t_in) . ' &ndash; ' . date('g:i A', $t_in + 3600);
?>
<section class="nv-card" aria-label="Your next visit">
  <div class="nv-grid">
    <div class="nv-when">
      <p class="nv-eyebrow">Your next visit</p>
      <div class="nv-main">
        <div class="nv-date"><span class="m"><?= date('M', $ts) ?></span><span class="d"><?= date('j', $ts) ?></span><span class="w"><?= date('l', $ts) ?></span></div>
        <div class="nv-info">
          <span class="nv-status s-<?= strtolower(e($next_appt['status'])) ?>"><?= e($next_appt['status']) ?></span>
          <h3><?= e($next_appt['service_name']) ?></h3>
          <p class="nv-time"><?= icon($ui['clock'], 15) ?><span><?= $range ?></span></p>
        </div>
      </div>
    </div>
    <div class="nv-where">
      <span class="nv-pin"><?= icon($ui['pin'], 18) ?></span>
      <div>
        <strong><?= e($next_appt['branch_name']) ?></strong>
        <small><?= e($next_appt['branch_address']) ?></small>
      </div>
    </div>
    <div class="nv-actions">
      <a class="btn btn-teal btn-pill btn-sm" href="patient-appointments.php">View details</a>
      <?php if ($gate['can_book']): ?><a class="btn btn-outline btn-pill btn-sm" href="patient-book.php">Book another</a><?php endif; ?>
    </div>
  </div>
</section>
<?php else: ?>
<section class="nv-card" aria-label="Your next visit">
  <div class="nv-grid nv-empty">
    <div class="nv-when">
      <p class="nv-eyebrow">No upcoming visits</p>
      <h3>You&rsquo;re all caught up</h3>
    </div>
    <div class="nv-actions">
      <?php if ($gate['can_book']): ?>
        <a class="btn btn-teal btn-pill btn-sm" href="patient-book.php"><?= icon($ui['plus'], 16) ?> Book appointment</a>
      <?php else: ?>
        <a class="btn btn-teal btn-pill btn-sm" href="patient-record.php"><?= icon($ui['file'], 16) ?> Complete my record</a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="rm-card" id="roadmap" aria-label="Your care roadmap">
  <div class="rm-head">
    <div>
      <h3>Five steps to better care.</h3>
    </div>
    <span class="rm-count"><b><?= (int)$rm_at ?></b><span><?= (int)$rm_at ?> of <?= (int)$rm_total ?> steps</span></span>
  </div>
  <ol class="rm-track">
    <?php foreach ($roadmap as $i => [$rt, $rd, $rdone, $rhref, $rbtn, $ricon]):
          $state = $rdone ? 'done' : ($rm_current === $i ? 'now' : 'todo'); ?>
      <li class="rm-step is-<?= $state ?>"<?= $state === 'now' ? ' aria-current="step"' : '' ?>>
        <span class="rm-dot"><?= $rdone ? icon($ui['check'], 16) : '<b>' . ($i + 1) . '</b>' ?></span>
        <div class="rm-body">
          <strong><?= e($rt) ?></strong>
          <?php if ($state === 'now'): ?><em class="rm-tag">You are here</em><?php endif; ?>
          <?php if ($rd !== ''): ?><p><?= e($rd) ?></p><?php endif; ?>
          <?php if ($state === 'now'): ?><a class="btn btn-primary btn-pill btn-sm" href="<?= e($rhref) ?>"><?= e($rbtn) ?></a><?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($rm_current === null): ?>
    <p class="rm-end">You finished every step. Need another visit? <a href="patient-book.php">Book again</a>.</p>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
