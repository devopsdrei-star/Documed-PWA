<?php
// medicine_import.php
// Simple CLI/web helper to bulk-import medicines (name + qty) for a campus.
// Usage (CLI): php medicine_import.php path/to/file.csv
// CSV format: name,qty  (no header required) — prices and issuance ignored
// When run via web, POST a file field named 'file' (multipart/form-data).

require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$campus = 'Lingayen';
$inserted = 0; $skipped = 0; $errors = [];

function upsertMedicine(PDO $pdo, $name, $qty, $campus) {
    // Trim and normalize
    $name = trim($name);
    if ($name === '') return false;
    $qty = max(0, (int)$qty);
    try {
        // Check existing
        $stmt = $pdo->prepare('SELECT id FROM medicines WHERE name = ? AND campus = ? LIMIT 1');
        $stmt->execute([$name, $campus]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // update quantity (set to provided value) and keep baseline if null
            $pdo->prepare('UPDATE medicines SET quantity = ?, baseline_qty = COALESCE(baseline_qty, ?), updated_at = NOW() WHERE id = ?')
                ->execute([$qty, $qty, $row['id']]);
            return (int)$row['id'];
        }
        // insert
        $ins = $pdo->prepare('INSERT INTO medicines (name, campus, quantity, baseline_qty) VALUES (?, ?, ?, ?)');
        $ins->execute([$name, $campus, $qty, $qty]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return false;
    }
}

// Accept CLI argument or uploaded file
$csvPath = '';
if (PHP_SAPI === 'cli') {
    global $argv;
    if (isset($argv[1]) && is_file($argv[1])) { $csvPath = $argv[1]; }
    else { echo "Usage: php medicine_import.php path/to/file.csv\n"; exit(1); }
} else {
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $csvPath = $_FILES['file']['tmp_name'];
    } elseif (!empty($_POST['csv'])) {
        // raw CSV content posted as 'csv'
        $tmp = tempnam(sys_get_temp_dir(), 'medimp'); file_put_contents($tmp, $_POST['csv']); $csvPath = $tmp;
    } else {
        echo json_encode(['success'=>false,'message'=>'Upload CSV file or POST csv content']); exit;
    }
}

if (!$csvPath || !is_readable($csvPath)) {
    echo json_encode(['success'=>false,'message'=>'CSV file not readable']); exit;
}

$fh = fopen($csvPath, 'r');
if (!$fh) { echo json_encode(['success'=>false,'message'=>'Failed to open CSV']); exit; }

while (($row = fgetcsv($fh)) !== false) {
    // Expect at least 1 column (name); second column qty optional
    if (count($row) < 1) continue;
    $name = trim($row[0]);
    $qty = isset($row[1]) ? $row[1] : 0;
    if ($name === '') { $skipped++; continue; }
    $id = upsertMedicine($pdo, $name, $qty, $campus);
    if ($id === false) { $errors[] = $name; $skipped++; } else { $inserted++; }
}
fclose($fh);

echo json_encode(['success'=>true,'inserted'=>$inserted,'skipped'=>$skipped,'errors'=>$errors]);

// Exit cleanly for CLI
if (PHP_SAPI === 'cli') exit(0);

?>
