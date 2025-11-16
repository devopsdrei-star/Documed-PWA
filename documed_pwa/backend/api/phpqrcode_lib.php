<?php
// PHP QR Code library (https://sourceforge.net/projects/phpqrcode/)
// Minimal integration for local QR code generation
require_once __DIR__ . '/phpqrcode.php'; // Make sure this file exists

function generateLocalQRCode($data, $outfile) {
    try {
        // QR_ECLEVEL_L is defined in phpqrcode.php
        QRcode::png($data, $outfile, QR_ECLEVEL_L, 6);
        return file_exists($outfile);
    } catch (Exception $e) {
        return false;
    }
}
?>
