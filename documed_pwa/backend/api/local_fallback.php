<?php
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$userMessage = trim($input['message'] ?? '');
$langParam = trim(strtolower($input['lang'] ?? ($_GET['lang'] ?? '')));
$faqFile = __DIR__ . '/../config/fallback_faq.json';
$allFaq = [];
if (file_exists($faqFile)) {
    $allFaq = json_decode(file_get_contents($faqFile), true) ?: [];
}

// choose language map; default to English replies
$faq_en = $allFaq['en'] ?? [];
$faq_fil = $allFaq['fil'] ?? [];

// choose language map for matching heuristics (match against combined cues)
$faq_for_match = array_merge($faq_en, $faq_fil);

// Decide reply language: honor explicit 'lang' param if provided and supported, otherwise default to English
$replyLang = 'en';
if ($langParam === 'fil' || $langParam === 'filipino' || $langParam === 'tagalog') $replyLang = 'en'; // keep replies in English by design
// Note: we still match against combined FAQs to recognize Filipino questions

function match_faq_public($msg, $faq_for_match, $faq_en, $faq_fil) {
    $m = strtolower($msg);
    if ($m === '') return $faq_en['default'] ?? "Sorry I can't access the AI right now. Please contact the clinic.";
    // Hours
    if (preg_match('/(hours|open|close|schedule|oras|bukas|sarado)/i', $m) && (isset($faq_for_match['hours']))) {
        return $faq_en['hours'] ?? ($faq_fil['hours'] ?? 'Our clinic hours are Monday–Friday 08:00–17:00.');
    }
    // Appointments
    if (preg_match('/(appointment|book|schedule|magpa-appointment|magpa appointment|book an appointment)/i', $m) && (isset($faq_for_match['appointments']))) {
        return $faq_en['appointments'] ?? ($faq_fil['appointments'] ?? 'To book an appointment, please use the Book Appointment page or contact the clinic.');
    }
    // Contact
    if (preg_match('/(contact|phone|call|email|tawag|kontakt)/i', $m) && (isset($faq_for_match['contact']))) {
        return $faq_en['contact'] ?? ($faq_fil['contact'] ?? 'Email: clinic@documed.com. Call the clinic during office hours for immediate help.');
    }
    // Medical certificate
    if (preg_match('/(medical certificate|med cert|certificate|medical certificate|medical cert|medical)/i', $m) && (isset($faq_for_match['medical_certificate']))) {
        return $faq_en['medical_certificate'] ?? ($faq_fil['medical_certificate'] ?? 'We issue medical certificates; please request one via the Medical Certificates section.');
    }
    // Services
    if (preg_match('/(services|dental|check[- ]?up|consultation|serbisyo)/i', $m) && (isset($faq_for_match['services']))) {
        return $faq_en['services'] ?? ($faq_fil['services'] ?? 'We offer general check-ups, dental treatments, medical certificates, and basic lab tests.');
    }
    // Thanks
    if (preg_match('/(thank|thanks|thank you|salamat)/i', $m) && (isset($faq_for_match['thanks']))) {
        return $faq_en['thanks'] ?? ($faq_fil['thanks'] ?? 'You are welcome!');
    }
    return $faq_en['default'] ?? ($faq_fil['default'] ?? "Sorry I can't access the AI right now. Please contact the clinic.");
}
// Run matching and always return English reply (replyLang variable set above)
$reply = match_faq_public($userMessage, $faq_for_match, $faq_en, $faq_fil);
echo json_encode(['reply' => $reply, 'info' => 'served_by_local_fallback', 'lang' => $replyLang]);
exit;
exit;
