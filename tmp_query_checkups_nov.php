<?php
require_once __DIR__ . '/documed_pwa/backend/config/db.php';
$start = '2025-11-01'; $end = '2025-11-30';
try {
    $stmt = $pdo->prepare("SELECT * FROM checkups WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 300");
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'count'=>count($rows),'rows'=>$rows]);
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
