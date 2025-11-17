<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');
$stmt = $pdo->prepare('SELECT id, name, quantity, baseline_qty, reorder_threshold_percent, campus FROM medicines WHERE campus = ? ORDER BY name ASC LIMIT 10');
$stmt->execute(['Lingayen']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success'=>true, 'count'=>count($rows), 'rows'=>$rows], JSON_PRETTY_PRINT);
