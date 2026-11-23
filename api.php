<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/reminders.php';
require_once __DIR__ . '/includes/appt_mail.php';
require_once __DIR__ . '/includes/revenue.php';
require_once __DIR__ . '/includes/recurrence.php';
require_once __DIR__ . '/includes/blocked_times.php';

header('Content-Type: application/json; charset=utf-8');

function respond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function notify_enabled($pdo, $branch_id, $key) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE branch_id = :b AND setting_key = :k");
        $st->execute([':b' => $branch_id, ':k' => $key]);
        $v = $st->fetchColumn();
        return $v === false ? true : $v === '1';
    } catch (PDOException $ex) {
        return true;
    }
}

function notify_patient($pdo, $appt_id, $branch_id, $title, $message) {
    try {
        $st = $pdo->prepare("SELECT patient_id FROM appointments WHERE id = :id AND branch_id = :b");
        $st->execute([':id' => $appt_id, ':b' => $branch_id]);
        $pid = $st->fetchColumn();
        if (!$pid) return false;

        $upd = $pdo->prepare("UPDATE notifications SET title = :t, message = :m
                               WHERE id = (SELECT id FROM notifications
                                            WHERE patient_id = :p AND appointment_id = :a AND lower(title) = lower(:t2)
                                              AND created_at > now() - interval '1 minute'
                                            ORDER BY id DESC LIMIT 1)");
        $upd->execute([':t' => $title, ':m' => $message, ':p' => $pid, ':a' => $appt_id, ':t2' => $title]);
        if ($upd->rowCount() === 0) {
            $ins = $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message) VALUES (:p, :a, :t, :m)");
            $ins->execute([':p' => $pid, ':a' => $appt_id, ':t' => $title, ':m' => $message]);
        }
        return true;
    } catch (PDOException $ex) {
        error_log('MSCare notify_patient: ' . $ex->getMessage());
        return false;
    }
}

function appt_summary($pdo, $appt_id) {
    $st = $pdo->prepare("SELECT s.name, a.appointment_date, a.appointment_time FROM appointments a JOIN services s ON s.id = a.service_id WHERE a.id = :id");
    $st->execute([':id' => $appt_id]);
    $r = $st->fetch();
    if (!$r) return 'your appointment';
    return $r['name'] . ' on ' . date('M j, Y', strtotime($r['appointment_date'])) . ' at ' . date('g:i A', strtotime($r['appointment_time']));
}

if (!current_admin()) {
    respond(['ok' => false, 'error' => 'Your admin session has expired. Please log in again.'], 401);
}

$admin = current_admin();
$admin_id = admin_id();
$branch_id = admin_clinic_id();
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
$action = (string)($data['action'] ?? $_GET['action'] ?? '');

$api_perm = [
    'confirm_appointment' => 'appointments', 'complete_appointment' => 'appointments',
    'cancel_appointment' => 'appointments', 'reschedule_appointment' => 'appointments',
    'create_appointment' => 'appointments', 'send_reminder' => 'appointments',
    'approve_consent' => 'patients', 'reject_consent' => 'patients', 'reset_sig_tries' => 'patients',
    'log_treatment' => 'treatments', 'save_tooth' => 'treatments',
    'mark_notif_read' => 'notifications',
    'preview_block' => 'blocktime', 'save_block' => 'blocktime', 'delete_block' => 'blocktime',
];
if ($action !== '' && isset($api_perm[$action])) {
    require_permission($api_perm[$action], true);
} elseif ($action !== '') {
    require_permission('team.manage', true);
}

