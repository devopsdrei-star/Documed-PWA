<?php
// Email sending via PHPMailer SMTP
// Fill these with your SMTP details (e.g., Gmail App Password)
// Security: Do NOT commit real credentials to version control.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Try to load Composer autoload from project root; fallback to local documed_pwa vendor if present
$autoloadRoot = __DIR__ . '/../../../vendor/autoload.php'; // c:\MAMP\htdocs\DocMed\vendor\autoload.php
$autoloadPwa  = __DIR__ . '/../../vendor/autoload.php';    // c:\MAMP\htdocs\DocMed\documed_pwa\vendor\autoload.php
if (file_exists($autoloadRoot)) {
    require_once $autoloadRoot;
} elseif (file_exists($autoloadPwa)) {
    require_once $autoloadPwa;
} else {
    // Defer error handling to send_email() so app doesn't fatally break
    error_log('DocuMed: Composer autoload not found for PHPMailer. Expected at ' . $autoloadRoot . ' or ' . $autoloadPwa);
}

function send_email($to, $subject, $htmlBody, $textBody = '') {
    // Ensure PHPMailer is available
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return [
            'success' => false,
            'error' => 'Email library not available. Please run composer install and configure SMTP.'
        ];
    }
    $mail = new PHPMailer(true);
    try {
        // Load local override config if present
        $cfgFile = __DIR__ . '/email.local.php';
        $cfg = [];
        if (file_exists($cfgFile)) {
            $cfg = include $cfgFile; // should return an array
            if (!is_array($cfg)) { $cfg = []; }
        }

    $host = $cfg['SMTP_HOST'] ?? (getenv('SMTP_HOST') ?: 'smtp.gmail.com');
        $user = $cfg['SMTP_USER'] ?? (getenv('SMTP_USER') ?: '');
        $pass = $cfg['SMTP_PASS'] ?? (getenv('SMTP_PASS') ?: '');
        $port = intval($cfg['SMTP_PORT'] ?? (getenv('SMTP_PORT') ?: 587));
        $secure = $cfg['SMTP_SECURE'] ?? (getenv('SMTP_SECURE') ?: 'tls'); // 'tls' or 'ssl'
        $fromEmail = $cfg['SMTP_FROM'] ?? (getenv('SMTP_FROM') ?: ($user ?: ''));
        $fromName = $cfg['SMTP_FROM_NAME'] ?? (getenv('SMTP_FROM_NAME') ?: 'DocuMed');
    $devDebug = (bool)($cfg['DEV_EMAIL_DEBUG'] ?? (getenv('DEV_EMAIL_DEBUG') ?: false));
    $allowSelfSigned = (bool)($cfg['SMTP_ALLOW_SELF_SIGNED'] ?? (getenv('SMTP_ALLOW_SELF_SIGNED') ?: false));

        if (!$user || !$pass) {
            // Dev fallback: save email to file for demos/tests if enabled
            if (!empty($devDebug)) {
                $saveDir = __DIR__ . '/../tmp/emails';
                if (!is_dir($saveDir)) { @mkdir($saveDir, 0777, true); }
            $devDebug = (bool)($cfg['DEV_EMAIL_DEBUG'] ?? (getenv('DEV_EMAIL_DEBUG') ?: false));
            }
            return [ 'success' => false, 'error' => 'SMTP not configured. Set SMTP_USER and SMTP_PASS (or create backend/config/email.local.php).' ];
        }

        $mail->isSMTP();
        if ($devDebug) {
            // Verbose debug to error_log for troubleshooting
            $mail->SMTPDebug = 2; // 2 = client and server messages
            $mail->Debugoutput = function($str, $level) { error_log("PHPMailer debug[$level]: " . trim($str)); };
        }
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        if (strtolower($secure) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            if ($port === 587) { $port = 465; }
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port = $port;

        if ($allowSelfSigned) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ];
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        error_log('Mail error: ' . $e->getMessage());
        // In dev, include more context to aid troubleshooting
        $err = $e->getMessage();
        return ['success' => false, 'error' => $err];
    }
}
