<?php
// backend/api/chatbot_deepseek.php
header('Content-Type: application/json');

// Load DeepSeek API key from config or env
$deepseekKeyFile = dirname(__DIR__) . '/config/deepseek_key.php';
$DEEPSEEK_API_KEY = '';
if (file_exists($deepseekKeyFile)) {
    $k = include $deepseekKeyFile;
    if (is_array($k) && !empty($k['DEEPSEEK_API_KEY'])) { $DEEPSEEK_API_KEY = $k['DEEPSEEK_API_KEY']; }
}
if (!$DEEPSEEK_API_KEY) { $DEEPSEEK_API_KEY = getenv('DEEPSEEK_API_KEY') ?: ''; }
$DEEPSEEK_AVAILABLE = (bool) $DEEPSEEK_API_KEY;
if (!$DEEPSEEK_AVAILABLE) {
    @file_put_contents(__DIR__ . '/../../tmp/deepseek_debug.log', json_encode(['time'=>date('c'),'note'=>'deepseek_key_missing']) . "\n", FILE_APPEND);
}

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

// ---------------------------------------------------------------------------
// Early rule-based intents (bypass external model when we can answer safely)
// ---------------------------------------------------------------------------
// We only provide general, non-diagnostic information. For medicine questions
// we return OTC guidance plus red‑flag warnings. For medical certificate we
// clarify face‑to‑face only issuance.
// Returned structure mirrors provider replies: { reply, info }
function documed_rule_intents(string $msg): ?array {
    $m = strtolower($msg);
    // Normalize multiple spaces
    $m = preg_replace('/\s+/', ' ', $m);

    // Medical certificate (med cert) queries
    if (preg_match('/\b(medical certificate|med cert|medical cert|certificate for|medical clearance)\b/i', $msg)) {
        $reply = "Medical certificates are only issued face-to-face at the Campus Medical Clinic after an in-person assessment. We do not provide online medical certificate issuance. Please visit during clinic hours (Mon–Fri 08:00–17:00) with a valid campus ID and explain the purpose (e.g., absence excuse, activity clearance).";
        return ['reply' => $reply, 'info' => 'served_by_rules_med_cert'];
    }

    // Headache / medicine queries
    $headachePattern = '/\b(headache|head ache|migraine|sumasakit ang ulo|masakit ang ulo)\b/i';
    $medicinePattern  = '/\b(medicine|medication|gamot|take.*medicine|cure|anong gamot|what.*medicine|pain reliever|painkiller)\b/i';
    if (preg_match($headachePattern, $msg) && preg_match($medicinePattern, $msg)) {
        $reply = "General guidance: Mild tension headaches can sometimes improve with rest, hydration, balanced meals, and avoiding prolonged screen strain. Over-the-counter pain relievers such as paracetamol (acetaminophen) or ibuprofen may help if you have no allergy, stomach ulcer, kidney problems, or other contraindications. Always follow the package directions and never exceed the recommended dose. Seek an in-person clinic evaluation urgently if the headache is sudden and severe (\"worst headache\"), persistent beyond 24–48 hours, occurs with fever, stiff neck, vomiting, vision changes, weakness, numbness, confusion, fainting, after a head injury, or if you are immunocompromised. For personalized assessment and any prescription needs, please visit the Campus Medical Clinic. This is not a diagnosis or a substitute for professional medical advice.";
        return ['reply' => $reply, 'info' => 'served_by_rules_headache_medicine'];
    }

    // Generic medicine inquiry without specific condition — provide safe disclaimer
    if (preg_match('/\b(what.*medicine|anong gamot|which medicine|recommend.*medicine|can I take.*medicine)\b/i', $msg)) {
        $reply = "I can only give very general guidance. For most minor symptoms, proper rest, hydration, and evaluation of triggers helps. Any decision to start, stop, or combine medicines should be based on an in-person assessment. Please visit the Campus Medical Clinic for evaluation—online recommendations cannot replace a clinician. If symptoms are severe or involve chest pain, breathing difficulty, fainting, sudden weakness, confusion, or uncontrollable vomiting, seek immediate medical attention.";
        return ['reply' => $reply, 'info' => 'served_by_rules_general_medicine'];
    }

    return null; // No rule matched
}

if ($rule = documed_rule_intents($userMessage)) {
    echo json_encode($rule);
    exit;
}

