<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/recurrence.php';

require_admin();
require_permission('appointments');
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$appointments = [];
$patients = [];
$services = [];
$db_error = null;
$taken_slots = [];
$open_days = [];
$time_slots = ['09:00 AM', '10:00 AM', '11:00 AM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM'];

try {
    $pdo = db();
    recurrence_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT a.id, a.ref, a.patient_id, a.email AS appt_email, a.comments, a.admin_notes, a.created_at, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
               s.name AS service_name, a.status, a.series_id, a.series_no, a.appointment_time::text AS time_raw, sr.total_count, sr.frequency,
               COALESCE(concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name), concat_ws(' ', a.first_name, a.last_name)) AS patient_name,
               COALESCE(p.phone, a.phone) AS phone,
               UPPER(SUBSTRING(COALESCE(p.first_name, a.first_name, 'P') FROM 1 FOR 1) || SUBSTRING(COALESCE(p.last_name, a.last_name, 'T') FROM 1 FOR 1)) AS initials
        FROM appointments a
        JOIN services s ON s.id = a.service_id AND s.name NOT ILIKE '%brace%'
        LEFT JOIN appointment_series sr ON sr.id = a.series_id
        LEFT JOIN patients p ON p.id = a.patient_id
        WHERE a.branch_id = :b
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute([':b' => $branch_id]);
    $appointments = $stmt->fetchAll();
    $laterCount = [];
    foreach ($appointments as $a) {
        if (!$a['series_id'] || !in_array($a['status'], ['Pending', 'Confirmed'], true)) continue;
        foreach ($appointments as $b) {
            if ($b['series_id'] === $a['series_id'] && in_array($b['status'], ['Pending', 'Confirmed'], true)
                && ($b['appointment_date'] . ' ' . $b['time_raw']) > ($a['appointment_date'] . ' ' . $a['time_raw'])) {
                $laterCount[(int)$a['id']] = ($laterCount[(int)$a['id']] ?? 0) + 1;
            }
        }
    }

    $t_stmt = $pdo->query("SELECT id, appointment_date::text AS d, to_char(appointment_time, 'HH12:MI AM') AS t
                           FROM appointments WHERE appointment_date >= CURRENT_DATE AND status <> 'Cancelled'");
    foreach ($t_stmt as $tr) { $taken_slots[] = [(int)$tr['id'], $tr['d'], $tr['t']]; }

    $od = $pdo->prepare("SELECT open_days FROM branches WHERE id = :b");
    $od->execute([':b' => $branch_id]);
    $od_raw = $od->fetchColumn();
    if (is_array($od_raw)) { $open_days = $od_raw; }
    else { $od_raw = trim((string)$od_raw, '{}'); $open_days = $od_raw === '' ? [] : array_map('trim', explode(',', $od_raw)); }

    $p_stmt = $pdo->query("SELECT id, concat_ws(' ', first_name, NULLIF(middle_name, ''), last_name) AS full_name FROM patients ORDER BY full_name");
    $patients = $p_stmt->fetchAll();

    $s_stmt = $pdo->query("SELECT id, name FROM services WHERE is_active = TRUE AND name NOT ILIKE '%brace%' ORDER BY sort_order");
    $services = $s_stmt->fetchAll();

} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

