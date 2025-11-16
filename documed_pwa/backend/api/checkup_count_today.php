<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$date = $_GET['date'] ?? date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM checkups WHERE DATE(created_at) = ?");
$stmt->execute([$date]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode(['count' => intval($row['count'])]);
