<?php
// send_verification_code.php
header('Content-Type: application/json');
require_once '../config/db.php';
require_once __DIR__ . '/../config/email.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = isset($data['email']) ? trim($data['email']) : '';

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Email required.']);
    exit;
}

// Generate a 6-digit code
$code = random_int(100000, 999999);

// Store code in DB (uses columns: verification_code, verification_expires)
try {
    $stmt = $pdo->prepare('UPDATE users SET verification_code = ?, verification_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE email = ?');
    $stmt->execute([$code, $email]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB error.']);
    exit;
}

// Send email via shared helper (PHPMailer SMTP if configured)
$subject = 'Your DocuMed Verification Code';
$htmlBody = '<p>Your verification code is: <strong style="font-size:18px;letter-spacing:4px;">' . htmlspecialchars($code) . '</strong></p>' .
            '<p>This code will expire in 15 minutes.</p>';
$textBody = "Your verification code is: $code\nThis code will expire in 15 minutes.";

$sent = send_email($email, $subject, $htmlBody, $textBody);
if (!empty($sent['success'])) {
    echo json_encode(['success' => true]);
    exit;
}

// Fallback to PHP mail() if SMTP not configured
$headers = "From: DocuMed <no-reply@documed.com>\r\n" .
           "Reply-To: no-reply@documed.com\r\n" .
           "Content-Type: text/plain; charset=UTF-8\r\n" .
           "X-Mailer: PHP/" . phpversion();
$mailSent = @mail($email, $subject, $textBody, $headers, '-f no-reply@documed.com');
if (!$mailSent) {
    $mailSent = @mail($email, $subject, $textBody, $headers);
}

if ($mailSent) {
    echo json_encode(['success' => true]);
} else {
    $msg = $sent['error'] ?? 'Failed to send email.';
    echo json_encode(['success' => false, 'message' => $msg]);
}
