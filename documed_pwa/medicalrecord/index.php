<?php
// Short download link redirect for medical records
// Usage: /medicalrecord?id=<record_id>&as=pdf
// Falls back to PDF if 'as' is not provided

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$as = isset($_GET['as']) ? trim($_GET['as']) : 'pdf';

if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Missing record id.';
    exit;
}

// Build absolute path to backend download endpoint
$target = sprintf('/documed_pwa/backend/api/download_medical_record.php?id=%s&as=%s', urlencode($id), urlencode($as));

header('Location: ' . $target, true, 302);
exit;
