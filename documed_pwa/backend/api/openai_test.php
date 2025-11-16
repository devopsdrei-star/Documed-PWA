<?php
// CLI test for OpenAI; loads key from config/env
$OPENAI_API_KEY = '';
$openaiKeyFile = dirname(__DIR__) . '/config/openai_key.php';
if (file_exists($openaiKeyFile)) {
    $k = include $openaiKeyFile; if (is_array($k) && !empty($k['OPENAI_API_KEY'])) { $OPENAI_API_KEY = $k['OPENAI_API_KEY']; }
}
if (!$OPENAI_API_KEY) { $OPENAI_API_KEY = getenv('OPENAI_API_KEY') ?: ''; }
if (!$OPENAI_API_KEY) { fwrite(STDERR, "Missing OPENAI_API_KEY\n"); exit(1); }

$data = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful medical assistant for a dental clinic.'],
        ['role' => 'user', 'content' => 'Hello from OpenAI CLI test']
    ],
    'max_tokens' => 128,
    'temperature' => 0.7
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $OPENAI_API_KEY
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP_CODE: " . $http . PHP_EOL;
echo "CURL_ERROR: " . ($err ?: '<none>') . PHP_EOL;
echo "RESPONSE:\n";
if ($response === false) {
    echo "<no response>\n";
} else {
    echo $response . PHP_EOL;
}

curl_close($ch);

if ($err || $http < 200 || $http >= 300) exit(1);
