<?php
// Enable session for doc/nurse/dentist login persistence
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function col_exists($pdo, $table, $col) {
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!$db) return false;
        $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND UPPER(COLUMN_NAME) = UPPER(?)");
        $q->execute([$db, $table, $col]);
        return intval($q->fetchColumn()) > 0;
    } catch (Throwable $e) { return false; }
}

function resolve_column_name($pdo, $table, $candidates) {
    // Return the actual column name from INFORMATION_SCHEMA matching any candidate (case-insensitive)
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!$db) return null;
        foreach ($candidates as $cand) {
            $q = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND UPPER(COLUMN_NAME) = UPPER(?) LIMIT 1");
            $q->execute([$db, $table, $cand]);
            $col = $q->fetchColumn();
            if ($col) return $col;
        }
    } catch (Throwable $e) { /* ignore */ }
    return null;
}

function audit($pdo, $admin_id, $action_txt, $details) {
    try {
        $audit = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)");
        $audit->execute([$admin_id, $action_txt, $details]);
    } catch (Throwable $e) { /* ignore */ }
}

if ($action === 'list') {
    $q = trim($_GET['q'] ?? '');
    $role = trim($_GET['role'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $hasStatus = col_exists($pdo, 'users', 'status');
    // Always use client_type for role filtering
    $roleCol = 'client_type';
    $sql = "SELECT *, {$roleCol} AS resolved_role FROM users WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (CONCAT_WS(' ', last_name, first_name, middle_initial) LIKE ? OR email LIKE ? OR student_faculty_id LIKE ?)";
        $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    // Case-insensitive role and status filters with legacy support
    if ($role !== '' && strcasecmp($role,'All') !== 0) {
        $r = strtolower(trim($role));
        if ($r === 'student') {
            $sql .= " AND (LOWER(TRIM(COALESCE(client_type,'')))='student' OR client_type IS NULL OR client_type='' OR (year_course IS NOT NULL AND TRIM(year_course)<>''))";
        } elseif ($r === 'teacher') {
            $sql .= " AND (LOWER(TRIM(COALESCE(client_type,'')))='teacher' OR (department IS NOT NULL AND TRIM(department)<>''))";
        } elseif (in_array($r, ['non-teaching','nonteaching','non_teaching'], true)) {
            $sql .= " AND (LOWER(TRIM(COALESCE(client_type,''))) IN ('non-teaching','nonteaching','non_teaching'))";
        } else {
            $sql .= " AND LOWER(TRIM(client_type)) = ?"; $params[] = $r;
        }
    }
    if ($hasStatus && $status !== '' && strcasecmp($status,'All') !== 0) {
        $statusLower = strtolower(trim($status));
        if ($statusLower === 'archived') { $statusLower = 'inactive'; }
        $sql .= " AND LOWER(status) = ?"; $params[] = $statusLower;
    }
    $sql .= " ORDER BY last_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Normalize role labels for UI consistency
    foreach ($rows as &$r) {
        // Normalize resolved_role then expose as role for UI
        $baseRole = isset($r['resolved_role']) ? $r['resolved_role'] : ($r['role'] ?? '');
        if ($baseRole !== '') {
            $rl = strtolower(trim($baseRole));
            if ($rl === 'student') $r['role'] = 'Student';
            else if ($rl === 'teacher') $r['role'] = 'Teacher';
            else if ($rl === 'non-teaching' || $rl === 'nonteaching' || $rl === 'non_teaching') $r['role'] = 'Non-Teaching';
            else $r['role'] = ucfirst($rl);
        }
    }
    echo json_encode(['success' => true, 'users' => $rows, 'hasStatus' => $hasStatus]);
    exit;
}

// Get Doc/Nurse profile by id
if ($action === 'get_doc_nurse_profile') {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    // Resolve photo column name
    $photoCol = resolve_column_name($pdo, 'doc_nurse', ['photo','dn_photo']);
    $photoSel = $photoCol ? (", $photoCol AS dn_photo") : '';
    $stmt = $pdo->prepare('SELECT id, name, email, role'. $photoSel . ' FROM doc_nurse WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { echo json_encode(['success'=>true,'profile'=>$row]); } else { echo json_encode(['success'=>false,'message'=>'Profile not found']); }
    exit;
}

// Update Doc/Nurse profile (name, email, role, photo)
if ($action === 'update_doc_nurse') {
    $id = $_POST['id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    if ($id === '') { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    if ($name === '' || $email === '') { echo json_encode(['success'=>false,'message'=>'Name and email are required']); exit; }
    // Normalize role label
    $r = strtolower($role);
    if ($r !== '') {
        if (strpos($r,'doctor')!==false) $role='Doctor';
        else if (strpos($r,'dentist')!==false) $role='Dentist';
        else if (strpos($r,'nurse')!==false) $role='Nurse';
    }
    // Resolve photo column and handle upload
    $photoCol = resolve_column_name($pdo, 'doc_nurse', ['photo','dn_photo']);
    $photoPath = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['photo']['tmp_name'];
        $nameF = basename($_FILES['photo']['name']);
        $targetDir = __DIR__ . '/../../frontend/assets/images/';
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
        $dest = $targetDir . uniqid('dn_') . '_' . $nameF;
        if (@move_uploaded_file($tmp, $dest)) {
            $photoPath = str_replace(__DIR__ . '/../../frontend/', '../', $dest);
        }
    }
    // Build update
    if ($photoCol && $photoPath) {
        $stmt = $pdo->prepare("UPDATE doc_nurse SET name=?, email=?, role=?, $photoCol=? WHERE id=?");
        $stmt->execute([$name,$email,$role,$photoPath,$id]);
    } else {
        $stmt = $pdo->prepare("UPDATE doc_nurse SET name=?, email=?, role=? WHERE id=?");
        $stmt->execute([$name,$email,$role,$id]);
    }
    // Return fresh profile
    $photoSel = $photoCol ? (", $photoCol AS dn_photo") : '';
    $q = $pdo->prepare('SELECT id, name, email, role' . $photoSel . ' FROM doc_nurse WHERE id=?');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success'=>true,'profile'=>$row]);
    exit;
}

// Change Doc/Nurse password
if ($action === 'change_password') {
    $id = $_POST['id'] ?? '';
    $old = $_POST['oldPassword'] ?? '';
    $new = $_POST['newPassword'] ?? '';
    if ($id === '' || $old === '' || $new === '') { echo json_encode(['success'=>false,'message'=>'Missing required fields']); exit; }
    if (strlen($new) < 6) { echo json_encode(['success'=>false,'message'=>'New password must be at least 6 characters']); exit; }
    $stmt = $pdo->prepare('SELECT id,password FROM doc_nurse WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($old, $row['password'])) { echo json_encode(['success'=>false,'message'=>'Current password is incorrect']); exit; }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $u = $pdo->prepare('UPDATE doc_nurse SET password=? WHERE id=?');
    $u->execute([$hash,$id]);
    echo json_encode(['success'=>true]);
    exit;
}

// List admin accounts (name, email); no status column
if ($action === 'list_admins') {
    $q = trim($_GET['q'] ?? '');
    $schoolCol = resolve_column_name($pdo, 'admins', ['school_id']);
    $hasSchool = $schoolCol !== null;
    // Ensure status column exists
    if (!col_exists($pdo, 'admins', 'status')) {
        try { $pdo->exec("ALTER TABLE admins ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) { /* ignore */ }
    }
    $sql = "SELECT id, " . ($hasSchool ? ($schoolCol . " AS school_id, ") : "") . "name, email, 'Admin' AS role, status FROM admins WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (name LIKE ? OR email LIKE ?)";
        $like = "%$q%"; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'users' => $rows, 'hasStatus' => true]);
    exit;
}

// List doc/nurse/dentist accounts
if ($action === 'list_doc_nurse') {
    $q = trim($_GET['q'] ?? '');
    $role = trim($_GET['role'] ?? '');
    $schoolCol = resolve_column_name($pdo, 'doc_nurse', ['school_id','school_ID']);
    $hasSchool = $schoolCol !== null;
    // Ensure status column exists
    if (!col_exists($pdo, 'doc_nurse', 'status')) {
        try { $pdo->exec("ALTER TABLE doc_nurse ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) { /* ignore */ }
    }
    $sql = "SELECT id, " . ($hasSchool ? ($schoolCol . " AS school_id, ") : "") . "name, email, role, status FROM doc_nurse WHERE 1=1";
    $params = [];
    if ($role !== '') {
        $roleLower = strtolower($role);
        if ($roleLower === 'doctor') {
            $sql .= " AND LOWER(role) LIKE ?"; $params[] = '%doctor%';
        } elseif ($roleLower === 'dentist') {
            $sql .= " AND LOWER(role) LIKE ?"; $params[] = '%dentist%';
        } elseif ($roleLower === 'nurse') {
            $sql .= " AND LOWER(role) LIKE ?"; $params[] = '%nurse%';
        }
    }
    if ($q !== '') {
        $sql .= " AND (name LIKE ? OR email LIKE ?)";
        $like = "%$q%"; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Normalize role case/labels to match UI options
    foreach ($rows as &$r) {
        $rl = strtolower(trim($r['role']));
        if (strpos($rl, 'doctor') !== false) {
            $r['role'] = 'Doctor';
        } elseif (strpos($rl, 'dentist') !== false) {
            $r['role'] = 'Dentist';
        } elseif (strpos($rl, 'nurse') !== false) {
            $r['role'] = 'Nurse';
        } else {
            $r['role'] = ucfirst($rl);
        }
    }
    echo json_encode(['success' => true, 'users' => $rows, 'hasStatus' => true]);
    exit;
}

// Toggle status for doc_nurse
if ($action === 'toggle_status_doc_nurse') {
    $id = $_POST['id'] ?? '';
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status, ['active','inactive'], true)) {
        echo json_encode(['success' => false, 'message' => 'Missing/invalid fields']); exit;
    }
    if (!col_exists($pdo, 'doc_nurse', 'status')) {
        try { $pdo->exec("ALTER TABLE doc_nurse ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) { /* ignore */ }
    }
    $stmt = $pdo->prepare('UPDATE doc_nurse SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    echo json_encode(['success' => true]); exit;
}

// Toggle status for admins
if ($action === 'toggle_status_admin') {
    $id = $_POST['id'] ?? '';
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status, ['active','inactive'], true)) {
        echo json_encode(['success' => false, 'message' => 'Missing/invalid fields']); exit;
    }
    if (!col_exists($pdo, 'admins', 'status')) {
        try { $pdo->exec("ALTER TABLE admins ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) { /* ignore */ }
    }
    $stmt = $pdo->prepare('UPDATE admins SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    echo json_encode(['success' => true]); exit;
}

// Reset password for doc_nurse
if ($action === 'reset_password_doc_nurse') {
    $id = $_POST['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $len = strlen($alphabet); $pwd = '';
    for ($i=0;$i<10;$i++) { $pwd .= $alphabet[random_int(0,$len-1)]; }
    $hashed = password_hash($pwd, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE doc_nurse SET password = ? WHERE id = ?');
    $stmt->execute([$hashed, $id]);
    echo json_encode(['success' => true, 'new_password' => $pwd]); exit;
}

// Reset password for admin
if ($action === 'reset_password_admin') {
    $id = $_POST['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $len = strlen($alphabet); $pwd = '';
    for ($i=0;$i<10;$i++) { $pwd .= $alphabet[random_int(0,$len-1)]; }
    $hashed = password_hash($pwd, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?');
    $stmt->execute([$hashed, $id]);
    echo json_encode(['success' => true, 'new_password' => $pwd]); exit;
}

// Simple reset: Doc/Nurse by email or School ID
if ($action === 'reset_password_doc_nurse_by_identifier') {
    $identifier = trim($_POST['identifier'] ?? '');
    if ($identifier === '') { echo json_encode(['success' => false, 'message' => 'Missing email or School ID']); exit; }
    // Look up doc_nurse by email OR school_id (if column exists)
    $schoolCol = resolve_column_name($pdo, 'doc_nurse', ['school_id','school_ID']);
    if ($schoolCol) {
        $stmt = $pdo->prepare("SELECT id FROM doc_nurse WHERE LOWER(email)=LOWER(?) OR $schoolCol = ? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM doc_nurse WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $stmt->execute([$identifier]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['id'])) { echo json_encode(['success' => false, 'message' => 'Account not found']); exit; }
    // Generate a random temporary password and update
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $len = strlen($alphabet); $pwd = '';
    for ($i=0; $i<10; $i++) { $pwd .= $alphabet[random_int(0, $len-1)]; }
    $hashed = password_hash($pwd, PASSWORD_DEFAULT);
    $up = $pdo->prepare('UPDATE doc_nurse SET password = ? WHERE id = ?');
    $up->execute([$hashed, $row['id']]);
    echo json_encode(['success' => true, 'new_password' => $pwd]); exit;
}

// Delete doc_nurse
if ($action === 'delete_doc_nurse') {
    $id = $_POST['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
    $stmt = $pdo->prepare('DELETE FROM doc_nurse WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['success' => true]); exit;
}

// Delete admin
if ($action === 'delete_admin') {
    $id = $_POST['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
    $stmt = $pdo->prepare('DELETE FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['success' => true]); exit;
}

if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Missing user id']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    // Audit trail log with dynamic admin id (fallback 0 if missing)
    $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
    if ($admin_id > 0) { audit($pdo, $admin_id, 'Deleted user', 'User ID: ' . $id); }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'update') {
    $id = $_POST['id'] ?? '';
    $student_faculty_id = $_POST['student_faculty_id'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $middle_initial = $_POST['middle_initial'] ?? '';
    $email = $_POST['email'] ?? '';
    $roleCol = resolve_column_name($pdo, 'users', ['client_type','role']) ?? 'role';
    $role = $_POST['role'] ?? ($_POST['client_type'] ?? ($_POST['client'] ?? ''));
    if (!$id || !$student_faculty_id || !$last_name || !$first_name || !$email) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE users SET student_faculty_id=?, last_name=?, first_name=?, middle_initial=?, email=?, {$roleCol}=? WHERE id=?");
    $stmt->execute([$student_faculty_id, $last_name, $first_name, $middle_initial, $email, $role, $id]);
    // Audit trail log
    $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
    $details = 'User ID: ' . $id . ', Name: ' . $last_name . ', ' . $first_name . ($middle_initial ? ' ' . $middle_initial . '.' : '');
    if ($admin_id > 0) { audit($pdo, $admin_id, 'Updated user', $details); }
    echo json_encode(['success' => true]);
    exit;
}

// Add a new user (admin)
if ($action === 'add') {
    $student_faculty_id = $_POST['student_faculty_id'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $middle_initial = $_POST['middle_initial'] ?? '';
    $email = $_POST['email'] ?? '';
    // Accept 'client' alias from newer UI, fallback to legacy 'role'
    $roleCol = resolve_column_name($pdo, 'users', ['client_type','role']) ?? 'role';
    $role = $_POST['client'] ?? ($_POST['role'] ?? ($_POST['client_type'] ?? ''));
    $status = $_POST['status'] ?? 'active';
    $password_plain = $_POST['password'] ?? '';
    $gender = trim($_POST['gender'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $year_course = trim($_POST['year_course'] ?? '');
    $department = trim($_POST['department'] ?? '');
    if (!$student_faculty_id || !$last_name || !$first_name || !$email || !$role) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    if ($password_plain === '') {
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        exit;
    }
    // Compute age from date_of_birth if provided
    $age = '';
    if ($date_of_birth !== '') {
        $ts = strtotime($date_of_birth);
        if ($ts === false || $ts > time()) {
            echo json_encode(['success' => false, 'message' => 'Invalid date of birth']); exit;
        }
        $dob = new DateTime(date('Y-m-d', $ts));
        $now = new DateTime('today');
        $ageYears = $dob->diff($now)->y;
        if ($ageYears < 0 || $ageYears > 150) { echo json_encode(['success' => false, 'message' => 'Derived age out of range']); exit; }
        $age = (string)$ageYears;
    }
    // Ensure gender column exists if not present
    if ($gender !== '') {
        try { if (!col_exists($pdo, 'users', 'gender')) { @$pdo->exec("ALTER TABLE users ADD COLUMN gender VARCHAR(32) NULL AFTER religion"); } } catch (Throwable $e) { /* ignore */ }
    }
    // Ensure status column exists if provided/used
    if (!col_exists($pdo, 'users', 'status')) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) { /* ignore */ }
    }
    // Check duplicates
    $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? OR student_faculty_id = ? LIMIT 1');
    $dup->execute([$email, $student_faculty_id]);
    if ($dup->fetch()) { echo json_encode(['success' => false, 'message' => 'Email or School ID already exists']); exit; }
    // Password: required
    $hashed = password_hash($password_plain, PASSWORD_DEFAULT);
    // Insert (dynamically include optional columns)
    $cols = "student_faculty_id, last_name, first_name, middle_initial, email, {$roleCol}, password";
    $vals = '?,?,?,?,?,?,?';
    $params = [$student_faculty_id, $last_name, $first_name, $middle_initial, $email, $role, $hashed];
    if ($age !== '') { $cols .= ', age'; $vals .= ', ?'; $params[] = $age; }
    if ($date_of_birth !== '') { $cols .= ', date_of_birth'; $vals .= ', ?'; $params[] = $date_of_birth; }
    if ($year_course !== '' && strcasecmp($role,'Student')===0) { $cols .= ', year_course'; $vals .= ', ?'; $params[] = $year_course; }
    if ($department !== '' && (strcasecmp($role,'Teacher')===0 || strcasecmp($role,'Non-Teaching')===0)) { $cols .= ', department'; $vals .= ', ?'; $params[] = $department; }
    if ($gender !== '' && col_exists($pdo, 'users', 'gender')) { $cols .= ', gender'; $vals .= ', ?'; $params[] = $gender; }
    if (col_exists($pdo, 'users', 'status')) { $cols .= ', status'; $vals .= ', ?'; $params[] = ($status === 'inactive' ? 'inactive' : 'active'); }
    $sql = "INSERT INTO users ($cols) VALUES ($vals)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    // Audit
    $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
    if ($admin_id > 0) { audit($pdo, $admin_id, 'Added user', 'School ID: ' . $student_faculty_id . ', Email: ' . $email); }
    // Return minimal user object (sans password) for UI quick refresh
    $newId = $pdo->lastInsertId();
    $selCols = '*';
    $fetch = $pdo->prepare("SELECT $selCols FROM users WHERE id=? LIMIT 1");
    $fetch->execute([$newId]);
    $row = $fetch->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($row) unset($row['password']);
    echo json_encode(['success' => true, 'user' => $row]);
    exit;
}

// Add a new admin (from Manage Users)
if ($action === 'add_admin') {
    $school_id = trim($_POST['school_id'] ?? '');
    // Build name either from provided 'name' or from parts
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $last_name = trim($_POST['last_name'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        if ($last_name !== '' && $first_name !== '') {
            $name = $last_name . ', ' . $first_name . ($middle_initial ? (' ' . $middle_initial . '.') : '');
        } else {
            $parts = array_filter([$first_name, $middle_initial ? ($middle_initial . '.') : '', $last_name]);
            $name = trim(implode(' ', $parts));
        }
    }
    $email = trim($_POST['email'] ?? '');
    $password_plain = trim($_POST['password'] ?? '');
    $status = trim($_POST['status'] ?? 'active');

    if ($name === '' || $email === '') {
        echo json_encode(['success' => false, 'message' => 'Missing required fields (name, email)']); exit;
    }
    // Ensure optional columns
    if (!col_exists($pdo, 'admins', 'status')) {
        try { $pdo->exec("ALTER TABLE admins ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) { /* ignore */ }
    }
    // school_id column may or may not exist
    $schoolCol = resolve_column_name($pdo, 'admins', ['school_id']);
    // Check duplicates by email
    $dup = $pdo->prepare('SELECT id FROM admins WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $dup->execute([$email]);
    if ($dup->fetch()) { echo json_encode(['success' => false, 'message' => 'Admin email already exists']); exit; }
    if ($password_plain === '') { echo json_encode(['success'=>false,'message'=>'Password is required']); exit; }
    $hashed = password_hash($password_plain, PASSWORD_DEFAULT);
    // Build insert
    if ($schoolCol) {
        $stmt = $pdo->prepare("INSERT INTO admins ($schoolCol, name, email, password, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$school_id, $name, $email, $hashed, ($status==='inactive'?'inactive':'active')]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO admins (name, email, password, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $hashed, ($status==='inactive'?'inactive':'active')]);
    }
    // Audit trail (placeholder admin id)
    $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
    if ($admin_id > 0) { audit($pdo, $admin_id, 'Added admin', 'Email: ' . $email); }
    echo json_encode(['success' => true]);
    exit;
}

// Activate/Deactivate user
if ($action === 'toggle_status') {
    $id = $_POST['id'] ?? '';
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status, ['active','inactive'], true)) {
        echo json_encode(['success' => false, 'message' => 'Missing/invalid fields']); exit;
    }
    // Ensure status column exists
    if (!col_exists($pdo, 'users', 'status')) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
        } catch (Throwable $e) { /* ignore if fails (may exist) */ }
    }
    try {
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
        if ($admin_id > 0) { audit($pdo, $admin_id, 'Toggled user status', 'User ID: ' . $id . ' => ' . $status); }
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Reset password (random or provided)
if ($action === 'reset_password') {
    $id = $_POST['id'] ?? '';
    $new = $_POST['new_password'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Missing user id']); exit; }
    if ($new === '') {
        // generate random 10-char alnum
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
        $len = strlen($alphabet); $pwd = '';
        for ($i=0;$i<10;$i++) { $pwd .= $alphabet[random_int(0,$len-1)]; }
        $new = $pwd;
    }
    $hashed = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$hashed, $id]);
    $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
    if ($admin_id > 0) { audit($pdo, $admin_id, 'Reset user password', 'User ID: ' . $id); }
    echo json_encode(['success' => true, 'new_password' => $_POST['new_password'] ? null : $new]);
    exit;
}

// Registration for doctor/dentist/nurse
if ($action === 'register_doc_nurse') {
    try {
        $school_id = $_POST['school_id'] ?? '';
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        // Normalize role to match DB enum/labels
        $r = strtolower(trim($role));
        if ($r !== '') {
            if (strpos($r, 'doctor') !== false) $role = 'Doctor';
            else if (strpos($r, 'dentist') !== false) $role = 'Dentist';
            else if (strpos($r, 'nurse') !== false) $role = 'Nurse';
        }

        // Require photo upload
        $photo_path = '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_tmp = $_FILES['photo']['tmp_name'];
            $photo_name = basename($_FILES['photo']['name']);
            $target_dir = '../../frontend/assets/images/';
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $photo_path = $target_dir . uniqid('doc_') . '_' . $photo_name;
            if (move_uploaded_file($photo_tmp, $photo_path)) {
                $photo_path = str_replace('../../frontend/', '../', $photo_path);
            } else {
                $photo_path = '';
            }
        }

        // Ensure school_id column exists if provided
        $dnSchoolCol = resolve_column_name($pdo, 'doc_nurse', ['school_id','school_ID']);
        if ($school_id !== '' && $dnSchoolCol === null) {
            try { $pdo->exec("ALTER TABLE doc_nurse ADD COLUMN school_id VARCHAR(50)"); $dnSchoolCol = 'school_id'; } catch (Throwable $e) { /* ignore */ }
        }

        if (!$name || !$email || !$password || !$role || !$photo_path) {
            echo json_encode(['success' => false, 'message' => 'All fields including photo are required.']); exit;
        }
        $stmt = $pdo->prepare('SELECT id FROM doc_nurse WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already registered.']); exit;
        }
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        // Resolve photo column (photo or dn_photo); create 'photo' if neither exists
        $dnPhotoCol = resolve_column_name($pdo, 'doc_nurse', ['photo','dn_photo']);
        if ($dnPhotoCol === null) {
            try { $pdo->exec("ALTER TABLE doc_nurse ADD COLUMN photo VARCHAR(255)"); $dnPhotoCol = 'photo'; } catch (Throwable $e) { /* ignore */ }
        }
        if ($dnSchoolCol !== null && $dnPhotoCol !== null) {
            $stmt = $pdo->prepare("INSERT INTO doc_nurse ($dnSchoolCol, name, email, password, role, $dnPhotoCol) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $name, $email, $hashed, $role, $photo_path]);
        } elseif ($dnSchoolCol !== null) {
            $stmt = $pdo->prepare("INSERT INTO doc_nurse ($dnSchoolCol, name, email, password, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $name, $email, $hashed, $role]);
        } elseif ($dnPhotoCol !== null) {
            $stmt = $pdo->prepare("INSERT INTO doc_nurse (name, email, password, role, $dnPhotoCol) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $hashed, $role, $photo_path]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO doc_nurse (name, email, password, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $email, $hashed, $role]);
        }
        echo json_encode(['success' => true]); exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]); exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]); exit;
    }
}
// Login for doctor/dentist/nurse (email or school_id)
if ($action === 'login_doc_nurse') {
    $identifier = $_POST['email'] ?? ($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($identifier === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Email/SID and password required.']); exit;
    }
    // doc_nurse may have school_id column (or none); attempt email OR school_id match
    $schoolCol = resolve_column_name($pdo, 'doc_nurse', ['school_id','school_ID']);
    if ($schoolCol) {
        $stmt = $pdo->prepare("SELECT * FROM doc_nurse WHERE (LOWER(email)=LOWER(?) OR $schoolCol = ?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM doc_nurse WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $stmt->execute([$identifier]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($password, $user['password'])) {
        if (session_status() === PHP_SESSION_ACTIVE) { @session_regenerate_id(true); }
        $photoVal = $user['photo'] ?? ($user['dn_photo'] ?? '');
        $_SESSION['doc_nurse_id'] = $user['id'];
        $_SESSION['doc_nurse_role'] = $user['role'];
        echo json_encode(['success' => true, 'session' => true, 'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'dn_photo' => $photoVal,
            'photo' => $photoVal,
            'email' => $user['email']
        ]]); exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']); exit;
    }
}


// (duplicate update_doc_nurse and change_password handlers removed; see earlier unified handlers)


echo json_encode(['success' => false, 'message' => 'Invalid action']);