$badge_map = [
    'Confirmed' => 'b-confirmed',
    'Pending'   => 'b-pending',
    'Completed' => 'b-completed',
    'Cancelled' => 'b-cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<style>
#cancelModal.modal-backdrop{position:fixed;inset:0;background:rgba(11,42,64,.5);align-items:center;justify-content:center;padding:20px;z-index:1000;}
#cancelModal .cancel-modal{background:#fff;border-radius:18px;width:100%;max-width:440px;padding:28px 28px 26px;position:relative;box-shadow:0 30px 70px -20px rgba(11,42,64,.55);font-family:'Inter',system-ui,sans-serif;color:#122531;max-height:92vh;overflow:auto;}
#cancelModal .cancel-x{position:absolute;top:16px;right:16px;border:0;background:transparent;width:30px;height:30px;border-radius:50%;color:#65798A;cursor:pointer;display:grid;place-items:center;}
#cancelModal .cancel-x:hover{background:#F4FAF9;}
#cancelModal .cancel-head{display:flex;align-items:center;gap:14px;margin-bottom:16px;}
#cancelModal .cancel-head h3{font-size:20px;font-weight:700;margin:0;color:#0B2A40;font-family:'Inter',system-ui,sans-serif;}
#cancelModal .cancel-ico{width:54px;height:54px;border-radius:16px;background:#E6F6F4;color:#168078;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
#cancelModal .cancel-mode{display:flex;gap:4px;background:#E6F6F4;border-radius:12px;padding:4px;margin-bottom:14px;}
#cancelModal .cancel-mode-btn{flex:1;border:none;background:transparent;border-radius:9px;padding:9px 10px;font:inherit;font-weight:600;font-size:13.5px;color:#65798A;cursor:pointer;}
#cancelModal .cancel-mode-btn.active{background:#fff;color:#0B2A40;box-shadow:0 1px 3px rgba(15,40,70,.12);}
#cancelModal .cancel-q{font-weight:600;font-size:15px;color:#0B2A40;margin:0 0 6px;}
#cancelModal .cancel-who{font-size:12.5px;color:#65798A;margin-bottom:14px;}
#cancelModal .cancel-label{display:block;font-size:13.5px;font-weight:600;color:#0B2A40;margin:12px 0 6px;}
#cancelModal .cancel-field{width:100%;box-sizing:border-box;border:1.5px solid #E1EAEC;border-radius:10px;padding:10px 12px;font:inherit;font-size:14px;background:#fff;color:#122531;}
#cancelModal .cancel-field:focus{outline:none;border-color:#1FA8A0;box-shadow:0 0 0 3px #E6F6F4;}
#cancelModal .cancel-area{position:relative;}
#cancelModal .cancel-area textarea{resize:none;padding-bottom:24px;}
#cancelModal .cancel-count{position:absolute;right:12px;bottom:8px;font-size:11.5px;color:#65798A;}
#cancelModal .cancel-err{color:#E9694C;font-size:12.5px;margin-top:10px;}
#cancelModal .cancel-confirm{width:100%;margin-top:18px;border:none;border-radius:12px;padding:13px;background:#0B2A40;color:#fff;font:inherit;font-weight:700;font-size:14.5px;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;}
#cancelModal .cancel-confirm:hover{background:#123A5C;}
#cancelModal .cancel-confirm:disabled{opacity:.6;cursor:default;}
#cancelModal .cancel-confirm.is-resched{background:#168078;}
#cancelModal .cancel-confirm.is-resched:hover{background:#1FA8A0;}
#cancelModal .resched-slots{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
#cancelModal .resched-slot{border:1.5px solid #E1EAEC;background:#fff;border-radius:10px;padding:9px 4px;font:inherit;font-size:13px;font-weight:600;color:#0B2A40;cursor:pointer;}
#cancelModal .resched-slot:hover:not(:disabled){border-color:#1FA8A0;}
#cancelModal .resched-slot.on{background:#1FA8A0;border-color:#1FA8A0;color:#fff;}
#cancelModal .resched-slot:disabled{background:#f1f3f5;color:#a3abb4;text-decoration:line-through;cursor:not-allowed;}
#cancelModal .resched-hint{font-size:11.5px;color:#65798A;margin-top:6px;}
#cancelModal .resched-note{font-size:12px;color:#65798A;margin-top:12px;}
</style>
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
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item active" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
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
      <div><h2>Appointments</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content tbl-fit">
      <div class="card">
        <div class="toolbar">
          <div class="tabs" id="statusFilterTabs">
            <button type="button" class="tab active" data-filter="all">All</button>
            <button type="button" class="tab" data-filter="Pending">Pending</button>
            <button type="button" class="tab" data-filter="Confirmed">Confirmed</button>
            <button type="button" class="tab" data-filter="Completed">Completed</button>
            <button type="button" class="tab" data-filter="Cancelled">Cancelled</button>
          </div>
          <div class="search-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="apptSearch" type="search" placeholder="Search name, phone, service, date, ref" aria-label="Search appointments" autocomplete="off">
          </div>
          <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('apptModal').style.display='flex';"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg> New appointment</button>
        </div>

        <div class="tbl-scroll">
        <table id="adminApptTable">
          <thead>
            <tr><th>Patient</th><th>Service</th><th>Attending Dentist</th><th>Date &amp; Time</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <tr id="adminApptTableEmpty" class="hidden"><td colspan="6" style="text-align:center;color:var(--muted);padding:28px 12px;">No appointments</td></tr>
            <?php if (empty($appointments)): ?>
              <tr>
                <td colspan="6" style="text-align:center;color:var(--muted);padding:28px 12px;">No appointments</td>
              </tr>
            <?php else: ?>
              <?php foreach ($appointments as $a): ?>
                <?php $info = ['id' => (int)$a['id'], 'ref' => $a['ref'], 'name' => $a['patient_name'], 'phone' => $a['phone'], 'email' => $a['appt_email'],
                       'service' => $a['service_name'], 'date' => date('l, F j, Y', strtotime($a['appointment_date'])), 'time' => $a['time_formatted'],
                       'raw_date' => $a['appointment_date'], 'status' => $a['status'], 'comments' => $a['comments'], 'notes' => $a['admin_notes'], 'patient_id' => $a['patient_id'],
                       'booked' => date('M j, Y g:i A', strtotime($a['created_at'])),
                       'series' => $a['series_id'] ? ('Visit ' . (int)$a['series_no'] . ' of ' . (int)$a['total_count'] . ' · ' . (recur_frequencies()[$a['frequency']] ?? '')) : '',
                       'later' => (int)($laterCount[(int)$a['id']] ?? 0)]; ?>
                <tr class="row-hover appt-row" id="appt-<?= (int)$a['id'] ?>" data-status="<?= htmlspecialchars($a['status']) ?>" data-info="<?= htmlspecialchars(json_encode($info), ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars(strtolower($a['patient_name'] . ' ' . $a['phone'] . ' ' . $a['service_name'] . ' ' . $a['ref'] . ' ' . $a['appt_email'] . ' ' . date('M j Y F j l', strtotime($a['appointment_date'])) . ' ' . $a['time_formatted'] . ' ' . $a['status'])) ?>">
                  <td>
                    <div class="row-people">
                      <div class="avatar-sm"><?= htmlspecialchars($a['initials']) ?></div>
                      <div>
                        <a href="#" class="name appt-view" style="text-decoration:none;color:inherit;" title="View details"><?= htmlspecialchars($a['patient_name']) ?></a>
                        <div style="font-size:11.5px;color:var(--muted);"><?= htmlspecialchars($a['phone']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($a['service_name']) ?><?php if ($a['series_id']): ?><div style="font-size:11.5px;color:var(--teal-dark, var(--muted));" title="Repeating appointment">&#8635; Visit <?= (int)$a['series_no'] ?>/<?= (int)$a['total_count'] ?></div><?php endif; ?></td>
                  <td>Dr. Marilou S. Cariño</td>
                  <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($a['appointment_date'])) ?><br><span style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($a['time_formatted']) ?></span></td>
                  <td><span class="badge <?= $badge_map[$a['status']] ?? 'b-pending' ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                  <td>
                    <div class="action-row">
                      <?php if (!empty($a['patient_id']) && in_array($a['status'], ['Pending', 'Confirmed'], true)): ?>
                        <button type="button" class="icon-btn" title="Send reminder to patient" aria-label="Send reminder to patient" onclick="sendReminder(<?= (int)$a['id'] ?>, this)">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($a['status'] === 'Pending'): ?>
                        <button type="button" class="icon-btn" title="Confirm" onclick="handleAppt(<?= (int)$a['id'] ?>, 'confirm_appointment')">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($a['status'] === 'Confirmed'): ?>
                        <button type="button" class="icon-btn" title="Mark completed" onclick="handleAppt(<?= (int)$a['id'] ?>, 'complete_appointment')">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($a['status'] !== 'Cancelled' && $a['status'] !== 'Completed'): ?>
                        <button type="button" class="icon-btn" title="Cancel or reschedule" aria-label="Cancel or reschedule" onclick="handleAppt(<?= (int)$a['id'] ?>, 'cancel_appointment')">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="detailModal" style="display:none;">
  <div class="modal ad-modal">
    <div class="ad-head">
      <h3 id="dName">Appointment</h3>
      <span class="badge" id="dStatus"></span>
      <button type="button" class="modal-close" aria-label="Close" onclick="closeDetail()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <dl id="dBody" class="ad-list"></dl>
    <div id="dActions" class="d-actions"></div>
  </div>
</div>

<div class="modal-backdrop" id="apptModal" style="display:none;">
  <div class="modal">
    <div class="modal-head">
      <h3>New appointment</h3>
      <button type="button" class="modal-close" onclick="document.getElementById('apptModal').style.display='none';"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <form id="newApptForm" onsubmit="submitNewAppt(event)">
      <div class="form-grid">
        <div class="full">
          <label>Patient</label>
          <select id="naPatient" required style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <?php foreach ($patients as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Service</label>
          <select id="naService" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <?php foreach ($services as $s): ?>
              <option value="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Date</label>
          <input type="date" id="naDate" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;">
        </div>
        <div>
          <label>Time</label>
          <select id="naTime" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <option>09:00 AM</option><option>10:00 AM</option><option>11:00 AM</option><option>01:00 PM</option>
            <option>02:00 PM</option><option>03:00 PM</option><option>04:00 PM</option>
          </select>
        </div>
        <div>
          <label>Repeat</label>
          <select id="naRepeat" onchange="document.getElementById('naRepeatCountWrap').style.display = this.value ? '' : 'none';" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <option value="">Does not repeat</option>
            <?php foreach (recur_frequencies() as $rk => $rl): ?>
              <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="naRepeatCountWrap" style="display:none;">
          <label>Total visits <span style="color:var(--muted);font-weight:500;">(including this one)</span></label>
          <select id="naRepeatCount" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <?php for ($n = 2; $n <= RECUR_MAX_VISITS; $n++): ?><option value="<?= $n ?>"<?= $n === 6 ? ' selected' : '' ?>><?= $n ?> visits</option><?php endfor; ?>
          </select>
        </div>
      </div>
      <p style="font-size:12px;color:var(--muted);margin:10px 0 0;">Repeat is for braces adjustments, follow-ups and regular check-ups. Later visits keep the same time and move to the next open day if the clinic is closed or the time is taken.</p>
      <button type="submit" class="btn btn-primary" style="margin-top:16px;width:100%;">Create Appointment</button>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="cancelModal" style="display:none;">
  <div class="modal cancel-modal" role="dialog" aria-modal="true" aria-labelledby="cancelTitle">
    <button type="button" class="modal-close cancel-x" aria-label="Close" onclick="closeCancel()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="cancel-head">
      <div class="cancel-ico"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M10 14l4 4M14 14l-4 4"/></svg></div>
      <h3 id="cancelTitle">Cancel Appointment</h3>
    </div>
    <div class="cancel-mode" role="tablist" aria-label="Choose an action">
      <button type="button" role="tab" class="cancel-mode-btn active" id="modeCancelBtn" aria-selected="true" onclick="setCancelMode('cancel')">Cancel</button>
      <button type="button" role="tab" class="cancel-mode-btn" id="modeReschedBtn" aria-selected="false" onclick="setCancelMode('reschedule')">Reschedule</button>
    </div>
    <p class="cancel-q" id="cancelQ">Are you sure you want to cancel this appointment?</p>
    <div id="cancelWho" class="cancel-who"></div>

    <div id="cancelPanel">
      <label class="cancel-label" for="cancelReason">Reason for cancellation <span style="color:var(--coral);">*</span></label>
      <select id="cancelReason" class="cancel-field">
        <option value="">Select a reason</option>
        <option>Schedule conflict</option>
        <option>Dentist unavailable</option>
        <option>Clinic closed</option>
        <option>Emergency</option>
        <option>Patient requested</option>
        <option>Duplicate booking</option>
        <option>Other</option>
      </select>
      <label class="cancel-label" for="cancelDetails">Additional details <span style="color:var(--muted);font-weight:500;">(optional)</span></label>
      <div class="cancel-area">
        <textarea id="cancelDetails" class="cancel-field" maxlength="200" rows="3" placeholder="e.g. The dentist is unavailable on the scheduled date."></textarea>
        <span class="cancel-count" id="cancelCount">0/200</span>
      </div>
      <label id="cancelSeriesRow" style="display:none;gap:8px;align-items:flex-start;margin-top:12px;font-size:13.5px;cursor:pointer;">
        <input type="checkbox" id="cancelSeries" style="margin-top:3px;">
        <span id="cancelSeriesText">Also cancel the later visits in this repeating series</span>
      </label>
    </div>

    <div id="reschedPanel" style="display:none;">
      <label class="cancel-label" for="reschedDate">New date <span style="color:var(--coral);">*</span></label>
      <input type="date" id="reschedDate" class="cancel-field" min="<?= date('Y-m-d') ?>">
      <label class="cancel-label">New time <span style="color:var(--coral);">*</span></label>
      <div class="resched-slots" id="reschedSlots">
        <?php foreach ($time_slots as $ts): ?>
          <button type="button" class="resched-slot" data-time="<?= htmlspecialchars($ts) ?>"><?= htmlspecialchars($ts) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="resched-hint" id="reschedHint">Pick a date first. Greyed-out times are already booked.</div>
      <label class="cancel-label" for="reschedReason">Reason <span style="color:var(--muted);font-weight:500;">(optional)</span></label>
      <div class="cancel-area">
        <textarea id="reschedReason" class="cancel-field" maxlength="200" rows="2" placeholder="Tell the patient why you need to move it."></textarea>
        <span class="cancel-count" id="reschedCount">0/200</span>
      </div>
      <div class="resched-note">The patient will be notified of the new schedule after you reschedule.</div>
    </div>

    <div class="cancel-err" id="cancelErr" style="display:none;">Please choose a reason for cancellation.</div>
    <button type="button" class="cancel-confirm" id="cancelConfirmBtn" onclick="confirmCancel()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>
      <span id="cancelConfirmText">Confirm Cancellation</span>
    </button>
  </div>
</div>

<script>
function adminToast(msg) {
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3200);
}

async function sendReminder(id, btn) {
  btn.disabled = true;
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ action: 'send_reminder', appointment_id: id })
    });
    const d = await res.json();
    adminToast(d.ok ? (d.message || 'Reminder sent to the patient.') : (d.error || 'Could not send the reminder.'));
  } catch (e) {
    adminToast('Network error: ' + e.message);
  }
  btn.disabled = false;
}

