<?php
// backend/config/db.php
//
// Auto-detects Railway or local MySQL credentials
// Supports MYSQL_URL and discrete MYSQL* variables

// Optional .env loader (local dev): reads key=value from ./config/.env
// and populates environment if not already set.
$__envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
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
            // Skip placeholder or empty values
            if ($v === '*******' || $v === '') continue;
            if ($k !== '' && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
    }
}

$envUrl = getenv('MYSQL_PUBLIC_URL') 
    ?: getenv('MYSQL_URL') 
    ?: getenv('DB_URL') 
    ?: getenv('DATABASE_URL');

$host = $port = $dbName = $user = $pass = null;

if ($envUrl) {
    // Parse connection string like:
    // mysql://root:password@mysql.railway.internal:3306/railway
    $u = parse_url($envUrl);
    if ($u && !empty($u['host'])) {
        $host   = $u['host'];
        $port   = isset($u['port']) ? (int)$u['port'] : 3306;
    $user   = $u['user'] ?? 'root';
    $pass   = $u['pass'] ?? '';
        $path   = $u['path'] ?? '/railway';
        $dbName = ltrim($path, '/');
    }
}

// If no URL vars, fallback to discrete vars (Railway-compatible)
if (!$host)   $host   = getenv('MYSQLHOST') ?: '127.0.0.1';
if (!$port)   $port   = (int)(getenv('MYSQLPORT') ?: 3306);
if (!$dbName) $dbName = getenv('MYSQLDATABASE') ?: (getenv('MYSQL_DATABASE') ?: 'db_med');
if (!$user)   $user   = getenv('MYSQLUSER') ?: 'root';
if (!$pass)   $pass   = getenv('MYSQLPASSWORD') ?: (getenv('MYSQL_ROOT_PASSWORD') ?: '');

// Build DSN
$dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

// PDO Options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Optional debug (safe): set DEBUG_DB=1 to log target host/port/db (no password)
if (getenv('DEBUG_DB')) {
    error_log("[DB] target host={$host} port={$port} db={$dbName} user={$user}");
}

// Connection timeout and retry controls (useful for public proxy flaps in local dev)
$options[PDO::ATTR_TIMEOUT] = max(1, (int)(getenv('DB_CONNECT_TIMEOUT') ?: 5));
$maxRetries = max(0, (int)(getenv('DB_CONNECT_RETRIES') ?: 2)); // total attempts = 1 + retries
$retryDelayMs = max(0, (int)(getenv('DB_CONNECT_RETRY_DELAY_MS') ?: 400));

$attempt = 0; $lastErr = null;
do {
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        // Optional debug log
        // error_log("Connected to DB: {$dbName} @ {$host}:{$port}");
        $lastErr = null;
        break;
    } catch (PDOException $e) {
        $lastErr = $e;
        $msg = $e->getMessage();
        $code = (string)$e->getCode();
        $shouldRetry = (
            strpos($msg, 'SQLSTATE[HY000] [2002]') !== false ||
            stripos($msg, 'getaddrinfo') !== false ||
            stripos($msg, 'timed out') !== false ||
            stripos($msg, 'refused') !== false
        );
        if ($attempt >= $maxRetries || !$shouldRetry) {
            error_log('DB connection failed: ' . $msg);
            throw $e; // bubble up
        }
        if ($retryDelayMs > 0) usleep($retryDelayMs * 1000);
    }
    $attempt++;
} while ($attempt <= $maxRetries);
