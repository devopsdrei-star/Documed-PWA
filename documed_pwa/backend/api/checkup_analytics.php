<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

// period options: day (24 hours), week (last 7 days), month (last 30 days), year (last 12 months)
$period = strtolower($_GET['period'] ?? 'day');
$valid = ['day','week','month','year','all'];
if (!in_array($period, $valid, true)) { $period = 'day'; }

try {
    $labels = [];
    $counts = [];

    if ($period === 'day') {
        // Today, grouped by hour
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT HOUR(created_at) AS h, COUNT(*) AS c FROM checkups WHERE DATE(created_at) = ? GROUP BY h");
        $stmt->execute([$today]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // h => c
        for ($h = 0; $h < 24; $h++) {
            $label = sprintf('%02d:00', $h);
            $labels[] = $label;
            $counts[] = isset($rows[$h]) ? intval($rows[$h]) : 0;
        }
    } elseif ($period === 'week') {
        // Last 7 days including today
        $end = new DateTime('today');
        $start = (clone $end)->modify('-6 days');
        $stmt = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM checkups WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY d");
        $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $rows[$r['d']] = intval($r['c']); }
        $periodIter = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        foreach ($periodIter as $d) {
            $key = $d->format('Y-m-d');
            $labels[] = $key;
            $counts[] = $rows[$key] ?? 0;
        }
    } elseif ($period === 'month') {
        // Last 30 days including today
        $end = new DateTime('today');
        $start = (clone $end)->modify('-29 days');
        $stmt = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM checkups WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY d");
        $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $rows[$r['d']] = intval($r['c']); }
        $periodIter = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        foreach ($periodIter as $d) {
            $key = $d->format('Y-m-d');
            $labels[] = $key;
            $counts[] = $rows[$key] ?? 0;
        }
    } elseif ($period === 'year') { // year
        // Last 12 months including current month
        $end = new DateTime('first day of this month');
        $start = (clone $end)->modify('-11 months');
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM checkups WHERE created_at >= ? GROUP BY ym ORDER BY ym ASC");
        $stmt->execute([$start->format('Y-m-d 00:00:00')]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ym => c
        $cur = clone $start;
        for ($i = 0; $i < 12; $i++) {
            $key = $cur->format('Y-m');
            $labels[] = $key;
            $counts[] = isset($rows[$key]) ? intval($rows[$key]) : 0;
            $cur->modify('+1 month');
        }
    } else { // all
        // All-time aggregated by month from first record to last
        $minmax = $pdo->query("SELECT MIN(created_at) AS min_dt, MAX(created_at) AS max_dt FROM checkups")->fetch(PDO::FETCH_ASSOC);
        if (!$minmax || empty($minmax['min_dt'])) {
            echo json_encode(['success' => true, 'period' => 'all', 'labels' => [], 'counts' => []]);
            exit;
        }
        $start = new DateTime(date('Y-m-01', strtotime($minmax['min_dt'])));
        $end = new DateTime(date('Y-m-01', strtotime($minmax['max_dt'])));
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM checkups WHERE created_at BETWEEN ? AND ? GROUP BY ym ORDER BY ym ASC");
        $stmt->execute([$start->format('Y-m-d 00:00:00'), $end->format('Y-m-t 23:59:59')]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ym => c
        $cur = clone $start;
        while ($cur <= $end) {
            $key = $cur->format('Y-%m');
            $labels[] = $key;
            $counts[] = isset($rows[$key]) ? intval($rows[$key]) : 0;
            $cur->modify('+1 month');
        }
    }

    echo json_encode(['success' => true, 'period' => $period, 'labels' => $labels, 'counts' => $counts]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Analytics error', 'error' => $e->getMessage()]);
}