var cancelTarget = null, cancelMode = 'cancel', reschedInfo = null, reschedTime = '';
var TAKEN_SLOTS = <?= json_encode($taken_slots) ?>;
var OPEN_DAYS = <?= json_encode(array_values($open_days)) ?>;
var DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
function dayName(iso) { var p = iso.split('-'); return DAY_NAMES[new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).getDay()]; }
function setCancelMode(mode) {
  cancelMode = mode;
  var isR = mode === 'reschedule';
  document.getElementById('cancelPanel').style.display = isR ? 'none' : '';
  document.getElementById('reschedPanel').style.display = isR ? '' : 'none';
  document.getElementById('modeCancelBtn').classList.toggle('active', !isR);
  document.getElementById('modeReschedBtn').classList.toggle('active', isR);
  document.getElementById('modeCancelBtn').setAttribute('aria-selected', String(!isR));
  document.getElementById('modeReschedBtn').setAttribute('aria-selected', String(isR));
  document.getElementById('cancelTitle').textContent = isR ? 'Reschedule Appointment' : 'Cancel Appointment';
  document.getElementById('cancelQ').textContent = isR ? 'Choose a new date and time for this appointment.' : 'Are you sure you want to cancel this appointment?';
  document.getElementById('cancelConfirmText').textContent = isR ? 'Confirm Reschedule' : 'Confirm Cancellation';
  var btn = document.getElementById('cancelConfirmBtn');
  btn.classList.toggle('is-resched', isR);
  document.getElementById('cancelErr').style.display = 'none';
  if (isR) refreshSlots();
}
function refreshSlots() {
  var date = document.getElementById('reschedDate').value, hint = document.getElementById('reschedHint');
  var closed = false;
  hint.textContent = 'Pick a date first. Greyed-out times are already booked.';
  if (date && OPEN_DAYS.length && OPEN_DAYS.indexOf(dayName(date)) === -1) {
    closed = true;
    hint.textContent = 'This clinic is only open on ' + OPEN_DAYS.join(', ') + '. Please pick one of those days.';
  }
  document.querySelectorAll('#reschedSlots .resched-slot').forEach(function (b) {
    var t = b.dataset.time;
    var off = !date || closed;
    var taken = !off && TAKEN_SLOTS.some(function (x) { return x[1] === date && x[2] === t && x[0] !== cancelTarget; });
    var isSame = !off && reschedInfo && date === reschedInfo.raw_date && t === reschedInfo.time;
    var past = false;
    if (!off) {
      var now = new Date(), today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
      if (date === today) {
        var m = t.match(/(\d+):(\d+)\s*(AM|PM)/i), h = parseInt(m[1], 10) % 12 + (m[3].toUpperCase() === 'PM' ? 12 : 0);
        past = h * 60 + parseInt(m[2], 10) <= now.getHours() * 60 + now.getMinutes();
      }
    }
    b.disabled = off || taken || past || !!isSame;
    b.title = taken ? 'Already booked' : (past ? 'Time already passed' : (isSame ? 'Current schedule' : ''));
    if (b.disabled && reschedTime === t) { reschedTime = ''; }
    b.classList.toggle('on', reschedTime === t);
  });
}
document.querySelectorAll('#reschedSlots .resched-slot').forEach(function (b) {
  b.addEventListener('click', function () {
    if (b.disabled) return;
    reschedTime = b.dataset.time;
    document.getElementById('cancelErr').style.display = 'none';
    refreshSlots();
  });
});
document.getElementById('reschedDate').addEventListener('change', function () {
  document.getElementById('cancelErr').style.display = 'none';
  refreshSlots();
});
function showCancelErr(msg) {
  var e = document.getElementById('cancelErr');
  e.textContent = msg;
  e.style.display = 'block';
}
function openCancel(id, mode) {
  var row = document.getElementById('appt-' + id);
  var info = row ? JSON.parse(row.dataset.info) : null;
  cancelTarget = id;
  reschedInfo = info;
  reschedTime = '';
  document.getElementById('cancelReason').value = '';
  document.getElementById('cancelDetails').value = '';
  document.getElementById('cancelCount').textContent = '0/200';
  document.getElementById('reschedDate').value = '';
  document.getElementById('reschedReason').value = '';
  document.getElementById('reschedCount').textContent = '0/200';
  var later = info ? (info.later || 0) : 0;
  document.getElementById('cancelSeries').checked = false;
  document.getElementById('cancelSeriesRow').style.display = later > 0 ? 'flex' : 'none';
  document.getElementById('cancelSeriesText').textContent = 'Also cancel the ' + later + ' later visit' + (later === 1 ? '' : 's') + ' in this repeating series';
  document.getElementById('cancelWho').textContent = info ? (info.name + ' \u00b7 ' + info.service + ' \u00b7 ' + info.date + ', ' + info.time) : '';
  document.getElementById('cancelConfirmBtn').disabled = false;
  setCancelMode(mode === 'reschedule' ? 'reschedule' : 'cancel');
  closeDetail();
  document.getElementById('cancelModal').style.display = 'flex';
  (cancelMode === 'reschedule' ? document.getElementById('reschedDate') : document.getElementById('cancelReason')).focus();
}
function closeCancel() { cancelTarget = null; document.getElementById('cancelModal').style.display = 'none'; }
document.getElementById('cancelDetails').addEventListener('input', function () {
  document.getElementById('cancelCount').textContent = this.value.length + '/200';
});
document.getElementById('reschedReason').addEventListener('input', function () {
  document.getElementById('reschedCount').textContent = this.value.length + '/200';
});
document.getElementById('cancelReason').addEventListener('change', function () {
  if (this.value) document.getElementById('cancelErr').style.display = 'none';
});
document.getElementById('cancelModal').addEventListener('mousedown', function (e) { if (e.target === this) closeCancel(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeCancel(); });
async function confirmCancel() {
  var payload;
  if (cancelMode === 'reschedule') {
    var date = document.getElementById('reschedDate').value;
    if (!date) { showCancelErr('Please choose a new date.'); document.getElementById('reschedDate').focus(); return; }
    if (!reschedTime) { showCancelErr('Please choose a new time.'); return; }
    payload = { action: 'reschedule_appointment', id: cancelTarget, date: date, time: reschedTime, reason: document.getElementById('reschedReason').value.trim() };
  } else {
    var reason = document.getElementById('cancelReason').value;
    var details = document.getElementById('cancelDetails').value.trim();
    if (!reason) { showCancelErr('Please choose a reason for cancellation.'); document.getElementById('cancelReason').focus(); return; }
    payload = { action: 'cancel_appointment', id: cancelTarget, reason: reason, details: details, series: document.getElementById('cancelSeries').checked };
  }
  var btn = document.getElementById('cancelConfirmBtn');
  btn.disabled = true;
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const d = await res.json();
    if (d.ok) { location.reload(); }
    else { msToast('Error: ' + (d.error || 'Action failed'), 'error'); btn.disabled = false; }
  } catch (e) {
    msToast('Network error: ' + e.message, 'error');
    btn.disabled = false;
  }
}