try {
    $pdo = db();

    switch ($action) {
        case 'confirm_appointment':
        case 'complete_appointment':
        case 'cancel_appointment':
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) respond(['ok' => false, 'error' => 'Invalid appointment.']);

            $status = [
                'confirm_appointment'  => 'Confirmed',
                'complete_appointment' => 'Completed',
                'cancel_appointment'   => 'Cancelled',
            ][$action];

            $sql = "UPDATE appointments SET status = :status, reviewed_by_admin_id = :adm, updated_at = now()";
            $params = [':status' => $status, ':adm' => $admin_id, ':id' => $id, ':bid' => $branch_id];
            if ($action === 'cancel_appointment') {
                $sql .= ", admin_notes = :note";
                $cancel_reason = trim((string)($data['reason'] ?? ''));
                $cancel_details = trim((string)($data['details'] ?? ''));
                if ($cancel_reason === '') respond(['ok' => false, 'error' => 'Please choose a reason for cancellation.']);
                $cancel_reason = function_exists('mb_substr') ? mb_substr($cancel_reason, 0, 80) : substr($cancel_reason, 0, 80);
                $cancel_details = function_exists('mb_substr') ? mb_substr($cancel_details, 0, 200) : substr($cancel_details, 0, 200);

                $params[':note'] = 'Cancelled by clinic: ' . $cancel_reason . ($cancel_details !== '' ? ' - ' . $cancel_details : '');
            }
            $sql .= " WHERE id = :id AND branch_id = :bid";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                respond(['ok' => false, 'error' => 'That appointment was not found for your clinic.']);
            }

            if ($action === 'cancel_appointment' && !empty($data['series'])) {
                try {
                    $cs = $pdo->prepare("SELECT series_id, appointment_date::text AS d, appointment_time::text AS t FROM appointments WHERE id = :id");
                    $cs->execute([':id' => $id]);
                    $cur = $cs->fetch();
                    if ($cur && $cur['series_id']) {
                        $pdo->prepare("UPDATE appointments SET status = 'Cancelled', reviewed_by_admin_id = :adm, admin_notes = :note, updated_at = now()
                                        WHERE series_id = :s AND branch_id = :bid AND id <> :id AND status IN ('Pending', 'Confirmed')
                                          AND (appointment_date > CAST(:d AS date) OR (appointment_date = CAST(:d2 AS date) AND appointment_time > CAST(:t AS time)))")
                            ->execute([':adm' => $admin_id, ':note' => $params[':note'], ':s' => $cur['series_id'], ':bid' => $branch_id, ':id' => $id, ':d' => $cur['d'], ':d2' => $cur['d'], ':t' => $cur['t']]);
                    }
                } catch (PDOException $sx) { error_log('MSCare cancel series (admin): ' . $sx->getMessage()); }
            }
            if ($action !== 'complete_appointment' && notify_enabled($pdo, $branch_id, 'n_patient_confirm')) {
                $what = appt_summary($pdo, $id);
                if ($action === 'confirm_appointment') {
                    notify_patient($pdo, $id, $branch_id, 'Appointment confirmed', 'Your ' . $what . ' is confirmed. See you at the clinic!');
                } else {
                    notify_patient($pdo, $id, $branch_id, 'Appointment cancelled', 'Your ' . $what . ' was cancelled by the clinic (' . $cancel_reason . '). Please book another time.');
                }
            }
            if ($action === 'confirm_appointment') appt_email($pdo, $id, 'confirmed');
            elseif ($action === 'cancel_appointment') appt_email($pdo, $id, 'cancelled');
            respond(['ok' => true]);

        case 'reschedule_appointment':
            $id = (int)($data['id'] ?? 0);
            $date = trim((string)($data['date'] ?? ''));
            $time = trim((string)($data['time'] ?? ''));
            $resched_reason = trim((string)($data['reason'] ?? ''));
            $resched_reason = function_exists('mb_substr') ? mb_substr($resched_reason, 0, 200) : substr($resched_reason, 0, 200);
            if ($id <= 0 || $date === '' || $time === '') {
                respond(['ok' => false, 'error' => 'Please provide both date and time.']);
            }
            $dt = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) respond(['ok' => false, 'error' => 'Please choose a valid date.']);
            if ($date < date('Y-m-d')) respond(['ok' => false, 'error' => 'You cannot reschedule to a past date.']);
            $ts = strtotime($time);
            if ($ts === false) respond(['ok' => false, 'error' => 'Please choose a valid time.']);
            $time_sql = date('H:i', $ts);
            if ($date === date('Y-m-d') && $time_sql <= date('H:i')) {
                respond(['ok' => false, 'error' => 'That time has already passed today. Please choose a later time.']);
            }

            $cur = $pdo->prepare("SELECT status, appointment_date, appointment_time FROM appointments WHERE id = :id AND branch_id = :bid");
            $cur->execute([':id' => $id, ':bid' => $branch_id]);
            $old = $cur->fetch();
            if (!$old) respond(['ok' => false, 'error' => 'Appointment not found.']);
            if (!in_array($old['status'], ['Pending', 'Confirmed'], true)) {
                respond(['ok' => false, 'error' => 'Only pending or confirmed appointments can be rescheduled.']);
            }
            $od = $pdo->prepare("SELECT open_days FROM branches WHERE id = :b");
            $od->execute([':b' => $branch_id]);
            $od_raw = $od->fetchColumn();
            $od_days = is_array($od_raw) ? $od_raw : (trim((string)$od_raw, '{}') === '' ? [] : array_map('trim', explode(',', trim((string)$od_raw, '{}'))));
            if ($od_days && !in_array($dt->format('D'), $od_days, true)) {
                respond(['ok' => false, 'error' => 'This clinic is only open on ' . implode(', ', $od_days) . '. Please pick one of those days.']);
            }
            if ($old['appointment_date'] === $date && date('H:i', strtotime($old['appointment_time'])) === $time_sql) {
                respond(['ok' => false, 'error' => 'That is the same schedule. Please pick a different date or time.']);
            }
            blocktime_ensure_schema($pdo);
            if ($hit = blocktime_match(blocktime_fetch($pdo, $branch_id, $date), $branch_id, $date, $time_sql)) {
                respond(['ok' => false, 'error' => blocktime_error($hit)]);
            }
            $old_text = date('M j, Y', strtotime($old['appointment_date'])) . ' ' . date('g:i A', strtotime($old['appointment_time']));
            $new_text = date('M j, Y', strtotime($date)) . ' ' . date('g:i A', $ts);
            $note = 'Rescheduled by clinic from ' . $old_text . ' to ' . $new_text . ($resched_reason !== '' ? ': ' . $resched_reason : '');

            try {
                $stmt = $pdo->prepare("UPDATE appointments
                                          SET appointment_date = :d, appointment_time = :t, reviewed_by_admin_id = :adm,
                                              admin_notes = :note, updated_at = now()
                                        WHERE id = :id AND branch_id = :bid");
                $stmt->execute([':d' => $date, ':t' => $time_sql, ':adm' => $admin_id, ':note' => $note, ':id' => $id, ':bid' => $branch_id]);
            } catch (PDOException $ex) {
                if (is_slot_conflict($ex)) respond(['ok' => false, 'error' => 'That time slot is already booked. Please choose another time.']);
                throw $ex;
            }
            if ($stmt->rowCount() === 0) respond(['ok' => false, 'error' => 'Appointment not found.']);
            if (notify_enabled($pdo, $branch_id, 'n_patient_confirm')) {
                notify_patient($pdo, $id, $branch_id, 'Appointment rescheduled',
                    'Your appointment was moved: ' . appt_summary($pdo, $id) . '.' . ($resched_reason !== '' ? ' Reason: ' . $resched_reason . '.' : ''));
            }
            appt_email($pdo, $id, 'rescheduled');
            respond(['ok' => true]);

        case 'create_appointment':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $service = trim((string)($data['service'] ?? ''));
            $date = trim((string)($data['date'] ?? ''));
            $time = trim((string)($data['time'] ?? ''));

            if ($patient_id <= 0 || $date === '' || $time === '') {
                respond(['ok' => false, 'error' => 'Patient, date and time are required.']);
            }

            $p_stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :pid AND status = 'Active'");
            $p_stmt->execute([':pid' => $patient_id]);
            $p = $p_stmt->fetch();
            if (!$p) respond(['ok' => false, 'error' => 'Patient not found.']);

            $s_stmt = $pdo->prepare("SELECT id FROM services WHERE name = :s AND is_active = TRUE AND name NOT ILIKE '%brace%'");
            $s_stmt->execute([':s' => $service]);
            $service_id = $s_stmt->fetchColumn();
            if (!$service_id) respond(['ok' => false, 'error' => 'Please choose a valid service.']);

            $ref = new_appt_ref($pdo, $date, $time);
            $time_sql = date('H:i', strtotime($time));
            blocktime_ensure_schema($pdo);
            if ($hit = blocktime_match(blocktime_fetch($pdo, $branch_id, $date), $branch_id, $date, $time_sql)) {
                respond(['ok' => false, 'error' => blocktime_error($hit)]);
            }
            $dentist_id = $pdo->query("SELECT id FROM dentists WHERE is_active = TRUE ORDER BY id LIMIT 1")->fetchColumn();

            $ins = $pdo->prepare("
                INSERT INTO appointments
                    (ref, patient_id, branch_id, service_id, dentist_id, appointment_date, appointment_time,
                     first_name, last_name, phone, email, is_current_patient, status, reviewed_by_admin_id)
                VALUES
                    (:ref, :pid, :bid, :sid, :did, :d, :t, :fn, :ln, :ph, :em, TRUE, 'Confirmed', :adm)
            ");
            $ins->execute([
                ':ref' => $ref, ':pid' => $p['id'], ':bid' => $branch_id,
                ':sid' => (int)$service_id, ':did' => $dentist_id ?: null,
                ':d' => $date, ':t' => $time_sql,
                ':fn' => $p['first_name'], ':ln' => $p['last_name'],
                ':ph' => $p['phone'], ':em' => $p['email'], ':adm' => $admin_id,
            ]);
            $new_id = (int)$pdo->query("SELECT id FROM appointments WHERE ref = " . $pdo->quote($ref))->fetchColumn();
            if ($new_id) appt_email($pdo, $new_id, 'confirmed');

            $more = 0; $skipped = 0;
            [$rf, $rn] = recur_parse($data['repeat'] ?? '', $data['repeat_count'] ?? 0);
            if ($rf && $new_id) {
                try {
                    recurrence_ensure_schema($pdo);
                    $rs = recur_extend($pdo, $new_id, $rf, $rn, 'admin');
                    $more = $rs['created']; $skipped = count($rs['skipped']);
                } catch (Throwable $rx) { error_log('MSCare recurring (admin): ' . $rx->getMessage()); }
            }
            respond(['ok' => true, 'ref' => $ref, 'more' => $more, 'skipped' => $skipped]);

        case 'approve_consent':
        case 'reject_consent':
            $patient_id = (int)($data['patient_id'] ?? 0);
            if ($patient_id <= 0) respond(['ok' => false, 'error' => 'Invalid patient.']);

            $status = $action === 'approve_consent' ? 'approved' : 'rejected';
            $note = $action === 'reject_consent'
                ? trim((string)($data['note'] ?? 'Signature not approved. Please upload again.'))
                : null;

            $stmt = $pdo->prepare("UPDATE patient_consents
                                      SET status = :st, rejection_note = :note,
                                          reviewed_by_admin_id = :adm, reviewed_at = now()
                                    WHERE patient_id = :pid
                                      AND patient_id IN (SELECT p.id FROM patients p WHERE (p.branch_id IS NULL OR p.branch_id = :b OR EXISTS (SELECT 1 FROM appointments ap WHERE ap.patient_id = p.id AND ap.branch_id = :b)))");
            $stmt->execute([':st' => $status, ':note' => $note, ':adm' => $admin_id, ':pid' => $patient_id, ':b' => $branch_id]);
            if ($stmt->rowCount() === 0) respond(['ok' => false, 'error' => 'No consent form from a patient of this branch was found.']);

            try {
                $ntitle = $status === 'approved' ? 'Signature Approved' : 'Signature Rejected';
                $nmsg = $status === 'approved'
                    ? 'Great news! Your informed consent signature has been approved. You can now book an appointment.'
                    : 'Your informed consent signature was not approved' . ($note ? ': ' . $note : '') . '. Please upload a new signature.';
                $dup = $pdo->prepare("SELECT 1 FROM notifications WHERE patient_id = :pid AND title = :t AND created_at > now() - interval '15 seconds' LIMIT 1");
                $dup->execute([':pid' => $patient_id, ':t' => $ntitle]);
                if (!$dup->fetchColumn()) {
                    $pdo->prepare("INSERT INTO notifications (patient_id, title, message) VALUES (:pid, :t, :m)")
                        ->execute([':pid' => $patient_id, ':t' => $ntitle, ':m' => $nmsg]);
                }
            } catch (Throwable $ex) { error_log('MSCare consent notify: ' . $ex->getMessage()); }
            respond(['ok' => true]);

        case 'reset_sig_tries':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE patient_consents SET sig_tries_used = 0 WHERE patient_id = :pid
                                      AND patient_id IN (SELECT p.id FROM patients p WHERE (p.branch_id IS NULL OR p.branch_id = :b OR EXISTS (SELECT 1 FROM appointments ap WHERE ap.patient_id = p.id AND ap.branch_id = :b)))");
            $stmt->execute([':pid' => $patient_id, ':b' => $branch_id]);
            respond(['ok' => true]);

        case 'log_treatment':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $date = trim((string)($data['date'] ?? date('Y-m-d')));
            $proc = trim((string)($data['procedure'] ?? ''));
            $tooth = trim((string)($data['tooth'] ?? '—'));
            $cost = (float)($data['cost'] ?? 0);
            $notes = trim((string)($data['notes'] ?? ''));
            $dentist = trim((string)($data['dentist'] ?? 'Dr. Marilou S. Cariño'));
            $method = rev_method($data['payment_method'] ?? 'Cash');
            $paid = isset($data['amount_paid']) && $data['amount_paid'] !== '' ? max(0.0, (float)$data['amount_paid']) : $cost;

            if ($patient_id <= 0 || $proc === '') {
                respond(['ok' => false, 'error' => 'Patient and Procedure are required.']);
            }

            rev_ensure_columns($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO treatments
                    (patient_id, branch_id, treatment_date, procedure_name, tooth, dentist_name,
                     cost, payment_method, amount_paid, notes, created_by_admin_id)
                VALUES (:pid, :bid, :d, :pr, :th, :dt, :c, :pm, :ap, :nt, :adm)
            ");
            $stmt->execute([
                ':pid' => $patient_id, ':bid' => $branch_id, ':d' => $date,
                ':pr' => $proc, ':th' => $tooth, ':dt' => $dentist,
                ':c' => $cost, ':pm' => $method, ':ap' => $paid, ':nt' => $notes, ':adm' => $admin_id,
            ]);
            respond(['ok' => true]);

        case 'save_tooth':
            $patient_id = (int)($data['patient_id'] ?? 0);
            $tooth_num = (int)($data['tooth_number'] ?? 0);
            $code = trim((string)($data['condition_code'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));

            if ($patient_id <= 0 || $tooth_num < 11 || $tooth_num > 85) {
                respond(['ok' => false, 'error' => 'Invalid patient or tooth number.']);
            }

            if ($code === '' || strtolower($code) === 'none') {
                $stmt = $pdo->prepare("DELETE FROM dental_charts WHERE patient_id = :pid AND tooth_number = :tn");
                $stmt->execute([':pid' => $patient_id, ':tn' => $tooth_num]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO dental_charts (patient_id, tooth_number, condition_code, notes, updated_by_admin_id)
                    VALUES (:pid, :tn, :c, :nt, :adm)
                    ON CONFLICT (patient_id, tooth_number) DO UPDATE SET
                        condition_code = EXCLUDED.condition_code,
                        notes = EXCLUDED.notes,
                        updated_by_admin_id = EXCLUDED.updated_by_admin_id
                ");
                $stmt->execute([':pid' => $patient_id, ':tn' => $tooth_num, ':c' => $code, ':nt' => $notes, ':adm' => $admin_id]);
            }
            respond(['ok' => true]);

        case 'mark_notif_read':

            admin_bell_ensure_table($pdo);
            if (!empty($data['all'])) {
                $pdo->prepare("INSERT INTO admin_notif_reads (admin_id, notif_key)
                               SELECT :a, 'p' || id FROM appointments WHERE branch_id = :b AND status = 'Pending'
                               ON CONFLICT DO NOTHING")->execute([':a' => $admin_id, ':b' => $branch_id]);
                $pdo->prepare("INSERT INTO admin_notif_reads (admin_id, notif_key)
                               SELECT :a, 'c' || id FROM appointments
                                WHERE branch_id = :b AND status = 'Cancelled' AND admin_notes LIKE 'Cancelled by patient%'
                               ON CONFLICT DO NOTHING")->execute([':a' => $admin_id, ':b' => $branch_id]);
                respond(['ok' => true]);
            }
            $key = (string)($data['key'] ?? '');
            if (preg_match('/^s(\d{1,18})t(\d{1,12})$/', $key, $sm)) {
                $own = $pdo->prepare("SELECT 1 FROM patient_consents pc JOIN patients p ON p.id = pc.patient_id WHERE pc.patient_id = :id AND (p.branch_id IS NULL OR p.branch_id = :b OR EXISTS (SELECT 1 FROM appointments ap WHERE ap.patient_id = p.id AND ap.branch_id = :b))");
                $own->execute([':id' => (int)$sm[1], ':b' => $branch_id]);
                if (!$own->fetchColumn()) respond(['ok' => false, 'error' => 'Notification not found.']);
                $pdo->prepare("INSERT INTO admin_notif_reads (admin_id, notif_key) VALUES (:a, :k) ON CONFLICT DO NOTHING")
                    ->execute([':a' => $admin_id, ':k' => $key]);
                respond(['ok' => true]);
            }
            if (!preg_match('/^[pc]\d{1,18}$/', $key)) respond(['ok' => false, 'error' => 'Invalid notification.']);
            $own = $pdo->prepare("SELECT 1 FROM appointments WHERE id = :id AND branch_id = :b");
            $own->execute([':id' => (int)substr($key, 1), ':b' => $branch_id]);
            if (!$own->fetchColumn()) respond(['ok' => false, 'error' => 'Notification not found.']);
            $pdo->prepare("INSERT INTO admin_notif_reads (admin_id, notif_key) VALUES (:a, :k) ON CONFLICT DO NOTHING")
                ->execute([':a' => $admin_id, ':k' => $key]);
            respond(['ok' => true]);

        case 'send_reminder':

            $appt_id = (int)($data['appointment_id'] ?? $data['id'] ?? 0);
            if ($appt_id <= 0) respond(['ok' => false, 'error' => 'Invalid appointment.']);

            $chk = $pdo->prepare("SELECT patient_id, status, email FROM appointments WHERE id = :id AND branch_id = :b");
            $chk->execute([':id' => $appt_id, ':b' => $branch_id]);
            $row = $chk->fetch();
            if (!$row) respond(['ok' => false, 'error' => 'That appointment was not found for your clinic.']);
            if (!in_array($row['status'], ['Pending', 'Confirmed'], true)) respond(['ok' => false, 'error' => 'Reminders can only be sent for pending or confirmed appointments.']);

            $bell = false; $mail = false; $mail_err = '';
            if ($row['patient_id']) {
                $bell = notify_patient($pdo, $appt_id, $branch_id, 'Appointment reminder',
                    'Reminder: ' . appt_summary($pdo, $appt_id) . '. Please arrive 10 minutes early.');
            }
            [$mail, $mail_err] = send_reminder_email($pdo, $appt_id);

            if (!$bell && !$mail) {
                respond(['ok' => false, 'error' => $mail_err !== '' ? 'Could not send the reminder: ' . $mail_err : 'Could not send the reminder.'], 500);
            }
            $where = $bell && $mail ? 'portal and email' : ($mail ? 'email' : 'portal');
            $msg = 'Reminder sent to the patient (' . $where . ').';
            if (!$mail && $mail_err !== '') $msg .= ' Email not sent: ' . $mail_err;
            respond(['ok' => true, 'bell' => $bell, 'email' => $mail, 'message' => $msg]);

        case 'preview_block':
        case 'save_block':
            blocktime_ensure_schema($pdo);
            $mode  = (($data['mode'] ?? 'whole') === 'range') ? 'range' : 'whole';
            $from  = trim((string)($data['from'] ?? ''));
            $to    = trim((string)($data['to'] ?? '')) ?: $from;
            $df = DateTime::createFromFormat('Y-m-d', $from);
            $dt = DateTime::createFromFormat('Y-m-d', $to);
            if (!$df || $df->format('Y-m-d') !== $from || !$dt || $dt->format('Y-m-d') !== $to) {
                respond(['ok' => false, 'error' => 'Please choose a valid date.']);
            }
            if ($to < $from) respond(['ok' => false, 'error' => 'The end date cannot be before the start date.']);
            if ($to < date('Y-m-d')) respond(['ok' => false, 'error' => 'You cannot block a date that has already passed.']);
            $allDay = ($mode === 'whole'); $sMin = null; $eMin = null;
            if (!$allDay) {
                $sMin = blocktime_minutes($data['start'] ?? '');
                $eMin = blocktime_minutes($data['end'] ?? '');
                if ($sMin === null || $eMin === null) respond(['ok' => false, 'error' => 'Please choose a start and end time.']);
                if ($eMin <= $sMin) respond(['ok' => false, 'error' => 'The end time must be after the start time.']);
            }
            $bothClinics = (($data['scope'] ?? 'this') === 'both');
            $scopeIds = $bothClinics ? null : [$branch_id];

            $aff = blocktime_affected($pdo, $scopeIds, $from, $to, $allDay, $sMin, $eMin);
            if ($action === 'preview_block') {
                $list = [];
                foreach ($aff as $a) {
                    $list[] = ['id' => (int)$a['id'], 'name' => $a['patient_name'], 'initials' => $a['initials'], 'status' => $a['status'],
                               'when' => date('D, M j', strtotime($a['appointment_date'])) . ' · ' . $a['time_formatted'], 'service' => $a['service_name']];
                }
                respond(['ok' => true, 'count' => count($list), 'items' => $list]);
            }

            $reason = trim((string)($data['reason'] ?? ''));
            $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 120) : substr($reason, 0, 120);
            if ($reason === '') respond(['ok' => false, 'error' => 'Please add a short reason patients will see.']);
            $notify = !empty($data['notify']);

            $ins = $pdo->prepare("INSERT INTO blocked_times (branch_id, date_from, date_to, all_day, start_time, end_time, reason, notify_patients, created_by_admin_id)
                                  VALUES (:b, :f, :t, :ad, :s, :e, :r, :n, :adm) RETURNING id");
            $ins->bindValue(':b', $bothClinics ? null : $branch_id, $bothClinics ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $ins->bindValue(':f', $from); $ins->bindValue(':t', $to);
            $ins->bindValue(':ad', $allDay ? 'true' : 'false');
            $ins->bindValue(':s', $allDay ? null : sprintf('%02d:%02d', intdiv($sMin, 60), $sMin % 60));
            $ins->bindValue(':e', $allDay ? null : sprintf('%02d:%02d', intdiv($eMin, 60), $eMin % 60));
            $ins->bindValue(':r', $reason);
            $ins->bindValue(':n', $notify ? 'true' : 'false');
            $ins->bindValue(':adm', $admin_id, PDO::PARAM_INT);
            $ins->execute();
            $block_id = (int)$ins->fetchColumn();

            $notified = 0;
            if ($notify && $aff) {
                $nIns = $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message) VALUES (:p, :a, :t, :m)");
                foreach ($aff as $a) {
                    try {
                        if ($a['patient_id']) {
                            $nIns->execute([':p' => $a['patient_id'], ':a' => $a['id'], ':t' => 'Clinic schedule update',
                                ':m' => 'Your ' . $a['service_name'] . ' on ' . date('M j, Y', strtotime($a['appointment_date'])) . ' at ' . $a['time_formatted']
                                      . ' falls within a clinic closure (' . $reason . '). Please reschedule to another time.']);
                        }
                        appt_email($pdo, (int)$a['id'], 'blocked');
                        $notified++;
                    } catch (Throwable $nx) { error_log('MSCare block notify: ' . $nx->getMessage()); }
                }
            }
            audit_log('block_time', ($allDay ? 'All day' : blocktime_label($sMin) . '-' . blocktime_label($eMin)) . ' ' . $from . ($to !== $from ? ' to ' . $to : '') . ' (' . $reason . ')');
            respond(['ok' => true, 'id' => $block_id, 'affected' => count($aff), 'notified' => $notified]);

        case 'delete_block':
            blocktime_ensure_schema($pdo);
            $bid = (int)($data['id'] ?? 0);
            if ($bid <= 0) respond(['ok' => false, 'error' => 'Invalid blocked time.']);
            $del = $pdo->prepare("DELETE FROM blocked_times WHERE id = :id AND (branch_id = :b OR branch_id IS NULL)");
            $del->execute([':id' => $bid, ':b' => $branch_id]);
            if ($del->rowCount() === 0) respond(['ok' => false, 'error' => 'That blocked time was not found for your clinic.']);
            audit_log('unblock_time', 'Removed blocked time #' . $bid);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (PDOException $ex) {
    if (is_slot_conflict($ex)) {
        respond(['ok' => false, 'error' => 'That time slot is already booked. Please choose another time.']);
    }
    respond(['ok' => false, 'error' => 'Database error: ' . $ex->getMessage()], 500);
}
