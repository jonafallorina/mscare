<?php
// Printable documents for the signed-in patient: consent form, dental certificate.
require_once __DIR__ . '/includes/auth.php';
require_patient();
$patient = current_patient();
$pid = patient_id();
$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$pdo = db();
$st = $pdo->prepare("SELECT p.*, r.birthdate, r.home_address FROM patients p LEFT JOIN patient_records r ON r.patient_id = p.id WHERE p.id = :id");
$st->execute([':id' => $pid]);
$p = $st->fetch();
if (!$p) { redirect_to('login.php'); }
$name = patient_full_name($p);

$doc = null; $heading = ''; $con = null; $appt = null;
if ($type === 'consent') {
    $c = $pdo->prepare("SELECT * FROM patient_consents WHERE patient_id = :p");
    $c->execute([':p' => $pid]);
    $con = $c->fetch();
    if (!$con) { flash('You have not signed the informed consent yet.', 'error'); redirect_to('patient-inbox.php?v=consents'); }
    $heading = 'Informed Consent';
} elseif ($type === 'certificate') {
    $c = $pdo->prepare("SELECT a.*, s.name AS service_name, b.name AS branch_name, b.address AS branch_address,
                               COALESCE(d.name, 'Dr. Marilou S. Cariño') AS dentist_name, d.license_no
                        FROM appointments a JOIN services s ON s.id = a.service_id JOIN branches b ON b.id = a.branch_id
                        LEFT JOIN dentists d ON d.id = a.dentist_id
                        WHERE a.id = :id AND a.patient_id = :p AND a.status = 'Completed'");
    $c->execute([':id' => $id, ':p' => $pid]);
    $appt = $c->fetch();
    if (!$appt) { flash('That certificate is not available.', 'error'); redirect_to('patient-inbox.php?v=certificates'); }
    $heading = 'Dental Certificate';
} else {
    redirect_to('patient-inbox.php');
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($heading) ?> | <?= e($clinic['name']) ?></title>
<style>
  body{font-family:Georgia,'Times New Roman',serif;color:#12263a;background:#eef3f6;margin:0;padding:24px;}
  .bar{max-width:800px;margin:0 auto 14px;display:flex;justify-content:space-between;gap:10px;font-family:Arial,sans-serif;}
  .bar a,.bar button{font:600 14px Arial,sans-serif;border:0;border-radius:999px;padding:10px 18px;cursor:pointer;text-decoration:none;background:#fff;color:#0B2A40;border:1px solid #cfdbe3;}
  .bar button{background:#14a398;color:#fff;border-color:#14a398;}
  .sheet{max-width:800px;margin:0 auto;background:#fff;padding:48px 56px;border-radius:6px;box-shadow:0 10px 30px rgba(11,42,64,.12);}
  .lh{text-align:center;border-bottom:2px solid #0B2A40;padding-bottom:14px;margin-bottom:26px;}
  .lh h1{margin:0;font-size:24px;letter-spacing:.04em;color:#0B2A40;}
  .lh p{margin:3px 0 0;font:13px Arial,sans-serif;color:#51677a;}
  h2{text-align:center;font-size:20px;letter-spacing:.12em;text-transform:uppercase;margin:0 0 24px;}
  p,li{font-size:15px;line-height:1.7;}
  .meta{font:14px Arial,sans-serif;margin-bottom:18px;}
  .meta b{display:inline-block;min-width:110px;}
  .rx{border:1px solid #cfdbe3;border-radius:8px;padding:16px 20px;white-space:pre-wrap;margin:10px 0 16px;}
  .sign{margin-top:54px;text-align:right;}
  .sign .line{display:inline-block;min-width:240px;border-top:1px solid #12263a;padding-top:6px;text-align:center;}
  .sig img{max-height:90px;max-width:320px;display:block;margin:6px 0;}
  .foot{margin-top:34px;font:12px Arial,sans-serif;color:#6b7f90;text-align:center;}
  @media print{body{background:#fff;padding:0}.bar{display:none}.sheet{box-shadow:none;border-radius:0;padding:24px 8px}}
</style></head><body>
<div class="bar"><a href="patient-inbox.php">&larr; Back to Inbox</a><button type="button" onclick="window.print()">Print</button></div>
<div class="sheet">
  <div class="lh"><h1><?= e(strtoupper($clinic['name'])) ?></h1><p><?= e($clinic['phone']) ?> &middot; Makati &amp; Manila</p></div>
  <h2><?= e($heading) ?></h2>

<?php if ($type === 'consent'): ?>
  <div class="meta"><b>Patient:</b> <?= e($name) ?><br><b>Date signed:</b> <?= e(date('F j, Y', strtotime($con['signature_date']))) ?><br><b>Status:</b> <?= e(ucfirst($con['status'])) ?><?= ($con['status'] === 'approved' && $con['reviewed_at']) ? ' (' . e(date('F j, Y', strtotime($con['reviewed_at']))) . ')' : '' ?></div>
  <p>I understand that dental treatment, like any health care, carries some risk. By signing below I confirm that I have read and understood the following, and that my questions were answered.</p>
  <?php include __DIR__ . '/includes/consent_items.php'; ?>
  <div class="sig"><?php if (!empty($con['signature_data'])): ?><img src="<?= e($con['signature_data']) ?>" alt="Signature"><?php endif; ?><div class="sign" style="margin-top:0;text-align:left;"><span class="line"><?= e($name) ?><br>Patient signature</span></div></div>

<?php elseif ($type === 'certificate'): ?>
  <p>This is to certify that <b><?= e($name) ?></b><?= $p['age'] ? ', ' . (int)$p['age'] . ' years old,' : '' ?> was seen and attended to at <b><?= e($appt['branch_name']) ?></b> (<?= e($appt['branch_address']) ?>) on <b><?= e(date('F j, Y', strtotime($appt['appointment_date']))) ?></b> for <b><?= e($appt['service_name']) ?></b>.</p>
  <p>This certificate is issued upon the request of the patient for whatever legal purpose it may serve.</p>
  <div class="meta" style="margin-top:20px;"><b>Reference:</b> <?= e($appt['ref']) ?><br><b>Date issued:</b> <?= e(date('F j, Y')) ?></div>
  <div class="sign"><span class="line"><?= e($appt['dentist_name']) ?><br>Attending dentist<?= !empty($appt['license_no']) ? '<br>License No. ' . e($appt['license_no']) : '' ?></span></div>

<?php endif; ?>

  <div class="foot">Generated from the MSCare patient portal on <?= e(date('F j, Y')) ?>.</div>
</div>
</body></html>
