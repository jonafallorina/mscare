<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();

$view = (string)($_GET['v'] ?? '');
if (!in_array($view, ['consents', 'certificates'], true)) $view = '';

$con = null; $certs = []; $db_error = null;
try {
    $pdo = db();
    $c = $pdo->prepare("SELECT status, signature_date, reviewed_at, rejection_note, signature_data FROM patient_consents WHERE patient_id = :p");
    $c->execute([':p' => $pid]);
    $con = $c->fetch() ?: null;

    $c = $pdo->prepare("SELECT a.id, a.ref, a.appointment_date, s.name AS service_name, b.name AS branch_name,
                               COALESCE(d.name, 'Dr. Marilou S. Cariño') AS dentist_name
                        FROM appointments a
                        JOIN services s ON s.id = a.service_id
                        JOIN branches b ON b.id = a.branch_id
                        LEFT JOIN dentists d ON d.id = a.dentist_id
                        WHERE a.patient_id = :p AND a.status = 'Completed'
                        ORDER BY a.appointment_date DESC, a.id DESC");
    $c->execute([':p' => $pid]);
    $certs = $c->fetchAll();

} catch (PDOException $ex) {
    error_log('MSCare DB (inbox): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your documents right now.');
}

$cs = $con['status'] ?? 'none';
$csLabel = ['approved' => 'Approved', 'pending' => 'Waiting for approval', 'rejected' => 'Not approved', 'none' => 'Not signed'][$cs] ?? 'Not signed';
$csBadge = ['approved' => 'b-confirmed', 'pending' => 'b-pending', 'rejected' => 'b-cancelled', 'none' => 'b-noshow'][$cs] ?? 'b-noshow';

$ptitle = 'Inbox';
$psub   = 'Documents & forms';
$pactive = 'inbox';
include __DIR__ . '/includes/portal_head.php';
?>

<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>

<?php if ($view === ''): ?>
  <div class="inbox-grid">
    <a class="inbox-card" href="patient-inbox.php?v=consents">
      <span class="ib-ic tone-amber"><?= icon($ui['pen'], 30) ?></span>
      <h3>View Consent Forms</h3>
      <p>Your signed informed consent and its approval status</p>
      <span class="ib-count"><?= $con ? '1 form' : 'No form yet' ?></span>
    </a>
    <a class="inbox-card" href="patient-inbox.php?v=certificates">
      <span class="ib-ic tone-violet"><?= icon($ui['award'], 30) ?></span>
      <h3>Dental Certificates</h3>
      <p>View and print certificates for your completed visits</p>
      <span class="ib-count"><?= count($certs) ?> available</span>
    </a>
  </div>

<?php elseif ($view === 'consents'): ?>
  <a class="back-link" href="patient-inbox.php"><?= icon($ui['back'], 16) ?> Back to Inbox</a>
  <div class="p-card">
    <div class="p-card-head"><h2>Consent forms</h2></div>
    <?php if (!$con): ?>
      <div class="p-empty"><strong>No signed form yet</strong>You have not signed the informed consent. Sign it in your Dental Records to unlock booking.</div>
      <div class="doc-actions"><a class="btn btn-primary btn-pill btn-sm" href="patient-record.php#consent">Sign informed consent</a></div>
    <?php else: ?>
      <div class="doc-row">
        <span class="doc-ic"><?= icon($ui['pen'], 22) ?></span>
        <div class="doc-main">
          <h3>Informed Consent &mdash; General dental treatment</h3>
          <p>Signed <?= e(date('M j, Y', strtotime($con['signature_date']))) ?><?= ($cs === 'approved' && $con['reviewed_at']) ? ' &middot; approved ' . e(date('M j, Y', strtotime($con['reviewed_at']))) : '' ?></p>
          <?php if ($cs === 'rejected' && !empty($con['rejection_note'])): ?><p class="bk-note">Clinic note: <?= e($con['rejection_note']) ?></p><?php endif; ?>
        </div>
        <span class="badge <?= $csBadge ?>"><?= e($csLabel) ?></span>
        <div class="doc-btns">
          <a class="btn btn-outline btn-pill btn-sm" target="_blank" rel="noopener" href="patient-print.php?type=consent"><?= icon($ui['print'], 16) ?> View / Print</a>
          <?php if ($cs !== 'approved'): ?><a class="btn btn-primary btn-pill btn-sm" href="patient-record.php#consent"><?= $cs === 'rejected' ? 'Upload again' : 'Open' ?></a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'certificates'): ?>
  <a class="back-link" href="patient-inbox.php"><?= icon($ui['back'], 16) ?> Back to Inbox</a>
  <div class="p-card">
    <div class="p-card-head"><h2>Dental certificates</h2><span class="meta"><?= count($certs) ?> available</span></div>
    <?php if (!$certs): ?>
      <div class="p-empty"><strong>No certificates yet</strong>A certificate becomes available after the clinic marks a visit as completed.</div>
    <?php else: foreach ($certs as $c): ?>
      <div class="doc-row">
        <span class="doc-ic"><?= icon($ui['award'], 22) ?></span>
        <div class="doc-main">
          <h3>Dental certificate &mdash; <?= e($c['service_name']) ?></h3>
          <p><?= e(date('F j, Y', strtotime($c['appointment_date']))) ?> &middot; <?= e($c['branch_name']) ?><?= $c['ref'] ? ' &middot; Ref ' . e($c['ref']) : '' ?></p>
        </div>
        <div class="doc-btns"><a class="btn btn-outline btn-pill btn-sm" target="_blank" rel="noopener" href="patient-print.php?type=certificate&amp;id=<?= (int)$c['id'] ?>"><?= icon($ui['print'], 16) ?> View / Print</a></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
