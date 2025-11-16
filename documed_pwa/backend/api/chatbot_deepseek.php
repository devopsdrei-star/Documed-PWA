<?php
// backend/api/chatbot_deepseek.php
header('Content-Type: application/json');

// Load OpenAI key securely from config or env
$openaiKeyFile = dirname(__DIR__) . '/config/openai_key.php';
$OPENAI_API_KEY = '';
if (file_exists($openaiKeyFile)) {
    $k = include $openaiKeyFile;
    if (is_array($k) && !empty($k['OPENAI_API_KEY'])) { $OPENAI_API_KEY = $k['OPENAI_API_KEY']; }
}
if (!$OPENAI_API_KEY) { $OPENAI_API_KEY = getenv('OPENAI_API_KEY') ?: ''; }
if (!$OPENAI_API_KEY) { echo json_encode(['error'=>'Missing OpenAI API key configuration']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
$langParam = trim(strtolower($input['lang'] ?? ($_GET['lang'] ?? '')));
if (!$userMessage) {
    echo json_encode(['error' => 'No message provided']);
    exit;
}

$system_prompt = 'You are a helpful medical assistant for a dental clinic. Always reply in English, even if the user writes in another language. Keep answers concise and friendly.';

// Call OpenAI Chat Completions API as primary provider
$ch = curl_init('https://api.openai.com/v1/chat/completions');
$payload = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
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
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log OpenAI call to tmp/openai_debug.log for diagnosis
$debugEntry = [
    'time' => date('c'),
    'request' => $payload,
    'http_code' => $httpCode,
    'curl_error' => $err,
    'response' => $response
];
@file_put_contents(__DIR__ . '/../../tmp/openai_debug.log', json_encode($debugEntry, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

$aiReply = '';
if ($err) {
    @file_put_contents(__DIR__ . '/../../tmp/openai_debug.log', json_encode(['time'=>date('c'),'note'=>'openai_curl_error','err'=>$err], JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
} else {
    $openaiData = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $aiReply = $openaiData['choices'][0]['message']['content'] ?? '';
    } else {
        @file_put_contents(__DIR__ . '/../../tmp/openai_debug.log', json_encode(['time'=>date('c'),'note'=>'openai_invalid_json','json_error'=>json_last_error_msg(),'response'=>$response], JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
    }
}

if ($aiReply) {
    echo json_encode(['reply' => $aiReply]);
    exit;
}

// Local rule-based fallback: match on combined FAQ cues but always return the English text when available.
$faqFile = __DIR__ . '/../config/fallback_faq.json';
$allFaq = [];
if (file_exists($faqFile)) {
    $allFaq = json_decode(file_get_contents($faqFile), true) ?: [];
}
$faq_en = $allFaq['en'] ?? [];
$faq_fil = $allFaq['fil'] ?? [];
$faq_for_match = array_merge($faq_en, $faq_fil);

function match_faq_force_en($msg, $faq_for_match, $faq_en, $faq_fil) {
    $m = strtolower($msg);
    if ($m === '') return $faq_en['default'] ?? ($faq_fil['default'] ?? 'Sorry, AI is not available right now.');
    if (preg_match('/(hours|open|close|schedule|oras|bukas|sarado)/i', $m) && isset($faq_for_match['hours'])) {
        return $faq_en['hours'] ?? ($faq_fil['hours'] ?? 'Our clinic hours are Mon–Fri 08:00–17:00.');
    }
    if (preg_match('/(appointment|book|schedule|magpa-appointment|magpa appointment|book an appointment)/i', $m) && isset($faq_for_match['appointments'])) {
        return $faq_en['appointments'] ?? ($faq_fil['appointments'] ?? 'To book an appointment, please use the Book Appointment page or call the clinic.');
    }
    if (preg_match('/(contact|phone|call|email|tawag|kontakt)/i', $m) && isset($faq_for_match['contact'])) {
        return $faq_en['contact'] ?? ($faq_fil['contact'] ?? 'Email: clinic@documed.com. Call during office hours for assistance.');
    }
    if (preg_match('/(medical certificate|med cert|certificate|medical cert|medical)/i', $m) && isset($faq_for_match['medical_certificate'])) {
        return $faq_en['medical_certificate'] ?? ($faq_fil['medical_certificate'] ?? 'Medical certificates are issued after consultation. Bring a valid ID.');
    }
    if (preg_match('/(services|dental|check[- ]?up|consultation|serbisyo)/i', $m) && isset($faq_for_match['services'])) {
        return $faq_en['services'] ?? ($faq_fil['services'] ?? 'We provide general check-ups, dental treatments, medical certificates, and basic lab tests.');
    }
    if (preg_match('/(thank|thanks|thank you|salamat)/i', $m) && isset($faq_for_match['thanks'])) {
        return $faq_en['thanks'] ?? ($faq_fil['thanks'] ?? 'You are welcome!');
    }
    return $faq_en['default'] ?? ($faq_fil['default'] ?? 'Sorry, AI is not available right now.');
}

$local = match_faq_force_en($userMessage, $faq_for_match, $faq_en, $faq_fil);
if ($local) {
    echo json_encode(['reply' => $local, 'info' => 'served_by_local_fallback', 'lang' => 'en']);
    exit;
}
// End OpenAI flow; if no provider reply was produced above, the local fallback handled it above.
