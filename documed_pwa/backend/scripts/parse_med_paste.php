<?php
// parse_med_paste.php
// Parse tab-separated pasted inventory in tmp/med_paste.txt and create CSV name,qty (ending balance)

// BASE set to documed_pwa folder
define('BASE', dirname(__DIR__, 2));
$in = BASE . '/tmp/med_paste.txt';
$out = BASE . '/tmp/med_import.csv';
if (!is_readable($in)) { echo "Input file not found: $in\n"; exit(1); }
$lines = file($in, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$rows = [];
foreach ($lines as $ln) {
    $ln = trim($ln);
    // Skip header lines and totals
    if ($ln === '' ) continue;
    $lower = strtolower($ln);
    if (strpos($lower, 'particulars') !== false) continue;
    if (strpos($lower, 'beginning balance') !== false) continue;
    if (strpos($lower, 'ending balance') !== false) continue;
    if (strpos($lower, 'sub total') !== false) continue;
    if (strpos($lower, 'grand total') !== false) continue;
    // Expect a tab-separated line with name first and numbers later
    $parts = preg_split('/\t+/', $ln);
    if (count($parts) < 2) continue;
    $name = trim($parts[0]);
    // find last numeric-looking token as ending qty
    $qty = null;
    for ($i = count($parts)-1; $i >= 1; $i--) {
        $p = trim($parts[$i]);
        // normalize comma thousands and remove parentheses
        $p2 = str_replace([',','(' ,')'], ['', '', ''], $p);
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $p2)) {
            // this might be unit cost or amount; we want the QTY before unit cost in ending balance group
            // Heuristic: if number has a decimal of 4 places (e.g., 6.0000) it's unit cost; skip those to find integer-ish QTY
            if (preg_match('/\.\d{4}$/', $p2)) continue;
            $qty = (int)round(floatval($p2));
            break;
        }
    }
    if ($name !== '' && $qty !== null) {
        $rows[] = [$name, $qty];
    }
}
// write CSV
$fh = fopen($out, 'w');
foreach ($rows as $r) { fputcsv($fh, $r); }
fclose($fh);
echo "Wrote " . count($rows) . " rows to $out\n";
exit(0);
