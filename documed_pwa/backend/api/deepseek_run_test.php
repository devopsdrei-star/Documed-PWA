<?php
header('Content-Type: application/json');

// Simple token guard
$expected = @include __DIR__ . '/../config/deepseek_debug_token.php';
$provided = $_GET['t'] ?? $_POST['t'] ?? $_SERVER['HTTP_X_DEBUG_TOKEN'] ?? '';
if (!$expected || $provided !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// Load DeepSeek API key from config or environment (do NOT hardcode real keys in source)
$DEEPSEEK_KEY_FILE = dirname(__DIR__) . '/config/deepseek_key.php';
$DEEPSEEK_API_KEY = '';
if (file_exists($DEEPSEEK_KEY_FILE)) {
    $k = include $DEEPSEEK_KEY_FILE;
    if (is_array($k) && !empty($k['DEEPSEEK_API_KEY'])) { $DEEPSEEK_API_KEY = $k['DEEPSEEK_API_KEY']; }
}
if (!$DEEPSEEK_API_KEY) { $DEEPSEEK_API_KEY = getenv('DEEPSEEK_API_KEY') ?: ''; }

$data = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful medical assistant for a Campus medical clinic.'],
        ['role' => 'user', 'content' => 'Live debug test']
    ],
    'max_tokens' => 64,
    'temperature' => 0.3
];

$ch = curl_init('https://api.deepseek.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $DEEPSEEK_API_KEY
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Include API key status in debug output to help diagnose missing/insufficient balance issues
$debugEntry = [
    'time' => date('c'),
    'http' => $http,
    'curl_error' => $err,
    'has_key' => (bool)$DEEPSEEK_API_KEY,
    'request' => $data,
    'response' => $response
];
@file_put_contents(__DIR__ . '/../../tmp/deepseek_debug.log', json_encode($debugEntry, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

curl_close($ch);

echo json_encode($debugEntry);
