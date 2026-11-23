<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/blocked_times.php';

require_admin();
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$SECTIONS = [
    'clinic'        => ['Clinic Profile',          '', 'M4 21V6l8-3v18M12 9h8v12M4 21h16M8 9h.01M8 13h.01M8 17h.01M16 13h.01M16 17h.01'],
    'appointments'  => ['Appointment Preferences', '', 'M8 3v3M16 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v6M4 6v13a1 1 0 0 0 1 1h6M17 22a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM17 14.5V17l1.5 1'],
    'notifications' => ['Notifications',           '', 'M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10.3 20a2 2 0 0 0 3.4 0'],
    'email'         => ['Email Sender',            '', 'M3 6h18v12H3zM3 7l9 6 9-6'],
    'account'       => ['Account & Security',      '', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21c0-3.9 3.1-7 7-7 1.4 0 2.7.4 3.8 1.1M18 15.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM18 13v1.5M18 20.5V22M21 14.5l-1.3.8M16.3 19.7 15 20.5M21 20.5l-1.3-.8M16.3 15.3 15 14.5'],
    'services'      => ['Services & Pricing',      '', 'M6 3v6a4 4 0 0 0 8 0V3M10 13v3a5 5 0 0 0 10 0v-2M20 11a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM4 3h4M12 3h4'],
    'privacy'       => ['Privacy & System',        '', 'M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6zM9 12l2 2 4-4'],
    'blocktime'     => ['Block Time',              '', 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM5.6 5.6l12.8 12.8'],
    'team'          => ['Team & Accounts',         '', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c.8-4 3.8-6 8-6s7.2 2 8 6'],
];

function section_perm($key) {
    return ['account' => 'settings.account', 'team' => 'team.manage', 'blocktime' => 'blocktime'][$key] ?? 'settings.manage';
}
$ALL_SECTIONS = $SECTIONS;
$SECTIONS = array_filter($SECTIONS, function ($k) { return can(section_perm($k)); }, ARRAY_FILTER_USE_KEY);
$sec = (string)($_GET['s'] ?? '');
if (isset($ALL_SECTIONS[$sec]) && !can(section_perm($sec))) require_permission(section_perm($sec));
if (!isset($SECTIONS[$sec])) $sec = '';

function icon_path($d, $size = 20) {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

$db_error = null;
$pdo = null;
try {
    $pdo = db();
    if (!$pdo->query("SELECT to_regclass('public.clinic_settings')")->fetchColumn())
    $pdo->exec("CREATE TABLE IF NOT EXISTS clinic_settings (
        branch_id smallint NOT NULL,
        setting_key varchar(60) NOT NULL,
        setting_value text NOT NULL DEFAULT '',
        updated_at timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (branch_id, setting_key))");
    if (!$pdo->query("SELECT to_regclass('public.admin_audit_logs')")->fetchColumn())
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
        id bigserial PRIMARY KEY,
        admin_id integer,
        branch_id smallint,
        action varchar(80) NOT NULL,
        detail text NOT NULL DEFAULT '',
        created_at timestamptz NOT NULL DEFAULT now())");
} catch (PDOException $ex) {
    $db_error = $ex->getMessage();
}

$DEFAULTS = [
    'open_time' => '09:00', 'close_time' => '17:00',
    'slot_minutes' => '30', 'max_per_day' => '12', 'advance_days' => '60', 'cancel_hours' => '24', 'auto_confirm' => '0',
    'n_booking_alert' => '1', 'n_cancel_alert' => '1', 'n_patient_confirm' => '1', 'n_reminder' => '1', 'reminder_hours' => '24', 'alert_email' => '',
    'clinic_logo' => '', 'p_mask_records' => '1', 'p_session_minutes' => '30', 'p_backup_freq' => 'weekly',
];

function get_settings($pdo, $bid, $defaults) {
    $out = $defaults;
    $st = $pdo->prepare("SELECT setting_key, setting_value FROM clinic_settings WHERE branch_id = :b");
    $st->execute([':b' => $bid]);
    foreach ($st->fetchAll() as $r) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}
function put_setting($pdo, $bid, $key, $val) {
    $st = $pdo->prepare("INSERT INTO clinic_settings (branch_id, setting_key, setting_value, updated_at)
                         VALUES (:b, :k, :v, now())
                         ON CONFLICT (branch_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now()");
    $st->execute([':b' => $bid, ':k' => $key, ':v' => (string)$val]);
}
function audit($pdo, $admin_id, $bid, $action, $detail = '') {
    $st = $pdo->prepare("INSERT INTO admin_audit_logs (admin_id, branch_id, action, detail) VALUES (:a, :b, :x, :d)");
    $st->execute([':a' => $admin_id, ':b' => $bid, ':x' => $action, ':d' => $detail]);
}
function initials_of($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '' && strtolower($part) !== 'dr.') $letters .= mb_substr($part, 0, 1);
    }
    return mb_strtoupper(mb_substr($letters, 0, 2));
}
function pg_days_arr($v) {
    if (is_array($v)) return $v;
    $v = trim((string)$v, '{}');
    return $v === '' ? [] : array_map('trim', explode(',', $v));
}
function valid_time($t) { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t); }
function int_in($v, $min, $max, $fallback) {
    $n = filter_var($v, FILTER_VALIDATE_INT);
    return ($n === false || $n < $min || $n > $max) ? $fallback : $n;
}

if (empty($_SESSION['settings_csrf'])) $_SESSION['settings_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['settings_csrf'];

$errors = [];
$DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = (string)($_POST['section'] ?? $sec);
    if (isset($ALL_SECTIONS[$sec]) && !can(section_perm($sec))) require_permission(section_perm($sec));
    if (!isset($SECTIONS[$sec])) $sec = '';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            if ($sec === 'clinic') {
                $name = trim((string)($_POST['name'] ?? ''));
                $addr = trim((string)($_POST['address'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $days = array_values(array_intersect($DAYS, (array)($_POST['days'] ?? [])));
                $open = (string)($_POST['open_time'] ?? ''); $close = (string)($_POST['close_time'] ?? '');
                if ($name === '' || $addr === '') $errors[] = 'Clinic name and address are required.';
                if ($phone !== '' && ($pe = mobile_problem($phone, 'Contact number'))) $errors[] = $pe;
                if (!$days) $errors[] = 'Select at least one operating day.';
                if (!valid_time($open) || !valid_time($close) || $open >= $close) $errors[] = 'Opening time must be earlier than closing time.';
                $logoPath = null;
                if (!empty($_FILES['clinic_logo']['name']) && $_FILES['clinic_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $lf = $_FILES['clinic_logo'];
                    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $mime = ($lf['error'] === UPLOAD_ERR_OK && is_uploaded_file($lf['tmp_name'])) ? (new finfo(FILEINFO_MIME_TYPE))->file($lf['tmp_name']) : '';
                    if ($lf['error'] !== UPLOAD_ERR_OK) $errors[] = 'The logo could not be uploaded. Try a smaller image.';
                    elseif (!isset($ext[$mime])) $errors[] = 'Logo must be a PNG, JPG, WEBP or GIF image.';
                    elseif ($lf['size'] > 2 * 1024 * 1024) $errors[] = 'Logo must be 2 MB or smaller.';
                    else {
                        $dir = __DIR__ . '/uploads';
                        if (!is_dir($dir)) @mkdir($dir, 0775, true);
                        foreach (glob($dir . '/logo_' . (int)$branch_id . '.*') ?: [] as $old) @unlink($old);
                        $rel = 'uploads/logo_' . (int)$branch_id . '.' . $ext[$mime];
                        if (move_uploaded_file($lf['tmp_name'], __DIR__ . '/' . $rel)) $logoPath = $rel;
                        else $errors[] = 'Could not save the logo file. Check that the uploads folder is writable.';
                    }
                }
                if (!$errors) {
                    if ($logoPath !== null) put_setting($pdo, $branch_id, 'clinic_logo', $logoPath);
                    $st = $pdo->prepare("UPDATE branches SET name = :n, address = :a, phone = :p, open_days = :d WHERE id = :id");
                    $st->execute([':n' => $name, ':a' => $addr, ':p' => $phone, ':d' => '{' . implode(',', $days) . '}', ':id' => $branch_id]);
                    put_setting($pdo, $branch_id, 'open_time', $open);
                    put_setting($pdo, $branch_id, 'close_time', $close);
                    audit($pdo, $admin['id'], $branch_id, 'Clinic profile updated', $name);
                }
            } elseif ($sec === 'appointments') {
                put_setting($pdo, $branch_id, 'slot_minutes', int_in($_POST['slot_minutes'] ?? '', 10, 120, 30));
                put_setting($pdo, $branch_id, 'max_per_day', int_in($_POST['max_per_day'] ?? '', 1, 100, 12));
                put_setting($pdo, $branch_id, 'advance_days', int_in($_POST['advance_days'] ?? '', 1, 365, 60));
                put_setting($pdo, $branch_id, 'cancel_hours', int_in($_POST['cancel_hours'] ?? '', 0, 168, 24));
                put_setting($pdo, $branch_id, 'auto_confirm', !empty($_POST['auto_confirm']) ? '1' : '0');
                audit($pdo, $admin['id'], $branch_id, 'Appointment preferences updated');
            } elseif ($sec === 'notifications') {
                $email = trim((string)($_POST['alert_email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid alert email address.';
                if (!$errors) {
                    foreach (['n_booking_alert', 'n_cancel_alert', 'n_patient_confirm', 'n_reminder'] as $k) {
                        put_setting($pdo, $branch_id, $k, !empty($_POST[$k]) ? '1' : '0');
                    }
                    put_setting($pdo, $branch_id, 'reminder_hours', int_in($_POST['reminder_hours'] ?? '', 1, 168, 24));
                    put_setting($pdo, $branch_id, 'alert_email', $email);
                    audit($pdo, $admin['id'], $branch_id, 'Notification settings updated');
                }
            } elseif ($sec === 'email' && ($_POST['do'] ?? '') === 'otp') {

                $mins = int_in($_POST['otp_minutes'] ?? '', 1, 60, 10);
                put_setting($pdo, 0, 'otp_minutes', $mins);
                audit($pdo, $admin['id'], $branch_id, 'Code validity updated', $mins . ' minutes');
                flash('Saved. New verification codes are valid for ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.');
                redirect_to('settings.php?s=email');
            } elseif ($sec === 'email') {
                require_once __DIR__ . '/includes/smtp.php';
                $cur = get_settings($pdo, 0, ['smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_username' => '', 'smtp_password' => '', 'smtp_from_name' => 'MSCare Dental Clinic', 'smtp_from_email' => '']);
                $host = trim((string)($_POST['smtp_host'] ?? '')) ?: 'smtp.gmail.com';
                $port = int_in($_POST['smtp_port'] ?? '', 1, 65535, 587);
                $user = trim((string)($_POST['smtp_username'] ?? ''));
                $pass = str_replace(' ', '', (string)($_POST['smtp_password'] ?? ''));
                if ($pass === '') $pass = $cur['smtp_password'];
                $fname = trim((string)($_POST['smtp_from_name'] ?? '')) ?: 'MSCare Dental Clinic';
                $testTo = trim((string)($_POST['test_to'] ?? ''));
                if (!filter_var($user, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter the sender email address (e.g. yourclinic@gmail.com).';
                if ($pass === '') $errors[] = 'Enter the sender app password.';
                if (strtolower($user) === 'yourclinic@gmail.com' || strtolower($pass) === 'abcdefghijklmnop') $errors[] = 'Those are sample values. Enter the real sender Gmail and its App Password.';
                elseif ($pass !== '' && !preg_match('/^[a-z0-9]{16}$/i', $pass) && substr($user, -10) === '@gmail.com') $errors[] = 'A Gmail App Password is exactly 16 characters (shown as 4 groups of 4). Your normal Gmail password will not work - create an App Password at myaccount.google.com/apppasswords.';
                if ($testTo !== '' && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) $errors[] = 'The test email address is not valid.';
                if (!$errors) {
                    put_setting($pdo, 0, 'smtp_host', $host);
                    put_setting($pdo, 0, 'smtp_port', $port);
                    put_setting($pdo, 0, 'smtp_username', $user);
                    put_setting($pdo, 0, 'smtp_password', $pass);
                    put_setting($pdo, 0, 'smtp_from_name', $fname);
                    audit($pdo, $admin['id'], $branch_id, 'Email sender updated', $user);
                    if ($testTo !== '') {
                        [$ok, $err] = smtp_send_with(['host' => $host, 'port' => $port, 'username' => $user, 'password' => $pass, 'from_name' => $fname, 'from_email' => '', 'verify_ssl' => false],
                            $testTo, 'MSCare test email', '<p>It works! MSCare can now send verification codes.</p>', 'It works! MSCare can now send verification codes.');
                        if ($ok) { flash('Email sender saved. Test email sent to ' . $testTo . ' - check the inbox (and Spam).'); }
                        else     { flash('Saved, but the test email FAILED: ' . $err, 'error'); }
                        redirect_to('settings.php?s=email');
                    }
                }
            } elseif ($sec === 'account') {
                $act = (string)($_POST['do'] ?? '');
                if ($act === 'profile') {
                    $dn = trim((string)($_POST['display_name'] ?? ''));
                    if ($dn === '') $errors[] = 'Display name is required.';
                    if (!$errors) {
                        $ini = initials_of($dn) ?: 'AD';
                        $st = $pdo->prepare("UPDATE admins SET display_name = :n, initials = :i WHERE id = :id");
                        $st->execute([':n' => $dn, ':i' => $ini, ':id' => $admin['id']]);
                        $_SESSION['mscare_admin']['display_name'] = $dn;
                        $_SESSION['mscare_admin']['initials'] = $ini;
                        audit($pdo, $admin['id'], $branch_id, 'Admin profile updated');
                    }
                } elseif ($act === 'password') {
                    $cur = (string)($_POST['current_password'] ?? '');
                    $new = (string)($_POST['new_password'] ?? '');
                    $con = (string)($_POST['confirm_password'] ?? '');
                    $st = $pdo->prepare("SELECT password_hash FROM admins WHERE id = :id");
                    $st->execute([':id' => $admin['id']]);
                    $hash = (string)$st->fetchColumn();
                    if (!password_verify($cur, $hash)) $errors[] = 'Your current password is incorrect.';
                    elseif (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
                    elseif ($new !== $con) $errors[] = 'New password and confirmation do not match.';
                    if (!$errors) {
                        $st = $pdo->prepare("UPDATE admins SET password_hash = :h WHERE id = :id");
                        $st->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $admin['id']]);
                        audit($pdo, $admin['id'], $branch_id, 'Password changed');
                    }
                }
            } elseif ($sec === 'services') {
                $svc_cats = ['Basic Dental Services', 'Specialized Services'];
                if (($_POST['do'] ?? '') === 'add_service') {

                    $nm = trim((string)($_POST['new_name'] ?? ''));
                    $ct = (string)($_POST['new_category'] ?? '');
                    $raw = str_replace(',', '', trim((string)($_POST['new_price'] ?? '0')));
                    if ($raw === '') $raw = '0';
                    if ($nm === '' || mb_strlen($nm) > 80) $errors[] = 'Enter a treatment name (up to 80 characters).';
                    if (!in_array($ct, $svc_cats, true)) $errors[] = 'Choose a category.';
                    if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 99999999) $errors[] = 'Price must be a valid amount (0 or more).';
                    if (!$errors) {
                        $dup = $pdo->prepare("SELECT 1 FROM services WHERE lower(name) = lower(:n)");
                        $dup->execute([':n' => $nm]);
                        if ($dup->fetchColumn()) $errors[] = '"' . $nm . '" already exists in the list.';
                    }
                    if (!$errors) {
                        $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM services")->fetchColumn();
                        $pdo->prepare("INSERT INTO services (name, category, sort_order, price, is_active) VALUES (:n, :c, :o, :p, TRUE)")
                            ->execute([':n' => $nm, ':c' => $ct, ':o' => $next, ':p' => number_format((float)$raw, 2, '.', '')]);
                        audit($pdo, $admin['id'], $branch_id, 'Service added', $nm . ' - PHP ' . number_format((float)$raw, 2));
                        flash('"' . $nm . '" added to Services & Pricing.');
                        redirect_to('settings.php?s=services');
                    }
                } else {

                    $prices = (array)($_POST['price'] ?? []);
                    $active = (array)($_POST['active'] ?? []);
                    $one = (int)($_POST['save_one'] ?? 0);
                    $ids = $pdo->query("SELECT id FROM services WHERE name NOT ILIKE '%brace%'")->fetchAll(PDO::FETCH_COLUMN);
                    if ($one > 0) $ids = array_values(array_intersect($ids, [$one]));
                    $pdo->beginTransaction();
                    $up = $pdo->prepare("UPDATE services SET price = :p, is_active = :a WHERE id = :id");
                    foreach ($ids as $sid) {
                        $raw = str_replace(',', '', trim((string)($prices[$sid] ?? '0')));
                        if ($raw === '') $raw = '0';
                        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 99999999) { $errors[] = 'Prices must be valid amounts (0 or more).'; break; }
                        $up->execute([':p' => number_format((float)$raw, 2, '.', ''), ':a' => isset($active[$sid]) ? 'true' : 'false', ':id' => (int)$sid]);
                    }
                    if ($errors) { $pdo->rollBack(); } else {
                        $pdo->commit();
                        audit($pdo, $admin['id'], $branch_id, $one > 0 ? 'Service price updated' : 'Services & pricing updated', $one > 0 ? 'Service #' . $one : '');
                    }
                }
            } elseif ($sec === 'team') {
                $team_ok = null;
                if (isset($_POST['toggle_admin'])) {

                    $tid = (int)($_POST['admin_id'] ?? 0);
                    $to  = !empty($_POST['to']);
                    $st = $pdo->prepare("SELECT username, is_active, role FROM admins WHERE id = :id");
                    $st->execute([':id' => $tid]);
                    $row = $st->fetch();
                    if (!$row) $errors[] = 'That admin account was not found.';
                    elseif (!$to && $tid === (int)$admin['id']) $errors[] = 'You cannot deactivate your own account while signed in.';
                    elseif (!$to && $row['is_active']) {
                        $left = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE is_active = TRUE AND id <> " . $tid)->fetchColumn();
                        if ($left < 1) $errors[] = 'At least one admin account must stay active.';
                        if (($row['role'] ?? '') === 'admin') {
                            $na = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE is_active = TRUE AND role = 'admin' AND id <> " . $tid)->fetchColumn();
                            if ($na < 1) $errors[] = 'At least one Admin must stay active.';
                        }
                    }
                    if (!$errors) {
                        $pdo->prepare("UPDATE admins SET is_active = :a WHERE id = :id")
                            ->execute([':a' => $to ? 'true' : 'false', ':id' => $tid]);
                        audit($pdo, $admin['id'], $branch_id, 'Admin account ' . ($to ? 'activated' : 'deactivated'), '@' . $row['username']);
                        $team_ok = 'Admin @' . $row['username'] . ' is now ' . ($to ? 'active.' : 'inactive. They can no longer sign in.');
                    }
                } elseif (isset($_POST['toggle_dentist'])) {

                    $tid = (int)($_POST['dentist_id'] ?? 0);
                    $to  = !empty($_POST['to']);
                    $st = $pdo->prepare("SELECT name, is_active FROM dentists WHERE id = :id");
                    $st->execute([':id' => $tid]);
                    $row = $st->fetch();
                    if (!$row) $errors[] = 'That dentist was not found.';
                    elseif (!$to && $row['is_active']) {
                        $left = (int)$pdo->query("SELECT COUNT(*) FROM dentists WHERE is_active = TRUE AND id <> " . $tid)->fetchColumn();
                        if ($left < 1) $errors[] = 'At least one dentist must stay active.';
                    }
                    if (!$errors) {
                        $pdo->prepare("UPDATE dentists SET is_active = :a WHERE id = :id")
                            ->execute([':a' => $to ? 'true' : 'false', ':id' => $tid]);
                        audit($pdo, $admin['id'], $branch_id, 'Dentist ' . ($to ? 'activated' : 'deactivated'), $row['name']);
                        $team_ok = $row['name'] . ' is now ' . ($to ? 'active.' : 'inactive. They will no longer be assigned to new appointments.');
                    }
                } elseif (isset($_POST['change_role'])) {
                    $tid = (int)($_POST['admin_id'] ?? 0);
                    $nr  = (string)($_POST['new_role'] ?? '');
                    $st = $pdo->prepare("SELECT username, role, is_active FROM admins WHERE id = :id");
                    $st->execute([':id' => $tid]);
                    $row = $st->fetch();
                    if (!$row) $errors[] = 'That account was not found.';
                    elseif (!isset(rbac_role_labels()[$nr])) $errors[] = 'Choose a valid role.';
                    elseif ($tid === (int)$admin['id']) $errors[] = 'You cannot change your own role.';
                    elseif ($row['role'] === $nr) $errors[] = 'That account already has this role.';
                    elseif ($row['role'] === 'admin' && $row['is_active']) {
                        $na = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE is_active = TRUE AND role = 'admin' AND id <> " . $tid)->fetchColumn();
                        if ($na < 1) $errors[] = 'At least one Admin must remain.';
                    }
                    if (!$errors) {
                        $pdo->prepare("UPDATE admins SET role = :r WHERE id = :id")->execute([':r' => $nr, ':id' => $tid]);
                        audit($pdo, $admin['id'], $branch_id, 'Role changed', '@' . $row['username'] . ': ' . rbac_role_labels()[$row['role']] . ' -> ' . rbac_role_labels()[$nr]);
                        $team_ok = '@' . $row['username'] . ' is now ' . rbac_role_labels()[$nr] . '.';
                    }
                } elseif (isset($_POST['create_admin'])) {
                    $un = trim((string)($_POST['admin_username'] ?? ''));
                    $pw = (string)($_POST['admin_password'] ?? '');
                    $dn = trim((string)($_POST['admin_name'] ?? ''));
                    $rl = (string)($_POST['admin_role'] ?? '');
                    if (!isset(rbac_role_labels()[$rl])) $errors[] = 'Choose a valid role.';
                    $bn = (int)($_POST['admin_branch'] ?? 0);
                    if ($un === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $un)) $errors[] = 'Username must be 3-50 letters, numbers, dots, dashes or underscores.';
                    if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
                    elseif (strlen($pw) > 72) $errors[] = 'Password must be 72 characters or fewer.';
                    elseif (!preg_match('/[A-Z]/', $pw)) $errors[] = 'Password needs at least one uppercase letter.';
                    elseif (!preg_match('/[0-9]/', $pw)) $errors[] = 'Password needs at least one number.';
                    elseif (!preg_match('/[^A-Za-z0-9]/', $pw)) $errors[] = 'Password needs at least one special character (for example ! @ # $ %).';
                    if ($dn === '') $errors[] = 'Display name is required.';
                                        if (!$errors) {
                        $st = $pdo->prepare("SELECT 1 FROM admins WHERE LOWER(username) = LOWER(:u)");
                        $st->execute([':u' => $un]);
                        if ($st->fetchColumn()) $errors[] = 'That username is already taken.';
                        $st = $pdo->prepare("SELECT 1 FROM branches WHERE id = :b");
                        $st->execute([':b' => $bn]);
                        if (!$st->fetchColumn()) $errors[] = 'Choose a valid branch.';
                    }
                    if (!$errors) {
                        $ins = $pdo->prepare("INSERT INTO admins (username, password_hash, branch_id, display_name, initials, role, is_active)
                                              VALUES (:u, :h, :b, :n, :i, :r, TRUE)");
                        $args = [':u' => $un, ':h' => password_hash($pw, PASSWORD_DEFAULT), ':b' => $bn, ':n' => $dn, ':i' => initials_of($dn) ?: 'AD', ':r' => $rl];
                        try { $ins->execute($args); }
                        catch (PDOException $ex) { if (is_sequence_conflict($ex)) { fix_sequences($pdo); $ins->execute($args); } else throw $ex; }
                        audit($pdo, $admin['id'], $branch_id, 'Account created', '@' . $un . ' as ' . rbac_role_labels()[$rl]);
                        $team_ok = rbac_role_labels()[$rl] . ' account @' . $un . ' created.';
                    }
                } elseif (isset($_POST['add_dentist'])) {
                    $dn = trim((string)($_POST['dentist_name'] ?? ''));
                    if ($dn === '') $errors[] = 'Enter the dentist name.';
                    elseif ($pe = letters_problem($dn, 'Dentist name')) $errors[] = $pe;
                    if (!$errors) {
                        $ins = $pdo->prepare("INSERT INTO dentists (name, is_active) VALUES (:n, TRUE)");
                        try { $ins->execute([':n' => $dn]); }
                        catch (PDOException $ex) { if (is_sequence_conflict($ex)) { fix_sequences($pdo); $ins->execute([':n' => $dn]); } else throw $ex; }
                        audit($pdo, $admin['id'], $branch_id, 'Dentist added', $dn);
                        $team_ok = $dn . ' added.';
                    }
                } elseif (isset($_POST['add_branch'])) {
                    $code = trim((string)($_POST['branch_code'] ?? ''));
                    $bnm  = trim((string)($_POST['branch_name'] ?? ''));
                    $city = trim((string)($_POST['branch_city'] ?? ''));
                    $addr = trim((string)($_POST['branch_address'] ?? ''));
                    $days = array_values(array_intersect($DAYS, (array)($_POST['branch_days'] ?? [])));
                    if ($code === '' || $bnm === '' || $city === '' || $addr === '') $errors[] = 'Fill in the branch code, name, city and address.';
                    if ($city !== '' && ($pe = letters_problem($city, 'City'))) $errors[] = $pe;
                    if (!$days) $errors[] = 'Select at least one open day.';
                    if (!$errors) {
                        $st = $pdo->prepare("SELECT 1 FROM branches WHERE LOWER(code) = LOWER(:c)");
                        $st->execute([':c' => $code]);
                        if ($st->fetchColumn()) $errors[] = 'That branch code is already used.';
                    }
                    if (!$errors) {
                        $ins = $pdo->prepare("INSERT INTO branches (code, name, city, address, open_days) VALUES (:c, :n, :ci, :a, :d)");
                        $args = [':c' => $code, ':n' => $bnm, ':ci' => $city, ':a' => $addr, ':d' => '{' . implode(',', $days) . '}'];
                        try { $ins->execute($args); }
                        catch (PDOException $ex) { if (is_sequence_conflict($ex)) { fix_sequences($pdo); $ins->execute($args); } else throw $ex; }
                        audit($pdo, $admin['id'], $branch_id, 'Branch created', $bnm);
                        $team_ok = 'Branch ' . $bnm . ' created.';
                    }
                }
                if (!$errors && $team_ok) { flash($team_ok); redirect_to('settings.php?s=team'); }
            } elseif ($sec === 'privacy') {
                $freq = in_array($_POST['p_backup_freq'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['p_backup_freq'] : 'weekly';
                put_setting($pdo, $branch_id, 'p_mask_records', !empty($_POST['p_mask_records']) ? '1' : '0');
                put_setting($pdo, $branch_id, 'p_session_minutes', int_in($_POST['p_session_minutes'] ?? '', 5, 480, 30));
                put_setting($pdo, $branch_id, 'p_backup_freq', $freq);
                audit($pdo, $admin['id'], $branch_id, 'Privacy & system settings updated');
            }
            if (!$errors && $sec !== '' && $sec !== 'team') {
                flash($SECTIONS[$sec][0] . ' saved.');
                redirect_to('settings.php?s=' . $sec);
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not save: ' . $ex->getMessage();
        }
    }
}

$S = $DEFAULTS; $branch = null; $dentists = []; $services = []; $logs = []; $bt_blocks = []; $bt_error = null; $bt_dentist = '';
if ($pdo) {
    try {
        $S = get_settings($pdo, $branch_id, $DEFAULTS);
        $st = $pdo->prepare("SELECT * FROM branches WHERE id = :id");
        $st->execute([':id' => $branch_id]);
        $branch = $st->fetch() ?: null;
        if ($sec === 'clinic') {
            $dentists = $pdo->query("SELECT * FROM dentists WHERE is_active = TRUE ORDER BY name")->fetchAll();
        } elseif ($sec === 'blocktime') {
            blocktime_ensure_schema($pdo);
            $bt_blocks = blocktime_fetch($pdo, $branch_id);
            foreach ($bt_blocks as &$bb) {
                $bscope = $bb['branch_id'] === null ? [$branch_id] : [(int)$bb['branch_id']];
                $bb['affected'] = count(blocktime_affected($pdo, $bscope, $bb['date_from'], $bb['date_to'], $bb['all_day'], $bb['start_min'], $bb['end_min']));
            }
            unset($bb);
            $bt_dentist = (string)$pdo->query("SELECT name FROM dentists WHERE is_active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
        } elseif ($sec === 'services') {
            $services = $pdo->query("SELECT * FROM services WHERE name NOT ILIKE '%brace%' ORDER BY category, sort_order, name")->fetchAll();
        } elseif ($sec === 'privacy') {
            $st = $pdo->prepare("SELECT l.*, a.display_name, a.initials AS admin_initials FROM admin_audit_logs l LEFT JOIN admins a ON a.id = l.admin_id
                                 WHERE l.branch_id = :b ORDER BY l.created_at DESC, l.id DESC LIMIT 50");
            $st->execute([':b' => $branch_id]);
            $logs = $st->fetchAll();
        }
    } catch (PDOException $ex) {
        $db_error = $ex->getMessage();
    }
}
$flash = take_flash();
function checked($v) { return $v === '1' ? ' checked' : ''; }
function switch_row($name, $title, $sub, $on) {
    return '<label class="set-switch"><span class="set-switch-text"><strong>' . e($title) . '</strong></span>'
         . '<input type="checkbox" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '><span class="set-slider" aria-hidden="true"></span></label>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
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
      <a class="nav-item active" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1.5 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= e($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= e($admin['display_name']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>Settings</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= e($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= e($admin['initials']) ?></div>
      </div>
    </div>
    <?php ?>
    <div class="content<?= ($sec === '' && can('settings.manage')) ? ' set-fit' : '' ?>">
  <?php if (!empty($db_error)): ?><div class="login-error" style="display:block;margin-bottom:16px;"><?= e($db_error) ?></div><?php endif; ?>
  <?php foreach ($errors as $er): ?><div class="login-error" style="display:block;margin-bottom:16px;"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($sec === ''): ?>
  <div class="card set-card">
    <nav class="set-list" aria-label="Settings sections">
      <?php foreach ($SECTIONS as $key => [$label, $desc, $d]): ?>
        <a class="set-row" href="settings.php?s=<?= e($key) ?>">
          <span class="set-text"><strong><?= e($label) ?></strong></span>
          <svg class="set-chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

<?php else: [$label, $desc, $d] = $SECTIONS[$sec]; ?>
  <a class="set-back" href="settings.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg> <?= $sec === 'clinic' ? 'Back to settings' : 'Back' ?></a>
  <div class="card set-card set-panel<?= ($sec === 'clinic' || $sec === 'appointments' || $sec === 'notifications' || $sec === 'email' || $sec === 'account' || $sec === 'services' || $sec === 'privacy' || $sec === 'team' || $sec === 'blocktime') ? ' cp-panel' : '' ?>">
    <?php if ($sec === 'clinic'): ?>
    <div class="cp-head">
      <div class="cp-head-main">
        <div><h3>Clinic profile</h3></div>
      </div>
      <span class="cp-active"><i></i>Profile is active</span>
    </div>
    <?php else: ?>
    <div class="set-title<?= ($sec === 'appointments' || $sec === 'notifications' || $sec === 'email' || $sec === 'account' || $sec === 'services' || $sec === 'privacy' || $sec === 'team' || $sec === 'blocktime') ? ' ap-title' : '' ?>">
      <div><h3><?= e($label) ?></h3></div>
      <?php if ($sec === 'team'): ?><a class="btn btn-primary tm-addbtn" href="#tmNewAccount"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Add team member</a><?php endif; ?>
    </div>
    <?php endif; ?>

  <?php if ($sec === 'clinic' && $branch): $open = pg_days_arr($branch['open_days'] ?? ''); ?>
    <form method="post" class="set-form cp-form" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="clinic">
      <div class="cp-grid">
        <div class="cp-col">
          <section class="cp-card">
            <div class="cp-card-head">

              <div><h4>Clinic details</h4></div>
            </div>
            <div class="form-grid cp-fields">
              <div class="full"><label>Clinic name</label><input type="text" name="name" value="<?= e($_POST['name'] ?? $branch['name']) ?>" required></div>
              <div class="full"><label>Clinic address</label><input type="text" name="address" value="<?= e($_POST['address'] ?? $branch['address']) ?>" required></div>
              <div><label>Contact number</label><input type="text" name="phone" data-mobile value="<?= e($_POST['phone'] ?? ph_mobile_prefill($branch['phone'])) ?>"></div>
              <div><label>City</label><input type="text" value="<?= e($branch['city']) ?>" disabled></div>
            </div>
          </section>

          <section class="cp-card">
            <div class="cp-card-head">

              <div><h4>Operating hours</h4></div>
            </div>
            <div class="form-grid cp-fields">
              <div><label>Opening time</label><input type="time" name="open_time" value="<?= e($_POST['open_time'] ?? $S['open_time']) ?>" required></div>
              <div><label>Closing time</label><input type="time" name="close_time" value="<?= e($_POST['close_time'] ?? $S['close_time']) ?>" required></div>
              <div class="full">
                <label>Operating days</label>
                <div class="day-pick cp-days">
                  <?php foreach ($DAYS as $dy): ?>
                    <label class="day-chip"><input type="checkbox" name="days[]" value="<?= $dy ?>"<?= in_array($dy, $open, true) ? ' checked' : '' ?>><span><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg><?= $dy ?></span></label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </section>
        </div>

        <div class="cp-col">
          <section class="cp-card">
            <div class="cp-card-head">

              <div><h4>Clinic branding</h4></div>
            </div>
            <div class="cp-drop" id="cpDrop">
              <span class="cp-logo" id="cpLogo"><?php if (!empty($S['clinic_logo'])): ?><img src="<?= e($S['clinic_logo']) ?>?v=<?= (int)@filemtime(__DIR__ . '/' . $S['clinic_logo']) ?>" alt="Clinic logo"><?php else: ?><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg><?php endif; ?></span>
              <strong>Upload clinic logo</strong>
              <small id="cpFileHint">PNG, JPG, WEBP or GIF &middot; Max 2 MB</small>
              <label class="btn btn-ghost cp-choose">Choose file<input type="file" name="clinic_logo" id="cpFile" accept="image/png,image/jpeg,image/webp,image/gif"></label>
            </div>
          </section>

          <?php if ($dentists): ?>
          <section class="cp-card">
            <div class="cp-card-head">

              <div><h4>Dentist on duty</h4></div>
            </div>
            <div class="cp-dentists">
              <?php foreach ($dentists as $dn): ?>
                <div class="cp-dentist"><span class="avatar"><?= e(initials_of($dn['name'])) ?></span><div><strong><?= e($dn['name']) ?> <i class="cp-dot" title="Active"></i></strong><small><?= e($dn['title']) ?><?= !empty($dn['phone']) ? ' &middot; ' . e($dn['phone']) : '' ?></small></div></div>
              <?php endforeach; ?>
            </div>
          </section>
          <?php endif; ?>
        </div>
      </div>
      <div class="cp-actions">
        <a class="btn btn-ghost" href="settings.php?s=clinic">Cancel changes</a>
        <button class="btn btn-primary" type="submit" data-save><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg> Save</button>
      </div>
    </form>
    <script>
    (function(){
      var f=document.getElementById('cpFile'),logo=document.getElementById('cpLogo'),hint=document.getElementById('cpFileHint');
      if(!f)return;
      f.addEventListener('change',function(){
        var file=f.files&&f.files[0];if(!file)return;
        hint.textContent=file.name;
        if(file.type.indexOf('image/')===0){logo.innerHTML='';var im=document.createElement('img');im.alt='New logo';im.src=URL.createObjectURL(file);logo.appendChild(im);}
      });
    })();
    </script>

  <?php elseif ($sec === 'appointments'): ?>
    <form method="post" class="ap-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="appointments">

      <section class="ap-card">
        <div class="ap-intro">

          <div><h4>Schedule capacity</h4></div>
        </div>
        <div class="ap-fields">
          <div class="ap-field"><label for="ap_slot">Appointment length</label>
            <div class="ap-input"><input id="ap_slot" type="number" name="slot_minutes" min="10" max="120" step="5" value="<?= e($S['slot_minutes']) ?>"><span>minutes</span></div></div>
          <div class="ap-field"><label for="ap_max">Daily appointment limit</label>
            <div class="ap-input"><input id="ap_max" type="number" name="max_per_day" min="1" max="100" value="<?= e($S['max_per_day']) ?>"><span>patients</span></div></div>
        </div>
      </section>

      <section class="ap-card">
        <div class="ap-intro">

          <div><h4>Booking rules</h4></div>
        </div>
        <div class="ap-fields">
          <div class="ap-field"><label for="ap_adv">Advance booking window</label>
            <div class="ap-input"><input id="ap_adv" type="number" name="advance_days" min="1" max="365" value="<?= e($S['advance_days']) ?>"><span>days</span></div></div>
          <div class="ap-field"><label for="ap_cut">Change or cancellation cut-off</label>
            <div class="ap-input"><input id="ap_cut" type="number" name="cancel_hours" min="0" max="168" value="<?= e($S['cancel_hours']) ?>"><span>hours</span></div></div>
        </div>
      </section>

      <section class="ap-card">
        <div class="ap-intro">

          <div><h4>Booking confirmation</h4></div>
        </div>
        <div class="ap-fields one">
          <label class="ap-toggle">
            <span class="ap-toggle-text"><strong>Automatically confirm new bookings</strong></span>
            <input type="checkbox" name="auto_confirm" value="1" id="ap_auto"<?= $S['auto_confirm'] === '1' ? ' checked' : '' ?>><span class="set-slider" aria-hidden="true"></span>
          </label>
        </div>
      </section>

      <div class="ap-footer">
        <div class="ap-btns"><a class="btn btn-ghost" href="settings.php">Cancel</a><button class="btn btn-primary" type="submit" data-save>Save</button></div>
      </div>
    </form>

  <?php elseif ($sec === 'notifications'): ?>
    <form method="post" class="ap-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="notifications">

      <section class="ap-card">
        <div class="ap-intro">

          <div><h4>Clinic alerts</h4></div>
        </div>
        <div class="np-panel">
          <label class="np-row"><span class="np-text"><strong>New booking alerts</strong></span>
            <input type="checkbox" name="n_booking_alert" value="1"<?= $S['n_booking_alert'] === '1' ? ' checked' : '' ?>><span class="set-slider" aria-hidden="true"></span></label>
          <label class="np-row"><span class="np-text"><strong>Cancellation alerts</strong></span>
            <input type="checkbox" name="n_cancel_alert" value="1"<?= $S['n_cancel_alert'] === '1' ? ' checked' : '' ?>><span class="set-slider" aria-hidden="true"></span></label>
          <div class="np-row np-block">
            <label for="np_email" class="np-label">Send clinic alerts to</label>
            <div class="np-email"><input id="np_email" type="email" name="alert_email" placeholder="clinic@example.com" value="<?= e($_POST['alert_email'] ?? $S['alert_email']) ?>"></div>
          </div>
        </div>
      </section>

      <section class="ap-card">
        <div class="ap-intro">

          <div><h4>Patient emails</h4></div>
        </div>
        <div class="np-panel">
          <label class="np-row"><span class="np-text"><strong>Booking confirmations</strong></span>
            <input type="checkbox" name="n_patient_confirm" value="1"<?= $S['n_patient_confirm'] === '1' ? ' checked' : '' ?>><span class="set-slider" aria-hidden="true"></span></label>
          <label class="np-row"><span class="np-text"><strong>Appointment reminders</strong></span>
            <input type="checkbox" name="n_reminder" value="1"<?= $S['n_reminder'] === '1' ? ' checked' : '' ?>><span class="set-slider" aria-hidden="true"></span></label>
          <div class="np-row np-timing">

            <span class="np-text"><strong>Reminder timing</strong></span>
            <div class="ap-input np-hours"><input type="number" name="reminder_hours" min="1" max="168" aria-label="Hours before appointment" value="<?= e($_POST['reminder_hours'] ?? $S['reminder_hours']) ?>"><span>hours before</span></div>
          </div>
        </div>
      </section>

      <div class="ap-footer">
        <div class="ap-btns"><a class="btn btn-ghost" href="settings.php">Cancel</a><button class="btn btn-primary" type="submit" data-save>Save</button></div>
      </div>
    </form>

  <?php elseif ($sec === 'email'): ?>
    <?php $E = get_settings($pdo, 0, ['smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_username' => '', 'smtp_password' => '', 'smtp_from_name' => 'MSCare Dental Clinic']);
          $pwSaved = $E['smtp_password'] !== ''; $portVal = (string)($_POST['smtp_port'] ?? $E['smtp_port']); ?>
    <form method="post" class="ap-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="email">
      <input type="hidden" name="test_to" id="es_test_to" value="">

      <section class="ap-card">
        <div class="ap-intro">

          <div><h4>Sender account</h4></div>
        </div>
        <div class="ap-fields">
          <div class="ap-field"><label for="es_user">Sender email</label>
            <div class="ef-input"><input id="es_user" type="email" name="smtp_username" placeholder="yourclinic@gmail.com" value="<?= e($_POST['smtp_username'] ?? $E['smtp_username']) ?>" required></div></div>
          <div class="ap-field"><label for="es_name">Sender name</label>
            <div class="ef-input"><input id="es_name" type="text" name="smtp_from_name" value="<?= e($_POST['smtp_from_name'] ?? $E['smtp_from_name']) ?>"></div></div>
          <div class="ap-field full"><label for="es_pass">App password</label>
            <div class="ef-input"><input id="es_pass" type="password" name="smtp_password" placeholder="<?= $pwSaved ? '&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;' : 'xxxx xxxx xxxx xxxx' ?>" autocomplete="new-password" <?= $pwSaved ? '' : 'required' ?>><?= $pwSaved ? '<em class="ef-badge">Encrypted</em>' : '' ?></div></div>
        </div>
      </section>

      <section class="ap-card">
        <div class="ap-intro">

          <div><h4>Mail server</h4></div>
        </div>
        <div class="ap-fields">
          <div class="ap-field"><label for="es_host">SMTP server</label>
            <div class="ef-input"><input id="es_host" type="text" name="smtp_host" value="<?= e($_POST['smtp_host'] ?? $E['smtp_host']) ?>"></div></div>
          <div class="ap-field"><label for="es_port">Port</label>
            <div class="ef-input"><input id="es_port" type="number" name="smtp_port" min="1" max="65535" value="<?= e($portVal) ?>"><em class="ef-badge violet" id="es_port_badge"><?= $portVal === '465' ? 'SSL' : 'TLS' ?></em></div></div>
          <div class="ap-field full">
            <div class="ef-secure"><div><strong>Secure connection</strong></div></div>
          </div>
        </div>
      </section>

      <div class="ap-footer">
        <div class="ap-btns"><a class="btn btn-ghost" href="settings.php">Cancel</a><button class="btn btn-primary" type="submit" data-save>Save</button></div>
      </div>
    </form>
    <script>
    (function(){
      var u=document.getElementById('es_user'),t=document.getElementById('es_test_to'),p=document.getElementById('es_port'),b=document.getElementById('es_port_badge');
      if(u&&t){var f=u.form;f.addEventListener('submit',function(){t.value=u.value.trim();});}
      if(p&&b){p.addEventListener('input',function(){b.textContent=(p.value==='465')?'SSL':'TLS';});}
    })();
    </script>

    <?php $otpMin = (int)get_settings($pdo, 0, ['otp_minutes' => '10'])['otp_minutes']; if ($otpMin < 1 || $otpMin > 60) $otpMin = 10; ?>
    <form method="post" class="ap-form" style="margin-top:18px;">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="email"><input type="hidden" name="do" value="otp">
      <section class="ap-card ap-otp">
        <div class="ap-intro">

          <div><h4>Verification code validity</h4></div>
        </div>
        <div class="ap-otp-row">
          <div class="ap-field"><label for="es_otp" class="ap-otp-label">Code expires after</label>
            <div class="ap-input"><input id="es_otp" type="number" name="otp_minutes" min="1" max="60" step="1" value="<?= (int)($_POST['otp_minutes'] ?? $otpMin) ?>" required><span>minutes</span></div></div>
          <button class="btn btn-ghost" type="submit" data-save>Save</button>
        </div>
      </section>
    </form>

  <?php elseif ($sec === 'account'): ?>
    <form method="post" class="ac-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="account"><input type="hidden" name="do" value="profile">
      <section class="ac-card">
        <header class="ac-head"><div><h4>Profile details</h4></div></header>
        <div class="ac-body form-grid">
          <div class="full"><label for="ac_dn">Display name</label><input id="ac_dn" type="text" name="display_name" value="<?= e($_POST['display_name'] ?? $admin['display_name']) ?>" required></div>
          <div><label for="ac_un">Username</label><input id="ac_un" type="text" value="<?= e($admin['username']) ?>" disabled></div>
          <div><label for="ac_role">Role &amp; location</label><input id="ac_role" type="text" value="<?= e(ucfirst($admin['role'])) ?> · <?= e($cinfo['label']) ?>" disabled></div>
        </div>
        <footer class="ac-foot"><button class="btn btn-primary" type="submit" data-save>Save</button></footer>
      </section>
    </form>

    <form method="post" class="ac-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="account"><input type="hidden" name="do" value="password">
      <section class="ac-card">
        <header class="ac-head"><div><h4>Change password</h4></div></header>
        <div class="ac-body form-grid">
          <div class="full"><label for="ac_current_password">Current password</label><div class="ac-pw"><input id="ac_current_password" type="password" name="current_password" autocomplete="current-password" required><button type="button" class="ac-eye" aria-label="Show password" aria-pressed="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
          <div><label for="ac_new_password">New password</label><div class="ac-pw"><input id="ac_new_password" type="password" name="new_password" autocomplete="new-password" minlength="8" required><button type="button" class="ac-eye" aria-label="Show password" aria-pressed="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
          <div><label for="ac_confirm_password">Confirm new password</label><div class="ac-pw"><input id="ac_confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required><button type="button" class="ac-eye" aria-label="Show password" aria-pressed="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
          <div class="full"></div>
        </div>
        <footer class="ac-foot"><button class="btn btn-primary" type="submit" data-save data-ctitle="Update password?" data-cmsg="Do you want to update your password?" data-cok="Update">Update</button></footer>
      </section>
    </form>
    <script>
    (function(){
      document.querySelectorAll('.ac-eye').forEach(function(b){
        b.addEventListener('click',function(){
          var i=b.parentNode.querySelector('input');if(!i)return;
          var show=i.type==='password';i.type=show?'text':'password';
          b.setAttribute('aria-pressed',show?'true':'false');b.setAttribute('aria-label',show?'Hide password':'Show password');
          b.classList.toggle('on',show);
        });
      });
    })();
    </script>

  <?php elseif ($sec === 'services'): ?>
    <?php
      $fmtP = function ($n) { $n = (float)$n; return floor($n) == $n ? number_format($n, 0, '.', '') : number_format($n, 2, '.', ''); };
      $services = $services ?? [];
      $svcTotal = count($services);
      $svcActive = 0; $svcSum = 0.0; $svcGroups = [];
      foreach ($services as $sv) { if (!empty($sv['is_active'])) $svcActive++; $svcSum += (float)$sv['price']; $svcGroups[$sv['category']][] = $sv; }
      $svcAvg = $svcTotal ? $svcSum / $svcTotal : 0;
    ?>
    <div class="sv-stats">
      <div class="sv-stat"><span>Total services</span><strong><?= (int)$svcTotal ?></strong></div>
      <div class="sv-stat"><span>Currently active</span><strong><span id="svActive"><?= (int)$svcActive ?></span><em>of <?= (int)$svcTotal ?></em></strong></div>
      <div class="sv-stat"><span>Average price</span><strong>&#8369;<?= number_format($svcAvg, 0) ?></strong></div>
    </div>

    <form method="post" class="sv-add">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="services"><input type="hidden" name="do" value="add_service">
      <h4>Add a new service</h4>
      <div class="sv-add-grid">
        <div class="sv-add-name"><label for="sv_new_name">Treatment name</label><input id="sv_new_name" type="text" name="new_name" maxlength="80" required placeholder="e.g. Dental crown" value="<?= e($_POST['new_name'] ?? '') ?>"></div>
        <div><label for="sv_new_cat">Category</label>
          <select id="sv_new_cat" name="new_category" required><?php foreach (['Basic Dental Services', 'Specialized Services'] as $c): ?><option<?= (($_POST['new_category'] ?? '') === $c) ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div><label for="sv_new_price">Price</label><div class="sv-money"><span>&#8369;</span><input id="sv_new_price" type="text" inputmode="decimal" name="new_price" placeholder="0" value="<?= e($_POST['new_price'] ?? '') ?>"></div></div>
        <div><button class="btn btn-primary" type="submit"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Add service</button></div>
      </div>
    </form>

    <div class="sv-listhead">
      <div><h4>Service list</h4></div>
      <label class="sv-search"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" id="svSearch" placeholder="Search services" aria-label="Search services" autocomplete="off"></label>
    </div>

    <form method="post" id="svcForm" class="sv-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="services">
      <button type="submit" class="svc-default" tabindex="-1" aria-hidden="true"></button>
      <?php if (!$services): ?>
        <p class="set-note">No services found in the database.</p>
      <?php else: foreach ($svcGroups as $cat => $list):
          $catActive = 0; foreach ($list as $x) if (!empty($x['is_active'])) $catActive++; ?>
        <section class="sv-cat">
          <header class="sv-cat-head">
            <div><h5><?= e($cat) ?></h5><small><?= count($list) ?> service<?= count($list) === 1 ? '' : 's' ?></small></div>
            <span class="sv-badge"><b class="sv-cat-active"><?= (int)$catActive ?></b> active</span>
          </header>
          <div class="sv-cols"><span>Service</span><span>Price</span><span>Status</span></div>
          <?php foreach ($list as $sv): $sid = (int)$sv['id'];
              $orig = $fmtP($sv['price']);
              $pv = isset($_POST['price'][$sid]) ? (string)$_POST['price'][$sid] : $orig;
              $on = !empty($sv['is_active']); ?>
          <div class="sv-row" data-name="<?= e(mb_strtolower($sv['name'])) ?>">
            <div class="sv-name"><strong><?= e($sv['name']) ?></strong><small class="sv-avail"><?= $on ? 'Available for booking' : 'Hidden from booking' ?></small></div>
            <label class="sv-price"><span>&#8369;</span><input type="text" inputmode="decimal" name="price[<?= $sid ?>]" value="<?= e($pv) ?>" data-orig="<?= e($orig) ?>" aria-label="Price for <?= e($sv['name']) ?>"></label>
            <label class="sv-status"><input type="checkbox" name="active[<?= $sid ?>]" value="1" data-orig="<?= $on ? '1' : '0' ?>"<?= $on ? ' checked' : '' ?>><span class="set-slider" aria-hidden="true"></span><em><?= $on ? 'Active' : 'Inactive' ?></em></label>
          </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
        <p class="set-note" id="svEmpty" hidden>No services match your search.</p>
      <?php endif; ?>
      <div class="sv-foot">
        <span id="svState">All changes are saved.</span>
        <div class="sv-foot-btns"><a class="btn btn-ghost" id="svCancel" href="settings.php?s=services" aria-disabled="true">Cancel</a><button class="btn btn-primary" type="submit" id="svSave" data-save disabled>Save</button></div>
      </div>
    </form>
    <script>
    (function(){
      var f=document.getElementById('svcForm'); if(!f) return;
      var save=document.getElementById('svSave'), cancel=document.getElementById('svCancel'), state=document.getElementById('svState'),
          stat=document.getElementById('svActive'), search=document.getElementById('svSearch'), empty=document.getElementById('svEmpty');
      var rows=[].slice.call(f.querySelectorAll('.sv-row')), cats=[].slice.call(f.querySelectorAll('.sv-cat'));
      function num(v){var n=parseFloat(String(v).replace(/,/g,'').trim()||'0');return isNaN(n)?NaN:n;}
      function rowState(r){
        var i=r.querySelector('.sv-price input'), c=r.querySelector('.sv-status input');
        r.querySelector('.sv-status em').textContent=c.checked?'Active':'Inactive';
        r.querySelector('.sv-avail').textContent=c.checked?'Available for booking':'Hidden from booking';
        var a=num(i.value), b=num(i.dataset.orig);
        return (a!==b)||(c.checked!==(c.dataset.orig==='1'));
      }
      function refresh(){
        var dirty=false, total=0;
        rows.forEach(function(r){var d=rowState(r); r.classList.toggle('dirty',d); if(d) dirty=true; if(r.querySelector('.sv-status input').checked) total++;});
        cats.forEach(function(c){var n=c.querySelectorAll('.sv-status input:checked').length; var b=c.querySelector('.sv-cat-active'); if(b) b.textContent=n;});
        if(stat) stat.textContent=total;
        save.disabled=!dirty; cancel.setAttribute('aria-disabled',dirty?'false':'true');
        state.textContent=dirty?'You have unsaved changes.':'All changes are saved.';
        state.classList.toggle('dirty',dirty);
      }
      f.addEventListener('input',refresh); f.addEventListener('change',refresh);
      f.querySelectorAll('.sv-price input').forEach(function(i){i.addEventListener('focus',function(){i.select();});});
      cancel.addEventListener('click',function(e){if(cancel.getAttribute('aria-disabled')==='true')e.preventDefault();});
      if(search){search.addEventListener('input',function(){
        var q=search.value.trim().toLowerCase(), any=false;
        rows.forEach(function(r){var hit=!q||r.dataset.name.indexOf(q)!==-1; r.hidden=!hit;});
        cats.forEach(function(c){var v=c.querySelectorAll('.sv-row:not([hidden])').length>0; c.hidden=!v; if(v) any=true;});
        if(empty) empty.hidden=any||!q;
      });}
      refresh();
    })();
    </script>

  <?php elseif ($sec === 'privacy'): ?>
    <?php
      $logs = $logs ?? [];
      $auCat = function ($action) {
          if (preg_match('/password|privacy|code validity|security|sign.?in|login|lock/i', $action)) return ['Security', 'security'];
          if (preg_match('/admin|account|role|profile/i', $action)) return ['Account', 'account'];
          return ['Clinic', 'clinic'];
      };
      $auDefault = [
          'Appointment preferences updated' => 'Booking rules and appointment duration were changed.',
          'Notification settings updated'   => 'Alert and reminder preferences were changed.',
          'Privacy & system settings updated' => 'Privacy and session preferences were changed.',
          'Password changed'                => 'An administrator password was changed.',
          'Admin profile updated'           => 'A profile name was changed.',
          'Services & pricing updated'      => 'Service prices or availability were changed.',
          'Service price updated'           => 'A service price was changed.',
      ];
    ?>
    <form method="post" class="pv-form" id="pvForm">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="privacy">

      <section class="ac-card">
        <header class="ac-head"><div><h4>Patient data</h4></div></header>
        <div class="ac-body">
          <label class="pv-toggle">
            <span class="pv-toggle-text"><strong>Mask sensitive details</strong></span>
            <span class="pv-toggle-ctl"><em id="pvMaskState"><?= $S['p_mask_records'] === '1' ? 'On' : 'Off' ?></em><input type="checkbox" name="p_mask_records" value="1" id="pvMask"<?= $S['p_mask_records'] === '1' ? ' checked' : '' ?>><span class="set-slider" aria-hidden="true"></span></span>
          </label>
        </div>
      </section>

      <section class="ac-card">
        <header class="ac-head"><div><h4>Session &amp; backup</h4></div></header>
        <div class="ac-body pv-grid">
          <div class="pv-field"><label for="pv_min">Auto log-out</label>
            <div class="pv-input"><input id="pv_min" type="number" name="p_session_minutes" min="5" max="480" value="<?= e($S['p_session_minutes']) ?>"><span>minutes idle</span></div></div>
          <div class="pv-field"><label for="pv_bk">Backup schedule</label>
            <div class="pv-select"><select id="pv_bk" name="p_backup_freq"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $v): ?><option value="<?= $k ?>"<?= $S['p_backup_freq'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div></div>
        </div>
      </section>

      <div class="sv-foot">
        <span class="pv-state" id="pvState">All settings are saved.</span>
        <div class="sv-foot-btns"><a class="btn btn-ghost" id="pvCancel" href="settings.php?s=privacy" aria-disabled="true">Cancel</a><button class="btn btn-primary" type="submit" id="pvSave" data-save disabled>Save</button></div>
      </div>
    </form>
    <script>
    (function(){
      var f=document.getElementById('pvForm'); if(!f) return;
      var save=document.getElementById('pvSave'), cancel=document.getElementById('pvCancel'), state=document.getElementById('pvState'), mask=document.getElementById('pvMask'), ms=document.getElementById('pvMaskState');
      var els=[].slice.call(f.querySelectorAll('input[name^="p_"],select[name^="p_"]'));
      els.forEach(function(el){el.dataset.o=(el.type==='checkbox')?(el.checked?'1':'0'):el.value;});
      function cur(el){return (el.type==='checkbox')?(el.checked?'1':'0'):el.value;}
      function refresh(){
        var d=els.some(function(el){return cur(el)!==el.dataset.o;});
        if(ms&&mask) ms.textContent=mask.checked?'On':'Off';
        save.disabled=!d; cancel.setAttribute('aria-disabled',d?'false':'true');
        state.textContent=d?'You have unsaved changes.':'All settings are saved.'; state.classList.toggle('dirty',d);
      }
      f.addEventListener('input',refresh); f.addEventListener('change',refresh);
      cancel.addEventListener('click',function(e){if(cancel.getAttribute('aria-disabled')==='true')e.preventDefault();});
      refresh();
    })();
    </script>

    <section class="au-wrap">
      <div class="au-head">
        <div><span class="au-eyebrow">System history</span><h4>Audit log</h4></div>
        <span class="sv-badge" id="auCount"><?= count($logs) ?> event<?= count($logs) === 1 ? '' : 's' ?></span>
      </div>
      <div class="au-tools">
        <label class="sv-search au-search"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" id="auSearch" placeholder="Search activity" aria-label="Search activity" autocomplete="off"></label>
        <div class="pv-select au-filter"><select id="auFilter" aria-label="Filter activity"><option value="">All activity</option><option value="security">Security</option><option value="account">Account</option><option value="clinic">Clinic</option></select></div>
      </div>
      <div class="au-table">
        <div class="au-cols"><span>When</span><span>Administrator</span><span>Activity</span></div>
        <?php if (!$logs): ?>
          <p class="set-note au-empty">No activity recorded yet. Changes made in Settings will appear here.</p>
        <?php else: foreach ($logs as $lg):
            $ts = strtotime($lg['created_at']);
            [$catLabel, $catKey] = $auCat((string)$lg['action']);
            $who = (string)($lg['display_name'] ?? 'Unknown');
            $ini = trim((string)($lg['admin_initials'] ?? '')) ?: (initials_of($who) ?: '?');
            $det = trim((string)$lg['detail']); if ($det === '') $det = $auDefault[$lg['action']] ?? '';
            $hay = mb_strtolower($lg['action'] . ' ' . $det . ' ' . $who . ' ' . $catLabel); ?>
        <div class="au-row" data-cat="<?= e($catKey) ?>" data-hay="<?= e($hay) ?>">
          <div class="au-when"><strong><?= e(date('M j, Y', $ts)) ?></strong><small><?= e(date('g:i A', $ts)) ?></small></div>
          <div class="au-who"><span class="au-av"><?= e($ini) ?></span><span><?= e($who) ?></span></div>
          <div class="au-act"><span class="au-tag <?= e($catKey) ?>"><?= e($catLabel) ?></span><div><strong><?= e($lg['action']) ?></strong><?= $det !== '' ? '<small>' . e($det) . '</small>' : '' ?></div></div>
        </div>
        <?php endforeach; ?>
        <p class="set-note au-empty" id="auNone" hidden>No activity matches your search.</p>
        <?php endif; ?>
      </div>
    </section>
    <script>
    (function(){
      var q=document.getElementById('auSearch'), sel=document.getElementById('auFilter'), none=document.getElementById('auNone'), cnt=document.getElementById('auCount');
      var rows=[].slice.call(document.querySelectorAll('.au-row')); if(!rows.length||!q||!sel) return;
      function apply(){
        var t=q.value.trim().toLowerCase(), c=sel.value, n=0;
        rows.forEach(function(r){var ok=(!c||r.dataset.cat===c)&&(!t||r.dataset.hay.indexOf(t)!==-1); r.hidden=!ok; if(ok) n++;});
        if(none) none.hidden=n>0; if(cnt) cnt.textContent=n+' event'+(n===1?'':'s');
      }
      q.addEventListener('input',apply); sel.addEventListener('change',apply);
    })();
    </script>

  <?php elseif ($sec === 'blocktime'): ?>
    <?php
      $today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day'));
      $hours = []; for ($h = 9; $h <= 17; $h++) { $hours[sprintf('%02d:00', $h)] = blocktime_label($h * 60); }
      $saved = isset($_GET['saved']) ? (int)$_GET['saved'] : -1;
      $notifiedN = isset($_GET['n']) ? (int)$_GET['n'] : 0;
      $dentist = !empty($bt_dentist) ? $bt_dentist : 'Dr. Marilou S. Cariño';
      $blocks = $bt_blocks;
      if (!function_exists('bt_city')) { function bt_city($name) { $p = preg_split('/\s+[—–-]\s+/u', (string)$name); return trim(end($p)); } }
    ?>
    <div class="bt-settings">
<?php if ($bt_error): ?><div class="bt-alert bt-alert-bad">Could not load blocked times: <?= e($bt_error) ?></div><?php endif; ?>
      <?php if ($saved >= 0): ?>
        <div class="bt-alert bt-alert-ok" id="btSaved">Blocked time saved. Patients can no longer book it online.<?= $saved > 0 ? ' ' . $saved . ' existing appointment' . ($saved === 1 ? '' : 's') . ' overlap' . ($saved === 1 ? 's' : '') . ' and stay booked until rescheduled' . ($notifiedN ? ' (' . $notifiedN . ' patient' . ($notifiedN === 1 ? '' : 's') . ' notified)' : '') . '.' : '' ?></div>
      <?php endif; ?>

      <div class="bt-layout">
        <div class="bt-main">

          <section class="card bt-step">
            <div class="bt-step-lbl">

              <div><div class="bt-step-n">Step 1</div><h4>Choose a date and time</h4></div>
            </div>
            <div class="bt-step-body">
              <div class="bt-seg" role="tablist" aria-label="Block type">
                <button type="button" class="bt-seg-btn active" data-mode="whole" role="tab" aria-selected="true">Whole day</button>
                <button type="button" class="bt-seg-btn" data-mode="range" role="tab" aria-selected="false">Time range</button>
              </div>
              <div class="bt-grid">
                <div><label for="btFrom">From date</label><input type="date" id="btFrom" min="<?= $today ?>" value="<?= $tomorrow ?>"></div>
                <div><label for="btTo">To date</label><input type="date" id="btTo" min="<?= $today ?>" value="<?= $tomorrow ?>"></div>
                <div class="bt-time-only" hidden><label for="btStart">Start time</label>
                  <select id="btStart"><?php foreach ($hours as $v => $l): if ($v === '17:00') continue; ?><option value="<?= $v ?>"<?= $v === '13:00' ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="bt-time-only" hidden><label for="btEnd">End time</label>
                  <select id="btEnd"><?php foreach ($hours as $v => $l): if ($v === '09:00') continue; ?><option value="<?= $v ?>"<?= $v === '16:00' ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
              </div>
              <div class="bt-err" id="btTimeErr" hidden></div>
            </div>
          </section>

          <section class="card bt-step">
            <div class="bt-step-lbl">

              <div><div class="bt-step-n">Step 2</div><h4>Add block details</h4></div>
            </div>
            <div class="bt-step-body">
              <label for="btReason">Reason shown to patients</label>
              <input type="text" id="btReason" maxlength="120" placeholder="e.g. Staff training" autocomplete="off">
              <div class="bt-chips" id="btChips">
                <button type="button" class="bt-chip" data-r="Dentist away">Dentist away</button>
                <button type="button" class="bt-chip" data-r="Staff training">Staff training</button>
                <button type="button" class="bt-chip" data-r="Holiday">Holiday</button>
                <button type="button" class="bt-chip" data-r="Clinic maintenance">Clinic maintenance</button>
              </div>
              <div class="bt-grid" style="margin-top:14px;">
                <div><label for="btScope">Applies to</label>
                  <select id="btScope">
                    <option value="this"><?= e(str_replace(' · ', ' — ', $cinfo['label'])) ?></option>
                    <option value="both">Both clinics</option>
                  </select></div>
                <div><label for="btDentist">Dentist</label><input type="text" id="btDentist" value="<?= e($dentist) ?>" disabled></div>
              </div>
              <label class="bt-notify">
                <span><strong>Notify affected patients</strong></span>
                <input type="checkbox" id="btNotify" checked><i class="bt-switch" aria-hidden="true"></i>
              </label>
            </div>
          </section>

          <section class="card bt-step bt-step-warn">
            <div class="bt-step-lbl">

              <div><div class="bt-step-n">Step 3</div><h4>Review affected bookings</h4><p id="btAffSub">Checking bookings…</p></div>
            </div>
            <div class="bt-step-body">
              <div class="bt-warnbox" id="btAffBox">
                <div class="bt-warnnote" id="btAffNote" hidden>These appointments will stay booked until they&rsquo;re rescheduled.</div>
                <div id="btAffList"></div>
              </div>
            </div>
          </section>
        </div>

        <aside class="bt-side">
          <div class="card bt-summary">
            <div class="bt-step-n">Block summary</div>
            <h3 id="sumDate">—</h3>
            <div class="bt-sum-time"><div><strong id="sumTime">All day</strong><small id="sumDur">Clinic closed all day</small></div></div>
            <dl class="bt-sum-list">
              <div><dt>Reason</dt><dd id="sumReason">—</dd></div>
              <div><dt>Clinic</dt><dd id="sumClinic"><?= e($cinfo['label']) ?></dd></div>
              <div><dt>Affected</dt><dd id="sumAff">—</dd></div>
            </dl>
            <div class="bt-step-n" style="margin-top:14px;">What patients will see</div>
            <div class="bt-see"><strong id="sumSeeReason">Reason</strong><span>Online booking is unavailable during this time.</span></div>
            <div class="bt-err" id="btFormErr" hidden></div>
            <button type="button" class="btn btn-primary btn-block" id="btSave">Save</button>
            <a class="btn btn-ghost btn-block" href="settings.php?s=blocktime" style="margin-top:8px;text-align:center;justify-content:center;">Cancel</a>
          </div>
        </aside>
      </div>

      <section class="card bt-schedule">
        <div class="toolbar" style="margin-bottom:10px;">
          <div><div class="bt-step-n">Schedule</div><h4 style="font-size:18px;margin-top:2px;">Upcoming blocked time</h4></div>
        </div>
        <div class="tbl-scroll">
        <table id="btTable">
          <thead><tr><th>Date</th><th>Time</th><th>Reason</th><th>Clinic</th><th>Affected</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (!$blocks): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:34px;">No blocked time yet. Patients can book every open day and hour.</td></tr>
          <?php else: foreach ($blocks as $b):
              $active = ($b['date_from'] <= $today && $b['date_to'] >= $today);
              $one = $b['date_from'] === $b['date_to'];
              $dateTxt = $one ? date('D, M j', strtotime($b['date_from'])) : date('M j', strtotime($b['date_from'])) . ' – ' . date('M j', strtotime($b['date_to'])); ?>
            <tr data-id="<?= (int)$b['id'] ?>">
              <td><strong><?= e($dateTxt) ?></strong></td>
              <td><span class="bt-time-pill"><?= e(blocktime_time_text($b)) ?></span></td>
              <td><?= e($b['reason']) ?></td>
              <td><?= $b['branch_id'] === null ? 'Both clinics' : e(bt_city($b['branch_name'])) ?></td>
              <td><?= (int)$b['affected'] ?> appointment<?= (int)$b['affected'] === 1 ? '' : 's' ?></td>
              <td><span class="bt-status<?= $active ? ' on' : '' ?>"><?= $active ? 'Active now' : 'Upcoming' ?></span></td>
              <td style="text-align:right;"><button type="button" class="icon-btn bt-del" title="Remove blocked time" aria-label="Remove blocked time"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg></button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
      </section>

    </div>
    <script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var mode = 'whole', timer = null, seq = 0, aff = null;
  var CLINIC_THIS = <?= json_encode($cinfo['label'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  function api(body) {
    return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function iso(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function mins(v) { var p = v.split(':'); return +p[0] * 60 + +p[1]; }
  function lbl(m) { var h = Math.floor(m / 60), mm = m % 60, ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + (mm < 10 ? '0' + mm : mm) + ' ' + ap; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function payload() {
    return { mode: mode, from: $('btFrom').value, to: $('btTo').value || $('btFrom').value,
             start: $('btStart').value, end: $('btEnd').value, scope: $('btScope').value,
             reason: $('btReason').value.trim(), notify: $('btNotify').checked ? 1 : 0 };
  }

  function timeValid() {
    var err = $('btTimeErr'), msg = '';
    if (!$('btFrom').value) msg = 'Choose a start date.';
    else if ($('btTo').value && $('btTo').value < $('btFrom').value) msg = 'The end date cannot be before the start date.';
    else if (mode === 'range' && mins($('btEnd').value) <= mins($('btStart').value)) msg = 'The end time must be after the start time.';
    err.textContent = msg; err.hidden = !msg;
    return !msg;
  }

  function summary() {
    var f = $('btFrom').value, t = $('btTo').value || f;
    if (f) {
      var df = iso(f), dt = iso(t);
      $('sumDate').textContent = (f === t || !t)
        ? df.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' })
        : df.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' – ' + dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    if (mode === 'whole') {
      $('sumTime').textContent = 'All day';
      $('sumDur').textContent = 'Clinic closed all day';
    } else {
      var s = mins($('btStart').value), e = mins($('btEnd').value);
      $('sumTime').textContent = lbl(s) + ' – ' + lbl(e);
      var h = (e - s) / 60;
      $('sumDur').textContent = e > s ? (h + (h === 1 ? ' hour' : ' hours') + ' unavailable') : 'Check the times';
    }
    var r = $('btReason').value.trim();
    $('sumReason').textContent = r || '—';
    $('sumSeeReason').textContent = r || 'Reason';
    $('sumClinic').textContent = $('btScope').value === 'both' ? 'Both clinics' : CLINIC_THIS;
    $('sumAff').textContent = aff === null ? '—' : aff + (aff === 1 ? ' appointment' : ' appointments');
  }

  function renderAffected(d) {
    var list = $('btAffList'), note = $('btAffNote');
    aff = d.count; summary();
    if (!d.count) {
      $('btAffSub').textContent = 'No bookings overlap with this block.';
      note.hidden = true;
      list.innerHTML = '<div class="bt-none">Nothing to reschedule. 🎉</div>';
      return;
    }
    $('btAffSub').textContent = d.count + (d.count === 1 ? ' appointment overlaps' : ' appointments overlap') + ' with this block.';
    note.hidden = false;
    list.innerHTML = d.items.map(function (a) {
      return '<div class="bt-aff"><span class="bt-av">' + esc(a.initials) + '</span><div><strong>' + esc(a.name) + '</strong><small>' + esc(a.when) + ' · ' + esc(a.service) + '</small></div>'
           + '<span class="badge ' + (a.status === 'Confirmed' ? 'b-confirmed' : 'b-pending') + '">' + esc(a.status) + '</span></div>';
    }).join('');
  }

  function refresh() {
    summary();
    if (!timeValid()) { $('btAffSub').textContent = 'Choose a valid date and time.'; $('btAffList').innerHTML = ''; $('btAffNote').hidden = true; aff = null; summary(); return; }
    clearTimeout(timer);
    timer = setTimeout(function () {
      var my = ++seq;
      $('btAffSub').textContent = 'Checking bookings…';
      api(Object.assign({ action: 'preview_block' }, payload())).then(function (d) {
        if (my !== seq) return;
        if (d.ok) renderAffected(d); else { $('btAffSub').textContent = d.error || 'Could not check bookings.'; }
      }).catch(function () { if (my === seq) $('btAffSub').textContent = 'Could not check bookings.'; });
    }, 250);
  }

  document.querySelectorAll('.bt-seg-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      mode = b.getAttribute('data-mode');
      document.querySelectorAll('.bt-seg-btn').forEach(function (x) { var on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', on); });
      document.querySelectorAll('.bt-time-only').forEach(function (n) { n.hidden = mode !== 'range'; });
      refresh();
    });
  });
  $('btFrom').addEventListener('change', function () {
    if (!$('btTo').value || $('btTo').value < this.value) $('btTo').value = this.value;
    $('btTo').min = this.value; refresh();
  });
  ['btTo', 'btStart', 'btEnd', 'btScope', 'btNotify'].forEach(function (id) { $(id).addEventListener('change', refresh); });
  $('btReason').addEventListener('input', summary);
  document.querySelectorAll('.bt-chip').forEach(function (c) {
    c.addEventListener('click', function () { $('btReason').value = c.getAttribute('data-r'); markChip(); summary(); });
  });
  function markChip() {
    var v = $('btReason').value.trim();
    document.querySelectorAll('.bt-chip').forEach(function (c) { c.classList.toggle('on', c.getAttribute('data-r') === v); });
  }
  $('btReason').addEventListener('input', markChip);

  $('btSave').addEventListener('click', function () {
    var err = $('btFormErr'), btn = this; err.hidden = true;
    if (!timeValid()) return;
    var p = payload();
    if (!p.reason) { err.textContent = 'Please add a short reason patients will see.'; err.hidden = false; $('btReason').focus(); return; }
    msConfirm({ title: 'Save changes?', message: 'Do you want to save this blocked time?', confirmText: 'Save', cancelText: 'Cancel' }).then(function (yes) {
    if (!yes) return;
    btn.disabled = true; btn.textContent = 'Saving…';
    api(Object.assign({ action: 'save_block' }, p)).then(function (d) {
      if (d.ok) { location.href = 'settings.php?s=blocktime&saved=' + (d.affected || 0) + '&n=' + (d.notified || 0); return; }
      err.textContent = d.error || 'Could not save.'; err.hidden = false;
      btn.disabled = false; btn.textContent = 'Save';
    }).catch(function () { err.textContent = 'Network problem. Please try again.'; err.hidden = false; btn.disabled = false; btn.textContent = 'Save'; });
    });
  });

  document.querySelectorAll('.bt-del').forEach(function (b) {
    b.addEventListener('click', function () {
      var tr = b.closest('tr'), id = +tr.getAttribute('data-id');
      msConfirm({ title: 'Remove blocked time?', message: 'Patients will be able to book this date and time again. Appointments already booked are not changed.', confirmText: 'Remove', danger: true }).then(function (ok) {
        if (!ok) return;
        api({ action: 'delete_block', id: id }).then(function (d) {
          if (d.ok) location.href = 'settings.php?s=blocktime'; else msToast(d.error || 'Could not remove.', 'error');
        });
      });
    });
  });

  markChip(); refresh();
})();
</script>

  <?php elseif ($sec === 'team'): ?>
    <?php
      $tm_admins = []; $tm_dentists = []; $tm_branches = [];
      try {
          $tm_admins = $pdo->query("SELECT a.id, a.username, a.display_name, a.initials, a.role, a.is_active, a.last_login_at, b.name AS branch_name
                                      FROM admins a LEFT JOIN branches b ON b.id = a.branch_id ORDER BY a.branch_id NULLS LAST, a.id")->fetchAll();
          $tm_dentists = $pdo->query("SELECT id, name, title, is_active FROM dentists ORDER BY is_active DESC, name")->fetchAll();
          $tm_branches = $pdo->query("SELECT id, name FROM branches ORDER BY id")->fetchAll();
      } catch (PDOException $ex) { error_log('MSCare settings team: ' . $ex->getMessage()); }
      $role_label = rbac_role_labels();
    ?>

    <?php
      $tmActive = 0; foreach ($tm_admins as $x) if (!empty($x['is_active'])) $tmActive++;
      $tmDocs = 0;   foreach ($tm_dentists as $x) if (!empty($x['is_active'])) $tmDocs++;
    ?>
    <div class="tm-stats">
      <div class="tm-stat"><div><strong><?= (int)$tmActive ?></strong><small>Active accounts</small></div></div>
      <div class="tm-stat"><div><strong><?= (int)$tmDocs ?></strong><small>Dentists</small></div></div>
      <div class="tm-stat"><div><strong><?= count($tm_branches) ?></strong><small>Clinic branches</small></div></div>
    </div>

    <section class="ac-card tm-card">
      <header class="ac-head"><div><h4>Accounts &amp; roles</h4></div><span class="sv-badge head-badge"><?= (int)$tmActive ?> active</span></header>
      <?php if (!$tm_admins): ?>
        <p class="set-note tm-pad">No accounts found.</p>
      <?php else: ?>
        <div class="tm-rows">
          <?php foreach ($tm_admins as $ad): $isMe = (int)$ad['id'] === (int)$admin['id']; $on = !empty($ad['is_active']); ?>
            <div class="tm-acct<?= $on ? '' : ' off' ?>">
              <div class="avatar-sm"><?= e($ad['initials'] ?: 'AD') ?></div>
              <div class="tm-main">
                <strong><?= e($ad['display_name']) ?><?php if ($isMe): ?> <span class="tm-me">You</span><?php endif; ?></strong>
                <small>@<?= e($ad['username']) ?> &middot; <?= e($ad['branch_name'] ?? 'All branches') ?><?= $ad['last_login_at'] ? ' &middot; last login ' . e(date('M j, g:i A', strtotime($ad['last_login_at']))) : '' ?></small>
              </div>
              <span class="tm-status<?= $on ? ' on' : '' ?>"><i></i><?= $on ? 'Active' : 'Inactive' ?></span>
              <?php if (!$isMe): ?>
              <form method="post" class="tm-roleform">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team">
                <input type="hidden" name="change_role" value="1"><input type="hidden" name="admin_id" value="<?= (int)$ad['id'] ?>">
                <label class="tm-rolelbl">Role
                  <span class="pv-select"><select name="new_role" data-orig="<?= e($ad['role']) ?>" aria-label="Role for <?= e($ad['username']) ?>"><?php foreach (rbac_role_labels() as $rk => $rv): ?><option value="<?= $rk ?>"<?= $ad['role'] === $rk ? ' selected' : '' ?>><?= e($rv) ?></option><?php endforeach; ?></select></span>
                </label>
                <button type="submit" class="btn-xs tm-setrole" hidden>Set role</button>
              </form>
              <form method="post" class="tm-toggle" data-title="<?= $on ? 'Deactivate admin account?' : 'Activate admin account?' ?>" data-ok="<?= $on ? 'Deactivate' : 'Activate' ?>"<?= $on ? '' : ' data-danger="0"' ?> data-confirm="<?= $on ? 'Deactivate ' . e($ad['display_name']) . '? They will no longer be able to sign in.' : 'Activate ' . e($ad['display_name']) . '? They will be able to sign in again.' ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team">
                <input type="hidden" name="toggle_admin" value="1"><input type="hidden" name="admin_id" value="<?= (int)$ad['id'] ?>"><input type="hidden" name="to" value="<?= $on ? '0' : '1' ?>">
                <button type="submit" class="tm-iconbtn<?= $on ? '' : ' act' ?>" title="<?= $on ? 'Deactivate account' : 'Activate account' ?>" aria-label="<?= $on ? 'Deactivate ' : 'Activate ' ?><?= e($ad['display_name']) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v8M6.4 6.4a8 8 0 1 0 11.2 0"/></svg></button>
              </form>
              <?php else: ?>
              <span class="tm-rolefix"><span class="badge b-crown"><?= e($role_label[$ad['role']] ?? ucfirst($ad['role'])) ?></span></span>
              <span class="tm-iconbtn" aria-hidden="true" style="opacity:.35;cursor:default"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 12h12"/></svg></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="ac-card tm-card">
      <header class="ac-head"><div><h4>Dentists</h4></div><span class="sv-badge head-badge"><?= count($tm_dentists) ?> total</span></header>
      <?php if ($tm_dentists): ?>
        <div class="tm-dchips">
          <?php foreach ($tm_dentists as $dn): $don = !empty($dn['is_active']); ?>
            <div class="tm-dchip<?= $don ? '' : ' off' ?>">
              <span class="ac-tile"><?= e(initials_of($dn['name']) ?: 'DR') ?></span>
              <div class="tm-main"><strong><?= e($dn['name']) ?></strong><small><?= e($dn['title']) ?> &middot; <?= $don ? 'Active' : 'Inactive' ?></small></div>
              <form method="post" class="tm-toggle" data-title="<?= $don ? 'Deactivate dentist?' : 'Activate dentist?' ?>" data-ok="<?= $don ? 'Deactivate' : 'Activate' ?>"<?= $don ? '' : ' data-danger="0"' ?> data-confirm="<?= $don ? 'Deactivate ' . e($dn['name']) . '? They will no longer be assigned to new appointments.' : 'Activate ' . e($dn['name']) . '? They can be assigned to new appointments again.' ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team">
                <input type="hidden" name="toggle_dentist" value="1"><input type="hidden" name="dentist_id" value="<?= (int)$dn['id'] ?>"><input type="hidden" name="to" value="<?= $don ? '0' : '1' ?>">
                <button type="submit" class="tm-iconbtn tm-check<?= $don ? ' on' : '' ?>" title="<?= $don ? 'Deactivate dentist' : 'Activate dentist' ?>" aria-label="<?= $don ? 'Deactivate ' : 'Activate ' ?><?= e($dn['name']) ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="set-note tm-pad">No dentists yet. Add one below.</p>
      <?php endif; ?>
    </section>

    <div class="tm-addhead"><span class="au-eyebrow">Add to your team</span></div>

    <form method="post" class="tm-formcard" id="tmNewAccount">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team"><input type="hidden" name="create_admin" value="1">
      <section class="ac-card">
        <header class="ac-head"><div><span class="tm-step">Step 1</span><h4>Create a new account</h4></div></header>
        <div class="ac-body form-grid">
          <div><label for="tm_user">Username</label><input id="tm_user" type="text" name="admin_username" required autocomplete="off" placeholder="e.g. admin3"></div>
          <div><label for="newAdminPw">Temporary password</label><input type="password" name="admin_password" id="newAdminPw" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="Enter a secure password">
            <ul class="pw-rules" id="newAdminPwRules">
              <li data-r="len">8+ characters</li><li data-r="upper">Uppercase letter</li><li data-r="num">Number</li><li data-r="sp">Special character</li>
            </ul></div>
          <div><label for="tm_name">Display name</label><input id="tm_name" type="text" name="admin_name" required placeholder="e.g. Clinic 3 Admin"></div>
          <div><label for="tm_role">Access level</label><select id="tm_role" name="admin_role" required><option value="staff">Staff / Assistant &mdash; appointments, patients, treatments</option><option value="admin" selected>Admin &mdash; everything, plus reports, clinic settings &amp; accounts</option></select></div>
          <div><label for="tm_branch">Assigned branch</label><select id="tm_branch" name="admin_branch"><?php foreach ($tm_branches as $br): ?><option value="<?= (int)$br['id'] ?>"><?= e($br['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <footer class="ac-foot tm-between"><button class="btn btn-primary" type="submit" data-save data-ctitle="Create account?" data-cmsg="Do you want to create this account?" data-cok="Create">Create</button></footer>
      </section>
    </form>
    <style>
      .pw-rules{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:2px 12px;font-size:12px;color:var(--muted);}
      .pw-rules li{position:relative;padding-left:16px;}
      .pw-rules li::before{content:"\25CB";position:absolute;left:0;top:0;}
      .pw-rules li.ok{color:#22693f;}.pw-rules li.ok::before{content:"\2713";}
      .pw-rules li.bad{color:#B8452B;}.pw-rules li.bad::before{content:"\2715";}
    </style>
    <script>
    (function(){
      var i=document.getElementById('newAdminPw'); if(!i) return;
      var li=document.querySelectorAll('#newAdminPwRules li');
      var t={len:function(v){return v.length>=8&&v.length<=72},upper:function(v){return /[A-Z]/.test(v)},num:function(v){return /[0-9]/.test(v)},sp:function(v){return /[^A-Za-z0-9]/.test(v)}};
      function run(){var v=i.value,all=true;li.forEach(function(e){var ok=t[e.dataset.r](v);if(!ok)all=false;e.className=v===''?'':(ok?'ok':'bad');});i.setCustomValidity(all?'':'Password does not meet all the rules.');}
      i.addEventListener('input',run);run();
    })();
    (function(){
      document.querySelectorAll('.tm-roleform select').forEach(function(sel){
        var btn=sel.closest('form').querySelector('.tm-setrole');
        sel.addEventListener('change',function(){btn.hidden=(sel.value===sel.dataset.orig);});
      });
    })();
    </script>

    <div class="tm-two">
      <form method="post" class="tm-formcard">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team"><input type="hidden" name="add_dentist" value="1">
        <section class="ac-card">
          <header class="ac-head"><div><span class="tm-step">Step 2</span><h4>Add a dentist</h4></div></header>
          <div class="ac-body">
            <label for="tm_dn" class="tm-lbl">Dentist name</label>
            <div class="tm-inline"><input id="tm_dn" type="text" name="dentist_name" data-letters required placeholder="e.g. Dr. Ana Rivera"><button class="btn btn-primary" type="submit"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Add dentist</button></div>
          </div>
        </section>
      </form>

      <form method="post" class="tm-formcard">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="section" value="team"><input type="hidden" name="add_branch" value="1">
        <section class="ac-card">
          <header class="ac-head"><div><span class="tm-step">Step 3</span><h4>Create a branch</h4></div></header>
          <div class="ac-body form-grid">
            <div><label for="tm_bc">Branch code</label><input id="tm_bc" type="text" name="branch_code" required placeholder="e.g. c3"></div>
            <div><label for="tm_bn">Branch name</label><input id="tm_bn" type="text" name="branch_name" required placeholder="e.g. Clinic 3 &mdash; Pasig"></div>
            <div><label for="tm_bcity">City</label><input id="tm_bcity" type="text" name="branch_city" data-letters required placeholder="e.g. Taguig"></div>
            <div><label for="tm_baddr">Address</label><input id="tm_baddr" type="text" name="branch_address" required placeholder="Building, street, area"></div>
            <div class="full"><label>Open days</label><div class="day-chips"><?php foreach ($DAYS as $dy): ?><label class="day-chip"><input type="checkbox" name="branch_days[]" value="<?= $dy ?>"><span><?= $dy ?></span></label><?php endforeach; ?></div></div>
          </div>
          <footer class="ac-foot"><button class="btn btn-primary" type="submit" data-save data-ctitle="Create branch?" data-cmsg="Do you want to create this branch?" data-cok="Create">Create</button></footer>
        </section>
      </form>
    </div>
  <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php if ($flash): ?>
<script>window.addEventListener('DOMContentLoaded',function(){var t=document.createElement('div');t.className='toast';t.textContent=<?= json_encode($flash['message']) ?>;document.body.appendChild(t);setTimeout(function(){t.remove();},3200);});</script>
<?php endif; ?>
  </div>
</div>
<script src="dialog.js?v=20261110"></script>
<script>
document.addEventListener('submit', function (ev) {
  var sb = ev.submitter, f0 = ev.target;
  if (ev.defaultPrevented || !sb || !sb.hasAttribute('data-save') || !f0 || f0.dataset.saveOk === '1') return;
  ev.preventDefault();
  function go() { f0.dataset.saveOk = '1'; if (f0.requestSubmit) f0.requestSubmit(sb); else f0.submit(); }
  var ct = sb.getAttribute('data-ctitle') || 'Save changes?', cm = sb.getAttribute('data-cmsg') || 'Do you want to save your changes?', ck = sb.getAttribute('data-cok') || 'Save';
  if (!window.msConfirm) { if (window.confirm(ct)) go(); return; }
  msConfirm({ title: ct, message: cm, confirmText: ck, cancelText: 'Cancel' })
    .then(function (yes) { if (yes) go(); });
});
document.addEventListener('submit', function (ev) {
  var f = ev.target.closest ? ev.target.closest('form.tm-toggle') : null;
  if (!f || f.dataset.ok === '1') return;
  var msg = f.getAttribute('data-confirm');
  if (!msg) return;
  ev.preventDefault();
  if (!window.msConfirm) { if (window.confirm(msg)) { f.dataset.ok = '1'; f.submit(); } return; }
  msConfirm({
    title: f.getAttribute('data-title') || 'Deactivate account?',
    message: msg,
    confirmText: f.getAttribute('data-ok') || 'Deactivate',
    cancelText: 'Cancel',
    danger: f.getAttribute('data-danger') !== '0', icon: f.getAttribute('data-danger') === '0' ? '' : 'warn'
  }).then(function (yes) { if (yes) { f.dataset.ok = '1'; f.submit(); } });
});
</script>
<script src="validate.js?v=<?= filemtime(__DIR__ . '/validate.js') ?>"></script>
</body>
</html>
