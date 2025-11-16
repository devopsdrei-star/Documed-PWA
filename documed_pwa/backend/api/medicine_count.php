<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS medicines (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL, campus VARCHAR(100) NOT NULL DEFAULT 'Lingayen', quantity INT NOT NULL DEFAULT 0, UNIQUE KEY uniq_name_campus (name, campus)) ENGINE=InnoDB");
} catch (Throwable $e) {}
$row = $pdo->query('SELECT COUNT(*) AS c FROM medicines')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['count'=>(int)($row['c'] ?? 0)]);
