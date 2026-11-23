<?php

foreach (['/config.php', '/includes/auth.php', '/includes/validation.php', '/includes/age_policy.php'] as $inc) { if (is_file(__DIR__ . $inc)) require_once __DIR__ . $inc; }
if (is_file(__DIR__ . '/includes/mailer.php')) require_once __DIR__ . '/includes/mailer.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('e')) { function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
function mscare_safe_next($n) {
    $n = (string)$n;
    return ($n !== '' && preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%\-]*)?$/', $n)) ? $n : 'patient-appointments.php';
}
$error = ''; $notice = ''; $old = [];
$branchOpts = [];
try { $branchOpts = db()->query("SELECT id, city AS name FROM branches ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $ex) {}
if (!$branchOpts) $branchOpts = [['id' => 'makati', 'name' => 'Makati'], ['id' => 'manila', 'name' => 'Manila']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['first_name','last_name','middle_name','birthdate','gender','mobile','email','branch'] as $k) $old[$k] = trim((string)($_POST[$k] ?? ''));
    $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['confirm_password'] ?? '');
    $old['email'] = strtolower($old['email']);
    foreach (['g_name','g_relationship','g_phone','g_email'] as $k) $old[$k] = trim((string)($_POST[$k] ?? ''));
    $old['g_email'] = strtolower($old['g_email']);
    $old['g_consent'] = !empty($_POST['g_consent']) ? '1' : '';

    $ageNow = age_from_birthdate($old['birthdate']);
    $tier   = $ageNow === null ? 'adult' : age_tier($ageNow);
    if ($tier === 'child') {
        $old['g_email'] = $old['email']; $old['g_phone'] = $old['mobile'];
    }
    if ($old['first_name'] === '' || $old['last_name'] === '' || $old['birthdate'] === '' || $old['gender'] === '' || $old['mobile'] === '' || $old['email'] === '' || $old['branch'] === '') {
        $error = 'Please fill in all required fields.';
    } elseif (($lp = letters_problem($old['first_name'], 'First name') ?? letters_problem($old['last_name'], 'Last name') ?? letters_problem($old['middle_name'], 'Middle name')) !== null) {
        $error = $lp;
    } elseif (($mp = mobile_problem($old['mobile'])) !== null) {
        $error = $mp;
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!in_array($old['gender'], ['Female', 'Male', 'Prefer not to say'], true)) {
        $error = 'Please select a gender.';
    } elseif ($ageNow === null || $old['birthdate'] < date('Y-m-d', strtotime('-125 years'))) {
        $error = 'Enter a valid birthdate.';
    } elseif (($gp = guardian_problem(['name' => $old['g_name'], 'relationship' => $old['g_relationship'], 'phone' => $old['g_phone'],
                                       'email' => $old['g_email'], 'consent' => $old['g_consent']], $tier)) !== null) {
        $error = $gp;
    } elseif (strlen($pw) < 8 || !preg_match('/[A-Z]/', $pw) || !preg_match('/\d/', $pw) || !preg_match('/[^A-Za-z0-9]/', $pw)) {
        $error = 'Password needs 8+ characters with an uppercase letter, a number and a symbol.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } elseif (empty($_POST['consent'])) {
        $error = 'Please agree to the data storage notice to continue.';
    } else {
        try {
            $pdo = db();
            ensure_email_schema();
            ensure_age_policy_schema();
            $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'patients'")->fetchAll(PDO::FETCH_COLUMN);
            $has = function ($c) use ($cols) { return in_array($c, $cols, true); };
            $d = $pdo->prepare("SELECT 1 FROM patients WHERE lower(email) = :e LIMIT 1");
            $d->execute([':e' => $old['email']]);
            if ($d->fetchColumn()) {
                $error = 'An account with this email already exists. Try logging in.';
            } else {
                $branchId = null;
                if (ctype_digit((string)$old['branch'])) $branchId = (int)$old['branch'];
                else { $b = $pdo->prepare("SELECT id FROM branches WHERE code ILIKE :x OR name ILIKE :x LIMIT 1"); $b->execute([':x' => '%' . $old['branch'] . '%']); $branchId = $b->fetchColumn() ?: null; }
                $age = (int)$ageNow;
                $vals = [
                    'first_name' => $old['first_name'], 'last_name' => $old['last_name'], 'middle_name' => $old['middle_name'] !== '' ? $old['middle_name'] : null,
                    'email' => $old['email'], 'gender' => $old['gender'], 'branch_id' => $branchId, 'age' => $age, 'status' => 'Active',
                    'email_verified' => 'false',
                    'account_type' => account_type_for_tier($tier),
                ];
                if ($tier !== 'adult') {
                    $vals += ['guardian_name' => mb_strtoupper($old['g_name']), 'guardian_relationship' => $old['g_relationship'],
                              'guardian_phone' => $old['g_phone'], 'guardian_email' => $old['g_email'],
                              'guardian_consent' => 'true', 'guardian_consent_at' => date('Y-m-d H:i:sP')];
                }
                $vals[$has('phone') ? 'phone' : 'mobile'] = $old['mobile'];
                foreach (['birthdate','date_of_birth','birth_date','dob'] as $bc) if ($has($bc)) { $vals[$bc] = $old['birthdate']; break; }
                $vals[$has('password_hash') ? 'password_hash' : 'password'] = password_hash($pw, PASSWORD_DEFAULT);
                $vals = array_filter($vals, function ($v, $k) use ($has) { return $has($k); }, ARRAY_FILTER_USE_BOTH);
                $names = array_keys($vals);
                $sql = 'INSERT INTO patients (' . implode(',', $names) . ') VALUES (' . implode(',', array_map(function ($n) { return ':' . $n; }, $names)) . ')';
                $ins = $pdo->prepare($sql . ' RETURNING id');
                $ins->execute(array_combine(array_map(function ($n) { return ':' . $n; }, $names), array_values($vals)));
                $newId = (int)$ins->fetchColumn();

                try {
                    $sexRec = in_array($old['gender'], ['Male', 'Female'], true) ? $old['gender'] : null;
                    $pdo->prepare('INSERT INTO patient_records (patient_id, birthdate, sex, is_completed) VALUES (:p, :b, :s, FALSE)
                                   ON CONFLICT (patient_id) DO UPDATE SET birthdate = EXCLUDED.birthdate, sex = COALESCE(EXCLUDED.sex, patient_records.sex)')
                        ->execute([':p' => $newId, ':b' => $old['birthdate'], ':s' => $sexRec]);
                } catch (Throwable $e) { error_log('MSCare register record prefill: ' . $e->getMessage()); }
                if ($tier !== 'adult') {
                    try { $pdo->prepare('UPDATE patient_records SET guardian_name = :g WHERE patient_id = :p')->execute([':g' => mb_strtoupper($old['g_name']), ':p' => $newId]); }
                    catch (Throwable $e) { error_log('MSCare register guardian prefill: ' . $e->getMessage()); }
                }
                if ($tier === 'teen') {
                    try {
                        $pn = e(ucwords(strtolower($old['first_name'] . ' ' . $old['last_name']))); $gn = e(ucwords(strtolower($old['g_name'])));
                        smtp_send($old['g_email'], 'MSCare: a patient account was opened with your consent',
                            '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;border:1px solid #e3e8ea;border-radius:12px;overflow:hidden">'
                          . '<div style="background:#0f6e6a;color:#fff;padding:18px 24px;font-size:20px;font-weight:bold">MSCare Dental Clinic</div>'
                          . '<div style="padding:24px;color:#1f2d33;line-height:1.5"><p>Hi ' . $gn . ',</p><p><b>' . $pn . '</b> created an MSCare patient account and confirmed that you, as parent / guardian, gave consent.</p>'
                          . '<p>If this is not correct, please contact the clinic so we can deactivate the account.</p></div></div>',
                            'Hi ' . $old['g_name'] . ', ' . $old['first_name'] . ' ' . $old['last_name'] . ' created an MSCare patient account and confirmed that you gave consent. If this is not correct, please contact the clinic.');
                    } catch (Throwable $e) { error_log('MSCare guardian notice: ' . $e->getMessage()); }
                }
                $_SESSION['verify_pid'] = $newId;
                $pt = $pdo->prepare('SELECT * FROM patients WHERE id = :i');
                $pt->execute([':i' => $newId]);
                $patientRow = $pt->fetch(PDO::FETCH_ASSOC);
                [$sent, $sendErr] = send_patient_code($patientRow, 'register');
                if ($sent) flash('We sent a 6-digit code to ' . mask_email($patientRow['email']) . '. Enter it below to verify your email.');
                else flash('We created your account but could not send the code yet. Tap "Resend code" below.', 'error');
                header('Location: verify-email.php'); exit;
            }
        } catch (Throwable $ex) {
            $error = 'We could not create your account right now. Please try again or contact the clinic.';
        }
    }
}
$o = function ($k) use ($old) { return e($old[$k] ?? ''); };
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create account · MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body class="auth-body">
<div class="auth-wrap"><div class="auth-card" id="authCard">
  <aside class="auth-side">
    <a class="brand" href="index.php"><svg  width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg> MSCare</a>
    <span class="side-foot">Makati &amp; Manila</span>
  </aside>
  <main class="auth-main wide register">
    <nav class="tabs"><a href="login.php">Log in</a><a class="on" href="register.php">Sign up</a></nav>
    <div class="form-box">
      <a class="back" href="login.php">← Back to log in</a>
      <p class="eyebrow">Join MSCare</p>
      <h1 class="title">Create your account.</h1>
      <?php if (!empty($error)): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="register.php">
        <div class="grid2">
          <div class="field"><label for="first_name">First name <i>*</i></label><div class="ctl"><input id="first_name" name="first_name" data-letters placeholder="Juan" required value="<?= $o('first_name') ?>"></div></div>
          <div class="field"><label for="last_name">Last name <i>*</i></label><div class="ctl"><input id="last_name" name="last_name" data-letters placeholder="Dela Cruz" required value="<?= $o('last_name') ?>"></div></div>
          <div class="field"><label for="middle_name">Middle name</label><div class="ctl"><input id="middle_name" name="middle_name" data-letters placeholder="Santos" value="<?= $o('middle_name') ?>"></div></div>
          <div class="field"><label for="birthdate">Birthdate <i>*</i></label><div class="ctl icon"><svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><input id="birthdate" name="birthdate" type="date" required value="<?= $o('birthdate') ?>"></div></div>
          <div class="field"><label for="gender">Gender <i>*</i></label><div class="ctl"><select id="gender" name="gender" required><option value="">Select gender</option><option<?= ($old['gender'] ?? '') === 'Female' ? ' selected' : '' ?>>Female</option><option<?= ($old['gender'] ?? '') === 'Male' ? ' selected' : '' ?>>Male</option><option value="Prefer not to say"<?= ($old['gender'] ?? '') === 'Prefer not to say' ? ' selected' : '' ?>>Prefer not to say</option></select></div></div>
          <div class="field"><label for="mobile"><span id="lblMobile">Mobile number</span> <i>*</i></label><div class="ctl icon"><svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><input id="mobile" name="mobile" type="tel" data-mobile placeholder="09171234567" required value="<?= $o('mobile') ?>"></div></div>
          <div class="field"><label for="email"><span id="lblEmail">Email address</span> <i>*</i></label><div class="ctl icon"><svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><input id="email" name="email" type="email" placeholder="you@email.com" autocomplete="email" required value="<?= $o('email') ?>"></div></div>
          <div class="field"><label for="branch">Preferred branch <i>*</i></label><div class="ctl"><select id="branch" name="branch" required><option value="">Select a clinic</option><?php foreach ($branchOpts as $b): ?><option value="<?= e($b['id']) ?>"<?= (string)($old['branch'] ?? '') === (string)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div></div>
          <div class="field"><label for="password">Password <i>*</i></label>
<div class="ctl icon has-eye"><svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><input id="password" name="password" type="password" placeholder="At least 8 characters" autocomplete="new-password" required>
<button type="button" class="eye" data-eye="password" aria-label="Show password"><svg  width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
          <div class="field"><label for="confirm">Confirm password <i>*</i></label>
<div class="ctl icon has-eye"><svg class="lead" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><input id="confirm" name="confirm_password" type="password" placeholder="Re-enter password" autocomplete="new-password" required>
<button type="button" class="eye" data-eye="confirm" aria-label="Show password"><svg  width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
          <p class="hint pw-hint">Use 8+ characters with an uppercase letter, number, and symbol.</p>
        </div>
        <div id="ageNote" class="agenote" hidden></div>
        <div id="guardianBox" class="guardian" hidden>
          <p class="g-title">Parent / guardian details</p>
          <div class="grid2">
            <div class="field"><label for="g_name">Parent / guardian full name <i>*</i></label><div class="ctl"><input id="g_name" name="g_name" data-letters placeholder="Maria Dela Cruz" value="<?= $o('g_name') ?>"></div></div>
            <div class="field"><label for="g_relationship">Relationship to patient <i>*</i></label><div class="ctl"><select id="g_relationship" name="g_relationship"><option value="">Select relationship</option><?php foreach (guardian_relationships() as $r): ?><option<?= ($old['g_relationship'] ?? '') === $r ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></div></div>
            <div class="field" id="gPhoneField"><label for="g_phone">Guardian mobile number <i>*</i></label><div class="ctl"><input id="g_phone" name="g_phone" type="tel" data-mobile placeholder="09171234567" value="<?= $o('g_phone') ?>"></div></div>
            <div class="field" id="gEmailField"><label for="g_email">Guardian email address <i>*</i></label><div class="ctl"><input id="g_email" name="g_email" type="email" placeholder="guardian@email.com" value="<?= $o('g_email') ?>"></div></div>
          </div>
          <label class="consent"><input type="checkbox" id="g_consent" name="g_consent" value="1"<?= !empty($old['g_consent']) ? ' checked' : '' ?>> <span id="gConsentText"></span></label>
        </div>
        <label class="consent"><input type="checkbox" name="consent" required> I agree to MSCare storing my dental and contact information for treatment purposes.</label>
        <button class="btn btn-primary" type="submit">Create account <span aria-hidden="true">→</span></button>
      </form>
    </div>
  </main>
</div></div>
<script>
(function(){
  var CHILD=13, ADULT=18;
  var bd=document.getElementById('birthdate'), note=document.getElementById('ageNote'), box=document.getElementById('guardianBox');
  var lblE=document.getElementById('lblEmail'), lblM=document.getElementById('lblMobile');
  var gPhone=document.getElementById('gPhoneField'), gEmail=document.getElementById('gEmailField'), gTxt=document.getElementById('gConsentText');
  var card=document.getElementById('authCard');
  var gIn=['g_name','g_relationship','g_phone','g_email','g_consent'].map(function(i){return document.getElementById(i);});
  function ageOf(v){var m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(v||'');if(!m)return null;var b=new Date(+m[1],+m[2]-1,+m[3]),t=new Date();
    if(isNaN(b)||b>t)return null;var a=t.getFullYear()-b.getFullYear();var md=t.getMonth()-b.getMonth();if(md<0||(md===0&&t.getDate()<b.getDate()))a--;return a;}
  function setReq(el,on){if(el)el.required=on;}
  function apply(){
    var a=ageOf(bd.value), tier=a===null?'adult':(a<CHILD?'child':(a<ADULT?'teen':'adult'));
    var minor=tier!=='adult';
    box.hidden=!minor;
    box.className='guardian '+tier; card.classList.toggle('minor',minor);
    note.hidden=!minor; note.className='agenote '+tier;
    lblE.textContent=tier==='child'?'Parent / guardian email':'Email address';
    lblM.textContent=tier==='child'?'Parent / guardian mobile number':'Mobile number';
    gPhone.hidden=gEmail.hidden=(tier==='child');
    setReq(gIn[0],minor); setReq(gIn[1],minor); setReq(gIn[2],tier==='teen'); setReq(gIn[3],tier==='teen'); setReq(gIn[4],minor);
    if(tier==='child'){
      note.innerHTML='<b>Under 13 \u2014 guardian-managed account.</b> A parent or guardian registers and manages this account. Please use the parent / guardian\u2019s email and mobile number below.';
      gTxt.textContent='I am the parent / legal guardian. I am registering this child and will manage the account, and I agree to MSCare storing their dental and contact information for treatment purposes.';
    } else if(tier==='teen'){
      note.innerHTML='<b>Ages 13\u201317 \u2014 guardian consent required.</b> You can use the patient portal with your parent or guardian\u2019s involvement. We will email them to confirm.';
      gTxt.textContent='My parent / guardian knows about and consents to this account, and to MSCare storing my dental and contact information for treatment purposes.';
    }
  }
  if(bd){bd.addEventListener('input',apply);bd.addEventListener('change',apply);bd.max=new Date().toISOString().slice(0,10);}
  apply();
})();
</script>
<script>
document.querySelectorAll('[data-eye]').forEach(function(b){b.addEventListener('click',function(){
  var i=document.getElementById(b.dataset.eye);i.type=i.type==='password'?'text':'password';});});
</script>
<div class="auth-foot"><span>&copy; <?= date('Y') ?> MSCare Dental Clinic. All rights reserved.</span><span>Every smile, looked after with care.</span></div>
<script src="assets/validate.js?v=<?= filemtime(__DIR__ . '/assets/validate.js') ?>"></script>
</body></html>