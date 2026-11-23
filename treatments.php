<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
require_permission('treatments');
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$treatments = [];
$patients = [];
$db_error = null;

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT t.id, t.treatment_date, t.procedure_name, t.tooth, t.dentist_name, t.cost, t.notes,
               concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name) AS patient_name
        FROM treatments t
        JOIN patients p ON p.id = t.patient_id
        WHERE t.branch_id = :b AND t.procedure_name NOT ILIKE '%brace%'
        ORDER BY t.treatment_date DESC, t.id DESC
    ");
    $stmt->execute([':b' => $branch_id]);
    $treatments = $stmt->fetchAll();

    $p_stmt = $pdo->query("SELECT id, concat_ws(' ', first_name, NULLIF(middle_name, ''), last_name) AS full_name FROM patients ORDER BY full_name");
    $patients = $p_stmt->fetchAll();

} catch (PDOException $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Treatment History | MSCare Dental Clinic</title>
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
      <a class="nav-item" href="index.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span>Dashboard</span></a>
      <a class="nav-item" href="appointments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Appointments</span></a>
      <a class="nav-item" href="patients.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c1-3.6 3.4-5.5 6.5-5.5s5.5 1.9 6.5 5.5"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M16 14.7c2.5.2 4.3 2 5.1 4.9"/></svg><span>Patients</span></a>
      <a class="nav-item active" href="treatments.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg><span>Treatment History</span></a>
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
      <div><h2>Treatment History</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content tbl-fit">
      <div class="card">
        <div class="toolbar">
          <div class="search-box">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input id="treatSearch" type="search" placeholder="Search by patient, procedure, tooth, dentist or date" oninput="filterTreatments()" autocomplete="off">
          </div>
          <button type="button" class="btn btn-primary btn-sm" style="margin-left:auto;" onclick="document.getElementById('treatModal').style.display='flex';">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg> Log treatment
          </button>
        </div>
        <div class="tbl-scroll">
        <table id="treatTable">
          <thead>
            <tr><th>Date</th><th>Patient</th><th>Procedure</th><th>Tooth</th><th>Attending Dentist</th><th>Cost</th></tr>
          </thead>
          <tbody>
            <?php if (empty($treatments)): ?>
              <tr><td colspan="6" style="text-align:center;color:var(--muted,#7a8a99);padding:28px 12px;">No treatments</td></tr>
            <?php else: ?>
              <?php foreach ($treatments as $t): ?>
                <tr class="row-hover t-row" data-search="<?= htmlspecialchars(strtolower(implode(' ', [
                    $t['patient_name'], $t['procedure_name'], $t['tooth'], $t['dentist_name'],
                    date('M j, Y', strtotime($t['treatment_date'])), $t['treatment_date'], $t['notes'] ?? ''
                  ])), ENT_QUOTES) ?>">
                  <td><?= date('M j, Y', strtotime($t['treatment_date'])) ?></td>
                  <td><b><?= htmlspecialchars($t['patient_name']) ?></b></td>
                  <td><?= htmlspecialchars($t['procedure_name']) ?></td>
                  <td><?= htmlspecialchars($t['tooth']) ?></td>
                  <td><?= htmlspecialchars($t['dentist_name']) ?></td>
                  <td style="font-weight:600;color:var(--navy);">₱<?= number_format((float)$t['cost'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            <tr id="treatNoResults" style="display:none;"><td colspan="6" style="text-align:center;color:var(--muted,#7a8a99);padding:28px 12px;">No treatments match your search.</td></tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="treatModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);z-index:99;align-items:center;justify-content:center;">
  <div class="modal" style="background:#fff;border-radius:14px;padding:24px;max-width:500px;width:100%;">
    <div class="modal-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
      <h3 style="margin:0;">Log a treatment</h3>
      <button type="button" class="modal-close" onclick="document.getElementById('treatModal').style.display='none';"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <form id="treatForm" onsubmit="submitTreatment(event)">
      <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div style="grid-column:1/-1;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Patient</label>
          <div class="pt-combo" id="ptCombo" style="position:relative;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted,#7a8a99);pointer-events:none;"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="text" id="tPatientSearch" placeholder="Search patient name..." autocomplete="off" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px 10px 36px;outline:none;">
            <input type="hidden" id="tPatient" value="">
            <ul id="ptList" role="listbox" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);max-height:270px;overflow-y:auto;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,.14);z-index:5;">
              <?php foreach ($patients as $p): ?>
                <li role="option" data-id="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars(strtolower($p['full_name'])) ?>" style="padding:10px 12px;border-radius:8px;cursor:pointer;font-size:14.5px;"><?= htmlspecialchars($p['full_name']) ?></li>
              <?php endforeach; ?>
              <li id="ptNone" style="display:none;padding:10px 12px;color:var(--muted,#7a8a99);font-size:14px;">No patients found.</li>
            </ul>
          </div>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Date</label>
          <input type="date" id="tDate" required value="<?= date('Y-m-d') ?>" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Tooth</label>
          <input id="tTooth" placeholder="e.g. #26 or Full mouth" value="—" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;">
        </div>
        <div style="grid-column:1/-1;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Procedure</label>
          <input id="tProc" required placeholder="e.g. Cleaning, Root Canal Treatment" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Attending Dentist</label>
          <input id="tDentist" value="Dr. Marilou S. Cariño" readonly style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;background:#f8fafc;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Cost (₱)</label>
          <input type="number" id="tCost" value="1200" min="0" step="50" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Payment Method</label>
          <select id="tMethod" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;">
            <option>Cash</option><option>Card</option><option>GCash</option><option>Bank Transfer</option>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Amount Paid (₱)</label>
          <input type="number" id="tPaid" value="1200" min="0" step="50" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;">
        </div>
        <div style="grid-column:1/-1;">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Clinical Notes</label>
          <textarea id="tNotes" placeholder="Procedure details and prescriptions" style="width:100%;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;height:70px;"></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:16px;width:100%;">Save</button>
    </form>
  </div>
