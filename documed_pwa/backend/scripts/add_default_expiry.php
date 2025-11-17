<?php
// add_default_expiry.php
// For each medicine in Lingayen without any batches, insert a default batch
// with qty equal to the medicine.quantity and expiry_date = +1 year from today.

require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$campus = 'Lingayen';
$now = new DateTime('today');
$defaultExpiry = (new DateTime('today'))->modify('+1 year')->format('Y-m-d');
$created = [];

try {
    $stmt = $pdo->prepare('SELECT id, name, quantity FROM medicines WHERE campus = ? ORDER BY id ASC');
    $stmt->execute([$campus]);
    $meds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($meds as $m) {
        $mid = (int)$m['id'];
        // check existing batches with qty > 0
        $q = $pdo->prepare('SELECT COUNT(*) FROM medicine_batches WHERE medicine_id = ? AND campus = ? AND qty > 0');
        $q->execute([$mid, $campus]);
        $count = (int)$q->fetchColumn();
        if ($count > 0) continue; // skip medicines that already have batches

        $qty = max(0, (int)$m['quantity']);
        if ($qty <= 0) continue; // nothing to create

        $batchNo = 'INIT-' . $mid . '-' . $now->format('Ymd');
        $ins = $pdo->prepare('INSERT INTO medicine_batches (medicine_id, campus, qty, expiry_date, received_at, batch_no, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$mid, $campus, $qty, $defaultExpiry, $now->format('Y-m-d'), $batchNo, 'Auto-created initial batch with default expiry +1y']);
        $created[] = ['medicine_id' => $mid, 'name' => $m['name'], 'qty' => $qty, 'expiry' => $defaultExpiry];
    }
    echo json_encode(['success' => true, 'created' => $created], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
