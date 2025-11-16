<?php
require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

// Aggregate quick dashboard stats in a single, cheap call
// Returns:
// {
//   success: true,
//   patientCount: number,
//   appointmentCount: number,
//   reportCount: number,
//   details: { patients:{total}, appointments:{totalPrimary,totalFallback}, reports:{today,source} }
// }

$out = [
    'success' => true,
    'patientCount' => 0,
    'appointmentCount' => 0,
    'reportCount' => 0,
    'details' => [
        'patients' => ['total' => 0],
        'appointments' => ['totalPrimary' => 0, 'totalFallback' => 0],
        'reports' => ['today' => 0, 'source' => null]
    ]
];

// Patients (checkups total)
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM checkups');
    $cnt = (int)$stmt->fetchColumn();
    $out['patientCount'] = $cnt;
    $out['details']['patients']['total'] = $cnt;
} catch (Throwable $e) {
    // leave as 0
}

// Appointments (sum primary + fallback if table exists)
$apTotal = 0; $apPrim = 0; $apFal = 0;
try {
    $s = $pdo->query('SELECT COUNT(*) FROM appointments');
    $apPrim = (int)$s->fetchColumn();
    $apTotal += $apPrim;
} catch (Throwable $e) { /* ignore if table missing */ }
try {
    // create fallback if not exists (same as appointments_new guard but lighter)
    $pdo->exec("CREATE TABLE IF NOT EXISTS appointments_pwa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        role VARCHAR(50),
        year_course VARCHAR(100),
        department VARCHAR(100),
        purpose TEXT,
        date DATE NOT NULL,
        time TIME NOT NULL,
        status VARCHAR(32) DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date_time (date, time)
    ) ENGINE=InnoDB");
    $s2 = $pdo->query('SELECT COUNT(*) FROM appointments_pwa');
    $apFal = (int)$s2->fetchColumn();
    $apTotal += $apFal;
} catch (Throwable $e) { /* ignore */ }
$out['appointmentCount'] = $apTotal;
$out['details']['appointments']['totalPrimary'] = $apPrim;
$out['details']['appointments']['totalFallback'] = $apFal;

// Reports: prefer transactions for today; fallback to checkups created today
$rep = 0; $src = 'transactions_today';
$today = date('Y-m-d');
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE date = ?');
    $st->execute([$today]);
    $rep = (int)$st->fetchColumn();
} catch (Throwable $e1) {
    // If transactions missing, fallback
}
if ($rep === 0) {
    try {
        $src = 'checkups_today_fallback';
        $st2 = $pdo->prepare('SELECT COUNT(*) FROM checkups WHERE DATE(created_at) = ?');
        $st2->execute([$today]);
        $rep = (int)$st2->fetchColumn();
    } catch (Throwable $e2) {
        $rep = 0;
    }
}
$out['reportCount'] = $rep;
$out['details']['reports']['today'] = $rep;
$out['details']['reports']['source'] = $src;

echo json_encode($out);
exit;
?>
