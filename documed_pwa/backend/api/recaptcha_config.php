<?php
// reCAPTCHA removed — return disabled config
header('Content-Type: application/json');
echo json_encode(['enabled' => false, 'siteKey' => '']);
