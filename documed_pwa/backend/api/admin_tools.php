<?php
require_once dirname(__DIR__) . '/config/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json');

function jsonResponse($arr) { echo json_encode($arr); exit; }

function ensureTable($pdo, $sql) { try { $pdo->exec($sql); } catch (Throwable $e) { /* ignore */ } }

function auditLog($pdo, $action, $details='') {
    try {
        $admin_id = 1; // TODO: read from session
        $stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)");
        $stmt->execute([$admin_id, $action, $details]);
    } catch (Throwable $e) {}
}

// Ensure settings and announcements tables
ensureTable($pdo, "CREATE TABLE IF NOT EXISTS settings ( `key` VARCHAR(100) PRIMARY KEY, `value` TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ) ENGINE=InnoDB");
ensureTable($pdo, "CREATE TABLE IF NOT EXISTS announcements ( id INT AUTO_INCREMENT PRIMARY KEY, message TEXT NOT NULL, audience VARCHAR(50) DEFAULT 'All', expires_at DATE NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB");
// Try to add expires_at if missing (idempotent)
try { $pdo->exec("ALTER TABLE announcements ADD COLUMN expires_at DATE NULL"); } catch (Throwable $e) { /* ignore */ }

if ($action === 'settings_get') {
    $stmt = $pdo->query('SELECT `key`,`value` FROM settings');
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    jsonResponse(['success'=>true,'settings'=>$rows]);
}

if ($action === 'settings_set') {
    $key = $_POST['key'] ?? '';
    $value = $_POST['value'] ?? '';
    if ($key === '') jsonResponse(['success'=>false,'message'=>'Missing key']);
    $stmt = $pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $stmt->execute([$key, $value]);
    auditLog($pdo, 'Updated setting', $key);
    jsonResponse(['success'=>true]);
}

if ($action === 'announcements_post') {
    $message = trim($_POST['message'] ?? '');
    $audience = trim($_POST['audience'] ?? 'All');
    $expires_at = trim($_POST['expires_at'] ?? '');
    if ($message === '') jsonResponse(['success'=>false,'message'=>'Message required']);
    $expires_sql = null;
    if ($expires_at !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires_at)) jsonResponse(['success'=>false,'message'=>'Invalid expires_at']);
        $expires_sql = $expires_at;
    }
    $stmt = $pdo->prepare('INSERT INTO announcements(message,audience,expires_at) VALUES(?,?,?)');
    $stmt->execute([$message, $audience, $expires_sql]);
    auditLog($pdo, 'Posted announcement', substr($message,0,60));
    jsonResponse(['success'=>true]);
}

if ($action === 'announcements_list') {
    $limit = intval($_GET['limit'] ?? 20);
    $includeExpired = isset($_GET['includeExpired']) ? (bool)intval($_GET['includeExpired']) : false;
    if ($includeExpired) {
        $stmt = $pdo->prepare('SELECT id,message,audience,expires_at,created_at FROM announcements ORDER BY id DESC LIMIT ?');
    } else {
        $stmt = $pdo->prepare('SELECT id,message,audience,expires_at,created_at FROM announcements WHERE expires_at IS NULL OR expires_at >= CURDATE() ORDER BY id DESC LIMIT ?');
    }
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success'=>true,'announcements'=>$rows]);
}

if ($action === 'export_csv') {
    $type = $_GET['type'] ?? 'users';
    // Build dataset
    $rows = [];
    $headers = [];
    try {
        if ($type === 'users') {
            $stmt = $pdo->query('SELECT id, student_faculty_id, last_name, first_name, middle_initial, email, role, COALESCE(status, "active") AS status, created_at FROM users');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($type === 'patients') {
            $stmt = $pdo->query('SELECT * FROM patients');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($type === 'appointments') {
            // Try union of primary and pwa
            try {
                $stmt = $pdo->query('SELECT id,name,email,role,year_course,department,purpose,date,time,status,created_at FROM appointments');
                $a = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $a = []; }
            try {
                $stmt = $pdo->query('SELECT id,name,email,role,year_course,department,purpose,date,time,status,created_at FROM appointments_pwa');
                $b = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $b = []; }
            $rows = array_merge($a, $b);
        } elseif ($type === 'checkups') {
            $stmt = $pdo->query('SELECT * FROM checkups');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            jsonResponse(['success'=>false,'message'=>'Unknown export type']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Query failed']);
    }
    if (!$rows) { $rows = []; }
    if ($rows) { $headers = array_keys($rows[0]); }
    // Output CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$type.'_export_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output', 'w');
    if ($headers) fputcsv($out, $headers);
    foreach ($rows as $r) { fputcsv($out, array_values($r)); }
    fclose($out);
    exit;
}

// JSON export for backups
if ($action === 'export_json') {
    $type = $_GET['type'] ?? 'all';
    $payload = [];
    try {
        if ($type === 'users' || $type === 'all') {
            $stmt = $pdo->query('SELECT * FROM users');
            $payload['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($type === 'settings' || $type === 'all') {
            $stmt = $pdo->query('SELECT `key`,`value`,`updated_at` FROM settings');
            $payload['settings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($type === 'announcements' || $type === 'all') {
            $stmt = $pdo->query('SELECT id,message,audience,expires_at,created_at FROM announcements ORDER BY id');
            $payload['announcements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($type === 'patients' || $type === 'all') {
            $stmt = $pdo->query('SELECT * FROM patients');
            $payload['patients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($type === 'appointments' || $type === 'all') {
            $apps = [];
            try { $apps = array_merge($apps, $pdo->query('SELECT * FROM appointments')->fetchAll(PDO::FETCH_ASSOC)); } catch (Throwable $e) {}
            try { $apps = array_merge($apps, $pdo->query('SELECT * FROM appointments_pwa')->fetchAll(PDO::FETCH_ASSOC)); } catch (Throwable $e) {}
            $payload['appointments'] = $apps;
        }
        if ($type === 'checkups' || $type === 'all') {
            $stmt = $pdo->query('SELECT * FROM checkups');
            $payload['checkups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($type === 'audit_trail' || $type === 'all') {
            $stmt = $pdo->query('SELECT * FROM audit_trail ORDER BY id DESC LIMIT 5000');
            $payload['audit_trail'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Export failed']);
    }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="'.($type === 'all' ? 'backup_all' : $type).'_'.date('Ymd_His').'.json"');
    echo json_encode(['exported_at'=>date('c'),'data'=>$payload]);
    exit;
}

jsonResponse(['success'=>false,'message'=>'Invalid action']);
