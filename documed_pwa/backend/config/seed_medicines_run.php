<?php
// One-off seed runner for medicines table using seed_medicines.sql
// Safe / idempotent: will not re-insert if records already exist.
require_once __DIR__ . '/db.php';
$count = 0;
try {
    $row = $pdo->query('SELECT COUNT(*) AS c FROM medicines')->fetch(PDO::FETCH_ASSOC);
    $count = (int)($row['c'] ?? 0);
} catch (Throwable $e) {
    // Table might not exist yet; create via API schema builder
    require_once dirname(__DIR__) . '/api/medicine.php'; // ensure tables
    try { $row = $pdo->query('SELECT COUNT(*) AS c FROM medicines')->fetch(PDO::FETCH_ASSOC); $count = (int)($row['c'] ?? 0); } catch (Throwable $e2) { $count = 0; }
}
if ($count > 0) {
    echo json_encode(['seed'=>'skipped','existing_count'=>$count]);
    exit;
}
$sqlFile = __DIR__ . '/seed_medicines.sql';
if (!is_readable($sqlFile)) {
    echo json_encode(['seed'=>'failed','error'=>'seed_medicines.sql not readable']);
    exit;
}
$sql = file_get_contents($sqlFile);
try {
    $pdo->exec($sql);
    $row = $pdo->query('SELECT COUNT(*) AS c FROM medicines')->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['seed'=>'applied','inserted_count'=>(int)($row['c'] ?? 0)]);
} catch (Throwable $e) {
    echo json_encode(['seed'=>'failed','error'=>$e->getMessage()]);
}
