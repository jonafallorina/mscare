<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bell.php';
require_once __DIR__ . '/includes/db.php';

require_admin();
require_permission('patients');
$admin = current_admin();
$branch_id = admin_clinic_id();
$cinfo = admin_clinic_info($branch_id);

$patients = [];
$selected_patient = null;
$prec = null;
$pmed = null;
$pcon = null;
$db_error = null;

try {
    $pdo = db();

    $p_stmt = $pdo->prepare("
        SELECT p.id, concat_ws(' ', p.first_name, NULLIF(p.middle_name, ''), p.last_name) AS full_name,
               p.email, p.phone, p.age, p.gender,
               COALESCE(pc.status, 'none') AS consent_status
        FROM patients p
        LEFT JOIN patient_consents pc ON pc.patient_id = p.id
        WHERE p.status = 'Active' AND (p.branch_id IS NULL OR p.branch_id = :b OR EXISTS (SELECT 1 FROM appointments ap WHERE ap.patient_id = p.id AND ap.branch_id = :b))
        ORDER BY full_name ASC
    ");
    $p_stmt->execute([':b' => admin_clinic_id()]);
    $patients = $p_stmt->fetchAll();

    $sel_id = (int)($_GET['patient'] ?? ($patients[0]['id'] ?? 0));

    if ($sel_id > 0) {
        $p_query = $pdo->prepare("SELECT p.* FROM patients p WHERE p.id = :id AND (p.branch_id IS NULL OR p.branch_id = :b OR EXISTS (SELECT 1 FROM appointments ap WHERE ap.patient_id = p.id AND ap.branch_id = :b))");
        $p_query->execute([':id' => $sel_id, ':b' => admin_clinic_id()]);
        $selected_patient = $p_query->fetch();

        $r_query = $pdo->prepare("SELECT * FROM patient_records WHERE patient_id = :id");
        $r_query->execute([':id' => $sel_id]);
        $prec = $r_query->fetch() ?: [];

        $c_query = $pdo->prepare("SELECT pc.*, adm.display_name AS reviewer_name FROM patient_consents pc LEFT JOIN admins adm ON adm.id = pc.reviewed_by_admin_id WHERE pc.patient_id = :id");
        $c_query->execute([':id' => $sel_id]);
        $pcon = $c_query->fetch() ?: [];
    }

} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

$c_given = (($pcon['status'] ?? 'none') === 'approved');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Dental Record | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<style>
.ar-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
.ar-item { background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; }
.ar-k { font-size: 11.5px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
.ar-v { font-size: 13.5px; font-weight: 600; color: var(--navy); margin-top: 3px; }
.ar-v.empty { color: #94a3b8; font-weight: normal; font-style: italic; }
.sig-img-wrap { max-width: 420px; border: 1.5px solid var(--line); border-radius: 10px; padding: 12px; background: #fff; margin: 12px 0; }
.sig-img-wrap img { max-width: 100%; height: auto; display: block; }
</style>
<script src="dialog.js?v=20261027"></script>
</head>
<body data-guard="admin"<?= isset($_GET['embed']) ? ' class="embed"' : '' ?>>
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
      <div><h2>Patient Dental Record</h2></div>
      <div style="display:flex;align-items:center;gap:14px;position:relative;">
          <span class="badge b-confirmed"><?= htmlspecialchars($cinfo['label']) ?></span>
          <?= admin_bell_html() ?>
          <div class="avatar"><?= htmlspecialchars($admin['initials']) ?></div>
      </div>
    </div>
    <div class="content">
      <div id="arRoot">
        <div class="card pick-card" style="margin-bottom:18px;">
          <div class="toolbar">
            <div class="search-box">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
              <input id="arPatientSearch" placeholder="Search patients by name or email" oninput="filterArPatients(this.value)">
            </div>
            <span style="font-size:12.5px;color:var(--muted);"><?= count($patients) ?> registered patients</span>
          </div>
          <table id="arPatientsTable" style="display:none;">
            <thead><tr><th>Patient</th><th>Contact</th><th>Age</th><th>Consent</th><th>Record</th></tr></thead>
            <tbody>
              <?php if (empty($patients)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:40px;">No registered patients found.</td></tr>
              <?php else: foreach ($patients as $pt): ?>
                <tr class="row-hover ar-p-row" data-search="<?= strtolower(htmlspecialchars($pt['full_name'] . ' ' . $pt['email'])) ?>">
                  <td>
                    <div class="row-people">
                      <div class="avatar-sm"><?= htmlspecialchars(strtoupper(substr($pt['full_name'],0,2))) ?></div>
                      <div>
                        <div class="name"><?= htmlspecialchars($pt['full_name']) ?></div>
                        <div class="sub"><?= htmlspecialchars($pt['gender']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($pt['email']) ?><br><span style="color:var(--muted);font-size:11.5px;"><?= htmlspecialchars($pt['phone']) ?></span></td>
                  <td><?= (int)$pt['age'] ?></td>
                  <td><span class="badge <?= $pt['consent_status'] === 'approved' ? 'b-confirmed' : 'b-pending' ?>"><?= $pt['consent_status'] === 'approved' ? 'Given' : 'Not yet' ?></span></td>
                  <td><a class="icon-btn" href="patient-record.php?patient=<?= (int)$pt['id'] ?>" title="View Dental Record"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></a></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($selected_patient): ?>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
          <span class="badge <?= $c_given ? 'b-confirmed' : 'b-pending' ?>" style="padding:6px 12px;font-size:12px;">Informed consent: <?= $c_given ? 'Given' : 'Not yet' ?></span>
        </div>
        <?php endif; ?>
        <?php if (!$selected_patient): ?>
          <div class="card"><p style="text-align:center;color:var(--muted);padding:40px;">No patient selected or registered yet.</p></div>
        <?php else: ?>
          <div class="card ar-card" id="arCard" style="margin-top:16px;">

            <div id="panel-info" class="ar-panel" style="padding:16px 0;">
              <h4 style="margin-bottom:14px;color:var(--navy);">Patient Information</h4>
              <div class="ar-grid">
                <div class="ar-item"><div class="ar-k">Full Name</div><div class="ar-v"><?= e(trim($selected_patient['first_name'] . ' ' . $selected_patient['middle_name'] . ' ' . $selected_patient['last_name'])) ?></div></div>
                <div class="ar-item"><div class="ar-k">Email Address</div><div class="ar-v"><?= htmlspecialchars($selected_patient['email']) ?></div></div>
                <div class="ar-item"><div class="ar-k">Mobile Number</div><div class="ar-v"><?= htmlspecialchars($selected_patient['phone']) ?></div></div>
                <div class="ar-item"><div class="ar-k">Age / Sex</div><div class="ar-v"><?= htmlspecialchars($selected_patient['age'] . ' yrs / ' . ($prec['sex'] ?? $selected_patient['gender'])) ?></div></div>
                <div class="ar-item"><div class="ar-k">Birthdate</div><div class="ar-v <?= empty($prec['birthdate']) ? 'empty' : '' ?>"><?= $prec['birthdate'] ?? 'Not provided' ?></div></div>
                <div class="ar-item"><div class="ar-k">Home Address</div><div class="ar-v <?= empty($prec['home_address']) ? 'empty' : '' ?>"><?= $prec['home_address'] ?? 'Not provided' ?></div></div>
                <div class="ar-item"><div class="ar-k">Occupation</div><div class="ar-v <?= empty($prec['occupation']) ? 'empty' : '' ?>"><?= $prec['occupation'] ?? 'Not provided' ?></div></div>
                <div class="ar-item"><div class="ar-k">Dental Insurance</div><div class="ar-v <?= empty($prec['dental_insurance']) ? 'empty' : '' ?>"><?= $prec['dental_insurance'] ?? 'None' ?></div></div>
                <div class="ar-item"><div class="ar-k">Reason for Consultation</div><div class="ar-v <?= empty($prec['reason_for_consultation']) ? 'empty' : '' ?>"><?= $prec['reason_for_consultation'] ?? 'Not specified' ?></div></div>
                <div class="ar-item"><div class="ar-k">Informed Consent</div><div class="ar-v <?= $c_given ? '' : 'empty' ?>"><?= $c_given ? 'Given' . (!empty($pcon['signature_date']) ? ' on ' . htmlspecialchars($pcon['signature_date']) : '') : 'Not yet given' ?></div></div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function filterArPatients(q) {
  const query = (q || '').trim().toLowerCase();
  const table = document.getElementById('arPatientsTable');
  if (table) table.style.display = query ? '' : 'none';
  document.querySelectorAll('.ar-p-row').forEach(row => {
    const s = row.dataset.search || '';
    row.style.display = (query && s.includes(query)) ? '' : 'none';
  });
}

</script>
</body>
</html>
