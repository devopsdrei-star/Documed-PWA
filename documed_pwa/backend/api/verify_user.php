<?php
// verify_user.php
header('Content-Type: application/json');
require_once '../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = isset($data['email']) ? trim($data['email']) : '';
$code = isset($data['code']) ? trim($data['code']) : '';

if (!$email || !$code) {
    echo json_encode(['success' => false, 'message' => 'Missing email or code.']);
    exit;
}

try {
    // Using columns: verification_code, verification_expires, and status (pending -> active)
    $stmt = $pdo->prepare('SELECT verification_code, verification_expires, status FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }
    if (isset($row['status']) && strtolower($row['status']) !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Account already verified or inactive.']);
        exit;
    }
    if ($row['verification_code'] !== $code) {
        echo json_encode(['success' => false, 'message' => 'Incorrect code.']);
        exit;
    }
    if (strtotime($row['verification_expires']) < time()) {
        echo json_encode(['success' => false, 'message' => 'Code expired.']);
        exit;
    }
    // Mark user as active via status, clear code/expiry
    $stmt = $pdo->prepare('UPDATE users SET status = "active", verification_code = NULL, verification_expires = NULL WHERE email = ?');
    $stmt->execute([$email]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB error.']);
}
