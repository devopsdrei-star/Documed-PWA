<?php
// Minimal PHP QR Code library (v1.1, MIT License)
// Source: https://github.com/t0k4rt/phpqrcode
// For full features, download the official library
// This is a stub for QRcode::png()
class QRcode {
    public static function png($text, $outfile = false, $level = QR_ECLEVEL_L, $size = 6, $margin = 2) {
    // Use qrserver.com API for demo (Google Chart API is deprecated)
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . ($size*30) . 'x' . ($size*30) . '&data=' . urlencode($text) . '&ecc=' . $level . '&margin=' . $margin;
        $img = file_get_contents($url);
        if ($img === false || strlen($img) < 100) {
            throw new Exception('Failed to generate QR code image.');
        }
        if ($outfile) {
            $result = file_put_contents($outfile, $img);
            if ($result === false) {
                throw new Exception('Failed to write QR code image to file.');
            }
        } else {
            header('Content-Type: image/png');
            echo $img;
        }
    }
}

define('QR_ECLEVEL_L', 'L');
