<?php
require_once __DIR__ . '/includes/auth.php';
require_patient();
require_once __DIR__ . '/includes/recurrence.php';
require_once __DIR__ . '/includes/blocked_times.php';
$patient = current_patient();
$pid = patient_id();
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$gate = ['consent' => 'none', 'can_book' => false];
$dbBranches = []; $dbServices = []; $taken = []; $lockBranch = null; $blocks = []; $codeById = [];
$error = ''; $old = null;

try {
    $pdo = db();
    recurrence_ensure_schema($pdo);
    blocktime_ensure_schema($pdo);
    $gate = booking_status($pid);
    $dbServices = $pdo->query("SELECT id, name FROM services WHERE is_active = TRUE AND name NOT ILIKE '%brace%' ORDER BY sort_order, name")->fetchAll();
    $dbBranches = $pdo->query("SELECT id, code, name, city, address, open_days FROM branches ORDER BY id")->fetchAll();
    foreach ($dbBranches as &$b) { $b['days'] = pg_days($b['open_days']); $codeById[(int)$b['id']] = $b['code']; } unset($b);
    $blocks = blocktime_fetch($pdo);

    $pb = $pdo->prepare("SELECT branch_id FROM patients WHERE id = :id");
    $pb->execute([':id' => $pid]);
    $prefId = (int)$pb->fetchColumn();

    if ($prefId === 0) {
        $mf = $pdo->prepare("SELECT branch_id FROM appointments WHERE patient_id = :id
                             GROUP BY branch_id ORDER BY COUNT(*) DESC, MAX(created_at) DESC LIMIT 1");
        $mf->execute([':id' => $pid]);
        $prefId = (int)$mf->fetchColumn();
        if ($prefId > 0) {
            try { $pdo->prepare("UPDATE patients SET branch_id = :b WHERE id = :id AND branch_id IS NULL")->execute([':b' => $prefId, ':id' => $pid]); }
            catch (PDOException $bx) { error_log('MSCare patient branch backfill: ' . $bx->getMessage()); }
        }
    }
    foreach ($dbBranches as $b) { if ((int)$b['id'] === $prefId) { $lockBranch = $b; break; } }

    $q = $pdo->prepare("SELECT appointment_date::text AS d, to_char(appointment_time, 'HH12:MI AM') AS t
                        FROM appointments WHERE appointment_date >= :today AND status <> 'Cancelled'");
    $q->execute([':today' => $today]);
    foreach ($q as $r) { $taken[$r['d']][] = $r['t']; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gate['can_book']) {
        $f = [];
        foreach (['branch', 'service', 'date', 'time', 'comments', 'repeat', 'repeat_count'] as $k) { $f[$k] = trim((string)($_POST[$k] ?? '')); }

        if ($lockBranch) { $f['branch'] = $lockBranch['code']; }
        $branch = null;
        foreach ($dbBranches as $b) { if ($b['code'] === $f['branch']) { $branch = $b; break; } }
        $service = null; foreach ($dbServices as $s) { if ($s['name'] === $f['service']) { $service = $s; break; } }
        $d = DateTime::createFromFormat('Y-m-d', $f['date']);
        $dateOk = $d && $d->format('Y-m-d') === $f['date'];

        if (!$branch) {
            $error = 'Please choose a clinic branch.';
        } elseif (!$service) {
            $error = 'Please choose a dental service.';
        } elseif (!$dateOk || $f['date'] < $tomorrow) {
            $error = 'Same-day booking is not available. Please choose tomorrow or a later date.';
        } elseif (!in_array($d->format('D'), $branch['days'], true)) {
            $error = $branch['name'] . ' is only open on ' . implode(', ', $branch['days']) . '. Please pick one of those days.';
        } elseif (!in_array($f['time'], $slots, true)) {
            $error = 'Please choose a valid time slot.';
        } elseif ($hit = blocktime_match($blocks, (int)$branch['id'], $f['date'], $f['time'])) {
            $error = blocktime_error($hit);
        } elseif (in_array($f['time'], $taken[$f['date']] ?? [], true)) {
            $error = 'That time slot was just booked by someone else. Please choose another time.';
        } elseif ($f['date'] === $today && strtotime($f['date'] . ' ' . $f['time']) <= time()) {
            $error = 'That time has already passed. Please choose a later time.';
        } elseif (str_len($f['comments']) > 500) {
            $error = 'Notes must be 500 characters or fewer.';
        } else {
            $dentist_id = $pdo->query("SELECT id FROM dentists WHERE is_active = TRUE ORDER BY id LIMIT 1")->fetchColumn();
            $ref = new_appt_ref($pdo, $f['date'], $f['time']);
            try {
                $ins = $pdo->prepare("
                    INSERT INTO appointments
                        (ref, patient_id, branch_id, service_id, dentist_id, appointment_date, appointment_time,
                         first_name, last_name, phone, email, is_current_patient, comments, status)
                    VALUES (:ref, :pid, :bid, :sid, :did, :d, :t, :fn, :ln, :ph, :em, TRUE, :cm, 'Pending')");
                $ins->bindValue(':ref', $ref);
                $ins->bindValue(':pid', $pid, PDO::PARAM_INT);
                $ins->bindValue(':bid', (int)$branch['id'], PDO::PARAM_INT);
                $ins->bindValue(':sid', (int)$service['id'], PDO::PARAM_INT);
                $ins->bindValue(':did', $dentist_id ?: null, $dentist_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $ins->bindValue(':d', $f['date']);
                $ins->bindValue(':t', date('H:i', strtotime($f['time'])));
                $ins->bindValue(':fn', $patient['first_name']);
                $ins->bindValue(':ln', $patient['last_name']);
                $ins->bindValue(':ph', $patient['phone']);
                $ins->bindValue(':em', $patient['email']);
                $ins->bindValue(':cm', $f['comments']);
                try { $ins->execute(); }
                catch (PDOException $ex1) {
                    if (!is_sequence_conflict($ex1)) throw $ex1;
                    fix_sequences($pdo);
                    $ins->execute();
                }

                $extra = 0; $skipped = 0;
                [$rf, $rn] = recur_parse($f['repeat'], $f['repeat_count']);
                if ($rf) {
                    try {
                        $firstId = (int)$pdo->query("SELECT id FROM appointments WHERE ref = " . $pdo->quote($ref))->fetchColumn();
                        $rs = recur_extend($pdo, $firstId, $rf, $rn, 'patient');
                        $extra = $rs['created']; $skipped = count($rs['skipped']);
                    } catch (Throwable $rx) { error_log('MSCare recurring: ' . $rx->getMessage()); }
                }

                try { $pdo->prepare("UPDATE patients SET branch_id = :b WHERE id = :id AND branch_id IS NULL")->execute([':b' => (int)$branch['id'], ':id' => $pid]); }
                catch (PDOException $bx) { error_log('MSCare patient branch: ' . $bx->getMessage()); }
                try {
                    require_once __DIR__ . '/includes/appt_mail.php';
                    $newId = (int)$pdo->query("SELECT id FROM appointments WHERE ref = " . $pdo->quote($ref))->fetchColumn();
                    if ($newId) { appt_email($pdo, $newId, 'received'); appt_email($pdo, $newId, 'new_booking_alert'); }
                } catch (Throwable $mx) { error_log('MSCare booking email: ' . $mx->getMessage()); }
                header('Location: patient-appointments.php?booked=' . urlencode($ref) . ($extra ? '&more=' . $extra . '&skipped=' . $skipped : ''));
                exit;
            } catch (PDOException $ex) {
                if (is_slot_conflict($ex)) {
                    $error = 'That time slot is already booked. Please choose another time.';
                } else { throw $ex; }
            }
        }

        $old = $f;
    }
} catch (PDOException $ex) {
    error_log('MSCare DB (patient book): ' . $ex->getMessage());
    $error = db_fail_text($ex, 'We could not complete that right now. Please try again.');
}

$jsBranches = [];
foreach ($dbBranches as $b) {
    $jsBranches[$b['code']] = ['id' => (int)$b['id'], 'name' => $b['name'], 'days' => $b['days'], 'daysText' => implode(', ', $b['days'])];
}
$preBranch = $lockBranch ? $lockBranch['code'] : (string)($old['branch'] ?? '');
if ($lockBranch && is_array($old)) { $old['branch'] = $lockBranch['code']; }

$ptitle = 'Book an appointment';
$psub   = '';
$pactive = 'book';
include __DIR__ . '/includes/portal_head.php';
?>

<?php if (!$gate['can_book']): ?>
  <div class="p-card book-lock">
    <div class="lock-head">
      <div class="lock-icon"><?= icon($ui['lock'], 24) ?></div>
      <div>
        <h2>Complete My Dental Record first</h2>
        <p>Before scheduling an appointment, please fill in your dental record and tick the informed consent box.</p>
      </div>
    </div>
    <ul class="lock-list">
      <li><span>Informed consent</span>
        <span class="badge <?= $gate['consent'] === 'approved' ? 'b-confirmed' : 'b-pending' ?>">
          <?= $gate['consent'] === 'approved' ? 'Given' : 'Required' ?>
        </span></li>
    </ul>
    <a class="btn btn-primary btn-pill" href="patient-record.php"><?= icon($ui['file'], 18) ?> Open My Dental Record</a>
  </div>
<?php else: ?>
  <div class="book-grid">
    <form class="card wizard" id="bookForm" method="post" action="patient-book.php" novalidate>
      <ol class="steps three">
        <li data-step="1" class="active"><button type="button" class="step-tab" disabled><span class="num">1</span><span class="lbl">Service</span></button></li>
        <li data-step="2"><button type="button" class="step-tab" disabled><span class="num">2</span><span class="lbl">Date &amp; time</span></button></li>
        <li data-step="3"><button type="button" class="step-tab" disabled><span class="num">3</span><span class="lbl">Review</span></button></li>
      </ol>

      <div class="panel" data-panel="1">
        <h2>Where and what for?</h2>
        <div class="group">
          <span class="group-label">Clinic branch <span class="req">*</span></span>
          <?php if ($lockBranch): ?>
            <input type="hidden" name="branch" value="<?= e($lockBranch['code']) ?>">
            <div class="choice branch-fixed">
              <span class="choice-text">
                <strong><?= e($lockBranch['city']) ?></strong>
                <span class="choice-sub"><?= icon($ui['pin'], 15) ?> <?= e(explode(',', $lockBranch['address'])[0]) ?></span>
                <span class="choice-sub"><?= icon($ui['calendar'], 15) ?> <?= e(implode(', ', $lockBranch['days'])) ?></span>
              </span>
            </div>
            <span class="hint">Your preferred branch. You can change it in <a href="patient-profile.php">My Profile</a>.</span>
          <?php else: ?>
          <div class="choice-grid">
            <?php foreach ($dbBranches as $b): ?>
              <label class="choice">
                <input type="radio" name="branch" value="<?= e($b['code']) ?>"<?= $preBranch === $b['code'] ? ' checked' : '' ?>>
                <span class="choice-text">
                  <strong><?= e($b['city']) ?></strong>
                  <span class="choice-sub"><?= icon($ui['pin'], 15) ?> <?= e(explode(',', $b['address'])[0]) ?></span>
                  <span class="choice-sub"><?= icon($ui['calendar'], 15) ?> <?= e(implode(', ', $b['days'])) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <span class="err" data-err="branch"></span>
        </div>
        <div class="field">
          <label for="service">Dental service <span class="req">*</span></label>
          <select id="service" name="service">
            <option value="">Select a service</option>
            <?php foreach ($dbServices as $s): ?>
              <option value="<?= e($s['name']) ?>"<?= (($old['service'] ?? '') === $s['name']) ? ' selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">Not sure? Choose &ldquo;General Dentistry&rdquo;.</span>
          <span class="err" data-err="service"></span>
        </div>
        <div class="dentist-line">
          <span class="avatar-sm">MC</span>
          <div><strong>Dr. Marilou S. Cariño</strong><small>Your attending dentist at both clinics</small></div>
        </div>
        <div class="actions">
          <span></span>
          <button type="button" class="btn btn-primary btn-pill" data-next>Continue <?= icon($ui['arrow'], 16) ?></button>
        </div>
      </div>

      <div class="panel" data-panel="2" hidden>
        <h2>Choose a date and time</h2>
        <div class="slot-info">
          <span class="slot-info-ico"><?= icon($ui['check'], 18) ?></span>
          <div><strong>Only available schedules can be selected</strong><span>Clinic holidays and reserved times are already marked below.</span></div>
        </div>
        <div class="group">
          <div class="group-row"><span class="group-label">Preferred date <span class="req">*</span></span><span class="chip-note">Next 14 days</span></div>
          <div class="date-month" id="dateMonth"></div>
          <div class="date-grid" id="dateGrid"></div>
          <div class="date-legend"><span><i class="lg-ok"></i>Available</span><span><i class="lg-limited"></i>Limited</span><span><i class="lg-off"></i>Unavailable</span></div>
          <span class="err" data-err="date"></span>
        </div>
        <div class="field narrow">
          <label for="altDate">Or pick another date <span class="opt">(optional)</span></label>
          <input type="date" id="altDate">
          <span class="err" data-err="altdate"></span>
        </div>
        <div class="group">
          <div class="group-row"><span class="group-label">Preferred time <span class="req">*</span></span><span class="chip-note">Philippine time</span></div>
          <div class="time-note" id="timeNote" hidden></div>
          <div class="time-grid" id="timeGrid"></div>
          <span class="err" data-err="time"></span>
        </div>
        <input type="hidden" name="date" id="dateVal">
        <input type="hidden" name="time" id="timeVal">
        <div class="picked" id="pickedBox" hidden>
          <span class="picked-ico"><?= icon($ui['calendar'], 18) ?></span>
          <div><small>Your selected appointment</small><strong id="pickedText"></strong><span id="pickedClinic"></span></div>
        </div>
        <div class="group repeat-box">
          <span class="group-label">Repeat this appointment <span class="opt">(optional)</span></span>
          <div class="field narrow">
            <select id="repeat" name="repeat">
              <option value="">Does not repeat</option>
              <?php foreach (recur_frequencies() as $rk => $rl): ?>
                <option value="<?= e($rk) ?>"<?= (($old['repeat'] ?? '') === $rk) ? ' selected' : '' ?>><?= e($rl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field narrow" id="repeatCountWrap" style="display:none;">
            <label for="repeatCount">Total visits <span class="opt">(including this one)</span></label>
            <select id="repeatCount" name="repeat_count">
              <?php for ($n = 2; $n <= RECUR_MAX_VISITS; $n++): ?>
                <option value="<?= $n ?>"<?= ((int)($old['repeat_count'] ?? 6) === $n) ? ' selected' : '' ?>><?= $n ?> visits</option>
              <?php endfor; ?>
            </select>
          </div>
          <span class="err" data-err="repeat"></span>
        </div>
        <div class="field">
          <label for="comments">Notes for the clinic <span class="opt">(optional)</span></label>
          <textarea id="comments" name="comments" rows="3" maxlength="500" placeholder="Tell us anything the dentist should know before your visit."></textarea>
        </div>
        <div class="actions">
          <button type="button" class="btn-back" data-prev><?= icon($ui['back'], 16) ?> Back</button>
          <button type="button" class="btn btn-primary btn-pill" data-next>Continue <?= icon($ui['arrow'], 16) ?></button>
        </div>
      </div>

      <div class="panel" data-panel="3" hidden>
        <h2>Review your request</h2>
        <div class="alert bad" id="formError" role="alert" hidden></div>
        <div class="review-list" id="review"></div>
        <p class="fineprint">This is a request, not a confirmed booking. The clinic will confirm your schedule.</p>
        <div class="actions">
          <button type="button" class="btn-back" data-prev><?= icon($ui['back'], 16) ?> Back</button>
          <button type="submit" class="btn btn-primary btn-pill"><?= icon($ui['send'], 16) ?> Confirm booking</button>
        </div>
      </div>
    </form>

    <aside class="book-side">
      <div class="p-card who-card">
        <span class="avatar-sm"><?= e($patient['initials']) ?></span>
        <div><strong><?= e($patient['full_name']) ?></strong><br><span class="meta"><?= e($patient['phone']) ?></span></div>
      </div>
      <div class="p-card sched-card">
        <div class="sched-head"><span class="sched-ico"><?= icon($ui['calendar'], 18) ?></span><div><strong>Clinic schedule</strong><small>Dates that may affect bookings</small></div></div>
        <?php
          $shown = 0;
          foreach ($blocks as $bk) {
              if ($lockBranch && $bk['branch_id'] !== null && (int)$bk['branch_id'] !== (int)$lockBranch['id']) continue;
              if ($shown >= 5) break; $shown++;
              $hrs = $bk['all_day'] ? 0 : (int)round(($bk['end_min'] - $bk['start_min']) / 60);
              $isHol = stripos($bk['reason'], 'holiday') !== false || stripos($bk['reason'], ' day') !== false;
              $tag = $bk['all_day'] ? ($isHol ? 'Holiday' : 'All day') : ($hrs . ' hour' . ($hrs === 1 ? '' : 's'));
              $where = $bk['branch_id'] === null ? 'Both clinics' : $bk['branch_name'];
              $rng = $bk['date_from'] === $bk['date_to'] ? '' : ' to ' . date('M j', strtotime($bk['date_to']));
        ?>
          <div class="sched-row">
            <span class="sched-date"><small><?= e(strtoupper(date('M', strtotime($bk['date_from'])))) ?></small><b><?= e(date('j', strtotime($bk['date_from']))) ?></b></span>
            <div><strong><?= e($bk['reason']) ?></strong><small><?= e(($bk['all_day'] ? 'All day' : blocktime_time_text($bk)) . $rng . ' · ' . $where) ?></small></div>
            <span class="sched-tag"><?= e($tag) ?></span>
          </div>
        <?php } if ($shown === 0): ?>
          <p class="sched-none">No clinic closures coming up.</p>
        <?php endif; ?>
        <p class="sched-help"><?= icon($ui['phone'], 14) ?> Need a blocked date? Call the clinic and we&rsquo;ll help find another schedule.</p>
      </div>
      <div class="call-card">
        <h3>Need it sooner?</h3>
        <p>Call the clinic directly for urgent concerns.</p>
        <a class="call-phone" href="tel:<?= e($clinic['phone_tel']) ?>"><?= icon($ui['phone'], 18) ?> <?= e($clinic['phone']) ?></a>
      </div>
    </aside>
  </div>

  <script>
  window.MSC = <?= json_encode([
      'branches' => $jsBranches,
      'slots'    => $slots,
      'taken'    => (object)$taken,
      'blocks'   => blocktime_for_js($blocks, $codeById),
      'old'      => $old,
      'error'    => $error,
      'member'   => ['name' => $patient['full_name'], 'phone' => $patient['phone'], 'email' => $patient['email']],
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="assets/book.js?v=<?= filemtime(__DIR__ . '/assets/book.js') ?>"></script>
<?php endif; ?>

<?php if (!$gate['can_book'] && $error): ?><div class="alert bad"><?= e($error) ?></div><?php endif; ?>
<?php include __DIR__ . '/includes/portal_foot.php'; ?>
