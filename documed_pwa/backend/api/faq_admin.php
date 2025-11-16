<?php
// Simple admin editor for fallback_faq.json
require_once __DIR__ . '/auth.php'; // provides jsonResponse and some helpers
header('Content-Type: application/json');

$faqFile = __DIR__ . '/../config/fallback_faq.json';

// Auth: allow if admin session present or Authorization: Bearer <token> matches config token
session_start();
$isAdmin = false;
if (!empty($_SESSION['admin']) && is_array($_SESSION['admin'])) {
    $isAdmin = true;
}

// Accept a static token as fallback for deployments without sessions
$tokenConfig = __DIR__ . '/../config/faq_admin_token.php';
$bearer = null;
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) $bearer = trim($m[1]);
}
if (!$isAdmin && $bearer && file_exists($tokenConfig)) {
    $cfg = include $tokenConfig;
    if (is_array($cfg) && isset($cfg['FAQ_ADMIN_TOKEN']) && hash_equals(trim($cfg['FAQ_ADMIN_TOKEN']), $bearer)) {
        $isAdmin = true;
    }
}

if (!$isAdmin) {
    http_response_code(403);
    jsonResponse(['success' => false, 'error' => 'Forbidden']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    if (!file_exists($faqFile)) {
        echo json_encode(['success' => true, 'faq' => new stdClass()]);
        exit;
    }
    $raw = file_get_contents($faqFile);
    $data = json_decode($raw, true);
    if ($data === null) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON in faq file']);
    }
    jsonResponse(['success' => true, 'faq' => $data]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonResponse(['success' => false, 'error' => 'Invalid input']);
    // Basic validation: expect 'faq' key with an object containing 'en' and/or 'fil'
    if (!isset($input['faq']) || !is_array($input['faq'])) jsonResponse(['success' => false, 'error' => 'Missing faq object']);
    $newFaq = $input['faq'];
    // Pretty-print and atomically write
    $tmp = $faqFile . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($newFaq, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($ok === false) jsonResponse(['success' => false, 'error' => 'Unable to write temp file']);
    if (!@rename($tmp, $faqFile)) {
        @unlink($tmp);
        jsonResponse(['success' => false, 'error' => 'Unable to move temp file']);
    }
    jsonResponse(['success' => true, 'message' => 'FAQ updated']);
}

// Other methods
http_response_code(405);
jsonResponse(['success' => false, 'error' => 'Method not allowed']);
