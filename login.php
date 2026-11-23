<?php

foreach (['/config.php', '/includes/db.php', '/includes/auth.php'] as $inc) { if (is_file(__DIR__ . $inc)) require_once __DIR__ . $inc; }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('e')) { function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

if (isset($_GET['logout'])) { unset($_SESSION['mscare_admin']); header('Location: login.php'); exit; }
if (!empty($_SESSION['mscare_admin']['id'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pw   = (string)($_POST['password'] ?? '');
    if ($user === '' || $pw === '') {
        $error = 'Enter your username and password.';
    } else {
        try {
            $hasEmail = (bool)db()->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'admins' AND column_name = 'email'")->fetchColumn();
            $st = db()->prepare("SELECT * FROM admins WHERE lower(username) = lower(:u)" . ($hasEmail ? " OR lower(email) = lower(:u)" : "") . " LIMIT 1");
            $st->execute([':u' => $user]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($pw, (string)$row['password_hash'])) {
                $error = 'Incorrect username or password.';
            } elseif (isset($row['is_active']) && !in_array($row['is_active'], [true, 't', 'true', '1', 1], true)) {
                $error = 'This account is inactive. Contact your administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['mscare_admin'] = [
                    'id' => (int)$row['id'], 'username' => $row['username'],
                    'display_name' => $row['display_name'], 'initials' => $row['initials'],
                    'branch_id' => $row['branch_id'], 'role' => $row['role'],
                ];
                try { db()->prepare("UPDATE admins SET last_login_at = now() WHERE id = :id")->execute([':id' => $row['id']]); } catch (Throwable $ig) {}
                header('Location: index.php'); exit;
            }
        } catch (Throwable $ex) {
            $error = 'We could not reach the database right now. Please try again.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin log in · MSCare</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--navy-deep:#0B2A40;--navy:#123A5C;--navy-soft:#1C4A6E;--teal:#1FA8A0;--teal-d:#168078;--ink:#122531;--label:#0F3B5F;--muted:#65798A;--field:#E8EFFB;--field-line:#D3DEEE;--bg:#F4FAF9;--err:#B3261E;}
*{box-sizing:border-box}html,body{margin:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;flex-direction:column;}
.wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:24px;}
.card{width:100%;max-width:880px;min-height:500px;display:grid;grid-template-columns:5fr 7fr;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 24px 60px -28px rgba(11,42,64,.35);}
.side{background:linear-gradient(160deg,var(--navy-soft) 0%,var(--navy-deep) 62%);color:#fff;padding:34px 36px;display:flex;flex-direction:column;justify-content:space-between;}
.brand{display:flex;align-items:center;gap:12px;font-family:'Fraunces',serif;font-weight:600;font-size:24px;}.brand svg{color:#5FD0C6;}
.side small{font-size:10.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:#C8D8E2;}
.main{padding:40px 52px;display:flex;flex-direction:column;justify-content:center;}
.box{width:100%;max-width:340px;margin:0 auto;}
h1{font-family:'Fraunces',serif;font-weight:600;font-size:30px;line-height:1.1;margin:0 0 8px;}
.sub{font-size:13.5px;color:var(--muted);margin:0 0 24px;}
label{display:block;color:var(--label);font-size:12.5px;font-weight:600;margin:0 0 6px;}
.row{display:flex;justify-content:space-between;align-items:baseline;}
.row a{font-size:12px;font-weight:600;color:var(--teal-d);text-decoration:none;}
.ctl{position:relative;margin-bottom:16px;}
.ctl svg.l{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#6B7F7C;}
.ctl input{width:100%;height:42px;border:1px solid transparent;border-radius:10px;background:var(--field);font:inherit;font-size:14px;color:var(--ink);padding:0 40px 0 38px;outline:none;box-shadow:0 0 0 1px var(--field-line);}
.ctl input:focus{box-shadow:0 0 0 2px var(--teal);}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:#6B7F7C;padding:7px;cursor:pointer;}
.btn{width:100%;height:44px;border:0;border-radius:12px;box-shadow:0 10px 22px -10px rgba(31,168,160,.7);background:var(--teal);color:#fff;font:inherit;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px;}
.btn:hover{background:var(--teal-d);}
.err{background:#FCEBEA;color:var(--err);border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px;}
.note{border-top:1px solid #E1EAEC;margin-top:22px;padding-top:14px;text-align:center;font-size:12px;color:var(--muted);line-height:1.7;}
.foot{display:flex;justify-content:space-between;gap:10px;padding:12px 24px;font-size:11px;color:var(--muted);}
@media(max-width:760px){.card{grid-template-columns:1fr;min-height:0}.side{padding:22px 26px}.side small{display:none}.main{padding:30px 24px}.foot{flex-direction:column}}
</style></head>
<body>
<div class="wrap"><div class="card">
  <aside class="side"><div class="brand"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg><span>MSCare</span></div><small>Makati &amp; Manila</small></aside>
  <main class="main"><div class="box">
    <h1>Admin log in</h1>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="login.php" autocomplete="on">
      <label for="u">Admin username or email</label>
      <div class="ctl"><svg class="l" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/></svg>
        <input id="u" name="username" value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username" required></div>
      <div class="row"><label for="p">Password</label><a href="forgot-password.php">Forgot password?</a></div>
      <div class="ctl"><svg class="l" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        <input id="p" name="password" type="password" autocomplete="current-password" required>
        <button type="button" class="eye" id="eye" aria-label="Show password"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      <button class="btn" type="submit">Log in <span aria-hidden="true">→</span></button>
    </form>
    <div class="note">For authorized clinic staff only.<br>Need access? Contact your administrator.</div>
  </div></main>
</div></div>
<div class="foot"><span>&copy; <?= date('Y') ?> MSCare Dental Clinic. All rights reserved.</span><span>MSCare · Admin portal</span></div>
<script>document.getElementById('eye').onclick=function(){var i=document.getElementById('p');i.type=i.type==='password'?'text':'password';};</script>
</body></html>
