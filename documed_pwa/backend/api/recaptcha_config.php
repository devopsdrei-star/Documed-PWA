<?php
// Expose reCAPTCHA client configuration without secrets
header('Content-Type: application/json');

$siteKey = getenv('RECAPTCHA_SITE_KEY') ?: '';
$enabledEnv = getenv('RECAPTCHA_ENABLED');
$enabled = null;
if ($enabledEnv === false || $enabledEnv === null || $enabledEnv === '') {
    // Auto-enable only if we actually have a site key configured
    $enabled = $siteKey !== '';
} else {
    $enabled = (int)$enabledEnv === 1 || strtolower((string)$enabledEnv) === 'true';
}

echo json_encode([
    'enabled' => (bool)$enabled,
    'siteKey' => $siteKey,
]);
