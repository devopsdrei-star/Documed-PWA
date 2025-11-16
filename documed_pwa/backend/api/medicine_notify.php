<?php
// Automated Medicine Inventory & Decision Digest Email
// Sends a consolidated email (low stock, expiring soon, restock & orientation recommendations)
// Trigger manually or via scheduled task (cron/Task Scheduler):
//   curl "https://yourhost/backend/api/medicine_notify.php?key=SECRET"
// Requirements: Configure SMTP in backend/config/email.local.php
// Optional email.local.php entries:
//   DIGEST_KEY => 'your-secret'          // auth key
//   DIGEST_RECIPIENTS => ['admin@site.tld','nurse@site.tld'] // override recipient list
//   DIGEST_SEND_ORIENTATION => true      // include orientation block
//   DIGEST_MIN_INTERVAL_MINUTES => 30    // throttle window
//   DIGEST_FORCE => true                 // bypass dedupe (for testing)

header('Content-Type: application/json');
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/email.php';

// Load local config
$cfgFile = dirname(__DIR__) . '/config/email.local.php';
$cfg = [];
if (file_exists($cfgFile)) { $tmp = include $cfgFile; if (is_array($tmp)) $cfg = $tmp; }

$keyProvided = $_GET['key'] ?? $_POST['key'] ?? '';
// Allow CLI usage: php medicine_notify.php key=SECRET [force=1]
if (PHP_SAPI === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, 'key=') === 0) { $keyProvided = substr($arg, 4); }
        if ($arg === 'force=1') { $force = true; }
    }
}
$digestKey = $cfg['DIGEST_KEY'] ?? getenv('DIGEST_KEY') ?? '';
if (!$digestKey || !$keyProvided || !hash_equals((string)$digestKey, (string)$keyProvided)) {
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$cacheDir = dirname(__DIR__) . '/tmp';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
$cacheFile = $cacheDir . '/medicine_digest_last.json';

// Throttle window
$minIntervalMin = (int)($cfg['DIGEST_MIN_INTERVAL_MINUTES'] ?? getenv('DIGEST_MIN_INTERVAL_MINUTES') ?? 60);
$force = !empty($cfg['DIGEST_FORCE']) || ($_GET['force'] ?? '') === '1';
if (file_exists($cacheFile) && !$force) {
    $prev = json_decode(@file_get_contents($cacheFile), true);
    if (is_array($prev) && !empty($prev['sent_at'])) {
        try {
            $prevTime = new DateTime($prev['sent_at']);
            $diffMin = ($nowUtc->getTimestamp() - $prevTime->getTimestamp())/60;
            if ($diffMin < $minIntervalMin) {
                echo json_encode(['success'=>false,'error'=>'Throttled','minutes_since'=>$diffMin,'required'=>$minIntervalMin]);
                exit;
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

// Helper to fetch suggestions (AI + heuristic)
function fetchSuggestions(): array {
    $url = basename(__DIR__) === 'api'
        ? (dirname(__DIR__) . '/api/medicine.php?action=suggestions&days=30')
        : 'medicine.php?action=suggestions&days=30';
    // Direct include not ideal because medicine.php echoes; use curl to itself if server accessible.
    // Fallback: replicate minimal stats from DB if curl fails.
    if (function_exists('curl_init')) {
        $base = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        if ($base) { $urlFull = $scheme . $base . '/backend/api/medicine.php?action=suggestions&days=30'; }
        else { $urlFull = $url; }
        $ch = curl_init($urlFull);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
        $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if (!$err && $resp) {
            $j = json_decode($resp, true);
            if (is_array($j) && !empty($j['success'])) return $j;
        }
    }
    return ['success'=>false];
}

// Low stock & expiring (reuse logic similar to medicine.php without duplicate code via queries)
function getAlerts(PDO $pdo, string $campus='Lingayen'): array {
    $stmt = $pdo->prepare('SELECT * FROM medicines WHERE campus=? ORDER BY name');
    $stmt->execute([$campus]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $low = []; $exp = [];
    $now = new DateTime('today');
    foreach ($rows as $m) {
        $percent = (int)($m['reorder_threshold_percent'] ?? 20);
        $baseline = $m['baseline_qty'] !== null ? (int)$m['baseline_qty'] : (int)$m['quantity'];
        $thr = (int)floor(($baseline * max(1,$percent))/100); $thr = max(1,$thr);
        if ((int)$m['quantity'] < $thr) { $m['threshold_qty']=$thr; $low[]=$m; }
        // nearest expiry
        try {
            $q=$pdo->prepare('SELECT expiry_date FROM medicine_batches WHERE medicine_id=? AND campus=? AND expiry_date IS NOT NULL AND qty>0 ORDER BY expiry_date ASC LIMIT 1');
            $q->execute([$m['id'],$campus]);
            $expDate=$q->fetchColumn();
            if ($expDate) {
                $dd = new DateTime($expDate);
                $diff=(int)$now->diff($dd)->format('%r%a');
                if ($diff>=0 && $diff<=60) { $m['nearest_expiry']=$expDate; $m['days_to_expiry']=$diff; $exp[]=$m; }
            }
        } catch (Throwable $e) { /* ignore */ }
    }
    return ['low'=>$low,'expiring'=>$exp];
}

$suggest = fetchSuggestions();
$alerts = getAlerts($pdo);

// Build digest content
$lowHtml = $alerts['low'] ? ('<ul>' . implode('', array_map(function($m){
    return '<li>' . htmlspecialchars($m['name']) . ' — ' . (int)$m['quantity'] . ' left (thr ' . (int)$m['threshold_qty'] . ')</li>';
}, $alerts['low'])) . '</ul>') : '<p>No low-stock items.</p>';

$expHtml = $alerts['expiring'] ? ('<ul>' . implode('', array_map(function($m){
    return '<li>' . htmlspecialchars($m['name']) . ' — expires ' . htmlspecialchars($m['nearest_expiry']) . ' (' . (int)$m['days_to_expiry'] . 'd)</li>';
}, $alerts['expiring'])) . '</ul>') : '<p>No items expiring in 60 days.</p>';

$restockHtml = '<p>No AI restock suggestions.</p>';
$orientHtml = '<p>No orientation recommendations.</p>';
if (!empty($suggest['actions'])) {
    if (!empty($suggest['actions']['restock'])) {
        $restockHtml = '<ul>' . implode('', array_map(function($r){
            $tag = !$r['in_inventory'] ? 'missing' : ($r['low_stock'] ? 'low stock' : 'ok');
            return '<li>' . htmlspecialchars($r['name']) . ' (' . htmlspecialchars($tag) . ') for ' . htmlspecialchars($r['linked_condition']) . '</li>';
        }, $suggest['actions']['restock'])) . '</ul>';
    }
    if (!empty($suggest['actions']['orientation']) && !empty($cfg['DIGEST_SEND_ORIENTATION'])) {
        $orientHtml = '<ul>' . implode('', array_map(function($o){
            return '<li>' . htmlspecialchars($o['condition']) . ' — ' . (int)$o['month_cases'] . ' cases (' . (int)$o['week_cases'] . ' last 7d)</li>';
        }, $suggest['actions']['orientation'])) . '</ul>';
    }
}

$summary = !empty($suggest['narrative']) ? htmlspecialchars($suggest['narrative']) : 'No trend narrative available.';

$htmlBody = '<h2>DocuMed Medicine & Condition Digest</h2>' .
    '<p><strong>Generated:</strong> ' . $nowUtc->format('Y-m-d H:i') . ' UTC</p>' .
    '<h3>Summary</h3><p>' . $summary . '</p>' .
    '<h3>Low Stock</h3>' . $lowHtml .
    '<h3>Expiring Soon (≤60d)</h3>' . $expHtml .
    '<h3>Restock Suggestions</h3>' . $restockHtml .
    (!empty($cfg['DIGEST_SEND_ORIENTATION']) ? ('<h3>Orientation Candidates</h3>' . $orientHtml) : '') .
    '<hr><small>Automated digest. Validate clinically before action. To reduce emails, adjust DIGEST_MIN_INTERVAL_MINUTES.</small>';

// Dedupe: hash content
$hash = hash('sha256', $htmlBody);
if (file_exists($cacheFile) && !$force) {
    $prev = json_decode(@file_get_contents($cacheFile), true);
    if (is_array($prev) && isset($prev['hash']) && $prev['hash'] === $hash) {
        echo json_encode(['success'=>false,'error'=>'No change since last digest','hash'=>$hash]);
        exit;
    }
}

// Determine recipients
$recipients = [];
if (!empty($cfg['DIGEST_RECIPIENTS']) && is_array($cfg['DIGEST_RECIPIENTS'])) {
    $recipients = $cfg['DIGEST_RECIPIENTS'];
} else {
    // fallback: all active admins
    try {
        $rs = $pdo->query('SELECT email FROM admins WHERE status="active"');
        $recipients = array_filter(array_map(fn($r)=>$r['email'] ?? '', $rs->fetchAll(PDO::FETCH_ASSOC))); 
    } catch (Throwable $e) { /* ignore */ }
}
$recipients = array_values(array_unique(array_filter($recipients, fn($e)=>filter_var($e, FILTER_VALIDATE_EMAIL))));
if (!$recipients) {
    echo json_encode(['success'=>false,'error'=>'No valid recipients']);
    exit;
}

$sent = [];
foreach ($recipients as $to) {
    $res = send_email($to, 'DocuMed Digest: Inventory & Trends', $htmlBody);
    $sent[] = ['email'=>$to,'result'=>$res];
}

@file_put_contents($cacheFile, json_encode(['sent_at'=>$nowUtc->format(DateTime::ATOM),'hash'=>$hash,'recipients'=>$recipients], JSON_PRETTY_PRINT));

echo json_encode(['success'=>true,'recipients'=>$sent,'hash'=>$hash]);
?>