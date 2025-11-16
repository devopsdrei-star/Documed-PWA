<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function ensurePrefs($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS doc_nurse_prefs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            hours JSON NULL,
            slot_per_hour INT DEFAULT 2,
            notify_email TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL,
            UNIQUE KEY uniq_user (user_id)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* ignore */ }
}

function ensureClosures($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dentist_closures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            date DATE NOT NULL,
            reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, date)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* ignore */ }
}

if ($action === 'get') {
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    ensurePrefs($pdo);
    $stmt = $pdo->prepare('SELECT hours, slot_per_hour, notify_email FROM doc_nurse_prefs WHERE user_id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $row = ['hours'=>json_encode(new stdClass()), 'slot_per_hour'=>2, 'notify_email'=>1]; }
    echo json_encode(['success'=>true, 'prefs'=>$row]);
    exit;
}

if ($action === 'save') {
    $id = intval($_POST['id'] ?? 0);
    $hours = $_POST['hours'] ?? '{}';
    $slot = intval($_POST['slot_per_hour'] ?? 2);
    $notify = intval($_POST['notify_email'] ?? 1) ? 1 : 0;
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    ensurePrefs($pdo);
    // Load previous hours to detect new closed weekdays
    $prev = null; try { $q = $pdo->prepare('SELECT hours FROM doc_nurse_prefs WHERE user_id = ?'); $q->execute([$id]); $prev = $q->fetch(PDO::FETCH_ASSOC); } catch (Throwable $ePrev) { $prev = null; }
    $prevHours = [];
    if ($prev && isset($prev['hours'])) { try { $prevHours = json_decode($prev['hours'] ?: '{}', true) ?: []; } catch (Throwable $eJ) { $prevHours = []; } }
    $newHours = []; try { $newHours = json_decode($hours ?: '{}', true) ?: []; } catch (Throwable $eJ2) { $newHours = []; }
    // upsert
    $stmt = $pdo->prepare('INSERT INTO doc_nurse_prefs (user_id, hours, slot_per_hour, notify_email) VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE hours = VALUES(hours), slot_per_hour = VALUES(slot_per_hour), notify_email = VALUES(notify_email), updated_at = NOW()');
    $stmt->execute([$id, $hours, $slot, $notify]);
    // Announce newly closed weekdays (persistent, no expiry)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements ( id INT AUTO_INCREMENT PRIMARY KEY, message TEXT NOT NULL, audience VARCHAR(50) DEFAULT 'All', expires_at DATE NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB");
        $weekMap = [ 'Mon'=>'Monday', 'Tue'=>'Tuesday', 'Wed'=>'Wednesday', 'Thu'=>'Thursday', 'Fri'=>'Friday', 'Sat'=>'Saturday', 'Sun'=>'Sunday' ];
        foreach ($weekMap as $k=>$label) {
            $wasClosed = isset($prevHours[$k]) && !empty($prevHours[$k]['closed']);
            $nowClosed = isset($newHours[$k]) && !empty($newHours[$k]['closed']);
            if (!$wasClosed && $nowClosed) {
                $msg = 'Dental clinic is closed every ' . $label . ' until further notice.';
                $ins = $pdo->prepare('INSERT INTO announcements (message, audience, expires_at) VALUES (?, ?, NULL)');
                $ins->execute([$msg, 'All']);
            }
        }
    } catch (Throwable $eAnn) { /* ignore */ }
    echo json_encode(['success'=>true]);
    exit;
}

// List closures for a dentist
if ($action === 'closures_list') {
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    ensureClosures($pdo);
    $stmt = $pdo->prepare('SELECT id, DATE_FORMAT(date, "%Y-%m-%d") AS date, reason FROM dentist_closures WHERE user_id = ? ORDER BY date DESC');
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'rows'=>$rows]);
    exit;
}

// Add a closure (date + optional reason)
if ($action === 'closures_add') {
    $id = intval($_POST['id'] ?? 0);
    $date = trim($_POST['date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if ($id <= 0 || $date === '') { echo json_encode(['success'=>false,'message'=>'Missing id or date']); exit; }
    // basic date validation YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['success'=>false,'message'=>'Invalid date']); exit; }
    ensureClosures($pdo);
    // prevent duplicates for same date
    $stmt = $pdo->prepare('SELECT id FROM dentist_closures WHERE user_id = ? AND date = ?');
    $stmt->execute([$id, $date]);
    if ($stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Closure already exists for this date']); exit; }
    $stmt = $pdo->prepare('INSERT INTO dentist_closures (user_id, date, reason) VALUES (?, ?, ?)');
    $stmt->execute([$id, $date, $reason !== '' ? $reason : null]);
    // Post an announcement so users see the closure in their notifications (expires on the closure date)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements ( id INT AUTO_INCREMENT PRIMARY KEY, message TEXT NOT NULL, audience VARCHAR(50) DEFAULT 'All', expires_at DATE NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB");
        $msg = 'Dental clinic is closed on ' . $date . ($reason !== '' ? ('. Reason: ' . $reason) : '.');
        $ins = $pdo->prepare('INSERT INTO announcements (message, audience, expires_at) VALUES (?, ?, ?)');
        $ins->execute([$msg, 'All', $date]);
    } catch (Throwable $e) { /* ignore announcement failure */ }
    echo json_encode(['success'=>true]);
    exit;
}

// Delete a closure by id (must belong to user)
if ($action === 'closures_delete') {
    $id = intval($_POST['id'] ?? 0);
    $closure_id = intval($_POST['closure_id'] ?? 0);
    if ($id <= 0 || $closure_id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing ids']); exit; }
    ensureClosures($pdo);
    $stmt = $pdo->prepare('DELETE FROM dentist_closures WHERE id = ? AND user_id = ?');
    $stmt->execute([$closure_id, $id]);
    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action']);
