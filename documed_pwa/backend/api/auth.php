<?php
// Start session for optional passwordless QR approvals
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../config/db.php';
require_once __DIR__ . '/../config/email.php';
header('Content-Type: application/json');

// Basic security headers for API responses
function apply_security_headers(){
    // Prevent MIME sniffing
    header('X-Content-Type-Options: nosniff');
    // Basic clickjacking mitigation; adjust if embedding is needed
    header('X-Frame-Options: SAMEORIGIN');
    // Minimal referrer leakage
    header('Referrer-Policy: no-referrer');
    // CORS: allow same-origin by default; relax per deployment if needed
    if (!headers_sent()) {
        header('Vary: Origin');
    }
}
apply_security_headers();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function jsonResponse($arr) {
    // Clear any previous output and buffer
    if (ob_get_level()) ob_end_clean();
    // Re-apply security headers
    apply_security_headers();
    // Clear any previous headers
    header_remove(); 
    
    // Set fresh headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    // Encode with error handling
    $json = json_encode($arr);
    if ($json === false) {
        // Log the error if json_encode fails
        error_log('JSON encode error: ' . json_last_error_msg());
        // Send a valid JSON error response
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    } else {
        echo $json;
    }
    exit;
}

// Utility: check if a column exists (case-insensitive)
// ✅ Session ping (returns whichever principal is active)
if ($action === 'session_ping') {
    $out = ['success' => false, 'message' => 'No active session'];
    try {
        if (!empty($_SESSION['user_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['user_id']]);
            if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
                unset($u['password'], $u['qr_token_hash'], $u['qr_token_lookup']);
                if (isset($u['client_type']) && !isset($u['role'])) { $u['role'] = $u['client_type']; }
                $out = ['success'=>true,'type'=>'user','user'=>$u];
            }
        } elseif (!empty($_SESSION['admin_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['admin_id']]);
            if ($a = $stmt->fetch(PDO::FETCH_ASSOC)) { unset($a['password']); $out = ['success'=>true,'type'=>'admin','admin'=>$a]; }
        } elseif (!empty($_SESSION['doc_nurse_id'])) {
            // Support doctor/nurse/dentist (stored by manage_user.php)
            $stmt = $pdo->prepare('SELECT * FROM doc_nurse WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['doc_nurse_id']]);
            if ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
                unset($d['password']);
                $photoVal = $d['photo'] ?? ($d['dn_photo'] ?? '');
                $d['photo'] = $photoVal; $d['dn_photo'] = $photoVal;
                $out = ['success'=>true,'type'=>'doc_nurse','doc_nurse'=>$d];
            }
        }
    } catch (Throwable $e) { $out = ['success'=>false,'message'=>'Session error']; }
    jsonResponse($out);
}

// ✅ Logout (destroy session for any actor)
if ($action === 'logout') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Clear session array
        $_SESSION = [];
        // Delete cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        @session_destroy();
    }
    jsonResponse(['success'=>true]);
}
function col_exists($pdo, $table, $col) {
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!$db) return false;
        $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND UPPER(COLUMN_NAME) = UPPER(?)");
        $q->execute([$db, $table, $col]);
        return intval($q->fetchColumn()) > 0;
    } catch (Throwable $e) { return false; }
}

// Ensure QR login columns exist on users table
function ensureQrColumns($pdo) {
    try {
        if (!col_exists($pdo, 'users', 'qr_enabled')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN qr_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
        }
        if (!col_exists($pdo, 'users', 'qr_token_hash')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN qr_token_hash VARCHAR(255) NULL AFTER qr_enabled");
        }
        if (!col_exists($pdo, 'users', 'qr_token_lookup')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN qr_token_lookup CHAR(64) NULL AFTER qr_token_hash");
            // Best-effort index
            try { $pdo->exec("CREATE INDEX idx_qr_token_lookup ON users (qr_token_lookup)"); } catch (Throwable $e) { /* ignore */ }
        }
        if (!col_exists($pdo, 'users', 'qr_last_rotated')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN qr_last_rotated DATETIME NULL AFTER qr_token_lookup");
        }
    } catch (Throwable $e) { /* ignore */ }
}

function randomBase64Url($bytes = 32) {
    $raw = random_bytes($bytes);
    $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return $b64; // URL-safe token
}

// Helpers for password reset
function ensurePasswordResetTable($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(128) NULL,
            otp VARCHAR(16) NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            UNIQUE(token),
            INDEX idx_otp (otp)
        ) ENGINE=InnoDB");
        // Migrate legacy table shape (if it already existed):
        // 1) Add otp column if missing
        try { $pdo->exec("ALTER TABLE password_resets ADD COLUMN otp VARCHAR(16) NULL"); } catch (Throwable $e) { /* ignore if exists */ }
        // 2) Relax token to allow NULL (older schema had NOT NULL)
        try { $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN token VARCHAR(128) NULL"); } catch (Throwable $e) { /* ignore if already NULL or no token col */ }
        // 3) Add index on otp if missing
        try { $pdo->exec("CREATE INDEX idx_otp ON password_resets (otp)"); } catch (Throwable $e) { /* ignore if exists */ }
    } catch (Throwable $e) { /* ignore */ }
}

function generateResetToken() {
    return bin2hex(random_bytes(32)); // 64-char hex
}

