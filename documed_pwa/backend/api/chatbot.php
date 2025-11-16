<?php
// backend/api/chatbot.php
header('Content-Type: application/json');

// Load OpenAI API key securely from config file or environment
$openaiKeyFile = dirname(__DIR__) . '/config/openai_key.php';
$OPENAI_API_KEY = '';
if (file_exists($openaiKeyFile)) {
    $k = include $openaiKeyFile;
    if (is_array($k) && !empty($k['OPENAI_API_KEY'])) { $OPENAI_API_KEY = $k['OPENAI_API_KEY']; }
}
if (!$OPENAI_API_KEY) { $OPENAI_API_KEY = getenv('OPENAI_API_KEY') ?: ''; }
if (!$OPENAI_API_KEY) {
    echo json_encode(['error'=>'Missing OpenAI API key configuration']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
if (!$userMessage) {
    echo json_encode(['error' => 'No message provided']);
    exit;
}

// Prepare OpenAI API request
$ch = curl_init('https://api.openai.com/v1/chat/completions');
$data = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful medical assistant for a dental clinic.'],
        ['role' => 'user', 'content' => $userMessage]
    ],
    'max_tokens' => 256,
    'temperature' => 0.7
];
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $OPENAI_API_KEY
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'OpenAI API error: ' . $err]);
    exit;
}

$resData = json_decode($response, true);
$aiReply = $resData['choices'][0]['message']['content'] ?? '';

if (!isset($resData['choices'][0]['message']['content'])) {
    echo json_encode([
        'error' => 'No response from AI',
        'openai_status' => $resData['error']['message'] ?? ($resData['error'] ?? 'No error field'),
        'raw' => $response,
        'data' => $resData
    ]);
    exit;
}

echo json_encode(['reply' => $aiReply]);
