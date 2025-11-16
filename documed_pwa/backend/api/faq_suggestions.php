<?php
header('Content-Type: application/json');
// Default suggestions to English so UI and local fallback replies match
$lang = strtolower(trim($_GET['lang'] ?? $_POST['lang'] ?? ''));
$lang = ($lang === 'fil' ? 'fil' : 'en');
$faqFile = __DIR__ . '/../config/fallback_faq.json';
$out = ['success' => false, 'lang' => $lang, 'suggestions' => []];
if (file_exists($faqFile)) {
    $all = json_decode(file_get_contents($faqFile), true) ?: [];
    $map = $all[$lang] ?? $all['fil'] ?? [];
    // Build categorized suggestions (label, key, text)
    $cats = [
        ['key'=>'hours','label'=>($lang==='en'?'Hours':'Oras')],
        ['key'=>'appointments','label'=>($lang==='en'?'Appointments':'Appointment')],
        ['key'=>'contact','label'=>($lang==='en'?'Contact':'Contact')],
        ['key'=>'services','label'=>($lang==='en'?'Services':'Serbisyo')],
        ['key'=>'medical_records','label'=>($lang==='en'?'Medical Records':'Medical Records')],
        ['key'=>'prescriptions','label'=>($lang==='en'?'Prescriptions':'Prescriptions')]
    ];
    $sugs = [];
    foreach ($cats as $c) {
        if (isset($map[$c['key']]) && trim($map[$c['key']])!=='') {
            $sugs[] = ['category'=>$c['key'],'label'=>$c['label'],'text'=>$map[$c['key']]];
        }
    }
    $out['success'] = true;
    $out['suggestions'] = $sugs;
}
echo json_encode($out);
