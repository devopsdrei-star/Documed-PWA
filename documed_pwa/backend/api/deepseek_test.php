<?php
// Quick CLI test for DeepSeek API
// Load key from config or environment so keys are not committed in the repository
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
           ['role' => 'system', 'content' => "You are a helpful assistant for the Campus Medical Clinic (DocuMed). Only answer questions about the Campus Medical Clinic and this system (appointments, hours, locations, clinic services, medical certificates, basic lab tests, referrals, and how to use this platform). If asked for medical diagnoses or treatment advice, decline and advise the user to consult a licensed clinician or go to emergency services when appropriate."],
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
