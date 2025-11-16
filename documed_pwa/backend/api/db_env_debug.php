<?php
// backend/api/db_env_debug.php
// Show resolved DB configuration (without connecting) to help verify Railway/local env.
header('Content-Type: application/json');

// Minimal .env loader to reflect local .env in this debug endpoint too
$__envPath = __DIR__ . '/../config/.env';
if (@is_readable($__envPath)) {
    $lines = @file($__envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#') continue;
            $pos = strpos($ln, '=');
            if ($pos === false) continue;
            $k = trim(substr($ln, 0, $pos));
            $v = trim(substr($ln, $pos + 1));
            $v = trim($v, " \t\n\r\0\x0B\"'" );
            if ($v === '*******' || $v === '') continue; // skip placeholders
            if ($k !== '' && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
    }
}

function parseUrl($url) {
    $res = [
        'source' => 'none', 'host' => null, 'port' => null, 'db' => null, 'user' => null, 'hasPassword' => false
    ];
    if (!$url) return $res;
    $u = parse_url($url);
    if (!$u || empty($u['host'])) return $res;
    $res['source'] = 'url';
    $res['host'] = $u['host'];
    $res['port'] = isset($u['port']) ? (int)$u['port'] : 3306;
    $res['user'] = $u['user'] ?? 'root';
    $res['hasPassword'] = array_key_exists('pass', $u) && strlen($u['pass']) > 0;
    $path = $u['path'] ?? '/railway';
    $res['db'] = ltrim($path, '/');
    return $res;
}

$onRailway = getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PROJECT_ID') || getenv('RAILWAY_STATIC_URL');

$envs = [
    'MYSQL_PUBLIC_URL' => getenv('MYSQL_PUBLIC_URL') ?: null,
    'MYSQL_URL'        => getenv('MYSQL_URL') ?: null,
    'DB_URL'           => getenv('DB_URL') ?: null,
    'DATABASE_URL'     => getenv('DATABASE_URL') ?: null,
];
// Prefer public URL for local dev
$resolved = parseUrl($envs['MYSQL_PUBLIC_URL'] ?: $envs['MYSQL_URL'] ?: $envs['DB_URL'] ?: $envs['DATABASE_URL']);

// If still not resolved via URL, try discrete vars
if (!$resolved['host']) {
    $resolved['source'] = 'discrete';
    $resolved['host'] = getenv('MYSQLHOST') ?: '127.0.0.1';
    $resolved['port'] = (int)(getenv('MYSQLPORT') ?: 3306);
    $resolved['db']   = getenv('MYSQLDATABASE') ?: 'db_med';
    $resolved['user'] = getenv('MYSQLUSER') ?: 'root';
    $resolved['hasPassword'] = getenv('MYSQLPASSWORD') !== false && getenv('MYSQLPASSWORD') !== null && getenv('MYSQLPASSWORD') !== '';
}

// Build notes
$notes = [];
if (!$onRailway) $notes[] = 'Not detected as Railway runtime (local dev likely).';
if ($resolved['source'] === 'discrete' && $resolved['host'] === '127.0.0.1') {
    $notes[] = 'Using local defaults; set MYSQL_PUBLIC_URL or MYSQL_* vars to connect to Railway from your laptop.';
}
if ($resolved['source'] === 'url' && stripos($envs['MYSQL_PUBLIC_URL'] ?? '', 'proxy.rlwy.net') !== false) {
    $notes[] = 'Public proxy URL detected (good for local dev).';
}
if ($resolved['source'] === 'url' && stripos($envs['MYSQL_URL'] ?? '', 'mysql.railway.internal') !== false) {
    $notes[] = 'Internal Railway URL detected (works only inside Railway deployment).';
}

$out = [
    'onRailway' => (bool)$onRailway,
    'envsPresent' => array_keys(array_filter($envs, fn($v) => !empty($v))),
    'resolved' => $resolved,
    'notes' => $notes,
];

echo json_encode($out);