</div>

<script>
(function () { // Amount Paid follows Cost until the admin edits it by hand
  var cost = document.getElementById('tCost'), paid = document.getElementById('tPaid'), touched = false;
  if (!cost || !paid) return;
  paid.addEventListener('input', function () { touched = true; });
  cost.addEventListener('input', function () { if (!touched) paid.value = cost.value; });
})();
(function () { // Searchable patient picker for the Log a treatment modal
  var combo = document.getElementById('ptCombo');
  if (!combo) return;
  var input = document.getElementById('tPatientSearch'),
      hidden = document.getElementById('tPatient'),
      list = document.getElementById('ptList'),
      none = document.getElementById('ptNone'),
      items = Array.prototype.slice.call(list.querySelectorAll('li[data-id]')),
      active = -1;
  function visible() { return items.filter(function (li) { return li.style.display !== 'none'; }); }
  function highlight(i) {
    var v = visible();
    items.forEach(function (li) { li.style.background = ''; });
    if (!v.length) { active = -1; return; }
    active = Math.max(0, Math.min(i, v.length - 1));
    v[active].style.background = 'var(--teal-tint,#E6F6F4)';
    v[active].scrollIntoView({block: 'nearest'});
  }
  function filter() {
    var terms = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean), n = 0;
    items.forEach(function (li) {
      var ok = terms.every(function (t) { return li.dataset.name.indexOf(t) !== -1; });
      li.style.display = ok ? '' : 'none';
      if (ok) n++;
    });
    none.style.display = n ? 'none' : '';
    highlight(0);
  }
  function open() { list.style.display = 'block'; filter(); input.style.borderColor = 'var(--teal,#1FA8A0)'; }
  function close() { list.style.display = 'none'; input.style.borderColor = ''; }
  function pick(li) { hidden.value = li.dataset.id; input.value = li.textContent.trim(); close(); }
  input.addEventListener('focus', open);
  input.addEventListener('input', function () { hidden.value = ''; open(); });
  input.addEventListener('keydown', function (e) {
    var v = visible();
    if (e.key === 'ArrowDown') { e.preventDefault(); if (list.style.display === 'none') open(); else highlight(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
    else if (e.key === 'Enter') { if (list.style.display !== 'none' && v[active]) { e.preventDefault(); pick(v[active]); } }
    else if (e.key === 'Escape') { close(); }
  });
  list.addEventListener('mousedown', function (e) {
    var li = e.target.closest('li[data-id]');
    if (li) { e.preventDefault(); pick(li); }
  });
  list.addEventListener('mousemove', function (e) {
    var li = e.target.closest('li[data-id]');
    if (li) { var i = visible().indexOf(li); if (i !== active) highlight(i); }
  });
  document.addEventListener('mousedown', function (e) { if (!combo.contains(e.target)) close(); });
})();
function filterTreatments() {
  const q = (document.getElementById('treatSearch').value || '').trim().toLowerCase();
  const terms = q.split(/\s+/).filter(Boolean);
  let shown = 0;
  document.querySelectorAll('.t-row').forEach(row => {
    const s = row.dataset.search || '';
    const ok = terms.every(t => s.includes(t));
    row.style.display = ok ? '' : 'none';
    if (ok) shown++;
  });
  const none = document.getElementById('treatNoResults');
  if (none) none.style.display = (shown === 0 && document.querySelector('.t-row')) ? '' : 'none';
}
async function submitTreatment(e) {
  e.preventDefault();
  const pid = document.getElementById('tPatient').value;
  if (!pid) {
    msToast('Please choose a patient from the list.', 'error');
    document.getElementById('tPatientSearch').focus();
    return;
  }
  const d = document.getElementById('tDate').value;
  const th = document.getElementById('tTooth').value;
  const pr = document.getElementById('tProc').value;
  const c = document.getElementById('tCost').value;
  const n = document.getElementById('tNotes').value;
  const pm = document.getElementById('tMethod').value;
  const ap = document.getElementById('tPaid').value;

  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: 'log_treatment',
        patient_id: pid,
        date: d,
        tooth: th,
        procedure: pr,
        cost: c,
        payment_method: pm,
        amount_paid: ap,
        notes: n
      })
    });
    const dres = await res.json();
    if (dres.ok) {
      location.reload();
    } else {
      msToast('Error: ' + (dres.error || 'Failed to save treatment'), 'error');
    }
  } catch (err) {
    msToast('Network error: ' + err.message, 'error');
  }
}
</script>
</body>
</html>
