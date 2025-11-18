<?php
require_once __DIR__ . '/../config/db.php';

// List recent checkups and show key fields for verification
$limit = $argv[1] ?? 20;
$stmt = $pdo->prepare("SELECT id, student_faculty_id, name, present_illness, assessment, history_past_illness, created_at FROM checkups ORDER BY created_at DESC LIMIT ?");
$stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo sprintf("ID:%s | SID:%s | Name:%s | Present:%s | Assessment:%s | Created:%s\n",
        $r['id'] ?? '', $r['student_faculty_id'] ?? '', $r['name'] ?? '', $r['present_illness'] ?? '', $r['assessment'] ?? '', $r['created_at'] ?? '');
}
exit(0);