$system_prompt = "You are a helpful assistant for the Campus Medical Clinic (DocuMed). You must only answer questions about the Campus Medical Clinic and its services (appointments, clinic hours, locations, walk-in policy, immunizations, minor procedures handled by the clinic, medical certificates, basic laboratory tests, referral processes, patient record access, and how to use this system). Always reply in English and keep answers concise and friendly. If a user asks for medical diagnoses, detailed treatment plans, dosing, or clinical management beyond administrative or clinic-process information, refuse to provide medical instructions and instead advise the user to consult a clinician or go to emergency services when appropriate. If the user asks about topics outside the Campus Medical Clinic (insurance policy beyond the clinic, unrelated medical specialties, or general medical textbooks), reply: 'I am only able to answer about the Campus Medical Clinic services and this system — please contact the clinic or a licensed clinician for that question.'";

// Load fallback FAQ early so we can use an out-of-scope message for sanitization
$faqFile = __DIR__ . '/../config/fallback_faq.json';
$allFaq = [];
if (file_exists($faqFile)) {
    $allFaq = json_decode(file_get_contents($faqFile), true) ?: [];
}
$faq_en = $allFaq['en'] ?? [];
$faq_fil = $allFaq['fil'] ?? [];
$out_of_scope_default = $faq_en['out_of_scope'] ?? ($faq_fil['out_of_scope'] ?? "I'm only able to answer about the Campus Medical Clinic services and this system. For medical diagnosis or treatment advice, please consult a licensed clinician or go to emergency services if urgent.");

// Try DeepSeek first (if key is configured)
$deepseekReply = '';
if ($DEEPSEEK_AVAILABLE) {
    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    $payload = [
        'model' => 'deepseek-chat',
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
        'Authorization: Bearer ' . $DEEPSEEK_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $dsResponse = curl_exec($ch);
    $dsErr = curl_error($ch);
    $dsHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log DeepSeek call
    $debugEntry = [
        'time' => date('c'),
        'request' => $payload,
        'http_code' => $dsHttp,
        'curl_error' => $dsErr,
        'response' => $dsResponse
    ];
    @file_put_contents(__DIR__ . '/../../tmp/deepseek_debug.log', json_encode($debugEntry, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

    if (!$dsErr) {
        $dsData = json_decode($dsResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $deepseekReply = $dsData['choices'][0]['message']['content'] ?? '';
            // Reply sanitization: detect if the provider gives diagnostic/treatment instructions or other out-of-scope clinical guidance
            $sanitized = false;
            if ($deepseekReply) {
                $oos_pattern = '/\b(diagnos|diagnosis|diagnose|symptom|how to treat|treat(?:ment)?|dose|dosage|prescribe|prescription|medication advice|should I take|should I use|surgery|operation|emergency|suicide|self[- ]harm|administer|inject|intravenous|iv|take as directed|apply (?:ice|heat)|take \d+mg|take \d+\s?ml)\b/i';
                if (preg_match($oos_pattern, $deepseekReply)) {
                    // Log that we sanitized an out-of-scope provider reply
                    @file_put_contents(__DIR__ . '/../../tmp/deepseek_debug.log', json_encode(['time'=>date('c'),'note'=>'sanitized_provider_reply','original_reply'=>$deepseekReply], JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
                    $deepseekReply = $out_of_scope_default;
                    $sanitized = true;
                }
            }
        } else {
            @file_put_contents(__DIR__ . '/../../tmp/deepseek_debug.log', json_encode(['time'=>date('c'),'note'=>'deepseek_invalid_json','json_error'=>json_last_error_msg(),'response'=>$dsResponse], JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
        }
    } else {
        @file_put_contents(__DIR__ . '/../../tmp/deepseek_debug.log', json_encode(['time'=>date('c'),'note'=>'deepseek_curl_error','err'=>$dsErr], JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
    }
}

if ($deepseekReply) {
    $info = isset($sanitized) && $sanitized ? 'served_by_deepseek_sanitized' : 'served_by_deepseek';
    echo json_encode(['reply' => $deepseekReply, 'info' => $info]);
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

    // Early out: detect requests asking for diagnosis/treatment or out-of-scope clinical instructions
    if (preg_match('/(diagnos|symptom|how to treat|treat(?:ment)?|dose|dosage|prescribe|prescription|medication advice|should I take|should I use|surgery|operation|emergency|suicide|self[- ]harm)/i', $m)) {
        return $faq_en['out_of_scope'] ?? ($faq_fil['out_of_scope'] ?? "I'm only able to answer questions about the Campus Medical Clinic services and this system. For medical diagnosis or treatment advice, please consult a licensed clinician or go to emergency services if urgent.");
    }

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
    if (preg_match('/(services|medical|clinic|check[- ]?up|consultation|serbisyo)/i', $m) && isset($faq_for_match['services'])) {
        return $faq_en['services'] ?? ($faq_fil['services'] ?? 'We provide general medical check-ups, immunizations, minor procedures, medical certificates, and basic lab tests.');
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
