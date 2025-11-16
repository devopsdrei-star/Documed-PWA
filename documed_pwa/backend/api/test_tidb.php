<?php
// TiDB connectivity test endpoint
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/config/db.php';

try {
    // Simple query to verify connection & get server version
    $stmt = $pdo->query('SELECT VERSION() as version');
    $row = $stmt->fetch();
    echo json_encode([
        'success' => true,
        'version' => $row['version'] ?? null,
        'driver'  => 'pdo_mysql_tidb',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'TiDB test failed',
        'detail' => $e->getMessage(),
    ]);
}
