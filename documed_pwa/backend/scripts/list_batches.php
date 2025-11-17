<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');
$stmt = $pdo->prepare('SELECT b.id, b.medicine_id, m.name AS medicine_name, b.qty, b.expiry_date, b.received_at, b.batch_no FROM medicine_batches b LEFT JOIN medicines m ON m.id=b.medicine_id WHERE b.campus = ? ORDER BY b.expiry_date ASC LIMIT 20');
$stmt->execute(['Lingayen']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success'=>true, 'count'=>count($rows), 'batches'=>$rows], JSON_PRETTY_PRINT);
