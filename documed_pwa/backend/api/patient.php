<?php

require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Ensure follow-up columns exist (best-effort, non-breaking)
try { $pdo->exec("ALTER TABLE patients ADD COLUMN follow_up TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE patients ADD COLUMN follow_up_date DATE NULL"); } catch (Throwable $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE patients MODIFY COLUMN date_of_examination DATE NULL"); } catch (Throwable $e) { /* ignore */ }
try { $pdo->exec("ALTER TABLE patients MODIFY COLUMN last_visit DATETIME NULL"); } catch (Throwable $e) { /* ignore */ }


// Normalize bad date values (empty string -> NULL) for Railway/MySQL safety
try {
    $pdo->exec("UPDATE patients SET date_of_examination = NULL WHERE date_of_examination = ''");
    $pdo->exec("UPDATE patients SET last_visit = NULL WHERE last_visit = ''");
} catch (Throwable $e) {
    // non-fatal; just avoid breaking API
}

// Count patients for dashboard/stat card
if ($action === 'count') {
    $stmt = $pdo->query("SELECT COUNT(*) AS count FROM patients");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['count' => intval($row['count'])]);
    exit;
}

// List patients with all standardized fields (optionally filter by School ID via user_id)
if ($action === 'list') {
    $user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? '';
    if ($user_id !== '') {
        // NOTE: use date-safe expressions; never let MySQL implicitly cast bad strings to DATE
        $stmt = $pdo->prepare("SELECT p.*, CONCAT(u.last_name, ', ', u.first_name, ' ', u.middle_initial) AS user_name, u.email
                               FROM patients p LEFT JOIN users u ON p.user_id = u.student_faculty_id
                               WHERE p.user_id = ?
                               ORDER BY COALESCE(p.date_of_examination, p.last_visit) DESC, p.id DESC");
        $stmt->execute([$user_id]);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query("SELECT p.*, CONCAT(u.last_name, ', ', u.first_name, ' ', u.middle_initial) AS user_name, u.email FROM patients p LEFT JOIN users u ON p.user_id = u.student_faculty_id");
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['patients' => $patients]);
    exit;
}

// Add patient record with standardized fields
if ($action === 'add') {
    $user_id = $_POST['user_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $address = $_POST['address'] ?? '';
    $age_sex = $_POST['age_sex'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $date_of_examination = $_POST['date_of_examination'] ?? '';
    $chief_complaint = $_POST['chief_complaint'] ?? '';
    $bp = $_POST['bp'] ?? '';
    $cr = $_POST['cr'] ?? '';
    $rr = $_POST['rr'] ?? '';
    $temp = $_POST['temp'] ?? '';
    $wt = $_POST['wt'] ?? '';
    $ht = $_POST['ht'] ?? '';
    $bmi = $_POST['bmi'] ?? '';
    $impression = $_POST['impression'] ?? '';
    $treatment = $_POST['treatment'] ?? '';
    $nurses_notes = $_POST['nurses_notes'] ?? '';
    $last_visit = $_POST['last_visit'] ?? date('Y-m-d H:i:s');
    $follow_up = isset($_POST['follow_up']) ? (int)!!$_POST['follow_up'] : 0;
    $follow_up_date = $_POST['follow_up_date'] ?? null;
    // reCAPTCHA removed

    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Missing student_faculty_id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO patients (user_id, name, address, age_sex, contact_number, date_of_examination, chief_complaint, bp, cr, rr, temp, wt, ht, bmi, impression, treatment, nurses_notes, last_visit, follow_up, follow_up_date) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id, $name, $address, $age_sex, $contact_number, $date_of_examination,
            $chief_complaint, $bp, $cr, $rr, $temp, $wt, $ht, $bmi, $impression, $treatment, $nurses_notes, $last_visit, (int)$follow_up, ($follow_up ? ($follow_up_date ?: null) : null)
        ]);
        $insertId = null;
        try { $insertId = $pdo->lastInsertId(); } catch (Throwable $e) { $insertId = null; }
        // Best-effort: mark any pending follow-up on checkups as completed for this SID
        try { $pdo->exec("ALTER TABLE checkups ADD COLUMN follow_up TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ignore */ }
        try { $pdo->exec("ALTER TABLE checkups ADD COLUMN follow_up_date DATE NULL"); } catch (Throwable $e) { /* ignore */ }
        try {
            $doneDate = $date_of_examination ?: date('Y-m-d');
            $upd = $pdo->prepare("UPDATE checkups SET follow_up = 0, follow_up_date = NULL
                                  WHERE student_faculty_id = ? AND follow_up = 1 AND (follow_up_date IS NULL OR follow_up_date <= ?)");
            $upd->execute([$user_id, $doneDate]);
        } catch (Throwable $e) { /* non-fatal if update fails */ }
        echo json_encode(['success' => true, 'message' => 'Follow-up record successfully inserted!', 'insert_id' => $insertId]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $_POST]);
    }
    exit;
}

// Update patient record with standardized fields
if ($action === 'update') {
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'] ?? '';
    $address = $_POST['address'] ?? '';
    $age_sex = $_POST['age_sex'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $date_of_examination = $_POST['date_of_examination'] ?? '';
    $chief_complaint = $_POST['chief_complaint'] ?? '';
    $bp = $_POST['bp'] ?? '';
    $cr = $_POST['cr'] ?? '';
    $rr = $_POST['rr'] ?? '';
    $temp = $_POST['temp'] ?? '';
    $wt = $_POST['wt'] ?? '';
    $ht = $_POST['ht'] ?? '';
    $bmi = $_POST['bmi'] ?? '';
    $impression = $_POST['impression'] ?? '';
    $treatment = $_POST['treatment'] ?? '';
    $nurses_notes = $_POST['nurses_notes'] ?? '';
    $last_visit = $_POST['last_visit'] ?? date('Y-m-d H:i:s');
    $follow_up = isset($_POST['follow_up']) ? (int)!!$_POST['follow_up'] : 0;
    $follow_up_date = $_POST['follow_up_date'] ?? null;
    if (!$id) {
        echo json_encode(['error' => 'Missing patient id']);
        exit;
    }
        $stmt = $pdo->prepare("UPDATE patients SET name=?, address=?, age_sex=?, contact_number=?, date_of_examination=?, chief_complaint=?, bp=?, cr=?, rr=?, temp=?, wt=?, ht=?, bmi=?, impression=?, treatment=?, nurses_notes=?, last_visit=?, follow_up=?, follow_up_date=? WHERE id=?");
    $stmt->execute([$name, $address, $age_sex, $contact_number, $date_of_examination, $chief_complaint, $bp, $cr, $rr, $temp, $wt, $ht, $bmi, $impression, $treatment, $nurses_notes, $last_visit, (int)$follow_up, ($follow_up ? ($follow_up_date ?: null) : null), $id]);
    echo json_encode(['success' => true]);
    exit;
}

// Delete patient record
if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    if (!$id) {
        echo json_encode(['error' => 'Missing patient id']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM patients WHERE id=?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}



echo json_encode(['error' => 'Invalid action']);
