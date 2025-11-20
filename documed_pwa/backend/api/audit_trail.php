<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Helpers to cope with schema differences across environments
function resolve_column_name($pdo, $table, $candidates) {
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

if ($action === 'list') {
    // Optional filters: start_date, end_date (YYYY-MM-DD), admin (id or name), q (search in action/details), limit
    $start = $_GET['start_date'] ?? $_POST['start_date'] ?? '';
    $end = $_GET['end_date'] ?? $_POST['end_date'] ?? '';
    $admin = $_GET['admin'] ?? $_POST['admin'] ?? '';
    $q = $_GET['q'] ?? $_POST['q'] ?? '';
    $limit = intval($_GET['limit'] ?? $_POST['limit'] ?? 200);
    // Default behavior: include all rows even if the related admin account no longer exists.
    $adminsOnly = $_GET['admins_only'] ?? $_POST['admins_only'] ?? '0';
    if ($limit <= 0 || $limit > 1000) $limit = 200;

    // Resolve timestamp column with sensible fallbacks
    $tsCol = resolve_column_name($pdo, 'audit_trail', ['timestamp','created_at','createdAt','date_time','datetime','logged_at','time']) ?: 'timestamp';

    $where = [];
    $params = [];
    if ($start) { $where[] = "a.$tsCol >= ?"; $params[] = $start . ' 00:00:00'; }
    if ($end) { $where[] = "a.$tsCol <= ?"; $params[] = $end . ' 23:59:59'; }
    if ($admin) {
        // allow numeric id or name partial
        if (ctype_digit($admin)) {
            $where[] = 'a.admin_id = ?';
            $params[] = (int)$admin;
        } else {
            $where[] = 'ad.name LIKE ?';
            $params[] = '%' . $admin . '%';
        }
    }
    if ($q) {
        $where[] = '(`a`.`action` LIKE ? OR `a`.`details` LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql = "SELECT a.admin_id, a.action, a.details, a.$tsCol AS timestamp, ad.name AS admin_name FROM audit_trail a LEFT JOIN admins ad ON a.admin_id=ad.id";
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    if ($adminsOnly && $adminsOnly !== '0' && $adminsOnly !== 'false') {
        $sql .= ($where ? ' AND ' : ' WHERE ') . 'ad.id IS NOT NULL';
    }
    $sql .= ' ORDER BY ' . $tsCol . ' DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}

if ($action === 'add') {
    $admin_id = $_POST['admin_id'] ?? '';
    $action_txt = $_POST['action_txt'] ?? '';
    $details = $_POST['details'] ?? '';
    if (!$admin_id || !$action_txt) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$admin_id, $action_txt, $details]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);