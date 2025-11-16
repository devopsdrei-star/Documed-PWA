<?php
// Simple DB connection test. Intended to be run from CLI or browser for diagnostics.
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/db.php';

    if (!isset($pdo) || !$pdo) {
        echo json_encode(['success' => false, 'message' => 'PDO not initialized']);
        exit;
    }
    $row = $pdo->query('SELECT VERSION() as v')->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'version' => $row['v'] ?? null]);
} catch (Throwable $e) {
    http_response_code(500);
    $debug = null;
    if (getenv('DEBUG_DB')) {
        // Re-resolve target for helpful diagnostics (no secrets)
        $envUrl = getenv('MYSQL_URL') 
            ?: getenv('MYSQL_PUBLIC_URL') 
            ?: getenv('DB_URL') 
            ?: getenv('DATABASE_URL');
        $host = $port = $db = $user = null;
        if ($envUrl) {
            $u = parse_url($envUrl);
            if ($u && !empty($u['host'])) {
                $host = $u['host'];
                $port = isset($u['port']) ? (int)$u['port'] : 3306;
                $user = $u['user'] ?? 'root';
                $path = $u['path'] ?? '/railway';
                $db   = ltrim($path, '/');
            }
        }
        if (!$host)   $host = getenv('MYSQLHOST') ?: '127.0.0.1';
        if (!$port)   $port = (int)(getenv('MYSQLPORT') ?: 3306);
        if (!$db)     $db   = getenv('MYSQLDATABASE') ?: 'db_med';
        if (!$user)   $user = getenv('MYSQLUSER') ?: 'root';
        $debug = ['host' => $host, 'port' => $port, 'db' => $db, 'user' => $user];
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => $debug,
    ]);
}
