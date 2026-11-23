<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/revenue.php';
require_once __DIR__ . '/includes/reminders.php';
require_once __DIR__ . '/includes/dash_helpers.php';

require_admin();
require_permission('dashboard');
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$total_patients = 0;
$upcoming = [];
$reminders = [];
$approvals = [];
$rebook = [];
$db_error = null;

try {
    $pdo = db();

    $total_patients = (int)$pdo->query("SELECT count(*) FROM patients WHERE status = 'Active'")->fetchColumn();

    ensure_reminder_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT a.id, a.patient_id, a.phone, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
               s.name AS service_name, a.status, COALESCE(d.name, 'Dr. Marilou S. Cariño') AS dentist_name,
               COALESCE(concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name), concat_ws(' ', a.first_name, a.last_name)) AS patient_name,
               UPPER(SUBSTRING(COALESCE(p.first_name, a.first_name, 'P') FROM 1 FOR 1) || SUBSTRING(COALESCE(p.last_name, a.last_name, 'T') FROM 1 FOR 1)) AS initials,
               (a.email_reminded_at IS NOT NULL OR EXISTS (SELECT 1 FROM notifications n WHERE n.appointment_id = a.id AND n.title = 'Appointment reminder')) AS reminded
        FROM appointments a
        JOIN services s ON s.id = a.service_id AND s.name NOT ILIKE '%brace%'
        LEFT JOIN dentists d ON d.id = a.dentist_id
        LEFT JOIN patients p ON p.id = a.patient_id
        WHERE a.branch_id = :b AND a.status IN ('Pending', 'Confirmed') AND a.appointment_date >= CURRENT_DATE
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 100
    ");
    $stmt->execute([':b' => $branch_id]);
    $upcoming = $stmt->fetchAll();
    $reminders = $upcoming;

    $stmt = $pdo->prepare("
        SELECT a.id, a.patient_id, a.phone, a.appointment_date, s.name AS service_name,
               COALESCE(concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name), concat_ws(' ', a.first_name, a.last_name)) AS patient_name,
               UPPER(SUBSTRING(COALESCE(p.first_name, a.first_name, 'P') FROM 1 FOR 1) || SUBSTRING(COALESCE(p.last_name, a.last_name, 'T') FROM 1 FOR 1)) AS initials
        FROM appointments a
        JOIN services s ON s.id = a.service_id AND s.name NOT ILIKE '%brace%'
        LEFT JOIN patients p ON p.id = a.patient_id
        WHERE a.branch_id = :b AND a.status = 'Cancelled' AND a.appointment_date >= CURRENT_DATE - 30
          AND NOT EXISTS (SELECT 1 FROM appointments x
                           WHERE x.status IN ('Pending', 'Confirmed') AND x.appointment_date >= CURRENT_DATE
                             AND ((a.patient_id IS NOT NULL AND x.patient_id = a.patient_id) OR LOWER(x.email) = LOWER(a.email)))
        ORDER BY a.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([':b' => $branch_id]);
    $rebook = $stmt->fetchAll();
} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

$mode = rev_mode($_GET['view'] ?? 'monthly');
$P = rev_periods($mode);
$n = $P['n'];
$y_from = $P['from']; $y_to = $P['to']; $p_from = $P['pfrom'];

$m_appts = array_fill(1, $n, 0); $m_done = array_fill(1, $n, 0); $m_fin = array_fill(1, $n, 0);
$m_rev = array_fill(1, $n, 0.0); $m_newpat = array_fill(1, $n, 0);
$status_counts = ['Completed' => 0, 'Confirmed' => 0, 'Pending' => 0, 'Cancelled' => 0, 'No-show' => 0];
$prev = ['appts' => 0, 'done' => 0, 'fin' => 0, 'rev' => 0.0, 'newpat' => 0];
$base_patients = 0; $tx_total = 0;
$top_tx = []; $activity = [];

