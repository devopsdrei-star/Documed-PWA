<?php
header('Content-Type: application/json');

$expected = @include __DIR__ . '/../config/deepseek_debug_token.php';
$provided = $_GET['t'] ?? $_POST['t'] ?? $_SERVER['HTTP_X_DEBUG_TOKEN'] ?? '';
if (!$expected || $provided !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$file = __DIR__ . '/../config/fallback_faq.json';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!file_exists($file)) { echo json_encode(new stdClass()); exit; }
    echo file_get_contents($file);
    exit;
}

// Update
$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
echo json_encode(['ok' => true]);