async function handleAppt(id, action) {
  if (action === 'cancel_appointment') { openCancel(id); return; }
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ action: action, id: id })
    });
    const d = await res.json();
    if (d.ok) {
      location.reload();
    } else {
      msToast('Error: ' + (d.error || 'Action failed'), 'error');
    }
  } catch (e) {
    msToast('Network error: ' + e.message, 'error');
  }
}

(function () { try { var m = sessionStorage.getItem('seriesMsg'); if (m) { sessionStorage.removeItem('seriesMsg'); if (window.msToast) msToast(m, 'success'); } } catch (e) {} })();

async function submitNewAppt(e) {
  e.preventDefault();
  const pid = document.getElementById('naPatient').value;
  const s = document.getElementById('naService').value;
  const d = document.getElementById('naDate').value;
  const t = document.getElementById('naTime').value;

  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: 'create_appointment',
        patient_id: pid,
        service: s,
        date: d,
        time: t,
        repeat: document.getElementById('naRepeat').value,
        repeat_count: document.getElementById('naRepeatCount').value
      })
    });
    const resp = await res.json();
    if (resp.ok) {
      if (resp.more) { sessionStorage.setItem('seriesMsg', 'Created ' + (resp.more + 1) + ' visits in the series' + (resp.skipped ? ' (' + resp.skipped + ' could not be placed)' : '') + '.'); }
      location.reload();
    } else {
      msToast('Error: ' + (resp.error || 'Could not create appointment'), 'error');
    }
  } catch (err) {
    msToast('Network error: ' + err.message, 'error');
  }
}

