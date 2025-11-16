<?php
require_once __DIR__ . '/../config/email.php';
header('Content-Type: application/json');

// Simple key-based guard to avoid abuse
$cfgFile = __DIR__ . '/../config/email.local.php';
$cfg = [];
if (file_exists($cfgFile)) { $cfg = include $cfgFile; if (!is_array($cfg)) { $cfg = []; } }
$keySet = $cfg['SMTP_TEST_KEY'] ?? (getenv('SMTP_TEST_KEY') ?: '');
$dev = !empty($cfg['DEV_EMAIL_DEBUG']) || getenv('DEV_EMAIL_DEBUG');
// Production-safe: no automatic localhost override; rely solely on config flags.
// Optional override: ALLOW_DEV_NO_KEY in config/env to skip key requirement when testing.
$allowNoKey = !empty($cfg['ALLOW_DEV_NO_KEY']) || getenv('ALLOW_DEV_NO_KEY');

$key = $_GET['key'] ?? $_POST['key'] ?? '';
// In DEV mode, if no key configured, allow access to simplify local testing
if (!($allowNoKey && $dev && !$keySet)) {
    if (!$keySet || !$key || !hash_equals((string)$keySet, (string)$key)) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

$to = $_GET['to'] ?? $_POST['to'] ?? ($cfg['SMTP_FROM'] ?? '');
if (!$to) { echo json_encode(['success' => false, 'error' => 'Missing recipient']); exit; }
$subj = 'DocuMed SMTP Test';
$html = '<p>This is a test email from DocuMed SMTP tester.</p><p>Time: ' . date('Y-m-d H:i:s') . '</p>';
$send = send_email($to, $subj, $html);
echo json_encode($send);