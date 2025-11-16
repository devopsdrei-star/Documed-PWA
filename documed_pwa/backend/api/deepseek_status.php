<?php
// Return last chunk of debug log for DeepSeek
header('Content-Type: text/plain; charset=utf-8');
// Simple token guard
$expected = @include __DIR__ . '/../config/deepseek_debug_token.php';
$provided = $_GET['t'] ?? $_POST['t'] ?? $_SERVER['HTTP_X_DEBUG_TOKEN'] ?? '';
if (!$expected || $provided !== $expected) {
    http_response_code(403);
    echo "<forbidden>";
    exit;
}

$log = __DIR__ . '/../../tmp/deepseek_debug.log';
if (!file_exists($log)) {
    echo "<no log file>";
    exit;
}
$content = file_get_contents($log);
// Return last 2000 chars to avoid huge outputs
if (strlen($content) > 20000) {
    $content = substr($content, -20000);
}
echo $content;