try {
    $pdo = db();
    $q = function ($sql, $params) use ($pdo) {
        try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); }
        catch (PDOException $ex) { error_log('MSCare dashboard: ' . $ex->getMessage()); return []; }
    };
    $bp = [':b' => $branch_id];

    foreach ($q("SELECT appointment_date AS d, status, count(*) AS c FROM appointments
                  WHERE branch_id = :b AND service_id NOT IN (SELECT id FROM services WHERE name ILIKE '%brace%') AND appointment_date >= :f AND appointment_date < :t GROUP BY 1, 2",
                $bp + [':f' => $p_from, ':t' => $y_to]) as $r) {
        $ix = rev_idx($r['d'], $P); if (!$ix) continue;
        $c = (int)$r['c']; $st = $r['status'];
        if ($ix['prev']) {
            $prev['appts'] += $c;
            if ($st === 'Completed') $prev['done'] += $c;
            if (in_array($st, ['Completed', 'Cancelled', 'No-show'], true)) $prev['fin'] += $c;
            continue;
        }
        $i = $ix['i'];
        $m_appts[$i] += $c;
        if (isset($status_counts[$st])) $status_counts[$st] += $c;
        if ($st === 'Completed') $m_done[$i] += $c;
        if (in_array($st, ['Completed', 'Cancelled', 'No-show'], true)) $m_fin[$i] += $c;
    }
    foreach ($q("SELECT treatment_date AS d, COALESCE(sum(cost), 0) AS rev, count(*) AS c FROM treatments
                  WHERE branch_id = :b AND procedure_name NOT ILIKE '%brace%' AND treatment_date >= :f AND treatment_date < :t GROUP BY 1",
                $bp + [':f' => $p_from, ':t' => $y_to]) as $r) {
        $ix = rev_idx($r['d'], $P); if (!$ix) continue;
        if ($ix['prev']) { $prev['rev'] += (float)$r['rev']; continue; }
        $m_rev[$ix['i']] += (float)$r['rev']; $tx_total += (int)$r['c'];
    }

    foreach ($q("SELECT created_at::date AS d, count(*) AS c FROM patients
                  WHERE created_at >= :f AND created_at < :t GROUP BY 1", [':f' => $p_from, ':t' => $y_to]) as $r) {
        $ix = rev_idx($r['d'], $P); if (!$ix) continue;
        if ($ix['prev']) $prev['newpat'] += (int)$r['c']; else $m_newpat[$ix['i']] += (int)$r['c'];
    }
    foreach ($q("SELECT count(*) AS c FROM patients WHERE status = 'Active' AND created_at < :f", [':f' => $y_from]) as $r) $base_patients = (int)$r['c'];

    $top_tx = $q("SELECT procedure_name, count(*) AS c FROM treatments
                   WHERE branch_id = :b AND procedure_name NOT ILIKE '%brace%' AND treatment_date >= :f AND treatment_date < :t
                   GROUP BY 1 ORDER BY c DESC, procedure_name LIMIT 5", $bp + [':f' => $y_from, ':t' => $y_to]);

    foreach ($q("SELECT extract(epoch FROM a.created_at)::bigint AS ts, trim(a.first_name || ' ' || a.last_name) AS n,
                        to_char(a.appointment_date, 'Mon FMDD, YYYY') AS d, to_char(a.appointment_time, 'FMHH12:MI AM') AS t
                   FROM appointments a WHERE a.branch_id = :b ORDER BY a.created_at DESC LIMIT 5", $bp) as $r)
        $activity[] = ['ts' => (int)$r['ts'], 'type' => 'appt', 'title' => 'New appointment', 'sub' => $r['n'] . ' · ' . $r['d'] . ' • ' . $r['t']];
    foreach ($q("SELECT extract(epoch FROM p.created_at)::bigint AS ts, trim(p.first_name || ' ' || p.last_name) AS n
                   FROM patients p WHERE (p.branch_id IS NULL OR p.branch_id = :b OR EXISTS (SELECT 1 FROM appointments ap WHERE ap.patient_id = p.id AND ap.branch_id = :b))
                   ORDER BY p.created_at DESC LIMIT 5", $bp) as $r)
        $activity[] = ['ts' => (int)$r['ts'], 'type' => 'patient', 'title' => 'New patient registered', 'sub' => $r['n']];
    foreach ($q("SELECT extract(epoch FROM created_at)::bigint AS ts, procedure_name, cost FROM treatments
                  WHERE branch_id = :b AND procedure_name NOT ILIKE '%brace%' ORDER BY created_at DESC LIMIT 5", $bp) as $r)
        $activity[] = ['ts' => (int)$r['ts'], 'type' => 'tx', 'title' => 'Treatment recorded', 'sub' => $r['procedure_name'] . ((float)$r['cost'] > 0 ? ' · ₱' . number_format((float)$r['cost'], 2) : '')];
    $msgs = $q("SELECT extract(epoch FROM created_at)::bigint AS ts, name FROM contact_messages
                 WHERE branch_id = :b AND COALESCE(sender, 'patient') = 'patient' ORDER BY created_at DESC LIMIT 5", $bp);
    if (!$msgs) $msgs = $q("SELECT extract(epoch FROM created_at)::bigint AS ts, name FROM contact_messages WHERE branch_id = :b ORDER BY created_at DESC LIMIT 5", $bp);
    foreach ($msgs as $r) $activity[] = ['ts' => (int)$r['ts'], 'type' => 'msg', 'title' => 'New message', 'sub' => 'From ' . $r['name']];
    usort($activity, function ($x, $y) { return $y['ts'] <=> $x['ts']; });
    $activity = array_slice($activity, 0, 6);
} catch (PDOException $e) {
    $db_error = $db_error ?: $e->getMessage();
}

$tot_appts = array_sum($status_counts);
$tot_rev   = array_sum($m_rev);
$fin       = $status_counts['Completed'] + $status_counts['Cancelled'] + $status_counts['No-show'];
$rate      = $fin > 0 ? $status_counts['Completed'] / $fin * 100 : null;
$prev_rate = $prev['fin'] > 0 ? $prev['done'] / $prev['fin'] * 100 : null;
$new_pat_period = array_sum($m_newpat);

$d_pat   = dash_delta($new_pat_period, $base_patients);
$d_appt  = dash_delta($tot_appts, $prev['appts']);
$d_rev   = dash_delta($tot_rev, $prev['rev']);
$d_rate  = ($rate !== null && $prev_rate !== null) ? dash_delta($rate, $prev_rate) : null;

$span = range(1, $n);
$sp_pat  = array_map(function ($m) use ($m_newpat) { return $m_newpat[$m]; }, $span);
$sp_appt = array_map(function ($m) use ($m_appts) { return $m_appts[$m]; }, $span);
$sp_rev  = array_map(function ($m) use ($m_rev) { return $m_rev[$m]; }, $span);
$sp_rate = array_map(function ($m) use ($m_done, $m_fin) { return $m_fin[$m] > 0 ? $m_done[$m] / $m_fin[$m] * 100 : 0; }, $span);

$chart = ['labels' => $P['labels'], 'full' => $P['full'], 'title' => $P['title'], 'appts' => array_values($m_appts), 'done' => array_values($m_done), 'rev' => array_values($m_rev)];

$stat_colors = ['Completed' => '#139C93', 'Confirmed' => '#2F80ED', 'Pending' => '#E7A93C', 'Cancelled' => '#E9694C', 'No-show' => '#7E6BC9'];
$first_name = trim(preg_replace('/\s+/', ' ', (string)$admin['display_name']));

$badge_map = ['Confirmed' => 'b-confirmed', 'Pending' => 'b-pending', 'Completed' => 'b-completed', 'Cancelled' => 'b-cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<script src="dialog.js?v=20261027"></script>
</head>
<body data-guard="admin">
<div class="shell">
  <div class="sidebar">
    <div class="brand">
      <?= admin_brand_mark() ?>
      <span>MSCare</span>
    </div>
    <div class="nav-group">
      <a class="nav-item active" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item" href="treatments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Treatment History</span></a>
      <?php if (can('reports')): ?><a class="nav-item" href="reports.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg><span>Revenue &amp; Reports</span></a><?php endif; ?>
      <a class="nav-item" href="settings.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg><span>Settings</span></a>
    </div>
    <div class="sidebar-foot">
      <div class="who">
        <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
        <div>
          <div class="who-name"><?= htmlspecialchars($admin['display_name']) ?></div>
        </div>
      </div>
      <a class="nav-item" href="login.php?logout=1"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Log out</span></a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <div><h2>MSCare Dental Clinic</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content content-wide dash-fit">
      <?php if ($db_error): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13.5px;">
          <b>Database Connection Notice:</b> <?= htmlspecialchars($db_error) ?>
        </div>
      <?php endif; ?>

      <div class="dash-page">

        <div class="dash-top">

          <div class="dash-hero">
            <svg class="deco" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
            <div class="hero-text">
              <h3>Welcome back!</h3>
              <div class="bar"></div>
            </div>
            <form method="get" class="hero-year">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
              <select name="view" aria-label="Period" onchange="this.form.submit()">
                <option value="weekly"<?= $mode === 'weekly' ? ' selected' : '' ?>>Weekly</option>
                <option value="monthly"<?= $mode === 'monthly' ? ' selected' : '' ?>>Monthly</option>
              </select>
            </form>
          </div>

          <div class="kpi-grid">
            <div class="kpi k-teal">
              <div class="kpi-icon" style="background:linear-gradient(135deg,#2BB5AC,#168078);"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg></div>
              <div class="kpi-body">
                <div class="kpi-num"><span><?= number_format($total_patients) ?></span><?= dash_delta_html($d_pat, $new_pat_period . ' new patients in ' . $P['range'] . ' vs. patients before then') ?></div>
                <div class="kpi-label">Total Patients</div>
              </div>
              <?= dash_spark($sp_pat, '#1FA8A0', 'a') ?>
            </div>
            <div class="kpi k-blue">
              <div class="kpi-icon" style="background:linear-gradient(135deg,#4C9BFF,#2F6FE0);"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></div>
              <div class="kpi-body">
                <div class="kpi-num"><span><?= number_format($tot_appts) ?></span><?= dash_delta_html($d_appt, $P['cmp']) ?></div>
                <div class="kpi-label">Appointments</div>
              </div>
              <?= dash_spark($sp_appt, '#2F80ED', 'b') ?>
            </div>
            <div class="kpi k-green">
              <div class="kpi-icon" style="background:linear-gradient(135deg,#4CC36F,#2E9A4F);font:700 26px 'Inter',sans-serif;">₱</div>
              <div class="kpi-body">
                <div class="kpi-num"><span>₱<?= number_format($tot_rev) ?></span><?= dash_delta_html($d_rev, $P['cmp']) ?></div>
                <div class="kpi-label">Total Revenue</div>
              </div>
              <?= dash_spark($sp_rev, '#3CA36B', 'c') ?>
            </div>
            <div class="kpi k-amber">
              <div class="kpi-icon" style="background:linear-gradient(135deg,#FFC04D,#F29A1F);"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg></div>
              <div class="kpi-body">
                <div class="kpi-num"><span><?= $rate === null ? '–' : round($rate) . '%' ?></span><?= dash_delta_html($d_rate, $P['cmp']) ?></div>
                <div class="kpi-label">Appointment<br>Completion Rate</div>
              </div>
              <?= dash_spark($sp_rate, '#E7A93C', 'd') ?>
            </div>
          </div>

          <div class="dash-row2">
            <div class="card">
              <div class="section-head">
                <h4><svg class="h-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 20V11M12 20V4M19 20v-6"/></svg><?= e($P['title']) ?> Performance Overview</h4>
                <div class="chart-legend"><span><i style="background:#0E7C86"></i>Appointments</span><span><i style="background:#1E90FF"></i>Completed</span><span><i class="ln" style="background:#16A34A"></i>Revenue (₱)</span></div>
              </div>
              <div class="rev-chart" id="perfChart"></div>
            </div>

            <div class="card status-card">
              <div class="section-head"><h4><svg class="h-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="3"/><path d="M6 21v-4a3 3 0 0 1 3-3h6a3 3 0 0 1 3 3v4"/></svg>Appointment Status</h4></div>
              <div class="donut-wrap">
                <div class="donut">
                  <svg viewBox="0 0 200 200" role="img" aria-label="Appointment status">
                    <circle cx="100" cy="100" r="70" fill="none" stroke="#EEF3F4" stroke-width="30"/>
                    <?php
                      $circ = 2 * M_PI * 70; $acc = 0;
                      if ($tot_appts > 0) foreach ($status_counts as $name => $cnt) {
                          if ($cnt <= 0) continue;
                          $len = $cnt / $tot_appts * $circ;
                          echo '<circle cx="100" cy="100" r="70" fill="none" stroke="' . $stat_colors[$name] . '" stroke-width="30" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '" stroke-dashoffset="' . round(-$acc, 2) . '" transform="rotate(-90 100 100)"><title>' . e($name) . ': ' . $cnt . '</title></circle>';
                          $acc += $len;
                      }
                    ?>
                  </svg>
                  <div class="donut-center"><b><?= number_format($tot_appts) ?></b><span>Total</span></div>
                </div>
                <ul class="donut-legend">
                  <?php foreach ($status_counts as $name => $cnt): ?>
                    <li><i style="background:<?= $stat_colors[$name] ?>"></i><span><?= e($name) ?></span><b><?= $tot_appts > 0 ? number_format($cnt / $tot_appts * 100, 1) : '0.0' ?>%</b></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <div class="dash-bottom">
            <div class="card">
              <div class="section-head"><h4><svg class="h-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 20V11M12 20V4M19 20v-6"/></svg><?= e($P['title']) ?> Statistics</h4></div>
              <div class="card-scroll mstat-scroll">
              <table class="mstat">
                <thead><tr><th><?= e($P['noun']) ?></th><th>Appointments</th><th>Revenue</th><th title="Share of the revenue shown on this dashboard">Percentage</th></tr></thead>
                <tbody>
                  <?php for ($m = max(1, $n - 5); $m <= $n; $m++): $share = $tot_rev > 0 ? $m_rev[$m] / $tot_rev * 100 : null; ?>
                    <tr>
                      <td><?= e($P['full'][$m - 1]) ?></td>
                      <td><?= number_format($m_appts[$m]) ?></td>
                      <td>₱<?= number_format($m_rev[$m]) ?></td>
                      <td><?= $share === null ? '<span class="muted-dash">—</span>' : '<span class="pct-pill">' . number_format($share, 1) . '%</span>' ?></td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
              </div>
            </div>

            <div class="card">
              <div class="section-head"><h4><svg class="h-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>Top Treatments</h4></div>
              <div class="card-scroll">
              <?php if (!$top_tx): ?>
                <div class="side-empty">No treatment records for <?= e($P['range']) ?> yet.</div>
              <?php else: ?>
              <div class="tt-head"><span>Treatment</span><span>Frequency</span><span>Percentage</span></div>
              <?php $tt_max = max(array_map(function ($t) { return (int)$t['c']; }, $top_tx)); foreach ($top_tx as $t): $pc = $tx_total > 0 ? $t['c'] / $tx_total * 100 : 0; ?>
                <div class="tt-row" title="<?= (int)$t['c'] ?> record(s)">
                  <span class="tt-name"><?= e($t['procedure_name']) ?></span>
                  <span class="tt-bar"><i style="width:<?= round($t['c'] / $tt_max * 100) ?>%"></i></span>
                  <span class="tt-pct"><?= number_format($pc, 1) ?>%</span>
                </div>
              <?php endforeach; endif; ?>
              </div>
            </div>

            <div class="card">
              <div class="section-head">
                <h4><svg class="h-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>Recent Activity</h4>
                <a class="link" href="notifications.php">View All →</a>
              </div>
              <div class="card-scroll">
              <?php if (!$activity): ?>
                <div class="side-empty">No activity yet.</div>
              <?php else: foreach ($activity as $ac): ?>
                <div class="act-item">
                  <div class="act-ico act-<?= e($ac['type']) ?>"><?php
                    $icons = [
                      'appt'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
                      'patient' => '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c.8-3.5 3.5-5.2 7-5.2s6.2 1.7 7 5.2"/>',
                      'tx'      => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
                      'msg'     => '<path d="M4 5h16v11H9l-5 4z"/>',
                    ]; ?><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$ac['type']] ?? '' ?></svg></div>
                  <div class="act-text"><b><?= e($ac['title']) ?></b><span><?= e($ac['sub']) ?></span></div>
                  <div class="act-time"><?= e(dash_ago($ac['ts'])) ?></div>
                </div>
              <?php endforeach; endif; ?>
              </div>
            </div>

            <div class="card">
              <div class="section-head">
                <h4><svg class="h-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M13.7 20a2 2 0 0 1-3.4 0"/></svg>Appointment Reminders</h4>
                <button type="button" class="link link-btn" data-open="remModal">View all</button>
              </div>
              <div class="rem-list card-scroll" id="remList">
                <?php if (!$reminders): ?>
                  <div class="side-empty">No upcoming appointments to remind.</div>
                <?php else: foreach ($reminders as $r): ?>
                  <div class="rem-item">
                    <div class="avatar-sm"><?= e($r['initials']) ?></div>
                    <div class="who"><b><?= e($r['patient_name']) ?></b><span><?= date('M j', strtotime($r['appointment_date'])) ?> · <?= e($r['time_formatted']) ?></span></div>
                    <?php $was = !empty($r['reminded']) && $r['reminded'] !== 'f'; ?>
                    <button type="button" class="btn-xs rem-btn<?= $was ? ' sent' : '' ?>" data-id="<?= (int)$r['id'] ?>" title="<?= $was ? 'Already reminded - click to send again' : 'Send reminder (portal + email)' ?>"><?= $was ? 'Remind again' : 'Remind' ?></button>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>

        </div>
      </div>
    </div>
  </div>
</div>

<div class="dm-back" id="remModal" role="dialog" aria-modal="true" aria-labelledby="remModalT" hidden>
  <div class="dm">
    <div class="dm-head"><h3 id="remModalT">Appointment reminders</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
    <div class="dm-body">
      <?php if (!$reminders): ?>
        <div class="side-empty" style="padding:30px;text-align:center;">No upcoming appointments to remind.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Patient</th><th>Service</th><th>Dentist</th><th>Date &amp; Time</th><th>Status</th><th style="width:110px;">Action</th></tr></thead>
        <tbody>
          <?php foreach ($reminders as $a): $was = !empty($a['reminded']) && $a['reminded'] !== 'f'; ?>
          <tr>
            <td><div class="row-people"><div class="avatar-sm"><?= e($a['initials']) ?></div><span class="name"><?= e($a['patient_name']) ?></span></div></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= e(preg_replace('/^Dr\.\s*/', '', $a['dentist_name'])) ?></td>
            <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($a['appointment_date'])) ?>, <?= e($a['time_formatted']) ?></td>
            <td><span class="badge <?= $badge_map[$a['status']] ?? 'b-pending' ?>"><?= e($a['status']) ?></span></td>
            <td><button type="button" class="btn-xs rem-btn<?= $was ? ' sent' : '' ?>" data-id="<?= (int)$a['id'] ?>"><?= $was ? 'Remind again' : 'Remind' ?></button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
var PERF = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;

(function () {
  var box = document.getElementById('perfChart');
  if (!box) return;
  var W = 760, H = 330, L = 46, R = 64, T = 18, B = 32;   // W/H are re-measured from the box on every draw (chart fills its card)
  function niceMax(v) {
    if (v <= 0) return 4;
    var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
  }
  function peso(n) { return '\u20B1' + Number(n).toLocaleString('en-PH', { maximumFractionDigits: 0 }); }
  function short(n) { return n >= 1000 ? '\u20B1' + (n / 1000).toLocaleString('en-PH', { maximumFractionDigits: 1 }) + 'k' : '\u20B1' + n; }
  function draw() {
    W = Math.max(320, box.clientWidth || 760); H = Math.max(200, box.clientHeight || 330);
    var n = PERF.labels.length, iw = W - L - R, ih = H - T - B, gw = iw / n;
    var maxA = niceMax(Math.max.apply(null, PERF.appts.concat(PERF.done)));
    var revVals = PERF.rev.filter(function (v) { return v !== null; });
    var maxR = niceMax(revVals.length ? Math.max.apply(null, revVals) : 0);
    var yA = function (v) { return T + ih - v / maxA * ih; }, yR = function (v) { return T + ih - v / maxR * ih; };
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + PERF.title + ' performance">';
    for (var g = 0; g <= 4; g++) {
      var gy = yA(maxA * g / 4);
      s += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + gy + '" y2="' + gy + '" stroke="#E1EAEC"' + (g ? ' stroke-dasharray="4 4"' : '') + '/>';
      s += '<text x="' + (L - 8) + '" y="' + (gy + 4) + '" text-anchor="end">' + Math.round(maxA * g / 4).toLocaleString('en-PH') + '</text>';
      s += '<text x="' + (W - R + 8) + '" y="' + (gy + 4) + '">' + short(Math.round(maxR * g / 4)) + '</text>';
    }
    var bw = Math.min(24, gw * 0.34), pts = [];
    for (var i = 0; i < n; i++) {
      var cx = L + gw * i + gw / 2;
      s += '<rect x="' + (cx - bw - 1) + '" y="' + yA(PERF.appts[i]) + '" width="' + bw + '" height="' + (T + ih - yA(PERF.appts[i])) + '" rx="2" fill="#0E7C86"/>';
      s += '<rect x="' + (cx + 1) + '" y="' + yA(PERF.done[i]) + '" width="' + bw + '" height="' + (T + ih - yA(PERF.done[i])) + '" rx="2" fill="#1E90FF"/>';
      s += '<text x="' + cx + '" y="' + (H - 8) + '" text-anchor="middle">' + PERF.labels[i] + '</text>';
      if (PERF.rev[i] !== null) pts.push([cx, yR(PERF.rev[i])]);
    }
    if (pts.length) {
      s += '<path d="M' + pts.map(function (p) { return p[0] + ' ' + p[1]; }).join(' L') + '" fill="none" stroke="#16A34A" stroke-width="2.2" stroke-linejoin="round"/>';
      pts.forEach(function (p) { s += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="3.6" fill="#16A34A" stroke="#fff" stroke-width="1.6"/>'; });
    }
    for (var k = 0; k < n; k++) s += '<rect class="perf-hit" data-i="' + k + '" x="' + (L + gw * k) + '" y="' + T + '" width="' + gw + '" height="' + ih + '" fill="transparent"/>';
    s += '</svg><div class="rev-tip perf-tip" hidden></div>';
    box.innerHTML = s;
    var tip = box.querySelector('.perf-tip'), svg = box.querySelector('svg');
    function show(i) {
      var k = svg.getBoundingClientRect().width / W, cx = L + gw * i + gw / 2;
      tip.textContent = PERF.full[i] + '\nAppointments: ' + PERF.appts[i] + '\nCompleted: ' + PERF.done[i] + '\nRevenue: ' + peso(PERF.rev[i] || 0);
      tip.style.left = (cx * k) + 'px'; tip.style.top = (T * k + 6) + 'px'; tip.hidden = false;
    }
    box.querySelectorAll('.perf-hit').forEach(function (r) {
      r.addEventListener('mouseenter', function () { show(+r.dataset.i); });
      r.addEventListener('click', function () { show(+r.dataset.i); });
    });
    svg.addEventListener('mouseleave', function () { tip.hidden = true; });
  }
  draw();
  window.addEventListener('resize', draw);
})();

async function post(body) {
  var res = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  return res.json();
}

function markSent(id) {
  document.querySelectorAll('.rem-btn[data-id="' + id + '"]').forEach(function (b) {
    b.textContent = 'Remind again'; b.classList.add('sent'); b.disabled = false;
    b.title = 'Already reminded - click to send again';
  });
}

document.addEventListener('click', async function (e) {
  var b = e.target.closest('.rem-btn');
  if (!b || b.disabled) return;
  var id = +b.dataset.id, label = b.textContent;
  b.disabled = true; b.textContent = '...';
  try {
    var d = await post({ action: 'send_reminder', appointment_id: id });
    if (d.ok) { markSent(id); msToast(d.message || 'Reminder sent to the patient.'); }
    else { b.disabled = false; b.textContent = label; msToast(d.error || 'Could not send the reminder.', 'error'); }
  } catch (err) { b.disabled = false; b.textContent = label; msToast('Connection error: ' + err.message, 'error'); }
});

(function () {
  var openEl = null;
  function close() { if (openEl) { openEl.classList.remove('open'); openEl.hidden = true; openEl = null; document.body.classList.remove('dm-lock'); } }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-open]');
    if (t) { var m = document.getElementById(t.dataset.open); if (m) { close(); m.hidden = false; m.classList.add('open'); openEl = m; document.body.classList.add('dm-lock'); } return; }
    if (openEl && (e.target === openEl || e.target.closest('[data-close]'))) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
</body>
</html>
