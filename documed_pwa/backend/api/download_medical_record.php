<?php
// medical_record_pdf.php
// Generates a one-page, table-based "Patient Medical Record" printable HTML or PDF
// Usage: medical_record_pdf.php?id=123    OR  ?sid=SFID    OR  ?sid=SFID&as=pdf

// Begin with a clean output buffer to avoid accidental whitespace before PDF
ob_start();

require_once dirname(__DIR__) . '/config/db.php';

$checkupId = isset($_GET['id']) ? trim($_GET['id']) : '';
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';
$as = isset($_GET['as']) ? strtolower(trim($_GET['as'])) : '';
$isPdf = ($as === 'pdf');
$downloadName = 'medical-record-' . ($sid ? preg_replace('/[^A-Za-z0-9_-]/', '-', $sid) : ($checkupId !== '' ? $checkupId : 'record')) . '.pdf';

function fetchCheckup($pdo, $id, $sid) {
    if ($id !== '') {
        $stmt = $pdo->prepare("SELECT c.*, u.last_name, u.first_name, u.middle_initial,
                                      u.address AS u_address, u.age AS u_age,
                                      u.civil_status AS u_civil_status, u.nationality AS u_nationality,
                                      u.religion AS u_religion, u.date_of_birth AS u_dob, u.place_of_birth AS u_pob,
                                      u.year_course AS u_year_course, u.contact_person AS u_contact_person, u.contact_number AS u_contact_number,
                                      u.photo AS u_photo
                               FROM checkups c LEFT JOIN users u ON u.student_faculty_id=c.student_faculty_id WHERE c.id=? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($sid !== '') {
        $stmt = $pdo->prepare("SELECT c.*, u.last_name, u.first_name, u.middle_initial,
                                      u.address AS u_address, u.age AS u_age,
                                      u.civil_status AS u_civil_status, u.nationality AS u_nationality,
                                      u.religion AS u_religion, u.date_of_birth AS u_dob, u.place_of_birth AS u_pob,
                                      u.year_course AS u_year_course, u.contact_person AS u_contact_person, u.contact_number AS u_contact_number,
                                      u.photo AS u_photo
                               FROM checkups c LEFT JOIN users u ON u.student_faculty_id=c.student_faculty_id WHERE c.student_faculty_id=? ORDER BY c.created_at DESC LIMIT 1");
        $stmt->execute([$sid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return null;
}

$row = fetchCheckup($pdo, $checkupId, $sid);
if (!$row) {
    // Show simple HTML when no record found
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>No record</title></head><body><h2>No record found.</h2></body></html>';
    exit;
}

function esc($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

// Normalize fields
$fullName = trim((($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . (($row['middle_initial'] ?? '') !== '' ? ' ' . $row['middle_initial'] . '.' : '')));
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

// Prepare photo data URI if possible (dompdf can use data URIs)
$photoDataUri = '';
if ($photo) {
    try {
        if (preg_match('#^https?://#i', $photo)) {
            $binary = @file_get_contents($photo);
            if ($binary !== false) {
                $ext = strtolower(pathinfo(parse_url($photo, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : ($ext === 'png' ? 'image/png' : 'image/*');
                $photoDataUri = 'data:' . $mime . ';base64,' . base64_encode($binary);
            }
        } else {
            // try to resolve relative to frontend assets
            $candidate = dirname(__DIR__, 2) . '/frontend/' . ltrim($photo, '/');
            $candidate2 = dirname(__DIR__, 2) . '/frontend/assets/images/' . ltrim($photo, '/');
            if (file_exists($candidate)) $c = $candidate; elseif (file_exists($candidate2)) $c = $candidate2; else $c = false;
            if ($c && file_exists($c)) {
                $binary = @file_get_contents($c);
                if ($binary !== false) {
                    $ext = strtolower(pathinfo($c, PATHINFO_EXTENSION));
                    $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : ($ext === 'png' ? 'image/png' : 'image/*');
                    $photoDataUri = 'data:' . $mime . ';base64,' . base64_encode($binary);
                }
            }
        }
    } catch (Throwable $e) { $photoDataUri = ''; }
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

// Build HTML using table-based layout suitable for Dompdf - Form 2 style
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Patient's Medical Record</title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color:#111; margin: 0; }
  .sheet { max-width: 820px; margin: 18px auto; padding: 14px 18px; border: 1px solid #111; box-sizing: border-box; }
  .row { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
  .col { flex: 1; }
  .lbl { font-weight: bold; }
  .box { min-height: 18px; border-bottom: 1px solid #111; padding: 2px 4px; }
  .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .section { margin-top: 8px; border: 1px solid #111; padding: 8px; }
  .section h4 { margin: 0 0 6px 0; }
  .checks { display:grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
  .sign { margin-top: 18px; display:flex; justify-content: space-between; }
  .photo { width: 120px; height: 120px; border:1px solid #111; display:flex; align-items:center; justify-content:center; overflow:hidden; }
  .header { text-align:center; font-weight:700; margin-bottom:8px; }
  .qr { display:none; }
  .right { display:flex; flex-direction:column; align-items:center; gap:8px; }
  /* Tighten DATE / NAME / AGE alignment */
  .row.compact { gap: 12px; }
  .label { font-weight: bold; padding-right:6px; }
  .field { border-bottom: 1px solid #111; min-height: 18px; padding: 2px 6px; }
  .line { flex: 1; border-bottom: 1px solid #111; height: 20px; display:flex; align-items:center; padding:0 8px; line-height: 18px; }
</style>
</head>
<body>
  <div class="sheet">
    <div class="header">PATIENT'S MEDICAL RECORD<br><small>Pangasinan State University - Lingayen Campus</small></div>
    <table style="width:100%; margin-top:8px;">
      <tr>
        <td style="width:72%; vertical-align:top; padding-right:6px;">

          <table style="width:100%; border-collapse:collapse;">
            <col style="width:16%"><col style="width:44%"><col style="width:12%"><col style="width:28%">
            <tr>
              <td class="label">DATE:</td>
              <td class="field" colspan="3"><?= esc($date) ?></td>
            </tr>
            <tr>
              <td class="label">NAME:</td>
              <td class="field"><?= esc($fullName) ?></td>
              <td class="label">AGE:</td>
              <td class="field"><?= esc($age) ?></td>
            </tr>
            <tr>
              <td class="label">ADDRESS:</td>
              <td class="field"><?= esc($addr) ?></td>
              <td class="label">CIVIL STATUS:</td>
              <td class="field"><?= esc($cstatus) ?></td>
            </tr>
            <tr>
              <td class="label">NATIONALITY:</td>
              <td class="field"><?= esc($nationality) ?></td>
              <td class="label">RELIGION:</td>
              <td class="field"><?= esc($religion) ?></td>
            </tr>
            <tr>
              <td class="label">DATE OF BIRTH:</td>
              <td class="field"><?= esc($dob) ?></td>
              <td class="label">PLACE OF BIRTH:</td>
              <td class="field"><?= esc($pob) ?></td>
            </tr>
            <tr>
              <td class="label">YEAR &amp; COURSE:</td>
              <td class="field"><?= esc($yc) ?></td>
              <td class="label">CONTACT PERSON:</td>
              <td class="field"><?= esc($contact_person) ?></td>
            </tr>
            <tr>
              <td class="label">CONTACT NO.:</td>
              <td class="field"><?= esc($contact_number) ?></td>
              <td class="label">STUDENT/EMP ID:</td>
              <td class="field"><?= esc($sid ?: '') ?></td>
            </tr>
          </table>

        </td>

  <td style="width:28%; vertical-align:top; text-align:center;">
          <?php if (!empty($photoDataUri)): ?>
            <div class="photo"><img src="<?= $photoDataUri ?>" style="width:100%; height:100%; object-fit:cover; display:block;" alt="Patient photo"></div>
            <div class="small" style="margin-top:6px; font-size:10px;">2 x 2 PHOTO</div>
          <?php else: ?>
            <div class="photo">2 x 2<br>PHOTO</div>
            <div class="small" style="margin-top:6px;">Place 2x2 photo here</div>
          <?php endif; ?>

        </td>
      </tr>
    </table>

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
    </div>
  </div>
</body>
</html>
<?php

$html = ob_get_clean();

if ($isPdf) {
    // Attempt to autoload dompdf
    if (!class_exists('Dompdf\Dompdf')) {
        $vendorRoot = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $vendorLocal = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (file_exists($vendorRoot)) {
            require_once $vendorRoot;
        } elseif (file_exists($vendorLocal)) {
            require_once $vendorLocal;
        }
    }

    if (!class_exists('Dompdf\Dompdf')) {
        // Fallback: send HTML as attachment
        header('Content-Type: text/html; charset=utf-8', true);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"', true);
        echo $html;
        exit;
    }

    // Configure Dompdf
    $options = new Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $tempDir = dirname(__DIR__) . '/tmp/dompdf';
    if (!is_dir($tempDir)) { @mkdir($tempDir, 0777, true); }
    if (is_dir($tempDir)) { $options->set('tempDir', realpath($tempDir)); }

    $rootDir = realpath(dirname(__DIR__, 2));
    if ($rootDir) { $options->set('chroot', $rootDir); }

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');

    try {
        $dompdf->render();
        // Clean any accidental buffer output before sending PDF
        if (ob_get_length()) { @ob_clean(); }
        $pdfOutput = $dompdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . strlen($pdfOutput));
        echo $pdfOutput;
    } catch (Throwable $e) {
        error_log('DocuMed dompdf render failed: ' . $e->getMessage());
        header('Content-Type: text/html; charset=utf-8', true);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"', true);
        echo $html;
    }

    exit;
}

// Otherwise output HTML for browser viewing
header('Content-Type: text/html; charset=utf-8');
echo $html;
exit;
?>