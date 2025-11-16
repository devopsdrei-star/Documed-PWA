<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

// Include phpqrcode library (make sure path is correct)
require_once __DIR__ . '/phpqrcode.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'generate') {
    $user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? '';
    $sid = $_GET['sid'] ?? $_POST['sid'] ?? '';

    if (!$user_id && !$sid) {
        echo json_encode(['success' => false, 'message' => 'Missing user_id or sid']);
        exit;
    }

    // If SID not provided, fetch it from DB by user id
    if (!$sid && $user_id) {
        try {
            $stmt = $pdo->prepare('SELECT student_faculty_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$user_id]);
            $sid = $stmt->fetchColumn();
        } catch (Exception $e) {
            // ignore and fallback to user_id
        }
    }
    $payload = $sid ?: (string)$user_id; // encode only SID or numeric id

    $qr_dir  = __DIR__ . '/../../frontend/assets/images';
    if (!is_dir($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }
    if (!is_writable($qr_dir)) {
        echo json_encode(['success' => false, 'message' => 'QR directory not writable.']);
        exit;
    }
    $fileKey = $user_id ?: preg_replace('/[^A-Za-z0-9_-]/', '_', $sid);
    $qr_filename = "qr_" . $fileKey . ".png";
    $qr_path = $qr_dir . "/" . $qr_filename;
    try {
        QRcode::png($payload, $qr_path, QR_ECLEVEL_L, 6);
        echo json_encode([
            'success' => true,
            'qr_path' => "../assets/images/" . $qr_filename,
            'encoded' => $payload
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'QR generation failed: ' . $e->getMessage()]);
    }
    exit;
}

// Generate a QR for arbitrary text/link
if ($action === 'generate_link') {
    $text = $_GET['text'] ?? $_POST['text'] ?? '';
    $key = $_GET['key'] ?? $_POST['key'] ?? '';
    if ($text === '') { echo json_encode(['success'=>false,'message'=>'Missing text']); exit; }
    $qr_dir  = __DIR__ . '/../../frontend/assets/images';
    if (!is_dir($qr_dir)) { mkdir($qr_dir, 0777, true); }
    if (!is_writable($qr_dir)) { echo json_encode(['success'=>false,'message'=>'QR directory not writable']); exit; }
    $fileKey = $key !== '' ? preg_replace('/[^A-Za-z0-9_-]/','_', $key) : substr(sha1($text),0,16);
    $qr_filename = "qr_link_" . $fileKey . ".png";
    $qr_path = $qr_dir . "/" . $qr_filename;
    try {
        QRcode::png($text, $qr_path, QR_ECLEVEL_L, 6);
        echo json_encode(['success'=>true, 'qr_path'=>"../assets/images/".$qr_filename, 'encoded'=>$text]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'QR generation failed: '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