function applyApptFilter() {
  var tab = document.querySelector('#statusFilterTabs .tab.active');
  var filter = tab ? tab.dataset.filter : 'all';
  var qEl = document.getElementById('apptSearch');
  var terms = (qEl ? qEl.value : '').toLowerCase().split(/\s+/).filter(Boolean);
  var visible = 0;
  document.querySelectorAll('.appt-row').forEach(function (r) {
    var okStatus = filter === 'all' || r.dataset.status === filter;
    var hay = r.dataset.search || '';
    var okText = terms.every(function (t) { return hay.indexOf(t) > -1; });
    r.style.display = (okStatus && okText) ? '' : 'none';
    if (okStatus && okText) visible++;
  });
  var emptyMsg = document.getElementById('adminApptTableEmpty');
  if (emptyMsg) emptyMsg.classList.toggle('hidden', visible > 0);
}
document.getElementById('apptSearch') && document.getElementById('apptSearch').addEventListener('input', applyApptFilter);

function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function openDetail(info) {
  document.getElementById('dName').textContent = info.name;
  var bc = {Confirmed: 'b-confirmed', Completed: 'b-completed', Pending: 'b-pending', Cancelled: 'b-cancelled', 'No-show': 'b-cancelled'};
  var st = document.getElementById('dStatus'); st.textContent = info.status; st.className = 'badge ' + (bc[info.status] || 'b-pending');
  var rows = [['Service', info.service], ['Date', info.date], ['Time', info.time], ['Phone', info.phone], ['Email', info.email],
              ['Reference', info.ref], ['Requested', info.booked]];
  if (info.series) rows.push(['Repeats', info.series]);
  if (info.comments) rows.push(['Patient notes', info.comments]);
  if (info.notes) rows.push(['Clinic notes', info.notes]);
  document.getElementById('dBody').innerHTML = rows.map(function (r) {
    return '<dt>' + r[0] + '</dt><dd>' + esc(r[1]) + '</dd>';
  }).join('');
  var a = [];
  if (info.status === 'Pending') a.push('<button type="button" class="btn btn-primary btn-sm" onclick="handleAppt(' + info.id + ',\'confirm_appointment\')">Confirm</button>');
  if (info.status === 'Confirmed') a.push('<button type="button" class="btn btn-primary btn-sm" onclick="handleAppt(' + info.id + ',\'complete_appointment\')">Mark completed</button>');
  if (info.status === 'Pending' || info.status === 'Confirmed') a.push('<button type="button" class="btn btn-ghost btn-sm" onclick="openCancel(' + info.id + ',\'reschedule\')">Reschedule</button>');
  if (info.patient_id) a.push('<a class="btn btn-ghost btn-sm" href="patient-record.php?patient=' + info.patient_id + '">Patient record</a>');
  if (info.status === 'Pending' || info.status === 'Confirmed') a.push('<button type="button" class="btn btn-danger btn-sm" onclick="handleAppt(' + info.id + ',\'cancel_appointment\')">Cancel</button>');
  document.getElementById('dActions').innerHTML = a.join('');
  document.getElementById('detailModal').style.display = 'flex';
}
function closeDetail() { document.getElementById('detailModal').style.display = 'none'; }
document.querySelectorAll('.appt-view').forEach(function (l) {
  l.addEventListener('click', function (e) { e.preventDefault(); openDetail(JSON.parse(l.closest('tr').dataset.info)); });
});
document.getElementById('detailModal').addEventListener('click', function (e) { if (e.target === this) closeDetail(); });
document.querySelectorAll('.appt-row').forEach(function (tr) {
  tr.style.cursor = 'pointer';
  tr.addEventListener('click', function (e) {
    if (e.target.closest('button, a, input, select, textarea, form, .icon-btn')) return;
    openDetail(JSON.parse(tr.dataset.info));
  });
});
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDetail(); });
(function () {
  var f = new URLSearchParams(location.search).get('focus');
  var row = f && document.getElementById('appt-' + parseInt(f, 10));
  if (!row) return;
  row.classList.add('focus-row');
  row.scrollIntoView({ block: 'center' });
  openDetail(JSON.parse(row.dataset.info));
})();

document.querySelectorAll('#statusFilterTabs button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#statusFilterTabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyApptFilter();
  });
});

(function () {
  var q = new URLSearchParams(location.search);
  if (q.get('new') !== '1') return;
  var p = document.getElementById('naPatient'), sv = document.getElementById('naService');
  if (p && q.get('patient')) p.value = q.get('patient');
  if (sv && q.get('service')) sv.value = q.get('service');
  var m = document.getElementById('apptModal');
  if (m) m.style.display = 'flex';
})();
</script>
</body>
</html>
