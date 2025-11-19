<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Define col_exists early (used by migration guards)
if (!function_exists('col_exists')) {
    function col_exists($pdo, $table, $col) {
        try {
            $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!$db) return false;
            $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND UPPER(COLUMN_NAME) = UPPER(?)");
            $q->execute([$db, $table, $col]);
            return intval($q->fetchColumn()) > 0;
        } catch (Throwable $e) { return false; }
    }
}

// Ensure soft-archive column exists (best-effort)
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN archived TINYINT(1) DEFAULT 0"); } catch (Throwable $e) { /* ignore */ }
// Ensure doctor/nurse performer and follow-up columns exist (best-effort)
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN doctor_nurse VARCHAR(150) NULL"); } catch (Throwable $e) { /* ignore */ }
// Track performer by id for reliable attribution (best-effort)
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN doc_nurse_id INT NULL AFTER doctor_nurse"); } catch (Throwable $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN follow_up TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ignore */ }
// Ensure follow-up date column exists (best-effort)
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN follow_up_date DATE NULL"); } catch (Throwable $e) { /* ignore */ }
// Ensure issuance tables exist (best-effort)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS checkup_medicines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        checkup_id INT NOT NULL,
        medicine_id INT NOT NULL,
        qty INT NOT NULL,
        campus VARCHAR(100) NOT NULL DEFAULT 'Lingayen',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_checkup (checkup_id),
        INDEX idx_medicine (medicine_id)
    ) ENGINE=InnoDB");
} catch (Throwable $e) { /* ignore */ }
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS checkup_medicine_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        checkup_medicine_id INT NOT NULL,
        batch_id INT NOT NULL,
        qty INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_checkup_med (checkup_medicine_id),
        INDEX idx_batch (batch_id)
    ) ENGINE=InnoDB");
} catch (Throwable $e) { /* ignore */ }
// Ensure exam_category column exists (based on current schema screenshot)
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN exam_category VARCHAR(200) NULL AFTER client_type"); } catch (Throwable $e) { /* ignore */ }
// Ensure client_type (renamed from role) & gender columns exist (best-effort, guarded)
try {
    if (!col_exists($pdo, 'checkups', 'client_type')) {
        try { $pdo->exec("ALTER TABLE checkups ADD COLUMN client_type VARCHAR(50) NULL AFTER student_faculty_id"); } catch (Throwable $e2) { /* ignore add fail */ }
        try { if (col_exists($pdo,'checkups','role') && col_exists($pdo,'checkups','client_type')) { $pdo->exec("UPDATE checkups SET client_type = role WHERE (client_type IS NULL OR client_type='') AND role IS NOT NULL"); } } catch (Throwable $e3) { /* ignore copy fail */ }
    }
    if (!col_exists($pdo, 'checkups', 'gender')) {
        try { $pdo->exec("ALTER TABLE checkups ADD COLUMN gender VARCHAR(32) NULL AFTER religion"); } catch (Throwable $e4) { /* ignore */ }
    }
} catch (Throwable $e) { /* ignore */ }
// Maintain age column
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN age INT NULL AFTER name"); } catch (Throwable $e) { /* ignore */ }
// Ensure department column exists (some deployments include it)
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN department VARCHAR(100) NULL AFTER year_and_course"); } catch (Throwable $e) { /* ignore */ }
// Normalize potential column name typos to expected 'physical_exam_chest_lungs'
try { $pdo->exec("ALTER TABLE checkups CHANGE physical_exam_chest_lunge physical_exam_chest_lungs TINYINT(1)"); } catch (Throwable $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE checkups CHANGE physical_exam_chest_lung physical_exam_chest_lungs TINYINT(1)"); } catch (Throwable $e) { /* ignore */ }
// Add note_for_physical_exam column to store free-text notes from the clinical exam
try { $pdo->exec("ALTER TABLE checkups ADD COLUMN note_for_physical_exam TEXT NULL AFTER physical_exam_musculoskeletal"); } catch (Throwable $e) { /* ignore */ }

function jsonResponse($arr) {
    echo json_encode($arr);
    exit;
}

// Helper: check if column exists (case-insensitive)
// col_exists already defined above

// Add new checkup record

if ($action === 'add') {
    // Basic role hardening: if called with admin_id, block clinical modification
    if (!empty($_POST['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Admins cannot add clinical records.']);
        exit;
    }
    $student_faculty_id = $_POST['student_faculty_id'] ?? '';
    // Accept new client_type plus legacy aliases
    $client_type = $_POST['client_type'] ?? ($_POST['role'] ?? null); // Student | Faculty | Staff (Non-Teaching)
    $age = $_POST['age'] ?? '';
    $exam_category = $_POST['exam_category'] ?? '';
    $name = $_POST['name'] ?? '';
    $address = $_POST['address'] ?? '';
    $civil_status = $_POST['civil_status'] ?? '';
    $nationality = $_POST['nationality'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $place_of_birth = $_POST['place_of_birth'] ?? '';
    $year_and_course = $_POST['year_and_course'] ?? '';
    $department = $_POST['department'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $history_past_illness = $_POST['history_past_illness'] ?? '';
    $present_illness = $_POST['present_illness'] ?? '';
    $operations_hospitalizations = $_POST['operations_hospitalizations'] ?? '';
    $immunization_history = $_POST['immunization_history'] ?? '';
    $social_environmental_history = $_POST['social_environmental_history'] ?? '';
    $ob_gyne_history = $_POST['ob_gyne_history'] ?? '';
    $physical_exam_general_survey = isset($_POST['physical_exam_general_survey']) ? 1 : 0;
    $physical_exam_skin = isset($_POST['physical_exam_skin']) ? 1 : 0;
    $physical_exam_heart = isset($_POST['physical_exam_heart']) ? 1 : 0;
    $physical_exam_chest_lungs = isset($_POST['physical_exam_chest_lungs']) ? 1 : 0;
    $physical_exam_abdomen = isset($_POST['physical_exam_abdomen']) ? 1 : 0;
    $physical_exam_genitourinary = isset($_POST['physical_exam_genitourinary']) ? 1 : 0;
    $physical_exam_musculoskeletal = isset($_POST['physical_exam_musculoskeletal']) ? 1 : 0;
    $note_for_physical_exam = $_POST['note_for_physical_exam'] ?? '';
    $neurological_exam = $_POST['neurological_exam'] ?? '';
    $laboratory_results = $_POST['laboratory_results'] ?? '';
    $assessment = $_POST['assessment'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    $photo = $_POST['photo'] ?? '';
    // New fields
    $doctor_nurse = $_POST['doctor_nurse'] ?? null; // free-text name (from doc_nurse login)
    // Defensive: allow passing doc_nurse_id and resolve to name (plus role) if present
    $doc_nurse_id = $_POST['doc_nurse_id'] ?? null;
    $follow_up = isset($_POST['follow_up']) ? (int)!!$_POST['follow_up'] : 0; // 0 or 1
    $follow_up_date = $_POST['follow_up_date'] ?? null; // YYYY-MM-DD
    $created_at = date('Y-m-d H:i:s');

    // Required field validation
    if (!$name || !$student_faculty_id) {
        echo json_encode(['success' => false, 'message' => 'Missing required field: name']);
        exit;
    }

    // Check for duplicate (same name and date_of_birth)
    // $stmt = $pdo->prepare("SELECT COUNT(*) FROM checkups WHERE name = ? AND date_of_birth = ?");
    // $stmt->execute([$name, $date_of_birth]);
    // if ($stmt->fetchColumn() > 0) {
    //     echo json_encode(['success' => false, 'message' => 'Checkup already exists for this patient and birthdate.']);
    //     exit;
    // }

    try {
        // Build insert dynamically to ensure column/value counts always match
        // Resolve performer name if missing and id is provided
        if ((!$doctor_nurse || $doctor_nurse === '') && $doc_nurse_id) {
            try {
                $dnStmt = $pdo->prepare('SELECT name, role FROM doc_nurse WHERE id = ?');
                $dnStmt->execute([$doc_nurse_id]);
                $dn = $dnStmt->fetch(PDO::FETCH_ASSOC);
                if ($dn && ($dn['name'] ?? '')) {
                    $doctor_nurse = $dn['role'] ? ($dn['name'] . ' (' . $dn['role'] . ')') : $dn['name'];
                }
            } catch (Throwable $e) { /* ignore */ }
        }

        $data = [
            'student_faculty_id' => $student_faculty_id,
            'client_type' => $client_type,
            'exam_category' => $exam_category,
            'name' => $name,
            'age' => $age,
            'address' => $address,
            'civil_status' => $civil_status,
            'nationality' => $nationality,
            'religion' => $religion,
            'gender' => $gender,
            'date_of_birth' => $date_of_birth,
            'place_of_birth' => $place_of_birth,
            'year_and_course' => $year_and_course,
            'department' => $department,
            'contact_person' => $contact_person,
            'contact_number' => $contact_number,
            'history_past_illness' => $history_past_illness,
            'present_illness' => $present_illness,
            'operations_hospitalizations' => $operations_hospitalizations,
            'immunization_history' => $immunization_history,
            'social_environmental_history' => $social_environmental_history,
            'ob_gyne_history' => $ob_gyne_history,
            'physical_exam_general_survey' => (int)$physical_exam_general_survey,
            'physical_exam_skin' => (int)$physical_exam_skin,
            'physical_exam_heart' => (int)$physical_exam_heart,
            'physical_exam_chest_lungs' => (int)$physical_exam_chest_lungs,
            'physical_exam_abdomen' => (int)$physical_exam_abdomen,
            'physical_exam_genitourinary' => (int)$physical_exam_genitourinary,
            'physical_exam_musculoskeletal' => (int)$physical_exam_musculoskeletal,
            'note_for_physical_exam' => $note_for_physical_exam,
            'neurological_exam' => $neurological_exam,
            'laboratory_results' => $laboratory_results,
            'assessment' => $assessment,
            'remarks' => $remarks,
            'photo' => $photo,
            'doctor_nurse' => $doctor_nurse,
            'doc_nurse_id' => ($doc_nurse_id !== null && $doc_nurse_id !== '') ? (int)$doc_nurse_id : null,
            'follow_up' => (int)$follow_up,
            'follow_up_date' => $follow_up ? ($follow_up_date ?: null) : null,
            'created_at' => $created_at,
        ];

        $columns = array_keys($data);
        $placeholders = rtrim(str_repeat('?,', count($columns)), ',');
        $sql = 'INSERT INTO checkups (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        // Audit trail log (align with schema: action, timestamp)
        $admin_id = $_POST['admin_id'] ?? 0;
        $log_action = 'Add Patient Checkup';
        $log_details = 'Added checkup for ' . $name;
        if ($admin_id) {
            try {
                $log_stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details, timestamp) VALUES (?, ?, ?, ?)");
                $log_stmt->execute([$admin_id, $log_action, $log_details, date('Y-m-d H:i:s')]);
            } catch (Throwable $e) { /* ignore audit log failure */ }
        }
    $insertId = null;
    try { $insertId = $pdo->lastInsertId(); } catch (Throwable $e) { $insertId = null; }

    // If medicines were provided, attempt to issue them and decrement inventory.
    $issuedSummary = [];
    try {
        $medsRaw = $_POST['medicines'] ?? null;
        if ($medsRaw) {
            // Accept JSON string or array
            if (is_string($medsRaw)) {
                $meds = json_decode($medsRaw, true);
                if (!is_array($meds)) $meds = [];
            } elseif (is_array($medsRaw)) {
                $meds = $medsRaw;
            } else {
                $meds = [];
            }
            // Use a campus default - callers may include campus per item in future
            $defaultCampus = 'Lingayen';
            foreach ($meds as $m) {
                $medicine_id = intval($m['medicine_id'] ?? $m['id'] ?? 0);
                $reqQty = intval($m['qty'] ?? $m['quantity'] ?? 0);
                if (!$medicine_id || $reqQty <= 0) continue;

                // Calculate available stock (prefer batch sum)
                $availStmt = $pdo->prepare("SELECT COALESCE(SUM(qty),0) AS avail FROM medicine_batches WHERE medicine_id = ? AND campus = ?");
                $availStmt->execute([$medicine_id, $defaultCampus]);
                $avail = intval($availStmt->fetchColumn());
                // Fallback to medicines.quantity if batches missing
                if ($avail <= 0) {
                    $mq = $pdo->prepare("SELECT COALESCE(quantity,0) FROM medicines WHERE id = ?");
                    $mq->execute([$medicine_id]);
                    $avail = intval($mq->fetchColumn());
                }
                $toIssue = min($reqQty, $avail);
                if ($toIssue <= 0) {
                    $issuedSummary[] = ['medicine_id' => $medicine_id, 'requested' => $reqQty, 'issued' => 0, 'note' => 'no stock'];
                    continue;
                }

                // Insert issuance record linked to checkup
                $ins = $pdo->prepare("INSERT INTO checkup_medicines (checkup_id, medicine_id, qty, campus) VALUES (?, ?, ?, ?)");
                $ins->execute([$insertId, $medicine_id, $toIssue, $defaultCampus]);
                $cmid = $pdo->lastInsertId();

                // Decrement medicines.quantity safely
                $updMed = $pdo->prepare("UPDATE medicines SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() WHERE id = ?");
                $updMed->execute([$toIssue, $medicine_id]);

                // Consume batches FIFO by nearest expiry
                $need = $toIssue;
                $bstmt = $pdo->prepare("SELECT id, qty FROM medicine_batches WHERE medicine_id = ? AND campus = ? AND qty > 0 ORDER BY COALESCE(expiry_date, '9999-12-31') ASC, id ASC");
                $bstmt->execute([$medicine_id, $defaultCampus]);
                while ($need > 0 && ($batch = $bstmt->fetch(PDO::FETCH_ASSOC))) {
                    $consume = min($need, intval($batch['qty']));
                    if ($consume <= 0) continue;
                    $updateBatch = $pdo->prepare("UPDATE medicine_batches SET qty = qty - ? WHERE id = ?");
                    $updateBatch->execute([$consume, $batch['id']]);
                    $insb = $pdo->prepare("INSERT INTO checkup_medicine_batches (checkup_medicine_id, batch_id, qty) VALUES (?, ?, ?)");
                    $insb->execute([$cmid, $batch['id'], $consume]);
                    $need -= $consume;
                }

                $issuedSummary[] = ['medicine_id' => $medicine_id, 'requested' => $reqQty, 'issued' => $toIssue];
            }
        }
    } catch (Throwable $e) {
        // Non-fatal: report in response
        $issuedSummary[] = ['error' => 'Issuance failed: ' . $e->getMessage()];
    }

    echo json_encode(['success' => true, 'message' => 'Checkup record successfully inserted!', 'insert_id' => $insertId, 'issued' => $issuedSummary]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $_POST]);
    }
    exit;
}

// List checkup records
if ($action === 'list') {
    $student_faculty_id = $_GET['student_faculty_id'] ?? $_POST['student_faculty_id'] ?? '';
    $archivedParam = strtolower(trim($_GET['archived'] ?? '0'));
    // archived filter: '1' => archived only, 'all' => both, default => active only
    $whereArchived = 'c.archived = 0';
    if ($archivedParam === '1' || $archivedParam === 'true' || $archivedParam === 'yes') {
        $whereArchived = 'c.archived = 1';
    } elseif ($archivedParam === 'all' || $archivedParam === 'both' || $archivedParam === 'any') {
        $whereArchived = '1=1';
    }
    // Include role fallback from users table (by SID) for legacy records without role in checkups
    try {
        if ($student_faculty_id) {
            $sql = "SELECT c.*,
                           COALESCE(NULLIF(c.client_type,''), u.client_type) AS client_type_effective,
                           COALESCE(NULLIF(c.gender,''), u.gender) AS gender_effective,
                           COALESCE(NULLIF(c.client_type,''), u.client_type) AS role_effective,
                           COALESCE(
                               NULLIF(c.doctor_nurse, ''), 
                               CASE WHEN dn.name IS NOT NULL AND dn.name <> '' THEN CONCAT(dn.name, CASE WHEN dn.role IS NOT NULL AND dn.role <> '' THEN CONCAT(' (', dn.role, ')') ELSE '' END) ELSE NULL END
                           ) AS doctor_nurse_effective
                    FROM checkups c
                    LEFT JOIN users u ON u.student_faculty_id = c.student_faculty_id
                    LEFT JOIN doc_nurse dn ON dn.id = c.doc_nurse_id
                    WHERE $whereArchived AND c.student_faculty_id = ?
                    ORDER BY c.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$student_faculty_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql = "SELECT c.*,
                           COALESCE(NULLIF(c.client_type,''), u.client_type) AS client_type_effective,
                           COALESCE(NULLIF(c.gender,''), u.gender) AS gender_effective,
                           COALESCE(NULLIF(c.client_type,''), u.client_type) AS role_effective,
                           COALESCE(
                               NULLIF(c.doctor_nurse, ''), 
                               CASE WHEN dn.name IS NOT NULL AND dn.name <> '' THEN CONCAT(dn.name, CASE WHEN dn.role IS NOT NULL AND dn.role <> '' THEN CONCAT(' (', dn.role, ')') ELSE '' END) ELSE NULL END
                           ) AS doctor_nurse_effective
                    FROM checkups c
                    LEFT JOIN users u ON u.student_faculty_id = c.student_faculty_id
                    LEFT JOIN doc_nurse dn ON dn.id = c.doc_nurse_id
                    WHERE $whereArchived
                    ORDER BY c.created_at DESC";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        jsonResponse(['success' => true, 'checkups' => $rows]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'List query failed', 'error' => $e->getMessage(), 'sql' => isset($sql)?$sql:null]);
    }
}

// Debug raw list (no joins, limited columns) for troubleshooting
if ($action === 'debug_list') {
    try {
        $archivedParam = strtolower(trim($_GET['archived'] ?? '0'));
        $whereArchived = 'archived = 0';
        if (in_array($archivedParam, ['1','true','yes'])) $whereArchived = 'archived = 1';
        elseif (in_array($archivedParam, ['all','both','any'])) $whereArchived = '1=1';
    $sql = "SELECT id, student_faculty_id, name, client_type, gender, exam_category, created_at FROM checkups WHERE $whereArchived ORDER BY created_at DESC LIMIT 200";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['success' => true, 'raw' => $rows, 'count' => count($rows)]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Debug list failed', 'error' => $e->getMessage()]);
    }
}

// View single checkup record
if ($action === 'view') {
    $id = $_POST['id'] ?? $_GET['id'] ?? '';
    if (!$id) jsonResponse(['success' => false, 'message' => 'Missing id']);
    // Include role fallback from users table (by SID) for legacy records without role in checkups
    $stmt = $pdo->prepare("SELECT c.*,
                                  COALESCE(NULLIF(c.client_type,''), u.client_type) AS client_type_effective,
                                  COALESCE(NULLIF(c.gender,''), u.gender) AS gender_effective,
                                  COALESCE(NULLIF(c.client_type,''), u.client_type) AS role_effective,
                                  COALESCE(
                                      NULLIF(c.doctor_nurse, ''),
                                      CASE WHEN dn.name IS NOT NULL AND dn.name <> '' THEN CONCAT(dn.name, CASE WHEN dn.role IS NOT NULL AND dn.role <> '' THEN CONCAT(' (', dn.role, ')') ELSE '' END) ELSE NULL END
                                  ) AS doctor_nurse_effective
                           FROM checkups c
                           LEFT JOIN users u ON u.student_faculty_id = c.student_faculty_id
                           LEFT JOIN doc_nurse dn ON dn.id = c.doc_nurse_id
                           WHERE c.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) jsonResponse(['success' => true, 'patient' => $row]);
    else jsonResponse(['success' => false, 'message' => 'Not found']);
}

// Update checkup record
if ($action === 'update') {
    // Basic role hardening: if called with admin_id, block clinical modification
    if (!empty($_POST['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Admins cannot update clinical records.']);
        exit;
    }
    $id = $_POST['id'] ?? $_GET['id'] ?? '';
    $student_faculty_id = $_POST['student_faculty_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $age = $_POST['age'] ?? '';
    $address = $_POST['address'] ?? '';
    $civil_status = $_POST['civil_status'] ?? '';
    $nationality = $_POST['nationality'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $place_of_birth = $_POST['place_of_birth'] ?? '';
    $year_and_course = $_POST['year_and_course'] ?? '';
    $department = $_POST['department'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $history_past_illness = $_POST['history_past_illness'] ?? '';
    $present_illness = $_POST['present_illness'] ?? '';
    $operations_hospitalizations = $_POST['operations_hospitalizations'] ?? '';
    $immunization_history = $_POST['immunization_history'] ?? '';
    $social_environmental_history = $_POST['social_environmental_history'] ?? '';
    $ob_gyne_history = $_POST['ob_gyne_history'] ?? '';
    $physical_exam_general_survey = isset($_POST['physical_exam_general_survey']) ? 1 : 0;
    $physical_exam_skin = isset($_POST['physical_exam_skin']) ? 1 : 0;
    $physical_exam_heart = isset($_POST['physical_exam_heart']) ? 1 : 0;
    $physical_exam_chest_lungs = isset($_POST['physical_exam_chest_lungs']) ? 1 : 0;
    $physical_exam_abdomen = isset($_POST['physical_exam_abdomen']) ? 1 : 0;
    $physical_exam_genitourinary = isset($_POST['physical_exam_genitourinary']) ? 1 : 0;
    $physical_exam_musculoskeletal = isset($_POST['physical_exam_musculoskeletal']) ? 1 : 0;
    $note_for_physical_exam = $_POST['note_for_physical_exam'] ?? '';
    $neurological_exam = $_POST['neurological_exam'] ?? '';
    $laboratory_results = $_POST['laboratory_results'] ?? '';
    $assessment = $_POST['assessment'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    $photo = $_POST['photo'] ?? '';

    if (!$id || !$student_faculty_id) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields (id, student_faculty_id)']);
        exit;
    }

    try {
    // Get checkup details before update
        $checkup_stmt = $pdo->prepare("SELECT * FROM checkups WHERE id = ?");
        $checkup_stmt->execute([$id]);
        $before = $checkup_stmt->fetch(PDO::FETCH_ASSOC);
    // Preserve fields not provided in POST
    // Preserve client_type/legacy role
    $client_type = array_key_exists('client_type', $_POST) ? ($_POST['client_type'] ?? null) : (array_key_exists('role', $_POST) ? ($_POST['role'] ?? null) : ($before['client_type'] ?? ($before['role'] ?? null)));
    $exam_category = array_key_exists('exam_category', $_POST) ? ($_POST['exam_category'] ?? '') : ($before['exam_category'] ?? '');
    $gender = array_key_exists('gender', $_POST) ? ($_POST['gender'] ?? '') : ($before['gender'] ?? '');
    $department = array_key_exists('department', $_POST) ? ($_POST['department'] ?? '') : ($before['department'] ?? '');
    $doctor_nurse = array_key_exists('doctor_nurse', $_POST) ? ($_POST['doctor_nurse'] ?? null) : ($before['doctor_nurse'] ?? null);
    $doc_nurse_id = array_key_exists('doc_nurse_id', $_POST) ? ($_POST['doc_nurse_id'] ?? null) : ($before['doc_nurse_id'] ?? null);
    $follow_up = array_key_exists('follow_up', $_POST) ? (int)!!$_POST['follow_up'] : (int)($before['follow_up'] ?? 0);
    $follow_up_date = array_key_exists('follow_up_date', $_POST) ? ($_POST['follow_up_date'] ?: null) : ($before['follow_up_date'] ?? null);
    $note_for_physical_exam = array_key_exists('note_for_physical_exam', $_POST) ? ($_POST['note_for_physical_exam'] ?? '') : ($before['note_for_physical_exam'] ?? '');
        // Resolve performer name on update if missing and id is provided
        if ((!$doctor_nurse || $doctor_nurse === '') && $doc_nurse_id) {
            try {
                $dnStmt = $pdo->prepare('SELECT name, role FROM doc_nurse WHERE id = ?');
                $dnStmt->execute([$doc_nurse_id]);
                $dn = $dnStmt->fetch(PDO::FETCH_ASSOC);
                if ($dn && ($dn['name'] ?? '')) {
                    $doctor_nurse = $dn['role'] ? ($dn['name'] . ' (' . $dn['role'] . ')') : $dn['name'];
                }
            } catch (Throwable $e) { /* ignore */ }
        }

        $stmt = $pdo->prepare("UPDATE checkups SET
            student_faculty_id=?, client_type=?, exam_category=?, name=?, age=?, address=?, civil_status=?, nationality=?, religion=?, gender=?, date_of_birth=?, place_of_birth=?, year_and_course=?, department=?,
            contact_person=?, contact_number=?, history_past_illness=?, present_illness=?, operations_hospitalizations=?, immunization_history=?,
            social_environmental_history=?, ob_gyne_history=?, physical_exam_general_survey=?, physical_exam_skin=?, physical_exam_heart=?,
            physical_exam_chest_lungs=?, physical_exam_abdomen=?, physical_exam_genitourinary=?, physical_exam_musculoskeletal=?, note_for_physical_exam=?,
            neurological_exam=?, laboratory_results=?, assessment=?, remarks=?, photo=?, doctor_nurse=?, doc_nurse_id=?, follow_up=?, follow_up_date=?
            WHERE id=?");
        $stmt->execute([
            $student_faculty_id, $client_type, $exam_category, $name, $age, $address, $civil_status, $nationality, $religion, $gender, $date_of_birth, $place_of_birth, $year_and_course, $department,
            $contact_person, $contact_number, $history_past_illness, $present_illness, $operations_hospitalizations, $immunization_history,
            $social_environmental_history, $ob_gyne_history, $physical_exam_general_survey, $physical_exam_skin, $physical_exam_heart,
            $physical_exam_chest_lungs, $physical_exam_abdomen, $physical_exam_genitourinary, $physical_exam_musculoskeletal, $note_for_physical_exam,
            $neurological_exam, $laboratory_results, $assessment, $remarks, $photo, $doctor_nurse, ($doc_nurse_id !== null && $doc_nurse_id !== '' ? (int)$doc_nurse_id : null), $follow_up, ($follow_up ? ($follow_up_date ?: null) : null), $id
        ]);
    // Audit trail log
    $admin_id = $_POST['admin_id'] ?? 0;
    $log_action = 'Update Patient Checkup';
    $log_details = 'Before: ' . ($before ? json_encode($before) : 'ID: ' . $id) . ' | After: ' . json_encode([
            'student_faculty_id' => $student_faculty_id,
            'client_type' => $client_type,
            'exam_category' => $exam_category,
            'gender' => $gender,
            'name' => $name,
            'age' => $age,
            'address' => $address,
            'civil_status' => $civil_status,
            'nationality' => $nationality,
            'religion' => $religion,
            'date_of_birth' => $date_of_birth,
            'place_of_birth' => $place_of_birth,
            'year_and_course' => $year_and_course,
            'contact_person' => $contact_person,
            'contact_number' => $contact_number,
            'history_past_illness' => $history_past_illness,
            'present_illness' => $present_illness,
            'operations_hospitalizations' => $operations_hospitalizations,
            'immunization_history' => $immunization_history,
            'social_environmental_history' => $social_environmental_history,
            'ob_gyne_history' => $ob_gyne_history,
            'physical_exam_general_survey' => $physical_exam_general_survey,
            'physical_exam_skin' => $physical_exam_skin,
            'physical_exam_heart' => $physical_exam_heart,
            'physical_exam_chest_lungs' => $physical_exam_chest_lungs,
            'physical_exam_abdomen' => $physical_exam_abdomen,
            'physical_exam_genitourinary' => $physical_exam_genitourinary,
            'physical_exam_musculoskeletal' => $physical_exam_musculoskeletal,
            'neurological_exam' => $neurological_exam,
            'laboratory_results' => $laboratory_results,
            'assessment' => $assessment,
            'remarks' => $remarks,
            'photo' => $photo
        ]);
        if ($admin_id) {
            try {
                $log_stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details, timestamp) VALUES (?, ?, ?, ?)");
                $log_stmt->execute([$admin_id, $log_action, $log_details, date('Y-m-d H:i:s')]);
            } catch (Throwable $e) { /* ignore audit log failure */ }
        }
        // After updating checkup, handle medicines adjustments if provided
        $issuedSummary = [];
        try {
            $pdo->beginTransaction();
            // Restore previous issued quantities back to medicines.quantity (batch restoration not attempted)
            $prevStmt = $pdo->prepare('SELECT medicine_id, SUM(qty) AS total FROM checkup_medicines WHERE checkup_id = ? GROUP BY medicine_id');
            $prevStmt->execute([$id]);
            $prevRows = $prevStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($prevRows as $pr) {
                $pmid = intval($pr['medicine_id']);
                $pqty = intval($pr['total']);
                if ($pqty > 0) {
                    // Add back to medicines.quantity
                    $up = $pdo->prepare('UPDATE medicines SET quantity = GREATEST(COALESCE(quantity,0) + ?, 0) WHERE id = ?');
                    $up->execute([$pqty, $pmid]);
                }
            }
            // Remove old issuance records and batches
            $delBatches = $pdo->prepare('DELETE b FROM checkup_medicine_batches b JOIN checkup_medicines cm ON cm.id = b.checkup_medicine_id WHERE cm.checkup_id = ?');
            $delBatches->execute([$id]);
            $delMeds = $pdo->prepare('DELETE FROM checkup_medicines WHERE checkup_id = ?');
            $delMeds->execute([$id]);

            // Process new medicines if provided (same logic as add)
            $medsRaw = $_POST['medicines'] ?? null;
            if ($medsRaw) {
                if (is_string($medsRaw)) {
                    $meds = json_decode($medsRaw, true);
                    if (!is_array($meds)) $meds = [];
                } elseif (is_array($medsRaw)) {
                    $meds = $medsRaw;
                } else {
                    $meds = [];
                }
                $defaultCampus = 'Lingayen';
                foreach ($meds as $m) {
                    $medicine_id = intval($m['medicine_id'] ?? $m['id'] ?? 0);
                    $reqQty = intval($m['qty'] ?? $m['quantity'] ?? 0);
                    if (!$medicine_id || $reqQty <= 0) continue;

                    // Calculate available stock (prefer batch sum)
                    $availStmt = $pdo->prepare("SELECT COALESCE(SUM(qty),0) AS avail FROM medicine_batches WHERE medicine_id = ? AND campus = ?");
                    $availStmt->execute([$medicine_id, $defaultCampus]);
                    $avail = intval($availStmt->fetchColumn());
                    if ($avail <= 0) {
                        $mq = $pdo->prepare("SELECT COALESCE(quantity,0) FROM medicines WHERE id = ?");
                        $mq->execute([$medicine_id]);
                        $avail = intval($mq->fetchColumn());
                    }
                    $toIssue = min($reqQty, $avail);
                    if ($toIssue <= 0) {
                        $issuedSummary[] = ['medicine_id' => $medicine_id, 'requested' => $reqQty, 'issued' => 0, 'note' => 'no stock'];
                        continue;
                    }

                    // Insert issuance record linked to checkup
                    $ins = $pdo->prepare("INSERT INTO checkup_medicines (checkup_id, medicine_id, qty, campus) VALUES (?, ?, ?, ?)");
                    $ins->execute([$id, $medicine_id, $toIssue, $defaultCampus]);
                    $cmid = $pdo->lastInsertId();

                    // Decrement medicines.quantity safely
                    $updMed = $pdo->prepare("UPDATE medicines SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() WHERE id = ?");
                    $updMed->execute([$toIssue, $medicine_id]);

                    // Consume batches FIFO by nearest expiry
                    $need = $toIssue;
                    $bstmt = $pdo->prepare("SELECT id, qty FROM medicine_batches WHERE medicine_id = ? AND campus = ? AND qty > 0 ORDER BY COALESCE(expiry_date, '9999-12-31') ASC, id ASC");
                    $bstmt->execute([$medicine_id, $defaultCampus]);
                    while ($need > 0 && ($batch = $bstmt->fetch(PDO::FETCH_ASSOC))) {
                        $consume = min($need, intval($batch['qty']));
                        if ($consume <= 0) continue;
                        $updateBatch = $pdo->prepare("UPDATE medicine_batches SET qty = qty - ? WHERE id = ?");
                        $updateBatch->execute([$consume, $batch['id']]);
                        $insb = $pdo->prepare("INSERT INTO checkup_medicine_batches (checkup_medicine_id, batch_id, qty) VALUES (?, ?, ?)");
                        $insb->execute([$cmid, $batch['id'], $consume]);
                        $need -= $consume;
                    }

                    $issuedSummary[] = ['medicine_id' => $medicine_id, 'requested' => $reqQty, 'issued' => $toIssue];
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
            // Non-fatal: include error in response
            $issuedSummary[] = ['error' => 'Issuance update failed: ' . $e->getMessage()];
        }

        echo json_encode(['success' => true, 'issued' => $issuedSummary]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Delete checkup record
if ($action === 'delete') {
    // Admins may permanently delete only archived records; doc_nurse can delete own records (legacy) without admin_id
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) jsonResponse(['success' => false, 'message' => 'Missing id']);
    // Get checkup details before delete
    $checkup_stmt = $pdo->prepare("SELECT * FROM checkups WHERE id = ?");
    $checkup_stmt->execute([$id]);
    $before = $checkup_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) jsonResponse(['success' => false, 'message' => 'Not found']);
    $admin_id = $_POST['admin_id'] ?? 0;
    if ($admin_id) {
        // Require archived=1 to allow admin deletion
        $arch = isset($before['archived']) ? intval($before['archived']) : 0;
        if ($arch !== 1) {
            jsonResponse(['success' => false, 'message' => 'Please archive this record first before permanent deletion.']);
        }
    }
    $stmt = $pdo->prepare("DELETE FROM checkups WHERE id = ?");
    $stmt->execute([$id]);
    // Audit trail log
    $log_action = 'Delete Patient Checkup';
    $log_details = 'Deleted checkup record: ' . ($before ? json_encode($before) : 'ID: ' . $id);
    if ($admin_id) {
        try {
            $log_stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details, timestamp) VALUES (?, ?, ?, ?)");
            $log_stmt->execute([$admin_id, $log_action, $log_details, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { /* ignore */ }
    }
    jsonResponse(['success' => true, 'message' => 'Patient deleted']);
}

// Soft archive a checkup record
if ($action === 'archive') {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    $student_faculty_id = $_GET['student_faculty_id'] ?? $_POST['student_faculty_id'] ?? '';
    if (!$id && !$student_faculty_id) jsonResponse(['success' => false, 'message' => 'Missing id or student_faculty_id']);

    $admin_id = $_POST['admin_id'] ?? 0;
    $log_action = 'Archive Patient Checkup';

    // If student_faculty_id provided, archive all records for that student (soft-archive by SID)
    if ($student_faculty_id) {
        try {
            // Fetch affected rows for audit
            $stmtBefore = $pdo->prepare("SELECT * FROM checkups WHERE student_faculty_id = ?");
            $stmtBefore->execute([$student_faculty_id]);
            $rowsBefore = $stmtBefore->fetchAll(PDO::FETCH_ASSOC);

            $upd = $pdo->prepare("UPDATE checkups SET archived = 1 WHERE student_faculty_id = ?");
            $upd->execute([$student_faculty_id]);
            $count = $upd->rowCount();

            $log_details = 'Archived checkup records for student_faculty_id=' . $student_faculty_id . ' Count=' . intval($count);
            if ($admin_id) {
                try {
                    $log_stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details, timestamp) VALUES (?, ?, ?, ?)");
                    $log_stmt->execute([$admin_id, $log_action, $log_details, date('Y-m-d H:i:s')]);
                } catch (Throwable $e) { /* ignore */ }
            }
            jsonResponse(['success' => true, 'message' => 'Patient records archived', 'archived_count' => intval($count), 'affected' => $rowsBefore]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => 'Archive by student failed', 'error' => $e->getMessage()]);
        }
    }

    // Fallback: archive single checkup by id
    try {
        // Get checkup details before archive
        $checkup_stmt = $pdo->prepare("SELECT * FROM checkups WHERE id = ?");
        $checkup_stmt->execute([$id]);
        $before = $checkup_stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("UPDATE checkups SET archived = 1 WHERE id = ?");
        $stmt->execute([$id]);

        $log_details = 'Archived checkup record: ' . ($before ? json_encode($before) : 'ID: ' . $id);
        if ($admin_id) {
            try {
                $log_stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details, timestamp) VALUES (?, ?, ?, ?)");
                $log_stmt->execute([$admin_id, $log_action, $log_details, date('Y-m-d H:i:s')]);
            } catch (Throwable $e) { /* ignore */ }
        }
        jsonResponse(['success' => true, 'message' => 'Patient archived']);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Archive failed', 'error' => $e->getMessage()]);
    }
}

// Un-archive a checkup record
if ($action === 'unarchive') {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) jsonResponse(['success' => false, 'message' => 'Missing id']);
    // Get checkup details before unarchive
    $checkup_stmt = $pdo->prepare("SELECT * FROM checkups WHERE id = ?");
    $checkup_stmt->execute([$id]);
    $before = $checkup_stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("UPDATE checkups SET archived = 0 WHERE id = ?");
    $stmt->execute([$id]);
    // Audit trail log
    $admin_id = $_POST['admin_id'] ?? 0;
    $log_action = 'Unarchive Patient Checkup';
    $log_details = 'Unarchived checkup record: ' . ($before ? json_encode($before) : 'ID: ' . $id);
    if ($admin_id) {
        try {
            $log_stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details, timestamp) VALUES (?, ?, ?, ?) ");
            $log_stmt->execute([$admin_id, $log_action, $log_details, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { /* ignore */ }
    }
    jsonResponse(['success' => true, 'message' => 'Patient unarchived']);
}

if ($action === 'count') {
	$stmt = $pdo->query("SELECT COUNT(*) AS count FROM checkups");
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	echo json_encode(['count' => intval($row['count'])]);
	exit;
}

jsonResponse(['error' => 'Invalid action']);
