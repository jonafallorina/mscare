<?php
require_once __DIR__ . '/includes/mailer.php';
ensure_email_schema();

if (current_patient()) { header('Location: patient-dashboard.php'); exit; }
$pid = (int)($_SESSION['verify_pid'] ?? 0);
if (!$pid) { header('Location: login.php'); exit; }

$error = ''; $info = '';
try {
    $st = db()->prepare("SELECT * FROM patients WHERE id = :i");
    $st->execute([':i' => $pid]);
    $p = $st->fetch();
} catch (PDOException $ex) { $p = null; $error = db_fail_text($ex, 'Database error.'); }
if (!$p) { unset($_SESSION['verify_pid']); header('Location: login.php'); exit; }
if (!empty($p['email_verified'])) { unset($_SESSION['verify_pid']); flash('Your email is already verified. Please log in.'); header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';
    try {
        if ($action === 'resend') {
            $wait = otp_wait_seconds($pid, 'register');
            if ($wait > 0) {

            } else {
                [$ok, $err] = send_patient_code($p, 'register');
                if ($ok) $info = 'A new code was sent to ' . mask_email($p['email']) . '.';
                else $error = 'We could not send the email. ' . (MSC_DEBUG ? $err : 'Please try again later.');
            }
        } else {
            $res = check_patient_code($pid, 'register', $_POST['code'] ?? '');
            if ($res === true) {
                db()->prepare("UPDATE patients SET email_verified = TRUE, email_verified_at = now() WHERE id = :i")->execute([':i' => $pid]);
                unset($_SESSION['verify_pid']);
                session_regenerate_id(true);
                $p['email_verified'] = true;
                login_patient_session($p);
                flash('Email verified! Welcome to MSCare, ' . $p['first_name'] . '. Please complete your dental record to unlock booking.');
                header('Location: patient-dashboard.php');
                exit;
            }
            $error = $res;
        }
    } catch (PDOException $ex) {
        error_log('MSCare DB (verify): ' . $ex->getMessage());
        $error = db_fail_text($ex, 'Something went wrong. Please try again.');
    }
}
$flash = take_flash();
$expIn  = otp_expires_in($pid, 'register');
$waitIn = otp_wait_seconds($pid, 'register');
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify your email · MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-body">
<div class="auth-wrap"><div class="auth-card">
  <aside class="auth-side">
    <a class="brand" href="index.php"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg> MSCare</a>
    <span class="side-foot">Makati &amp; Manila</span>
  </aside>
  <main class="auth-main">
    <div class="form-box">
      <a class="back" href="login.php">← Back</a>
      <h1 class="title">Verify your email</h1>
      <?php if ($flash): ?><div class="alert<?= ($flash['type'] ?? '') === 'error' ? '' : ' ok' ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
      <?php if ($info): ?><div class="alert ok" role="status"><?= e($info) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
      <p id="codeTimer" class="sub" style="margin:0 0 14px;font-weight:600" data-left="<?= (int)$expIn ?>"></p>
      <form method="post" action="verify-email.php" novalidate>
        <input type="hidden" name="action" value="verify">
        <div class="field"><label for="code">Verification code</label>
          <div class="ctl"><input id="code" name="code" type="text" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="123456" required autofocus style="height:58px;font-size:1.6rem;letter-spacing:.5rem;text-align:center"></div></div>
        <button class="btn btn-primary" type="submit">Verify &amp; continue <span aria-hidden="true">→</span></button>
      </form>
      <form method="post" action="verify-email.php" style="margin-top:18px;text-align:center">
        <input type="hidden" name="action" value="resend">
        <p class="sub" style="margin:0">Didn’t get it? <button type="submit" id="resendBtn" data-wait="<?= (int)$waitIn ?>" style="background:none;border:0;color:inherit;font:inherit;font-weight:600;text-decoration:underline;cursor:pointer">Resend code</button></p>
      </form>
    </div>
  </main>
</div></div>
<?php include __DIR__ . '/includes/otp_timer.php'; ?>
<div class="auth-foot"><span>&copy; <?= date('Y') ?> MSCare Dental Clinic. All rights reserved.</span><span>Every smile, looked after with care.</span></div>
</body></html>
