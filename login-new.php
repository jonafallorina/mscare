<?php
/* MSCare admin log in - compact layout.
   Uses includes/auth.php (db(), session) when present. Test this file first; your existing login.php is untouched. */
foreach (['/config.php', '/includes/db.php', '/includes/auth.php'] as $inc) { if (is_file(__DIR__ . $inc)) require_once __DIR__ . $inc; }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('e')) { function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

if (isset($_GET['logout'])) { unset($_SESSION['mscare_admin']); header('Location: ' . basename(__FILE__)); exit; }
if (!empty($_SESSION['mscare_admin']['id'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pw   = (string)($_POST['password'] ?? '');
    if ($user === '' || $pw === '') {
        $error = 'Enter your username and password.';
    } else {
        try {
            $st = db()->prepare("SELECT * FROM admins WHERE lower(username) = lower(:u) LIMIT 1");
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--forest:#143B3B;--teal:#0B7F77;--teal-d:#096B64;--cream:#F9F9F3;--ink:#143B3B;--muted:#5F7371;--line:#DCE5E1;--bg:#E8F0EC;--err:#B3261E;}
*{box-sizing:border-box}html,body{margin:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;flex-direction:column;}
.wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:24px;}
.card{width:100%;max-width:880px;min-height:500px;display:grid;grid-template-columns:5fr 7fr;background:var(--cream);border-radius:22px;overflow:hidden;box-shadow:0 24px 60px -28px rgba(20,59,59,.45);}
.side{background:var(--forest);color:#fff;padding:34px 36px;display:flex;flex-direction:column;justify-content:space-between;}
.brand{font-family:'Playfair Display',serif;font-size:26px;letter-spacing:.2px;}
.side small{font-size:10.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:#B9D3CC;}
.main{padding:40px 52px;display:flex;flex-direction:column;justify-content:center;}
.box{width:100%;max-width:340px;margin:0 auto;}
h1{font-family:'Playfair Display',serif;font-weight:400;font-size:34px;line-height:1.1;margin:0 0 8px;}
.sub{font-size:13.5px;color:var(--muted);margin:0 0 24px;}
label{display:block;font-size:12.5px;font-weight:500;margin:0 0 6px;}
.row{display:flex;justify-content:space-between;align-items:baseline;}
.row a{font-size:12px;font-weight:600;color:var(--teal);text-decoration:none;}
.ctl{position:relative;margin-bottom:16px;}
.ctl svg.l{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#6B7F7C;}
.ctl input{width:100%;height:42px;border:1px solid transparent;border-radius:10px;background:#fff;font:inherit;font-size:14px;color:var(--ink);padding:0 40px 0 38px;outline:none;box-shadow:0 0 0 1px var(--line);}
.ctl input:focus{box-shadow:0 0 0 2px var(--teal);}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:#6B7F7C;padding:7px;cursor:pointer;}
.btn{width:100%;height:44px;border:0;border-radius:999px;background:var(--teal);color:#fff;font:inherit;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px;}
.btn:hover{background:var(--teal-d);}
.err{background:#FCEBEA;color:var(--err);border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px;}
.note{border-top:1px solid var(--line);margin-top:22px;padding-top:14px;text-align:center;font-size:12px;color:var(--muted);line-height:1.7;}
.foot{display:flex;justify-content:space-between;gap:10px;padding:12px 24px;font-size:11px;color:var(--muted);}
@media(max-width:760px){.card{grid-template-columns:1fr;min-height:0}.side{padding:22px 26px}.side small{display:none}.main{padding:30px 24px}.foot{flex-direction:column}}
</style></head>
<body>
<div class="wrap"><div class="card">
  <aside class="side"><div class="brand">MSCare</div><small>Makati &amp; Manila</small></aside>
  <main class="main"><div class="box">
    <h1>Admin log in</h1>
    <p class="sub">Welcome back. Sign in to your clinic workspace.</p>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(basename(__FILE__)) ?>" autocomplete="on">
      <label for="u">Admin username</label>
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
