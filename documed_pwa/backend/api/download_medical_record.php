<?php
require_once dirname(__DIR__) . '/config/db.php';
// Render a printable HTML that mirrors the provided medical record form.
// Input: id (checkup id) OR sid (student_faculty_id & latest)
$checkupId = isset($_GET['id']) ? trim($_GET['id']) : '';
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';
$as = isset($_GET['as']) ? strtolower(trim($_GET['as'])) : '';
$isPdf = ($as === 'pdf');
if (!$isPdf) {
  header('Content-Type: text/html; charset=utf-8');
}

function fetchCheckup($pdo, $id, $sid) {
    if ($id !== '') {
        $stmt = $pdo->prepare("SELECT c.*, u.last_name, u.first_name, u.middle_initial, u.address AS u_address, u.age AS u_age,
                                      u.civil_status AS u_civil_status, u.nationality AS u_nationality, u.religion AS u_religion,
                                      u.date_of_birth AS u_dob, u.place_of_birth AS u_pob, u.year_course AS u_year_course,
                                      u.contact_person AS u_contact_person, u.contact_number AS u_contact_number, u.photo AS u_photo
                               FROM checkups c LEFT JOIN users u ON u.student_faculty_id=c.student_faculty_id WHERE c.id=? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($sid !== '') {
        $stmt = $pdo->prepare("SELECT c.*, u.last_name, u.first_name, u.middle_initial, u.address AS u_address, u.age AS u_age,
                                      u.civil_status AS u_civil_status, u.nationality AS u_nationality, u.religion AS u_religion,
                                      u.date_of_birth AS u_dob, u.place_of_birth AS u_pob, u.year_course AS u_year_course,
                                      u.contact_person AS u_contact_person, u.contact_number AS u_contact_number, u.photo AS u_photo
                               FROM checkups c LEFT JOIN users u ON u.student_faculty_id=c.student_faculty_id WHERE c.student_faculty_id=? ORDER BY c.created_at DESC LIMIT 1");
        $stmt->execute([$sid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return null;
}

$row = fetchCheckup($pdo, $checkupId, $sid);
if (!$row) {
    echo '<!doctype html><html><body><h3>No record found.</h3></body></html>';
    exit;
}

function esc($s){ return htmlspecialchars((string)($s??''), ENT_QUOTES, 'UTF-8'); }
$fullName = trim(($row['last_name']??'').', '.($row['first_name']??'').(($row['middle_initial']??'')!==''?' '.($row['middle_initial']).'.':''));
$date = date('Y-m-d', strtotime($row['created_at'] ?? 'now'));
$age = $row['u_age'] ?? $row['age'] ?? '';
$addr = $row['u_address'] ?? $row['address'] ?? '';
$cstatus = $row['u_civil_status'] ?? $row['civil_status'] ?? '';
$nationality = $row['u_nationality'] ?? $row['nationality'] ?? '';
$religion = $row['u_religion'] ?? $row['religion'] ?? '';
$dob = $row['u_dob'] ?? $row['date_of_birth'] ?? '';
$pob = $row['u_pob'] ?? $row['place_of_birth'] ?? '';
$yc = $row['year_and_course'] ?? $row['u_year_course'] ?? '';
$contact_person = $row['u_contact_person'] ?? $row['contact_person'] ?? '';
$contact_number = $row['u_contact_number'] ?? $row['contact_number'] ?? '';
$photo = $row['u_photo'] ?? '';
// Normalize stored photo path (often '../assets/images/...') to a web-accessible path
if ($photo) {
  // If it starts with '../assets/images', adjust to '../../frontend/assets/images'
  if (preg_match('#^\.\./assets/images/#', $photo)) {
    $photo = '../../frontend/' . substr($photo, 3); // remove '../' and prefix '../../frontend/'
  }
  // If it's a filesystem path, try to map to frontend/assets/images as a best effort
  if (!preg_match('#^https?://#', $photo) && !preg_match('#^\.{1,2}/#', $photo)) {
    $photo = '../../frontend/assets/images/' . ltrim($photo, '/');
  }
}
$history = $row['history_past_illness'] ?? '';
$present = $row['present_illness'] ?? '';
$ops = $row['operations_hospitalizations'] ?? '';
$imm = $row['immunization_history'] ?? '';
$soc = $row['social_environmental_history'] ?? '';
$obg = $row['ob_gyne_history'] ?? '';
$neuro = $row['neurological_exam'] ?? '';
$labs = $row['laboratory_results'] ?? '';
$assessment = $row['assessment'] ?? '';
$remarks = $row['remarks'] ?? '';
$gs = intval($row['physical_exam_general_survey'] ?? 0);
$skin = intval($row['physical_exam_skin'] ?? 0);
$heart = intval($row['physical_exam_heart'] ?? 0);
$abd = intval($row['physical_exam_abdomen'] ?? 0);
$gu = intval($row['physical_exam_genitourinary'] ?? 0);
$chest = intval($row['physical_exam_chest_lungs'] ?? 0);
$msk = intval($row['physical_exam_musculoskeletal'] ?? 0);
$dn = $row['doctor_nurse'] ?? $row['doctor_nurse_effective'] ?? '';

$logo = '../../frontend/assets/images/doc_68bebd410d87b_psulogo-1-1280x1280.png';
$logoCandidate = __DIR__ . '/../../frontend/assets/images/doc_68bebd410d87b_psulogo-1-1280x1280.png';
if (!file_exists($logoCandidate)) {
  $logo = '../../frontend/assets/images/documed_logo.png';
}

function checkSymbol($flag) {
    return $flag ? '&#x2611;' : '&#x2610;';
}

// No QR on the printable form per request

// Start buffering HTML so we can optionally render as PDF
ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Patient's Medical Record</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; color:#111; }
  .sheet { width: 820px; margin: 0 auto; padding: 16px; border: 1px solid #111; }
  .row { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
  .col { flex: 1; }
  .lbl { font-weight: bold; }
  .box { min-height: 18px; border-bottom: 1px solid #111; padding: 2px 4px; }
  .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .section { margin-top: 8px; border: 1px solid #111; padding: 8px; }
  .section h4 { margin: 0 0 6px 0; }
  .checks { display:grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
  .sign { margin-top: 18px; display:flex; justify-content: space-between; }
  .photo { width: 140px; height: 160px; border:1px solid #111; display:flex; align-items:center; justify-content:center; }
  .header { text-align:center; font-weight:700; margin-bottom:8px; }
  .qr { display:none; }
  .right { display:flex; flex-direction:column; align-items:center; gap:8px; }
  /* Tighten DATE / NAME / AGE alignment */
  .row.compact { gap: 12px; }
  .label { width: 80px; min-width: 80px; font-weight: bold; }
  .line { flex: 1; border-bottom: 1px solid #111; height: 20px; display:flex; align-items:center; padding:0 8px; line-height: 18px; }
</style>
</head>
<body>
  <div class="sheet">
    <div class="header">PATIENT'S MEDICAL RECORD<br><small>Pangasinan State University - Lingayen Campus</small></div>
    <div class="row compact" style="align-items:flex-start;">
      <div style="flex:1 1 auto;">
        <div class="row compact" style="margin-top: 16px; gap:4px;">
          <div class="label">DATE:</div>
          <div class="line" style="justify-content:flex-start; flex:0 0 160px; padding:0 2px;"><?=esc($date)?></div>
        </div>
      </div>
      <div class="right" style="flex:0 0 160px;">
        <div class="photo"><?php if ($photo) { echo '<img src="'.esc($photo).'" style="max-width:100%;max-height:100%">'; } else { echo '2x2 PHOTO'; } ?></div>
      </div>
    </div>
    <div class="row compact" style="align-items:flex-start; margin-bottom: 10px; gap:2px;">
      <div class="label" style="width:58px;min-width:58px;">NAME:</div>
      <div class="line" style="flex: 1; padding:0 2px;"><?=esc($fullName)?></div>
      <div class="label" style="width:50px;min-width:50px;">AGE:</div>
      <div class="line" style="flex:0 0 70px; justify-content:center; height:20px;"><?=esc($age)?></div>
    </div>
    <div class="row grid">
      <div><span class="lbl">ADDRESS:</span> <span class="box" style="display:inline-block;min-width:300px;"><?=esc($addr)?></span></div>
      <div><span class="lbl">CIVIL STATUS:</span> <span class="box"><?=esc($cstatus)?></span></div>
      <div><span class="lbl">NATIONALITY:</span> <span class="box"><?=esc($nationality)?></span></div>
      <div><span class="lbl">RELIGION:</span> <span class="box"><?=esc($religion)?></span></div>
      <div><span class="lbl">DATE OF BIRTH:</span> <span class="box">&nbsp;<?=esc($dob)?></span></div>
      <div><span class="lbl">PLACE OF BIRTH:</span> <span class="box">&nbsp;<?=esc($pob)?></span></div>
      <div><span class="lbl">YEAR &amp; COURSE:</span> <span class="box">&nbsp;<?=esc($yc)?></span></div>
      <div><span class="lbl">CONTACT PERSON:</span> <span class="box">&nbsp;<?=esc($contact_person)?></span></div>
      <div><span class="lbl">CONTACT NO.:</span> <span class="box">&nbsp;<?=esc($contact_number)?></span></div>
    </div>

    <div class="section">
      <h4>MEDICAL HISTORY</h4>
      <div><span class="lbl">I. HISTORY OF PAST ILLNESS (if any)</span><div class="box"><?=nl2br(esc($history))?></div></div>
      <div><span class="lbl">II. PRESENT ILLNESS</span><div class="box"><?=nl2br(esc($present))?></div></div>
      <div><span class="lbl">III. OPERATIONS AND HOSPITALIZATIONS</span><div class="box"><?=nl2br(esc($ops))?></div></div>
      <div><span class="lbl">IV. IMMUNIZATION HISTORY</span><div class="box"><?=nl2br(esc($imm))?></div></div>
      <div><span class="lbl">V. SOCIAL AND ENVIRONMENTAL HISTORY</span><div class="box"><?=nl2br(esc($soc))?></div></div>
      <div><span class="lbl">VI. OB/GYNECOLOGICAL HISTORY (for female only)</span><div class="box"><?=nl2br(esc($obg))?></div></div>
    </div>

    <div class="section">
      <h4>PHYSICAL EXAMINATION (Check all that apply)</h4>
      <div class="checks">
        <div><?= $gs ? '☑' : '☐' ?> General Survey</div>
        <div><?= $heart ? '☑' : '☐' ?> Heart</div>
        <div><?= $skin ? '☑' : '☐' ?> Skin</div>
        <div><?= $abd ? '☑' : '☐' ?> Abdomen</div>
        <div><?= $chest ? '☑' : '☐' ?> Chest and Lungs</div>
        <div><?= $gu ? '☑' : '☐' ?> Genitourinary</div>
        <div><?= $msk ? '☑' : '☐' ?> Musculoskeletal</div>
      </div>
    </div>

    <div class="section">
      <div><span class="lbl">NEUROLOGICAL EXAMINATION:</span><div class="box"><?=nl2br(esc($neuro))?></div></div>
      <div><span class="lbl">LABORATORY RESULTS:</span><div class="box"><?=nl2br(esc($labs))?></div></div>
      <div><span class="lbl">ASSESSMENT:</span><div class="box"><?=nl2br(esc($assessment))?></div></div>
      <div><span class="lbl">REMARKS:</span><div class="box"><?=nl2br(esc($remarks))?></div></div>
    </div>

    <div class="sign">
      <div><small>Generated by DocuMed</small></div>
      <div><small>Doctor/Nurse: <?=esc($dn)?></small></div>
    ob_start();
    ?>
    <!-- <!doctype html>
    <html>
    <head>
    <meta charset="utf-8">
    <title>Patient's Medical Record</title>
    <style>
      * { box-sizing: border-box; }
      body { font-family: Arial, sans-serif; font-size: 12px; color:#000; margin:0; }
      @page { margin: 0; }
      .sheet { width: 8.27in; min-height: 11.69in; padding: 0.5in 0.55in; border: 1px solid #000; margin: 0 auto; }
      .header-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
      .header-table td { vertical-align: middle; }
      .logo-cell { width: 90px; text-align: center; }
      .logo-cell img { max-width: 80px; max-height: 80px; }
      .title-cell { text-align: center; font-weight: 700; }
      .title-cell .title { font-size: 18px; letter-spacing: 0.5px; }
      .title-cell .subtitle { font-size: 12px; margin-top: 4px; text-transform: uppercase; }
      .photo-cell { width: 160px; height: 180px; border: 1px solid #000; text-align: center; font-size: 12px; }
      .photo-cell img { max-width: 100%; max-height: 100%; object-fit: cover; }
      .info-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
      .info-table td { padding: 4px 6px; vertical-align: middle; }
      .info-table .label { font-weight: 600; font-size: 11px; white-space: nowrap; }
      .info-table .line { border-bottom: 1px solid #000; min-height: 18px; padding: 0 6px; }
      .info-table .line.center { text-align: center; }
      .name-grid { width: 100%; border-collapse: collapse; }
      .name-grid td { border-bottom: 1px solid #000; padding: 4px 6px; }
      .name-grid tr.caption td { border-bottom: 0; font-size: 9px; text-align: center; padding-top: 2px; }
      .full-line { border: 1px solid #000; min-height: 20px; padding: 6px; margin-top: 4px; }
      .history-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
      .history-table td { padding: 6px 4px; vertical-align: top; }
      .history-table .subhead { font-weight: 600; font-size: 11px; }
      .history-table .text-area { border: 1px solid #000; min-height: 60px; padding: 6px; margin-top: 3px; }
      .physical-table { width: 100%; border: 1px solid #000; border-collapse: collapse; margin-top: 8px; }
      .physical-table td { width: 33%; padding: 6px 8px; font-size: 11px; border-right: 1px solid #000; border-bottom: 1px solid #000; }
      .physical-table tr:last-child td { border-bottom: 0; }
      .physical-table td:last-child { border-right: 0; }
      .exam-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
      .exam-table td { padding: 6px 4px; vertical-align: top; }
      .exam-table .label { width: 230px; font-weight: 600; font-size: 11px; }
      .exam-table .text-area { border: 1px solid #000; min-height: 50px; padding: 6px; }
      .signatures { margin-top: 32px; display: flex; justify-content: space-between; font-size: 11px; }
      .signatures .line { border-top: 1px solid #000; width: 240px; padding-top: 4px; text-align: center; margin-top: 16px; }
      .small-note { font-size: 10px; margin-top: 4px; }
    </style>
    </head>
    <body>
      <div class="sheet">
        <table class="header-table">
          <tr>
            <td class="logo-cell"><?php if ($logo) { echo '<img src="'.esc($logo).'" alt="PSU Logo">'; } ?></td>
            <td class="title-cell">
              <div class="title">PATIENT'S MEDICAL RECORD</div>
              <div class="subtitle">PANGASINAN STATE UNIVERSITY<br>LINGAYEN CAMPUS</div>
            </td>
            <td class="photo-cell"><?php if ($photo) { echo '<img src="'.esc($photo).'" alt="2x2 Photo">'; } else { echo '2x2 PHOTO'; } ?></td>
          </tr>
        </table>

        <table class="info-table">
          <tr>
            <td class="label">DATE:</td>
            <td class="line" style="width: 160px;"><?=esc($date)?></td>
            <td></td>
            <td class="label">AGE:</td>
            <td class="line center" style="width: 70px;"><?=esc($age)?></td>
          </tr>
          <tr>
            <td class="label">NAME:</td>
            <td colspan="3">
              <table class="name-grid">
                <tr>
                  <td><?=esc($row['last_name'] ?? '')?></td>
                  <td><?=esc($row['first_name'] ?? '')?></td>
                  <td style="width: 60px; text-align:center;"><?=esc($row['middle_initial'] ?? '')?></td>
                </tr>
                <tr class="caption">
                  <td>Last Name</td>
                  <td>First Name</td>
                  <td>M.I.</td>
                </tr>
              </table>
            </td>
            <td></td>
          </tr>
          <tr>
            <td class="label">ADDRESS:</td>
            <td class="line" colspan="4" style="min-height: 22px;"><?=esc($addr)?></td>
          </tr>
          <tr>
            <td class="label">CIVIL STATUS:</td>
            <td class="line" style="width: 160px;"><?=esc($cstatus)?></td>
            <td></td>
            <td class="label">NATIONALITY:</td>
            <td class="line" style="width: 160px;"><?=esc($nationality)?></td>
          </tr>
          <tr>
            <td class="label">RELIGION:</td>
            <td class="line" style="width: 160px;"><?=esc($religion)?></td>
            <td></td>
            <td class="label">DATE OF BIRTH:</td>
            <td class="line" style="width: 160px;"><?=esc($dob)?></td>
          </tr>
          <tr>
            <td class="label">PLACE OF BIRTH:</td>
            <td class="line" style="width: 160px;"><?=esc($pob)?></td>
            <td></td>
            <td class="label">YEAR &amp; COURSE:</td>
            <td class="line" style="width: 160px;"><?=esc($yc)?></td>
          </tr>
          <tr>
            <td class="label">CONTACT PERSON:</td>
            <td class="line" style="width: 160px;"><?=esc($contact_person)?></td>
            <td></td>
            <td class="label">CONTACT NO.:</td>
            <td class="line" style="width: 160px;"><?=esc($contact_number)?></td>
          </tr>
        </table>

        <table class="history-table">
          <tr>
            <td class="subhead" style="width: 260px;">I. HISTORY OF PAST ILLNESS (if any)</td>
            <td><div class="text-area"><?=nl2br(esc($history))?></div></td>
          </tr>
          <tr>
            <td class="subhead">II. PRESENT ILLNESS</td>
            <td><div class="text-area"><?=nl2br(esc($present))?></div></td>
          </tr>
          <tr>
            <td class="subhead">III. OPERATIONS AND HOSPITALIZATIONS</td>
            <td><div class="text-area"><?=nl2br(esc($ops))?></div></td>
          </tr>
          <tr>
            <td class="subhead">IV. IMMUNIZATION HISTORY</td>
            <td><div class="text-area"><?=nl2br(esc($imm))?></div></td>
          </tr>
          <tr>
            <td class="subhead">V. SOCIAL AND ENVIRONMENTAL HISTORY</td>
            <td><div class="text-area"><?=nl2br(esc($soc))?></div></td>
          </tr>
          <tr>
            <td class="subhead">VI. OB/GYNECOLOGICAL HISTORY (for female only)</td>
            <td><div class="text-area"><?=nl2br(esc($obg))?></div></td>
          </tr>
        </table>

        <table class="physical-table">
          <tr>
            <td><?=checkSymbol($gs)?> General Survey</td>
            <td><?=checkSymbol($heart)?> Heart</td>
            <td><?=checkSymbol($skin)?> Skin</td>
          </tr>
          <tr>
            <td><?=checkSymbol($abd)?> Abdomen</td>
            <td><?=checkSymbol($chest)?> Chest and Lungs</td>
            <td><?=checkSymbol($gu)?> Genitourinary</td>
          </tr>
          <tr>
            <td><?=checkSymbol($msk)?> Musculoskeletal</td>
            <td></td>
            <td></td>
          </tr>
        </table>

        <table class="exam-table">
          <tr>
            <td class="label">NEUROLOGICAL EXAMINATION:</td>
            <td><div class="text-area"><?=nl2br(esc($neuro))?></div></td>
          </tr>
          <tr>
            <td class="label">LABORATORY RESULTS (Provide photocopy of Laboratory Result):</td>
            <td><div class="text-area"><?=nl2br(esc($labs))?></div></td>
          </tr>
          <tr>
            <td class="label">ASSESSMENT:</td>
            <td><div class="text-area"><?=nl2br(esc($assessment))?></div></td>
          </tr>
          <tr>
            <td class="label">REMARKS:</td>
            <td><div class="text-area"><?=nl2br(esc($remarks))?></div></td>
          </tr>
        </table>

        <div class="signatures">
          <div>
            <div class="small-note">Generated by DocuMed</div>
          </div>
          <div>
            <div class="line">Doctor/Nurse: <?=esc($dn)?></div>
            <div style="text-align:center; font-size:10px;">(Signature over Printed Name)</div>
          </div>
        </div>
      </div>
    </body>
    </html> -->