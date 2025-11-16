<?php
// db_ping.php - Lightweight connectivity & metadata check for Railway MySQL
// Returns: success flag, server version, current database, sample table counts (if lightweight), and env source hints.
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php'; // provides $pdo or exits with 500

$out = [ 'success' => true ];
try {
    $ver = $pdo->query('SELECT VERSION() AS v')->fetchColumn();
    $out['version'] = $ver ?: null;
} catch (Throwable $e) { $out['version'] = null; $out['success'] = false; $out['error_version'] = $e->getMessage(); }

try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $out['database'] = $dbName ?: null;
} catch (Throwable $e) { $out['database'] = null; }

// Additional connection info
try { $out['current_user'] = $pdo->query('SELECT CURRENT_USER()')->fetchColumn() ?: null; } catch (Throwable $e) {}
try { $out['server_hostname'] = $pdo->query('SELECT @@hostname')->fetchColumn() ?: null; } catch (Throwable $e) {}

// Optional quick counts (use small limit & suppress errors individually)
$tables = ['medicines','medicine_batches','patients','appointments','checkups'];
$counts = [];
foreach ($tables as $t) {
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM `$t`");
        $counts[$t] = (int)$q->fetchColumn();
    } catch (Throwable $e) { /* table may not exist */ }
}
$out['counts'] = $counts;

// Show which env vars were used (for debugging deployments)
$out['env_source'] = [
    'MYSQLHOST' => getenv('MYSQLHOST') ? 'set' : 'missing',
    'MYSQLPORT' => getenv('MYSQLPORT') ? 'set' : 'missing',
    'MYSQLUSER' => getenv('MYSQLUSER') ? 'set' : 'missing',
    'MYSQLPASSWORD' => getenv('MYSQLPASSWORD') ? 'set' : 'missing',
    'MYSQLDATABASE' => getenv('MYSQLDATABASE') ? 'set' : 'missing',
    'MYSQL_URL' => getenv('MYSQL_URL') ? 'set' : 'missing',
];

// Which path likely used by db.php
if (isset($GLOBALS['DM_CONN_META']) && is_array($GLOBALS['DM_CONN_META'])) {
    $out['resolved'] = $GLOBALS['DM_CONN_META'];
}
$out['env_choice'] = getenv('MYSQLHOST') ? 'individual_vars' : (getenv('MYSQL_URL') || getenv('MYSQL_PUBLIC_URL') ? 'url' : 'fallback_local');

http_response_code($out['success'] ? 200 : 500);
echo json_encode($out);