// Simple file-based rate limiting and lockout primitives
function rate_limit($key, $maxPerWindow = 20, $windowSec = 60){
    $dir = __DIR__ . '/../tmp/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $now = time();
    $file = $dir . '/' . md5($key) . '.json';
    $data = ['t'=>$now, 'cnt'=>0];
    if (file_exists($file)) { $raw = @file_get_contents($file); $d = json_decode($raw, true); if (is_array($d)) $data=$d; }
    if ($now - ($data['t'] ?? 0) > $windowSec) { $data = ['t'=>$now, 'cnt'=>0]; }
    $data['cnt'] = ($data['cnt'] ?? 0) + 1;
    @file_put_contents($file, json_encode($data));
    if ($data['cnt'] > $maxPerWindow) return false;
    return true;
}
function lockout_check($idKey, $maxFails = 5, $cooldownSec = 900){ // 15 minutes
    $dir = __DIR__ . '/../tmp/lockout';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/' . md5($idKey) . '.json';
    $data = ['fails'=>0, 'lock_until'=>0];
    if (file_exists($file)) { $d = json_decode(@file_get_contents($file), true); if (is_array($d)) $data=$d; }
    $now = time();
    if (($data['lock_until'] ?? 0) > $now) return ['locked'=>true, 'retry_after'=>($data['lock_until'] - $now)];
    return ['locked'=>false, 'retry_after'=>0];
}
function lockout_fail($idKey, $maxFails = 5, $cooldownSec = 900){
    $dir = __DIR__ . '/../tmp/lockout'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/' . md5($idKey) . '.json';
    $data = ['fails'=>0, 'lock_until'=>0];
    if (file_exists($file)) { $d = json_decode(@file_get_contents($file), true); if (is_array($d)) $data=$d; }
    $now = time();
    if (($data['lock_until'] ?? 0) > $now) return; // still locked
    $data['fails'] = ($data['fails'] ?? 0) + 1;
    if ($data['fails'] >= $maxFails) { $data['lock_until'] = $now + $cooldownSec; $data['fails'] = 0; }
    @file_put_contents($file, json_encode($data));
}
function lockout_success($idKey){
    $file = __DIR__ . '/../tmp/lockout/' . md5($idKey) . '.json';
    if (file_exists($file)) @unlink($file);
}

// ✅ Request password reset (token-based; no longer returns token)
if ($action === 'request_password_reset') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit('pwreset:ip:'.$ip, 10, 300)) {
        // Generic response to avoid leaking
        jsonResponse(['success' => true, 'message' => 'If the email exists, a reset link has been sent.']);
    }
    $email = trim($_POST['email'] ?? '');
    if ($email === '') jsonResponse(['success' => false, 'message' => 'Email is required']);

    ensurePasswordResetTable($pdo);
    try {
        $stmt = $pdo->prepare('SELECT id,email FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            // Do not reveal if email exists
            jsonResponse(['success' => true, 'message' => 'If the email exists, a reset link has been sent.']);
        }
        $token = generateResetToken();
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        $ins = $pdo->prepare('INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)');
        $ins->execute([$user['id'], $user['email'], $token, $expires]);

        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $resetLink = $baseUrl . '/documed_pwa/frontend/user/reset_password.html?token=' . urlencode($token);
        $subj = 'DocuMed Password Reset';
        $html = '<p>You requested a password reset.</p><p>Click the link to reset: <a href="' . htmlspecialchars($resetLink) . '">' . htmlspecialchars($resetLink) . '</a></p><p>This link expires in 1 hour.</p>';
    $send = send_email($user['email'], $subj, $html);
    // Always respond generically; rely on email delivery only
    jsonResponse([ 'success' => true, 'message' => 'If the email exists, a reset link has been sent.' ]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Unable to process request']);
    }
}

// ✅ Perform password reset using token
if ($action === 'reset_password') {
    $token = trim($_POST['token'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    if ($token === '' || $newPassword === '') {
        jsonResponse(['success' => false, 'message' => 'Missing token or password']);
    }
    ensurePasswordResetTable($pdo);
    try {
        $stmt = $pdo->prepare('SELECT id, user_id, email, expires_at, used_at FROM password_resets WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(['success' => false, 'message' => 'Invalid token']);
        if ($row['used_at']) jsonResponse(['success' => false, 'message' => 'Token already used']);
        if (strtotime($row['expires_at']) < time()) jsonResponse(['success' => false, 'message' => 'Token expired']);

        // Update user password
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $pdo->prepare('UPDATE users SET password = ? WHERE email = ?');
        $upd->execute([$hash, $row['email']]);
        // Mark token used
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        jsonResponse(['success' => true, 'message' => 'Password updated successfully']);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Reset failed']);
    }
}

// ✅ Request password OTP based reset (no token, only email-based OTP)
if ($action === 'request_password_otp') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit('pwotp:ip:'.$ip, 10, 300)) {
        jsonResponse(['success' => true, 'message' => 'If the account exists, an OTP has been sent.']);
    }
    $identifier = trim($_POST['identifier'] ?? ''); // email or student_faculty_id
    if ($identifier === '') jsonResponse(['success' => false, 'message' => 'Email/SID is required']);
    ensurePasswordResetTable($pdo);
    try {
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE (LOWER(email)=LOWER(?) OR student_faculty_id = ?) LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            // do not reveal
            jsonResponse(['success' => true, 'message' => 'If the account exists, an OTP has been sent.']);
        }
        // generate 6-digit numeric OTP
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes
        // Invalidate previous unused entries for this email
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE email = ? AND used_at IS NULL')->execute([$user['email']]);
        $ins = $pdo->prepare('INSERT INTO password_resets (user_id, email, otp, expires_at) VALUES (?, ?, ?, ?)');
        $ins->execute([$user['id'], $user['email'], $otp, $expires]);
        $subj = 'Your DocuMed OTP Code';
        $html = '<p>Your One-Time Password (OTP) is: <strong style="font-size:20px;letter-spacing:4px;">' . htmlspecialchars($otp) . '</strong></p><p>This code will expire in 5 minutes. If you did not request this, you can ignore this email.</p>';
        $send = send_email($user['email'], $subj, $html);
        // Always respond generically; rely on email delivery only
        if (!$send['success']) {
            // Log failure but do not expose details to client
            error_log('OTP email send failed: ' . ($send['error'] ?? 'unknown'));
        }
        jsonResponse(['success' => true, 'message' => 'If the account exists, an OTP has been sent.']);
    } catch (Throwable $e) {
        error_log('request_password_otp error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Unable to process request']);
    }
}

