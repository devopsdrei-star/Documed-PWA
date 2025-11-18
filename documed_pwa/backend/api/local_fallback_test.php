<?php
// Simple CLI test for local FAQ fallback used by chatbot_deepseek.php
$msg = $argv[1] ?? "What are your hours?";
$faqFile = __DIR__ . '/../config/fallback_faq.json';
$faq = [];
if (file_exists($faqFile)) {
    $faq = json_decode(file_get_contents($faqFile), true) ?: [];
}

function match_faq_simple($msg, $faq) {
    $m = strtolower($msg);
    if (preg_match('/(hours|open|close|schedule)/i', $m) && isset($faq['hours'])) return $faq['hours'];
    if (preg_match('/(appointment|book|schedule an appointment|book an appointment)/i', $m) && isset($faq['appointments'])) return $faq['appointments'];
    if (preg_match('/(contact|phone|call|email)/i', $m) && isset($faq['contact'])) return $faq['contact'];
    if (preg_match('/(medical certificate|med cert|certificate)/i', $m) && isset($faq['medical_certificate'])) return $faq['medical_certificate'];
    if (preg_match('/(services|medical|clinic|check[- ]?up|consultation)/i', $m) && isset($faq['services'])) return $faq['services'];
    if (preg_match('/(thank|thanks|thank you)/i', $m) && isset($faq['thanks'])) return $faq['thanks'];
    return $faq['default'] ?? '';
}

$reply = match_faq_simple($msg, $faq);
echo json_encode(['input' => $msg, 'reply' => $reply], JSON_PRETTY_PRINT) . PHP_EOL;
