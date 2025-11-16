<?php
// Quick CLI test for DeepSeek API
$DEEPSEEK_API_KEY = 'sk-fd9a3334a522478fae7964b580ba3ec5';
$data = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful medical assistant for a dental clinic.'],
        ['role' => 'user', 'content' => 'Hello from CLI test']
    ],
    'max_tokens' => 128,
    'temperature' => 0.7
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

echo "HTTP_CODE: " . $http . PHP_EOL;
echo "CURL_ERROR: " . ($err ?: '<none>') . PHP_EOL;
echo "RESPONSE:\n";
if ($response === false) {
    echo "<no response>\n";
} else {
    echo $response . PHP_EOL;
}

curl_close($ch);

// Exit with non-zero code if error for CI-friendly checks
if ($err || $http < 200 || $http >= 300) exit(1);