// ✅ Verify OTP (without resetting password) to allow UI gating
if ($action === 'verify_password_otp') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit('otpverify:ip:'.$ip, 30, 300)) {
        jsonResponse(['success' => false, 'message' => 'Too many attempts, wait a moment']);
    }
    $identifier = trim($_POST['identifier'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    if ($identifier === '' || $otp === '') {
        jsonResponse(['success' => false, 'message' => 'Missing fields']);
    }
    ensurePasswordResetTable($pdo);
    try {
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE (LOWER(email)=LOWER(?) OR student_faculty_id = ?) LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonResponse(['success' => false, 'message' => 'Invalid account']);
        $q = $pdo->prepare('SELECT id, expires_at, used_at FROM password_resets WHERE email = ? AND otp = ? ORDER BY id DESC LIMIT 1');
        $q->execute([$user['email'], $otp]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(['success' => false, 'message' => 'Invalid OTP']);
        if ($row['used_at']) jsonResponse(['success' => false, 'message' => 'OTP already used']);
        if (strtotime($row['expires_at']) < time()) jsonResponse(['success' => false, 'message' => 'OTP expired']);
        // Do not mark as used here; only validation for UI
        jsonResponse(['success' => true, 'message' => 'OTP verified']);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Verification failed']);
    }
}

// ✅ Reset password using OTP (email is fetched from DB via identifier)
if ($action === 'reset_password_otp') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit('otpreset:ip:'.$ip, 10, 300)) {
        jsonResponse(['success' => false, 'message' => 'Too many requests']);
    }
    $identifier = trim($_POST['identifier'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    if ($identifier === '' || $otp === '' || $newPassword === '') {
        jsonResponse(['success' => false, 'message' => 'Missing fields']);
    }
    ensurePasswordResetTable($pdo);
    try {
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE (LOWER(email)=LOWER(?) OR student_faculty_id = ?) LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonResponse(['success' => false, 'message' => 'Invalid account']);
        $q = $pdo->prepare('SELECT id, expires_at, used_at FROM password_resets WHERE email = ? AND otp = ? ORDER BY id DESC LIMIT 1');
        $q->execute([$user['email'], $otp]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(['success' => false, 'message' => 'Invalid OTP']);
        if ($row['used_at']) jsonResponse(['success' => false, 'message' => 'OTP already used']);
        if (strtotime($row['expires_at']) < time()) jsonResponse(['success' => false, 'message' => 'OTP expired']);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $pdo->prepare('UPDATE users SET password = ? WHERE email = ?');
        $upd->execute([$hash, $user['email']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        jsonResponse(['success' => true, 'message' => 'Password updated successfully']);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Reset failed']);
    }
}

// ✅ Get user info
if ($action === 'get_user') {
    // Accept id, student_faculty_id (sid), or email to keep legacy callers working
    $id = trim($_POST['id'] ?? '');
    $sid = trim($_POST['sid'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($id === '' && $sid === '' && $email === '') {
        jsonResponse(['success' => false, 'message' => 'User identifier is required']);
    }

    try {
        // Determine lookup priority: explicit id → sid → email
        if ($id !== '') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
        } elseif ($sid !== '') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE student_faculty_id = ? LIMIT 1");
            $stmt->execute([$sid]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$user) {
            jsonResponse(['success' => false, 'message' => 'User not found']);
        }

        // Remove sensitive data and provide legacy aliases the frontend expects
        unset($user['password'], $user['qr_token_hash'], $user['qr_token_lookup']);

        // Provide aggregated display name for older UI widgets
        $nameParts = [
            $user['first_name'] ?? '',
            $user['middle_initial'] ?? '',
            $user['last_name'] ?? ''
        ];
        $user['name'] = trim(preg_replace('/\s+/', ' ', implode(' ', $nameParts)));

        // Preserve legacy role key
        if (isset($user['client_type']) && !isset($user['role'])) {
            $user['role'] = $user['client_type'];
        }

        jsonResponse(['success' => true, 'user' => $user]);
    } catch (Throwable $e) {
        error_log('get_user error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Unable to fetch user']);
    }
}

// ✅ User registration
if ($action === 'user_register') {
    $last_name = trim($_POST['last_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_initial = trim($_POST['middle_initial'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $religion = trim($_POST['religion'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $place_of_birth = trim($_POST['place_of_birth'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // 'client' is the new field name replacing role in the registration form
        // Use client_type column explicitly (schema already migrated)
        $client_type = $_POST['client_type'] ?? ($_POST['client'] ?? ($_POST['role'] ?? ''));
    $gender = trim($_POST['gender'] ?? '');
    $year_course = trim($_POST['year_course'] ?? '');
    $student_faculty_id = trim($_POST['student_faculty_id'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $created_at = $_POST['created_at'] ?? date('Y-m-d H:i:s');

    // Handle photo upload
    $photo_path = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo_tmp = $_FILES['photo']['tmp_name'];
        $photo_name = basename($_FILES['photo']['name']);
        $target_dir = '../../frontend/assets/images/';
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $photo_path = $target_dir . uniqid('user_') . '_' . $photo_name;
        if (move_uploaded_file($photo_tmp, $photo_path)) {
            $photo_path = str_replace('../../frontend/', '../', $photo_path); // for frontend access
        } else {
            $photo_path = '';
        }
    }

    if (!$last_name || !$first_name || !$student_faculty_id || !$email || !$password) {
        jsonResponse(['error' => 'Missing required fields']);
    }

    // Server-side validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Invalid email address']);
    }
    if (!preg_match('/^\d{11}$/', $contact_number)) {
        jsonResponse(['success' => false, 'message' => 'Contact number must be exactly 11 digits']);
    }
    if (!preg_match('/^[-A-Za-z0-9]{1,10}$/', $student_faculty_id)) {
        jsonResponse(['success' => false, 'message' => 'School ID must be up to 10 characters (letters, numbers, or dash)']);
    }

    // Validate DOB and compute age server-side to avoid inconsistencies
    if ($date_of_birth !== '') {
        $ts = strtotime($date_of_birth);
        if ($ts === false) {
            jsonResponse(['success' => false, 'message' => 'Invalid date of birth']);
        }
        if ($ts > time()) {
            jsonResponse(['success' => false, 'message' => 'Birthdate cannot be in the future']);
        }
        // Compute age
        $dob = new DateTime(date('Y-m-d', $ts));
        $now = new DateTime('today');
        $ageYears = $dob->diff($now)->y;
        if ($ageYears < 0 || $ageYears > 150) {
            jsonResponse(['success' => false, 'message' => 'Invalid derived age from birthdate']);
        }
        $age = (string)$ageYears;
    }

    // Ensure department is only required for Teacher or Non-Teaching roles
    if (($client_type === 'Teacher' || $client_type === 'Non-Teaching') && !$department) {
        jsonResponse(['error' => 'Department is required for the selected role.']);
    }

    // Ensure users table has required evolving columns
    try {
        if (!col_exists($pdo, 'users', 'gender')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN gender VARCHAR(32) NULL AFTER religion");
        }
        if (!col_exists($pdo, 'users', 'client_type')) {
            // If legacy role exists, rename; else create fresh client_type
            if (col_exists($pdo, 'users', 'role')) {
                @$pdo->exec("ALTER TABLE users CHANGE COLUMN role client_type VARCHAR(100)");
            } else {
                @$pdo->exec("ALTER TABLE users ADD COLUMN client_type VARCHAR(100) NULL AFTER password");
            }
        }
        if (!col_exists($pdo, 'users', 'department')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN department VARCHAR(100) NULL AFTER client_type");
        }
        if (!col_exists($pdo, 'users', 'qr_code')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN qr_code VARCHAR(255) NULL AFTER department");
        }
        if (!col_exists($pdo, 'users', 'status')) {
            @$pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER qr_code");
        }
    } catch (Throwable $e) { /* ignore schema migration errors */ }

    $hash = password_hash($password, PASSWORD_DEFAULT);

require_once __DIR__ . '/phpqrcode.php';
    try {
        // Dynamically build columns/values to avoid 1136 mismatch errors
        $hasGender = col_exists($pdo, 'users', 'gender');
        $cols = [
            'last_name','first_name','middle_initial','age','address','civil_status','nationality','religion'
        ];
        $vals = [
            $last_name,$first_name,$middle_initial,$age,$address,$civil_status,$nationality,$religion
        ];
        if ($hasGender) { $cols[]='gender'; $vals[]=$gender; }
        $cols = array_merge($cols,[
            'date_of_birth','place_of_birth','year_course','student_faculty_id','contact_person','contact_number','email','password','client_type','department','photo','created_at'
        ]);
        $vals = array_merge($vals,[
            $date_of_birth,$place_of_birth,$year_course,$student_faculty_id,$contact_person,$contact_number,$email,$hash,$client_type,$department,$photo_path,$created_at
        ]);
        // Prepare statement
        $placeholders = rtrim(str_repeat('?,', count($cols)),',');
        $sql = 'INSERT INTO users (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        $user_id = $pdo->lastInsertId();


        // QR content policy: only SID; fallback to numeric id
        $qr_data = $student_faculty_id !== '' ? $student_faculty_id : (string)$user_id;
        $qr_dir = __DIR__ . '/../../frontend/assets/images';
        $qr_filename = "qr_" . $user_id . ".png";
        $qr_path = $qr_dir . "/" . $qr_filename;

        if (!is_dir($qr_dir)) {
            mkdir($qr_dir, 0777, true);
        }

        try {
            QRcode::png($qr_data, $qr_path, QR_ECLEVEL_L, 6);
            $qr_db_path = "../assets/images/" . $qr_filename;
            if (file_exists($qr_path)) {
                // Save QR code path to user
                $stmt2 = $pdo->prepare("UPDATE users SET qr_code=? WHERE id=?");
                $stmt2->execute([$qr_db_path, $user_id]);
            }
        } catch (Exception $e) {
            jsonResponse([
                'success' => false,
                'message' => 'QR code generation failed: ' . $e->getMessage()
            ]);
        }

        // Fetch full inserted user (without password) to return consistent client state
        try {
            $cols = '*';
            $stmtFetch = $pdo->prepare("SELECT $cols FROM users WHERE id=? LIMIT 1");
            $stmtFetch->execute([$user_id]);
            $newUser = $stmtFetch->fetch(PDO::FETCH_ASSOC);
            if ($newUser) { unset($newUser['password']); }
        } catch (Throwable $e) { $newUser = null; }

        if ($newUser && isset($newUser['client_type']) && !isset($newUser['role'])) {
            $newUser['role'] = $newUser['client_type'];
        }
        jsonResponse([
            'success' => true,
            'message' => 'Registration successful! You can now login.',
            'qr_code' => $qr_db_path ?? null,
            'user_id' => $user_id,
            'user' => $newUser
        ]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ✅ User login (email or student_faculty_id)
if ($action === 'user_login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit('login:ip:'.$ip, 30, 60)) jsonResponse(['success'=>false,'message'=>'Too many requests, slow down']);
    $identifier = trim($_POST['email'] ?? ($_POST['identifier'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
        jsonResponse(['success' => false, 'message' => 'Missing email/SID or password']);
    }

    // Brute-force lockout per identifier
    if ($identifier !== '') {
        $lock = lockout_check('login:id:'.strtolower($identifier), 5, 900);
        if ($lock['locked']) jsonResponse(['success'=>false,'message'=>'Account temporarily locked','retry_after'=>$lock['retry_after']]);
    }
    // Get complete user data including all profile fields
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (LOWER(email)=LOWER(?) OR student_faculty_id = ?) LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID to prevent fixation
        if (session_status() === PHP_SESSION_ACTIVE) { @session_regenerate_id(true); }
        // Opportunistic password rehash if algorithm/cost changed
        try {
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $upd->execute([$newHash, $user['id']]);
                // Update in-memory value to avoid accidental leakage
                $user['password'] = $newHash;
            }
        } catch (Throwable $e) { /* ignore rehash failure */ }
        // Remove sensitive data
        unset($user['password']);
        // Persist session identity for passwordless QR approval
        $_SESSION['user_id'] = $user['id'];
        
        // Ensure QR code path is included
        if ($user['qr_code']) {
            $user['qr_code'] = $user['qr_code'];
        }
        
        // Provide backward-compatible 'role' key even if column is client_type
        // Alias client_type to role for backward compatibility
        if (isset($user['client_type']) && !isset($user['role'])) {
            $user['role'] = $user['client_type'];
        }
        lockout_success('login:id:'.strtolower($identifier));
        jsonResponse(['success' => true, 'user' => $user]);
    } else {
        if ($identifier !== '') lockout_fail('login:id:'.strtolower($identifier), 5, 900);
        jsonResponse(['success' => false, 'message' => 'Invalid credentials']);
    }
}

// ✅ Generate/rotate QR login token for a user (requires user credentials)
if ($action === 'qr_generate_token') {
    $identifier = trim($_POST['identifier'] ?? ($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    if ($identifier === '' || $password === '') {
        jsonResponse(['success' => false, 'message' => 'Missing email/SID or password']);
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (LOWER(email)=LOWER(?) OR student_faculty_id = ?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid credentials']);
        }
        ensureQrColumns($pdo);
        $token = randomBase64Url(32); // 256-bit
        $lookup = hash('sha256', $token);
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET qr_enabled=1, qr_token_hash=?, qr_token_lookup=?, qr_last_rotated=NOW() WHERE id=?");
        $upd->execute([$hash, $lookup, $user['id']]);

        // Render QR image with namespaced prefix
        $qrText = 'DMQR:v1:' . $token;
        // Optional: create PNG for convenience
        try {
            require_once __DIR__ . '/phpqrcode.php';
            $qr_dir = __DIR__ . '/../../frontend/assets/images';
            if (!is_dir($qr_dir)) { @mkdir($qr_dir, 0777, true); }
            $qr_filename = 'qr_login_' . $user['id'] . '.png';
            $qr_path = $qr_dir . '/' . $qr_filename;
            QRcode::png($qrText, $qr_path, QR_ECLEVEL_L, 6);
            $qr_img = '../assets/images/' . $qr_filename;
        } catch (Throwable $e) { $qr_img = null; }

        // Return minimal user info
        $public = $user; unset($public['password'], $public['qr_token_hash'], $public['qr_token_lookup']);
        jsonResponse(['success' => true, 'qr_text' => $qrText, 'qr_image' => $qr_img, 'user' => $public]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Unable to issue QR token']);
    }
}

// ✅ Disable QR login for a user (requires user credentials)
if ($action === 'qr_disable') {
    $identifier = trim($_POST['identifier'] ?? ($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    if ($identifier === '' || $password === '') {
        jsonResponse(['success' => false, 'message' => 'Missing email/SID or password']);
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (LOWER(email)=LOWER(?) OR student_faculty_id = ?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid credentials']);
        }
        ensureQrColumns($pdo);
        $pdo->prepare("UPDATE users SET qr_enabled=0 WHERE id=?")->execute([$user['id']]);
        jsonResponse(['success' => true]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Unable to disable QR login']);
    }
}

// ✅ Login using QR token (scanned value)
if ($action === 'qr_login') {
    $qr = trim($_POST['qr'] ?? ($_GET['qr'] ?? ''));
    if ($qr === '') { jsonResponse(['success' => false, 'message' => 'Missing QR token']); }
    // Accept both DMQR:v1:<token> and raw token
    $parts = explode(':', $qr);
    $token = $qr;
    if (count($parts) >= 3 && strtoupper($parts[0]) === 'DMQR' && strtolower($parts[1]) === 'v1') {
        $token = end($parts);
    }
    ensureQrColumns($pdo);
    try {
        // 1) Primary path: DMQR:v1 personal token (hashed lookup)
        $lookup = hash('sha256', $token);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE qr_enabled=1 AND qr_token_lookup = ? LIMIT 1");
        $stmt->execute([$lookup]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2) Fallback path: Allow login using School ID QR generated at registration
        //    We only consider it if personal token wasn't found and the scanned value looks like a valid SID.
        if (!$user) {
            // Match the same SID shape enforced on registration: letters/numbers/dash up to 10 chars
            if (preg_match('/^[-A-Za-z0-9]{1,10}$/', $qr)) {
                $stmt2 = $pdo->prepare('SELECT * FROM users WHERE student_faculty_id = ? LIMIT 1');
                $stmt2->execute([$qr]);
                $user = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }

        // 3) Secondary fallback: Some users may have QR containing numeric internal ID (when SID missing at registration)
        if (!$user) {
            // Accept up to 18-digit numeric IDs to be safe
            if (preg_match('/^\d{1,18}$/', $qr)) {
                $stmt3 = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $stmt3->execute([$qr]);
                $user = $stmt3->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }

        if (!$user) { jsonResponse(['success' => false, 'message' => 'Invalid QR']); }

        // Additional checks for personal-token path
        if (!empty($user['qr_token_hash']) && $user['qr_token_lookup'] === $lookup) {
            if (!password_verify($token, $user['qr_token_hash'])) {
                jsonResponse(['success' => false, 'message' => 'Invalid QR']);
            }
        }

        if (isset($user['status']) && strtolower($user['status']) === 'inactive') {
            jsonResponse(['success' => false, 'message' => 'Account inactive']);
        }
        // Build the same response shape as user_login
        unset($user['password'], $user['qr_token_hash'], $user['qr_token_lookup']);
        if (session_status() === PHP_SESSION_ACTIVE) { @session_regenerate_id(true); }
        // Persist session identity for passwordless QR approval
        $_SESSION['user_id'] = $user['id'];
        if (isset($user['client_type']) && !isset($user['role'])) { $user['role'] = $user['client_type']; }
        // Optional: rotate on use (disabled by default)
        jsonResponse(['success' => true, 'user' => $user, 'login' => 'qr']);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'QR login failed']);
    }
}

// ✅ Shopee-style session QR: create short-lived challenge (no credentials required)
if ($action === 'qr_create_challenge') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit('qrchlg:ip:'.$ip, 60, 300)) {
        jsonResponse(['success' => false, 'message' => 'Too many QR requests']);
    }
    try {
        $challenge = randomBase64Url(24);
        $expiresIn = 120; // seconds
        $dir = __DIR__ . '/../tmp/challenges';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $path = $dir . '/' . $challenge . '.json';
        // Capture requesting device info
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        // Minimal UA summary
        $ua_summary = 'Browser';
        try {
            $u = strtolower($ua);
            $browser = (strpos($u,'edg')!==false ? 'Edge' : (strpos($u,'chrome')!==false ? 'Chrome' : (strpos($u,'safari')!==false ? 'Safari' : (strpos($u,'firefox')!==false ? 'Firefox' : 'Browser'))));
            $os = (strpos($u,'windows')!==false ? 'Windows' : (strpos($u,'mac os x')!==false ? 'macOS' : (strpos($u,'android')!==false ? 'Android' : (strpos($u,'iphone')!==false || strpos($u,'ipad')!==false ? 'iOS' : ''))));
            $ua_summary = $os ? ($browser . ', ' . $os) : $browser;
        } catch (Throwable $e) { /* keep default */ }
        $data = [
            'status' => 'pending',
            'user_id' => null,
            'created_at' => time(),
            'expires_at' => time() + $expiresIn,
            'ua' => $ua,
            'ua_summary' => $ua_summary,
            'ip' => $ip
        ];
        file_put_contents($path, json_encode($data));

        // Build QR contents and PNG
        $qrText = 'DMQR:chlg:' . $challenge;
        $qr_img_rel = null;
        try {
            require_once __DIR__ . '/phpqrcode.php';
            $imgDir = __DIR__ . '/../../frontend/assets/images/challenges';
            if (!is_dir($imgDir)) { @mkdir($imgDir, 0777, true); }
            $png = $imgDir . '/' . $challenge . '.png';
            QRcode::png($qrText, $png, QR_ECLEVEL_L, 6);
            $qr_img_rel = '../assets/images/challenges/' . $challenge . '.png';
        } catch (Throwable $e) { /* ignore QR image failure */ }

        jsonResponse(['success' => true, 'challenge' => $challenge, 'qr_text' => $qrText, 'qr_image' => $qr_img_rel, 'expires_in' => $expiresIn]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Unable to create challenge']);
    }
}

// ✅ Poll challenge status (login modal side)
if ($action === 'qr_poll_challenge') {
    $challenge = trim($_POST['challenge'] ?? ($_GET['challenge'] ?? ''));
    if ($challenge === '') jsonResponse(['success' => false, 'message' => 'Missing challenge']);
    $path = __DIR__ . '/../tmp/challenges/' . basename($challenge) . '.json';
    if (!file_exists($path)) jsonResponse(['success' => false, 'message' => 'Not found']);
    $data = json_decode(file_get_contents($path), true);
    if (!$data) jsonResponse(['success' => false, 'message' => 'Corrupt challenge']);
    if (time() > ($data['expires_at'] ?? 0)) {
        @unlink($path);
        jsonResponse(['success' => true, 'status' => 'expired']);
    }
    if (($data['status'] ?? '') === 'cancelled') {
        @unlink($path);
        jsonResponse(['success' => true, 'status' => 'cancelled']);
    }
    if (($data['status'] ?? '') === 'approved' && !empty($data['user_id'])) {
        // Issue login session shape similar to user_login
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$data['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            unset($user['password'], $user['qr_token_hash'], $user['qr_token_lookup']);
            if (isset($user['client_type']) && !isset($user['role'])) { $user['role'] = $user['client_type']; }
            // One-time consume
            @unlink($path);
            jsonResponse(['success' => true, 'status' => 'approved', 'user' => $user]);
        } else {
            @unlink($path);
            jsonResponse(['success' => false, 'message' => 'User missing']);
        }
    }
    // If a scanner has fetched info, status may be 'scanned'
    $status = $data['status'] ?? 'pending';
    jsonResponse(['success' => true, 'status' => $status]);
}

// ✅ Approve challenge (from logged-in device by scanning DMQR:chlg:<id>)
if ($action === 'qr_approve_challenge') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit('qrappr:ip:'.$ip, 60, 300)) {
        jsonResponse(['success' => false, 'message' => 'Too many approvals']);
    }
    // Approver must authenticate (email+password) OR provide existing QR login token
    $identifier = trim($_POST['identifier'] ?? ($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $qr = trim($_POST['qr'] ?? '');
    $challengeRaw = trim($_POST['challenge'] ?? '');
    if ($challengeRaw === '') jsonResponse(['success' => false, 'message' => 'Missing challenge']);
    $parts = explode(':', $challengeRaw);
    $challenge = ($parts && count($parts) >= 3 && strtolower($parts[1] ?? '') === 'chlg') ? end($parts) : $challengeRaw;

    $path = __DIR__ . '/../tmp/challenges/' . basename($challenge) . '.json';
    if (!file_exists($path)) jsonResponse(['success' => false, 'message' => 'Challenge not found']);
    $data = json_decode(file_get_contents($path), true);
    if (!$data) jsonResponse(['success' => false, 'message' => 'Corrupt challenge']);
    if (time() > ($data['expires_at'] ?? 0)) { @unlink($path); jsonResponse(['success' => false, 'message' => 'Challenge expired']); }

    // Authenticate approver
    $user = null;
    if ($identifier !== '' && $password !== '') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE (LOWER(email)=LOWER(?) OR student_faculty_id = ?) LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($password, $row['password'])) { $user = $row; }
    } elseif ($qr !== '') {
        // Allow using personal QR login token as auth to approve
        $tparts = explode(':', $qr);
        $token = $qr;
        if (count($tparts) >= 3 && strtoupper($tparts[0]) === 'DMQR' && strtolower($tparts[1]) === 'v1') { $token = end($tparts); }
        ensureQrColumns($pdo);
        $lookup = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE qr_enabled=1 AND qr_token_lookup = ? LIMIT 1');
        $stmt->execute([$lookup]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($token, $row['qr_token_hash'])) { $user = $row; }
    }
    // Allow passwordless approval if existing authenticated session present
    if (!$user && isset($_SESSION['user_id']) && $_SESSION['user_id']) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $sessUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sessUser) { $user = $sessUser; }
    }
    if (!$user) jsonResponse(['success' => false, 'message' => 'Invalid approver credentials']);

    // Approve
    $data['status'] = 'approved';
    $data['user_id'] = $user['id'];
    file_put_contents($path, json_encode($data));
    jsonResponse(['success' => true]);
}

// ✅ Get challenge info (for phone confirm screen) and mark as scanned
if ($action === 'qr_challenge_info') {
    $challengeRaw = trim($_POST['challenge'] ?? ($_GET['challenge'] ?? ''));
    if ($challengeRaw === '') jsonResponse(['success' => false, 'message' => 'Missing challenge']);
    $parts = explode(':', $challengeRaw);
    $challenge = ($parts && count($parts) >= 3 && strtolower($parts[1] ?? '') === 'chlg') ? end($parts) : $challengeRaw;
    $path = __DIR__ . '/../tmp/challenges/' . basename($challenge) . '.json';
    if (!file_exists($path)) jsonResponse(['success' => false, 'message' => 'Not found']);
    $data = json_decode(file_get_contents($path), true);
    if (!$data) jsonResponse(['success' => false, 'message' => 'Corrupt challenge']);
    if (time() > ($data['expires_at'] ?? 0)) { @unlink($path); jsonResponse(['success' => false, 'message' => 'Expired']); }
    // Mark scanned if still pending
    if (($data['status'] ?? '') === 'pending') {
        $data['status'] = 'scanned';
        file_put_contents($path, json_encode($data));
    }
    jsonResponse(['success' => true, 'ua_summary' => ($data['ua_summary'] ?? ''), 'created_at' => ($data['created_at'] ?? time()) ]);
}

// ✅ Cancel a challenge (from phone confirm screen)
if ($action === 'qr_cancel_challenge') {
    $challengeRaw = trim($_POST['challenge'] ?? ($_GET['challenge'] ?? ''));
    if ($challengeRaw === '') jsonResponse(['success' => false, 'message' => 'Missing challenge']);
    $parts = explode(':', $challengeRaw);
    $challenge = ($parts && count($parts) >= 3 && strtolower($parts[1] ?? '') === 'chlg') ? end($parts) : $challengeRaw;
    $path = __DIR__ . '/../tmp/challenges/' . basename($challenge) . '.json';
    if (!file_exists($path)) jsonResponse(['success' => false, 'message' => 'Not found']);
    $data = json_decode(file_get_contents($path), true);
    if (!$data) jsonResponse(['success' => false, 'message' => 'Corrupt challenge']);
    $data['status'] = 'cancelled';
    file_put_contents($path, json_encode($data));
    jsonResponse(['success' => true]);
}

// ✅ Admin login (email or school_id if present)
if ($action === 'admin_login') {
    $identifier = $_POST['email'] ?? ($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($identifier === '' || $password === '') jsonResponse(['error' => 'Missing email/SID or password']);

    $hasSchool = col_exists($pdo, 'admins', 'school_id');
    if ($hasSchool) {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE (LOWER(email)=LOWER(?) OR school_id = ?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE LOWER(email)=LOWER(?) LIMIT 1");
        $stmt->execute([$identifier]);
    }
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        unset($admin['password']);
        if (session_status() === PHP_SESSION_ACTIVE) { @session_regenerate_id(true); }
        $_SESSION['admin_id'] = $admin['id'];
        jsonResponse(['success' => true, 'admin' => $admin, 'session' => true]);
    } else {
        jsonResponse(['error' => 'Invalid credentials']);
    }
}

// ✅ Admin registration
if ($action === 'admin_register') {
    $name = $_POST['name'] ?? '';
    $school_id = $_POST['school_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!$name || !$email || !$password) jsonResponse(['success' => false, 'message' => 'Missing required fields']);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        // Ensure school_id exists if provided
        if ($school_id !== '' && !col_exists($pdo, 'admins', 'school_id')) {
            try { $pdo->exec("ALTER TABLE admins ADD COLUMN school_id VARCHAR(50)"); } catch (Throwable $e) { /* ignore */ }
        }
        if (col_exists($pdo, 'admins', 'school_id')) {
            $stmt = $pdo->prepare("INSERT INTO admins (school_id, name, email, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$school_id, $name, $email, $hash]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO admins (name, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $hash]);
        }
        jsonResponse(['success' => true]);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Email already exists']);
    }
}

// ✅ Update user info
if ($action === 'update_user') {
    $id = $_POST['id'] ?? '';
    $last_name = trim($_POST['last_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_initial = trim($_POST['middle_initial'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $religion = trim($_POST['religion'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $place_of_birth = trim($_POST['place_of_birth'] ?? '');
    $year_course = trim($_POST['year_course'] ?? '');
    $student_faculty_id = trim($_POST['student_faculty_id'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // Use client_type explicitly (legacy support via alias when responding)
    $client_type = $_POST['client_type'] ?? ($_POST['client'] ?? ($_POST['role'] ?? ''));
    $department = trim($_POST['department'] ?? '');

    if (!$id || !$last_name || !$first_name || !$email || !$student_faculty_id) {
        jsonResponse(['success' => false, 'error' => 'Missing required fields']);
    }

    try {
        // Get current photo if no new photo is uploaded
        $stmtCurrent = $pdo->prepare("SELECT photo, client_type FROM users WHERE id=?");
        $stmtCurrent->execute([$id]);
        $currentRow = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        $currentPhoto = $currentRow ? ($currentRow['photo'] ?? '') : '';
        // Enforce client_type as read-only: always use current DB value
        if ($currentRow && isset($currentRow['client_type'])) {
            $client_type = $currentRow['client_type'];
        }

        $photo_path = $currentPhoto;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_tmp = $_FILES['photo']['tmp_name'];
            $photo_name = basename($_FILES['photo']['name']);
            $target_dir = __DIR__ . '/../../frontend/assets/images/';
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $photo_path = $target_dir . uniqid('user_') . '_' . $photo_name;
            if (move_uploaded_file($photo_tmp, $photo_path)) {
                $photo_path = str_replace(__DIR__ . '/../../frontend/', '../', $photo_path);
            }
        }

        // Update user info (include gender if column exists)
        $hasGender = col_exists($pdo, 'users', 'gender');
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hasGender) {
                $stmt = $pdo->prepare("UPDATE users SET last_name=?, first_name=?, middle_initial=?, age=?, address=?, civil_status=?, nationality=?, religion=?, gender=?, date_of_birth=?, place_of_birth=?, year_course=?, department=?, student_faculty_id=?, contact_person=?, contact_number=?, email=?, password=?, client_type=?, photo=? WHERE id=?");
                $stmt->execute([$last_name, $first_name, $middle_initial, $age, $address, $civil_status, $nationality, $religion, $gender, $date_of_birth, $place_of_birth, $year_course, $department, $student_faculty_id, $contact_person, $contact_number, $email, $hash, $client_type, $photo_path, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET last_name=?, first_name=?, middle_initial=?, age=?, address=?, civil_status=?, nationality=?, religion=?, date_of_birth=?, place_of_birth=?, year_course=?, department=?, student_faculty_id=?, contact_person=?, contact_number=?, email=?, password=?, client_type=?, photo=? WHERE id=?");
                $stmt->execute([$last_name, $first_name, $middle_initial, $age, $address, $civil_status, $nationality, $religion, $date_of_birth, $place_of_birth, $year_course, $department, $student_faculty_id, $contact_person, $contact_number, $email, $hash, $client_type, $photo_path, $id]);
            }
        } else {
            if ($hasGender) {
                $stmt = $pdo->prepare("UPDATE users SET last_name=?, first_name=?, middle_initial=?, age=?, address=?, civil_status=?, nationality=?, religion=?, gender=?, date_of_birth=?, place_of_birth=?, year_course=?, department=?, student_faculty_id=?, contact_person=?, contact_number=?, email=?, client_type=?, photo=? WHERE id=?");
                $stmt->execute([$last_name, $first_name, $middle_initial, $age, $address, $civil_status, $nationality, $religion, $gender, $date_of_birth, $place_of_birth, $year_course, $department, $student_faculty_id, $contact_person, $contact_number, $email, $client_type, $photo_path, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET last_name=?, first_name=?, middle_initial=?, age=?, address=?, civil_status=?, nationality=?, religion=?, date_of_birth=?, place_of_birth=?, year_course=?, department=?, student_faculty_id=?, contact_person=?, contact_number=?, email=?, client_type=?, photo=? WHERE id=?");
                $stmt->execute([$last_name, $first_name, $middle_initial, $age, $address, $civil_status, $nationality, $religion, $date_of_birth, $place_of_birth, $year_course, $department, $student_faculty_id, $contact_person, $contact_number, $email, $client_type, $photo_path, $id]);
            }
        }

        // Regenerate QR code with updated info
        try {
            require_once __DIR__ . '/phpqrcode.php';
            
            // Prepare updated user data for QR code
            // Generate new QR code with only SID/ID
            $qr_data = $student_faculty_id !== '' ? $student_faculty_id : (string)$id;
            $qr_dir = __DIR__ . '/../../frontend/assets/images';
            $qr_filename = "qr_" . $id . ".png";
            $qr_path = $qr_dir . "/" . $qr_filename;

            if (!is_dir($qr_dir)) {
                mkdir($qr_dir, 0777, true);
            }

            if (!is_writable($qr_dir)) {
                throw new Exception('QR directory is not writable');
            }

            QRcode::png($qr_data, $qr_path, QR_ECLEVEL_L, 6);
            
            if (!file_exists($qr_path)) {
                throw new Exception('Failed to generate QR code file');
            }

            $qr_db_path = "../assets/images/" . $qr_filename;
            
            // Update QR code path in database
            $stmt = $pdo->prepare("UPDATE users SET qr_code=? WHERE id=?");
            $stmt->execute([$qr_db_path, $id]);

            // Fetch updated user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$id]);
            $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($updated_user) {
                unset($updated_user['password']);
                // Backward-compatible alias if client_type column exists
                if (isset($updated_user['client_type']) && !isset($updated_user['role'])) {
                    $updated_user['role'] = $updated_user['client_type'];
                }
            }

            $response = [
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => $updated_user,
                'qr_code' => $qr_db_path
            ];
            if ($photo_path) {
                $response['photo'] = $photo_path;
            }
            
            jsonResponse($response);
        } catch (Exception $e) {
            jsonResponse([
                'success' => false,
                'error' => 'QR Code Generation Error: ' . $e->getMessage()
            ]);
        }
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'error' => 'Error updating profile']);
    }
}

// ✅ Change user password
if ($action === 'change_password') {
    // Accept id OR email and flexible param names
    $id = trim($_POST['id'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $oldPassword = trim($_POST['oldPassword'] ?? ($_POST['current_password'] ?? ''));
    $newPassword = trim($_POST['newPassword'] ?? ($_POST['new_password'] ?? ''));

    if ((!$id && $email === '') || $oldPassword === '' || $newPassword === '') {
        jsonResponse(['success' => false, 'error' => 'Missing required fields']);
    }
    if (strlen($newPassword) < 6) {
        jsonResponse(['success' => false, 'error' => 'New password must be at least 6 characters']);
    }
    if (hash_equals($oldPassword, $newPassword)) {
        jsonResponse(['success' => false, 'error' => 'New password must be different from current password']);
    }

    // Lookup user by id first, fallback to email
    if ($id !== '') {
        $stmt = $pdo->prepare('SELECT id, email, password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare('SELECT id, email, password FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($oldPassword, $user['password'])) {
        jsonResponse(['success' => false, 'error' => 'Current password is incorrect']);
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt2 = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt2->execute([$hash, $user['id']]);

    // Optionally fetch fresh user (without password) for client-side state
    // Include gender if column exists
    $hasGender = col_exists($pdo, 'users', 'gender');
    $selectCols = "id, email, first_name, last_name, client_type, year_course, department, qr_code, photo, student_faculty_id";
    if ($hasGender) { $selectCols .= ', gender'; }
    $stmt3 = $pdo->prepare('SELECT ' . $selectCols . ' FROM users WHERE id = ?');
    $stmt3->execute([$user['id']]);
    $updatedUser = $stmt3->fetch(PDO::FETCH_ASSOC);
    if ($updatedUser && isset($updatedUser['client_type']) && !isset($updatedUser['role'])) {
        $updatedUser['role'] = $updatedUser['client_type'];
    }
    if ($updatedUser) { /* ensure no password field */ }

    jsonResponse(['success' => true, 'message' => 'Password updated successfully', 'user' => $updatedUser]);
}


// ✅ Get full user info for profile
if ($action === 'get_user_full') {
    $id = $_POST['id'] ?? '';
    if (!$id) jsonResponse(['success' => false, 'message' => 'Missing user id']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        if (isset($user['client_type']) && !isset($user['role'])) { $user['role'] = $user['client_type']; }
        jsonResponse(['success' => true, 'user' => $user]);
    } else {
        jsonResponse(['success' => false, 'message' => 'User not found']);
    }
}

// ✅ Get user by student_faculty_id (SID)
if ($action === 'get_user_by_sid') {
    $sid = $_POST['sid'] ?? $_GET['sid'] ?? '';
    if (!$sid) jsonResponse(['success' => false, 'message' => 'Missing sid']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE student_faculty_id = ? LIMIT 1");
    $stmt->execute([$sid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        unset($user['password']);
        if (isset($user['client_type']) && !isset($user['role'])) { $user['role'] = $user['client_type']; }
        jsonResponse(['success' => true, 'user' => $user]);
    } else {
        jsonResponse(['success' => false, 'message' => 'User not found']);
    }
}


jsonResponse(['error' => 'Invalid action']);
