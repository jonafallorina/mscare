<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
require_once __DIR__ . '/includes/recurrence.php';
$patient = current_patient();
$pid = patient_id();

$appts = []; $db_error = null; $taken = []; $apptInfo = []; $detail = [];
try {
    recurrence_ensure_schema(db());
    require_once __DIR__ . '/includes/blocked_times.php';
    blocktime_ensure_schema(db());
    $stmt = db()->prepare("
        SELECT a.id, a.ref, a.appointment_date, to_char(a.appointment_time, 'HH12:MI AM') AS time_formatted,
               a.appointment_date::text AS date_iso, a.appointment_time::text AS time_raw, a.branch_id, b.open_days,
               s.name AS service_name, b.name AS branch_name, b.code AS branch_code, b.address AS branch_address,
               COALESCE(d.name, 'Dr. Marilou S. Cariño') AS dentist_name, a.status, a.admin_notes, a.comments, a.series_id, a.series_no, sr.total_count, sr.frequency
        FROM appointments a
        LEFT JOIN appointment_series sr ON sr.id = a.series_id
        JOIN services s ON s.id = a.service_id AND s.name NOT ILIKE '%brace%'
        JOIN branches b ON b.id = a.branch_id
        LEFT JOIN dentists d ON d.id = a.dentist_id
        WHERE a.patient_id = :pid
        ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->execute([':pid' => $pid]);
    $appts = $stmt->fetchAll();

    $laterCount = [];
    foreach ($appts as $a) {
        if (!$a['series_id'] || !in_array($a['status'], ['Pending', 'Confirmed'], true)) continue;
        foreach ($appts as $b) {
            if ($b['series_id'] === $a['series_id'] && in_array($b['status'], ['Pending', 'Confirmed'], true)
                && ($b['date_iso'] . ' ' . $b['time_raw']) > ($a['date_iso'] . ' ' . $a['time_raw'])) {
                $laterCount[(int)$a['id']] = ($laterCount[(int)$a['id']] ?? 0) + 1;
            }
        }
    }

    $q = db()->prepare("SELECT appointment_date::text AS d, to_char(appointment_time, 'HH12:MI AM') AS t
                         FROM appointments WHERE appointment_date >= :today AND status <> 'Cancelled'");
    $q->execute([':today' => date('Y-m-d')]);
    foreach ($q as $r) { $taken[$r['d']][] = $r['t']; }
    require_once __DIR__ . '/includes/blocked_times.php';
    blocktime_ensure_schema(db());
    $blocksJs = blocktime_for_js(blocktime_fetch(db()), array_column(db()->query("SELECT id, code FROM branches")->fetchAll(), 'code', 'id'));
    $blockCancelled = 0;
    foreach ($appts as $a) {

        $byClinic = $a['status'] === 'Cancelled' && strpos((string)$a['admin_notes'], BLOCK_CANCEL_NOTE) === 0 && $a['date_iso'] > date('Y-m-d');
        if ($byClinic) $blockCancelled++;
        $detail[(int)$a['id']] = [
            'service' => $a['service_name'], 'ref' => (string)$a['ref'], 'status' => $a['status'],
            'date' => date('l, F j, Y', strtotime($a['appointment_date'])), 'time' => $a['time_formatted'],
            'clinic' => $a['branch_name'], 'address' => (string)$a['branch_address'], 'dentist' => $a['dentist_name'],
            'notes' => trim((string)$a['comments']),
            'manage' => in_array($a['status'], ['Pending', 'Confirmed'], true),
            'series' => $a['series_id'] ? ('Visit ' . (int)$a['series_no'] . ' of ' . (int)$a['total_count'] . ' · ' . (recur_frequencies()[$a['frequency']] ?? '')) : '',
            'later' => (int)($laterCount[(int)$a['id']] ?? 0),
            'resched' => $byClinic,
            'clinicNote' => $byClinic ? trim(preg_replace('/^' . preg_quote(BLOCK_CANCEL_NOTE, '/') . '\s*/', '', (string)$a['admin_notes']), " ()") : '',
        ];
        if (in_array($a['status'], ['Pending', 'Confirmed'], true) || $byClinic) {
            $apptInfo[(int)$a['id']] = ['date' => $a['date_iso'], 'time' => $a['time_formatted'], 'branch' => $a['branch_name'], 'branch_code' => $a['branch_code'], 'days' => pg_days($a['open_days'])];
        }
    }
} catch (PDOException $ex) {
    error_log('MSCare DB (appointments): ' . $ex->getMessage());
    $db_error = db_fail_text($ex, 'We could not load your appointments right now.');
}

$more = max(0, (int)($_GET['more'] ?? 0)); $skipped = max(0, (int)($_GET['skipped'] ?? 0));
$booked = isset($_GET['booked']) ? preg_replace('/[^A-Za-z0-9:\-]/', '', (string)$_GET['booked']) : '';
$badge = ['Confirmed' => 'b-confirmed', 'Pending' => 'b-pending', 'Completed' => 'b-completed', 'Cancelled' => 'b-cancelled', 'No-show' => 'b-noshow'];

$ptitle = 'My Appointments';
$psub   = '';
$pactive = 'appointments';
include __DIR__ . '/includes/portal_head.php';
?>

<?php if ($booked): ?>
  <div class="banner ok" role="status">
    <?= icon($ui['check'], 20) ?>
    <div class="b-text"><strong>Appointment requested!</strong><span>Your reference number is <b><?= e($booked) ?></b>. The clinic will confirm it shortly.<?php if ($more): ?> We also requested <b><?= $more ?> more repeat visit<?= $more === 1 ? '' : 's' ?></b>, shown in the list below.<?php endif; ?><?php if ($more && $skipped): ?> <?= $skipped ?> visit<?= $skipped === 1 ? '' : 's' ?> could not be placed because no open time was free nearby. Please book <?= $skipped === 1 ? 'it' : 'them' ?> separately.<?php endif; ?></span></div>
  </div>
<?php endif; ?>
<?php if ($db_error): ?>
  <div class="banner err"><?= icon($ui['lock'], 20) ?><div class="b-text"><strong><?= e($db_error) ?></strong></div></div>
<?php endif; ?>

<?php if (!empty($blockCancelled)): ?>
  <div class="banner err" role="alert">
    <?= icon($ui['lock'], 20) ?>
    <div class="b-text"><strong><?= $blockCancelled === 1 ? 'One of your appointments was cancelled by the clinic.' : $blockCancelled . ' of your appointments were cancelled by the clinic.' ?></strong><span>The clinic is unavailable at <?= $blockCancelled === 1 ? 'that time' : 'those times' ?>. We are sorry for the inconvenience. Tap the cancelled appointment and choose <b>Reschedule</b> to pick a new date and time.</span></div>
  </div>
<?php endif; ?>
<div class="p-card">
  <div class="p-toolbar">
    <div class="chips" id="pFilterTabs">
      <?php foreach (['all' => 'All', 'upcoming' => 'Upcoming Appointments', 'past' => 'Past Appointments'] as $k => $label): ?>
        <button type="button" class="chip<?= $k === 'all' ? ' on' : '' ?>" data-filter="<?= e($k) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$appts): ?>
    <div class="p-empty"><strong>No appointments yet</strong>You have no appointments booked yet.</div>
  <?php else: ?>
  <ul class="inbox" id="apptInbox">
    <?php foreach ($appts as $a): $ts = strtotime($a['appointment_date']);
          $when = ((in_array($a['status'], ['Pending', 'Confirmed'], true) && $a['date_iso'] >= date('Y-m-d')) || !empty($detail[(int)$a['id']]['resched'])) ? 'upcoming' : 'past'; ?>
      <li class="p-appt-row" data-status="<?= e($a['status']) ?>" data-when="<?= $when ?>">
        <button type="button" class="inbox-item" data-id="<?= (int)$a['id'] ?>" aria-haspopup="dialog">
          <span class="inbox-date"><b><?= date('M', $ts) ?></b><span><?= date('j', $ts) ?></span></span>
          <span class="inbox-main">
            <strong><?= e($a['service_name']) ?></strong>
            <span class="inbox-sub"><?= e($a['branch_name']) ?> &middot; <?= date('D', $ts) ?>, <?= e($a['time_formatted']) ?><?php if ($a['series_id']): ?> &middot; <b title="Repeating appointment">&#8635; Visit <?= (int)$a['series_no'] ?>/<?= (int)$a['total_count'] ?></b><?php endif; ?></span>
          </span>
          <span class="badge <?= $badge[$a['status']] ?? 'b-pending' ?>"><?= e($a['status']) ?></span>
          <?= icon('M9 6l6 6-6 6', 16) ?>
        </button>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p class="p-empty hidden" id="apptNone" style="padding:18px 0 4px;">No appointments in this filter.</p>
</div>

<div class="ad-back" id="apptDetail" style="display:none;">
  <div class="ad-modal" role="dialog" aria-modal="true" aria-labelledby="adService">
    <button type="button" class="cancel-x" aria-label="Close" onclick="closeDetail()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="ad-head">
      <h3 id="adService"></h3>
      <span class="badge" id="adStatus"></span>
    </div>
    <dl class="ad-list">
      <dt>Reference</dt><dd id="adRef"></dd>
      <dt>Date</dt><dd id="adDate"></dd>
      <dt>Time</dt><dd id="adTime"></dd>
      <dt>Clinic</dt><dd id="adClinic"></dd>
      <dt>Address</dt><dd id="adAddress"></dd>
      <dt>Dentist</dt><dd id="adDentist"></dd>
      <dt id="adSeriesT">Repeats</dt><dd id="adSeries"></dd>
      <dt id="adNotesT">Your notes</dt><dd id="adNotes"></dd>
      <dt id="adClinicNoteT">Cancelled because</dt><dd id="adClinicNote"></dd>
    </dl>
    <div class="ad-actions">
      <button type="button" class="btn btn-outline btn-pill" onclick="closeDetail()">Close</button>
      <button type="button" class="btn btn-outline btn-pill" id="adResched" onclick="apptReschedule()" style="display:none;">Reschedule</button>
      <button type="button" class="btn btn-danger btn-pill" id="adCancel" onclick="apptCancel()" style="display:none;">Cancel</button>
      <a class="btn btn-primary btn-pill ad-call" id="adCall" href="tel:<?= e($clinic['phone_tel']) ?>"><?= icon($ui['phone'], 18) ?> Call Clinic</a>
    </div>
  </div>
</div>

<div class="cancel-back" id="cancelModal" style="display:none;">
  <div class="cancel-modal" role="dialog" aria-modal="true" aria-labelledby="cancelTitle">
    <button type="button" class="cancel-x" aria-label="Close" onclick="closeCancel()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="cancel-head">
      <div class="cancel-ico"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M10 14l4 4M14 14l-4 4"/></svg></div>
      <h3 id="cancelTitle">Cancel Appointment</h3>
    </div>
    <div class="cancel-mode" role="tablist">
      <button type="button" role="tab" class="cancel-mode-btn active" id="modeCancelBtn" aria-selected="true" onclick="setCancelMode('cancel')">Cancel</button>
      <button type="button" role="tab" class="cancel-mode-btn" id="modeReschedBtn" aria-selected="false" onclick="setCancelMode('reschedule')">Reschedule</button>
    </div>
    <p class="cancel-q" id="cancelQ">Are you sure you want to cancel this appointment?</p>
    <div id="cancelWho" class="cancel-who"></div>

    <div id="cancelPanel">
    <label class="cancel-label" for="cancelReason">Reason for cancellation <span style="color:#E9694C;">*</span></label>
    <select id="cancelReason" class="cancel-field">
      <option value="">Select a reason</option>
      <option>Schedule conflict</option>
      <option>Feeling better</option>
      <option>Found another clinic</option>
      <option>Emergency</option>
      <option>Financial reasons</option>
      <option>Booked by mistake</option>
      <option>Other</option>
    </select>
    <label class="cancel-label" for="cancelDetails">Additional details <span style="color:#65798A;font-weight:500;">(optional)</span></label>
    <div class="cancel-area">
      <textarea id="cancelDetails" class="cancel-field" maxlength="200" rows="3" placeholder="Tell the clinic anything they should know."></textarea>
      <span class="cancel-count" id="cancelCount">0/200</span>
    </div>
    <label class="cancel-series" id="cancelSeriesRow" style="display:none;gap:8px;align-items:flex-start;margin-top:12px;font-size:.92rem;cursor:pointer;">
      <input type="checkbox" id="cancelSeries" style="margin-top:3px;accent-color:#1FA89C;">
      <span id="cancelSeriesText">Also cancel my later visits in this repeating series</span>
    </label>
    </div>

    <div id="reschedPanel" style="display:none;">
      <label class="cancel-label" for="reschedDate">New date <span style="color:#E9694C;">*</span></label>
      <input type="date" id="reschedDate" class="cancel-field" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
      <label class="cancel-label">New time <span style="color:#E9694C;">*</span></label>
      <div class="resched-slots" id="reschedSlots">
        <?php foreach ($slots as $ts): ?>
          <button type="button" class="resched-slot" data-time="<?= e($ts) ?>"><?= e($ts) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="resched-hint" id="reschedHint">Pick a date first. Greyed-out times are already booked.</div>
      <label class="cancel-label" for="reschedReason">Reason <span style="color:#65798A;font-weight:500;">(optional)</span></label>
      <div class="cancel-area">
        <textarea id="reschedReason" class="cancel-field" maxlength="200" rows="2" placeholder="Tell the clinic why you need to move it."></textarea>
        <span class="cancel-count" id="reschedCount">0/200</span>
      </div>
      <div class="resched-note">Your request goes back to the clinic for confirmation after you reschedule.</div>
    </div>

    <div class="cancel-err" id="cancelErr" style="display:none;">Please choose a reason for cancellation.</div>
    <button type="button" class="cancel-confirm" id="cancelConfirmBtn" onclick="confirmCancel()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>
      <span id="cancelConfirmText">Confirm Cancellation</span>
    </button>
  </div>
</div>

<script>
(function () {
  var tabs = document.querySelectorAll('#pFilterTabs .chip');
  var rows = document.querySelectorAll('.p-appt-row');
  var none = document.getElementById('apptNone');
  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabs.forEach(function (b) { b.classList.remove('on'); });
      btn.classList.add('on');
      var f = btn.getAttribute('data-filter'), shown = 0;
      rows.forEach(function (row) {
        var ok = f === 'all' || row.getAttribute('data-when') === f;
        row.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      if (none && rows.length) none.classList.toggle('hidden', shown !== 0);
    });
  });
  var APPT = <?= json_encode($apptInfo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var TAKEN = <?= json_encode((object)$taken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var BLOCKS = <?= json_encode($blocksJs ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  function blockedSlot(branchCode, date, t) {
    var s = minutes(t);
    for (var i = 0; i < BLOCKS.length; i++) {
      var bk = BLOCKS[i];
      if ((bk.b === '*' || bk.b === branchCode) && date >= bk.f && date <= bk.t && (bk.all || (s < bk.e && s + 60 > bk.s))) return bk;
    }
    return null;
  }
  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var cancelId = null, cancelMode = 'cancel', reschedTime = '';
  var errBox = document.getElementById('cancelErr');

  function showErr(msg) { errBox.textContent = msg; errBox.style.display = 'block'; }
  function hideErr() { errBox.style.display = 'none'; }
  function pad(n) { return n < 10 ? '0' + n : String(n); }
  function todayISO() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function minutes(label) {
    var m = /^(\d+):(\d+) (AM|PM)$/.exec(label), h = Number(m[1]) % 12;
    if (m[3] === 'PM') h += 12;
    return h * 60 + Number(m[2]);
  }
  function dayName(iso) { var p = iso.split('-'); return DAYS[new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).getDay()]; }

  window.setCancelMode = function (mode) {
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
    document.getElementById('cancelConfirmBtn').classList.toggle('is-resched', isR);
    hideErr();
  };

  function refreshSlots() {
    var info = APPT[cancelId], date = document.getElementById('reschedDate').value, hint = document.getElementById('reschedHint');
    var closed = false;
    hint.textContent = 'Pick a date first. Greyed-out times are already booked.';
    if (date && date <= todayISO()) {
      closed = true;
      hint.textContent = 'Same-day booking is not available. Please pick tomorrow or a later date.';
    }
    if (date && info && info.days.length && info.days.indexOf(dayName(date)) === -1) {
      closed = true;
      hint.textContent = info.branch + ' is only open on ' + info.days.join(', ') + '. Please pick one of those days.';
    }
    var bcode = info ? info.branch_code : '', blockedMsg = '';
    document.querySelectorAll('#reschedSlots .resched-slot').forEach(function (b) {
      var t = b.dataset.time, off = !date || closed;
      var bk = (!off && date) ? blockedSlot(bcode, date, t) : null;
      if (bk) { off = true; blockedMsg = bk.r; }
      b.classList.toggle('blocked', !!bk);
      b.title = bk ? bk.r : '';
      if (!off && (TAKEN[date] || []).indexOf(t) > -1) off = true;
      if (!off && date === todayISO()) {
        var n = new Date();
        if (minutes(t) <= n.getHours() * 60 + n.getMinutes()) off = true;
      }
      b.disabled = off;
      if (off && reschedTime === t) reschedTime = '';
      b.classList.toggle('on', reschedTime === t);
    });
    if (blockedMsg && !closed) hint.textContent = 'Some times are closed by the clinic (' + blockedMsg + '). They are shown in red.';
  }
  document.querySelectorAll('#reschedSlots .resched-slot').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.disabled) return;
      reschedTime = b.dataset.time; hideErr(); refreshSlots();
    });
  });
  document.getElementById('reschedDate').addEventListener('change', function () { hideErr(); refreshSlots(); });
  document.getElementById('reschedReason').addEventListener('input', function () {
    document.getElementById('reschedCount').textContent = this.value.length + '/200';
  });

  window.closeCancel = function () { cancelId = null; document.getElementById('cancelModal').style.display = 'none'; };
  var DETAIL = <?= json_encode((object)$detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var BADGE = <?= json_encode($badge) ?>;
  var detailBack = document.getElementById('apptDetail'), detailId = null;
  function setText(id, v) { document.getElementById(id).textContent = v; }
  function showRow(id, on) { document.getElementById(id).style.display = on ? '' : 'none'; document.getElementById(id + 'T').style.display = on ? '' : 'none'; }
  window.closeDetail = function () { detailId = null; detailBack.style.display = 'none'; };
  function openDetail(id) {
    var d = DETAIL[id]; if (!d) return;
    detailId = id;
    setText('adService', d.service);
    var st = document.getElementById('adStatus'); st.textContent = d.status; st.className = 'badge ' + (BADGE[d.status] || 'b-pending');
    setText('adRef', d.ref || '\u2014'); setText('adDate', d.date); setText('adTime', d.time);
    setText('adClinic', d.clinic); setText('adAddress', d.address || '\u2014'); setText('adDentist', d.dentist);
    setText('adNotes', d.notes); showRow('adNotes', d.notes !== '');
    setText('adSeries', d.series); showRow('adSeries', !!d.series);
    setText('adClinicNote', d.clinicNote || ''); showRow('adClinicNote', !!d.clinicNote);
    document.getElementById('adResched').style.display = (d.manage || d.resched) ? '' : 'none';
    document.getElementById('adCancel').style.display = d.manage ? '' : 'none';
    detailBack.style.display = 'flex';
  }
  window.apptCancel = function () { var id = detailId; closeDetail(); if (id) openCancel(id); };
  window.apptReschedule = function () { var id = detailId; closeDetail(); if (id) { openCancel(id); setCancelMode('reschedule'); } };
  document.querySelectorAll('.inbox-item').forEach(function (btn) {
    btn.addEventListener('click', function () { openDetail(Number(btn.getAttribute('data-id'))); });
  });
  detailBack.addEventListener('mousedown', function (e) { if (e.target === this) closeDetail(); });

  function openCancel(id) {
    cancelId = id;
    var d = DETAIL[id];
    document.getElementById('cancelReason').value = '';
    document.getElementById('cancelDetails').value = '';
    document.getElementById('cancelCount').textContent = '0/200';
    document.getElementById('reschedDate').value = '';
    document.getElementById('reschedReason').value = '';
    document.getElementById('reschedCount').textContent = '0/200';
    reschedTime = '';
    hideErr();
    setCancelMode('cancel');
    document.querySelector('#cancelModal .cancel-mode').style.display = (d && !d.manage) ? 'none' : '';
    refreshSlots();
    var later = d ? d.later : 0;
    document.getElementById('cancelSeries').checked = false;
    document.getElementById('cancelSeriesRow').style.display = later > 0 ? 'flex' : 'none';
    document.getElementById('cancelSeriesText').textContent = 'Also cancel my ' + later + ' later visit' + (later === 1 ? '' : 's') + ' in this repeating series';
    document.getElementById('cancelWho').textContent = d ? (d.service + ' \u00b7 ' + d.date + ', ' + d.time) : '';
    document.getElementById('cancelModal').style.display = 'flex';
    document.getElementById('cancelReason').focus();
  }
  document.getElementById('cancelDetails').addEventListener('input', function () {
    document.getElementById('cancelCount').textContent = this.value.length + '/200';
  });
  document.getElementById('cancelReason').addEventListener('change', function () { if (this.value) hideErr(); });
  document.getElementById('cancelModal').addEventListener('mousedown', function (e) { if (e.target === this) closeCancel(); });
  var rq = /[?&]resched=(\d+)/.exec(location.search);
  if (rq && DETAIL[rq[1]] && (DETAIL[rq[1]].manage || DETAIL[rq[1]].resched)) { openCancel(Number(rq[1])); setCancelMode('reschedule'); }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeCancel(); closeDetail(); } });

  window.confirmCancel = function () {
    var payload, failMsg;
    if (cancelMode === 'reschedule') {
      var date = document.getElementById('reschedDate').value;
      if (!date) { showErr('Please choose a new date.'); document.getElementById('reschedDate').focus(); return; }
      if (!reschedTime) { showErr('Please choose a new time.'); return; }
      payload = { action: 'reschedule_appointment', id: cancelId, date: date, time: reschedTime, reason: document.getElementById('reschedReason').value.trim() };
      failMsg = 'Could not reschedule.';
    } else {
      var reason = document.getElementById('cancelReason').value;
      if (!reason) { showErr('Please choose a reason for cancellation.'); document.getElementById('cancelReason').focus(); return; }
      payload = { action: 'cancel_appointment', id: cancelId, reason: reason, details: document.getElementById('cancelDetails').value.trim(), series: document.getElementById('cancelSeries').checked };
      failMsg = 'Could not cancel.';
    }
    var btn = document.getElementById('cancelConfirmBtn');
    btn.disabled = true;
    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) location.reload(); else { msToast(d.error || failMsg, 'error'); btn.disabled = false; } })
      .catch(function (err) { msToast('Network error: ' + err.message, 'error'); btn.disabled = false; });
  };
})();
</script>

<?php include __DIR__ . '/includes/portal_foot.php'; ?>
