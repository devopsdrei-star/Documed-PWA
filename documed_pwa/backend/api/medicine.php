<?php
// Medicine Inventory API (Admin)
// Features:
// - CRUD for medicines and batches
// - Low-stock (20%) and expiry alerts
// - Decision suggestions based on common conditions in recent checkups

require_once dirname(__DIR__) . '/config/db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function ensure_tables(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS medicines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            campus VARCHAR(100) NOT NULL DEFAULT 'Lingayen',
            unit VARCHAR(100) NULL,
            form VARCHAR(100) NULL,
            strength VARCHAR(100) NULL,
            quantity INT NOT NULL DEFAULT 0,
            baseline_qty INT NULL,
            reorder_threshold_percent INT NOT NULL DEFAULT 20,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL,
            UNIQUE KEY uniq_name_campus (name, campus)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* ignore */ }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS medicine_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT NOT NULL,
            campus VARCHAR(100) NOT NULL DEFAULT 'Lingayen',
            qty INT NOT NULL,
            expiry_date DATE NULL,
            received_at DATE NULL,
            batch_no VARCHAR(100) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_medicine_batches_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
            INDEX idx_medicine (medicine_id),
            INDEX idx_expiry (expiry_date)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* ignore */ }
}

function thresholdQty(array $m): int {
    $percent = isset($m['reorder_threshold_percent']) ? (int)$m['reorder_threshold_percent'] : 20;
    $baseline = isset($m['baseline_qty']) && $m['baseline_qty'] !== null ? (int)$m['baseline_qty'] : (int)$m['quantity'];
    $t = (int)floor(($baseline * max(1, $percent)) / 100);
    return max(1, $t);
}

function nearestExpiry(PDO $pdo, int $medicineId, string $campus = 'Lingayen') {
    try {
        $q = $pdo->prepare("SELECT expiry_date FROM medicine_batches WHERE medicine_id = ? AND campus = ? AND expiry_date IS NOT NULL AND qty > 0 ORDER BY expiry_date ASC LIMIT 1");
        $q->execute([$medicineId, $campus]);
        $d = $q->fetchColumn();
        return $d ?: null;
    } catch (Throwable $e) { return null; }
}

ensure_tables($pdo);

if ($action === 'list') {
    $q = trim($_GET['q'] ?? '');
    $campus = trim($_GET['campus'] ?? 'Lingayen');
    $lowOnly = ($_GET['low_stock_only'] ?? '0');
    $expWithin = (int)($_GET['expiring_within_days'] ?? 0);

    $sql = "SELECT * FROM medicines WHERE campus = ?";
    $params = [$campus];
    if ($q !== '') { $sql .= " AND name LIKE ?"; $params[] = "%$q%"; }
    $sql .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTime('today');
    $out = [];
    foreach ($rows as $m) {
        $thr = thresholdQty($m);
        $isLow = ((int)$m['quantity']) < $thr;
        $exp = nearestExpiry($pdo, (int)$m['id'], $m['campus']);
        $expiringSoon = false;
        if ($exp && $expWithin > 0) {
            try { $d = new DateTime($exp); $diff = (int)$now->diff($d)->format('%r%a'); $expiringSoon = ($diff >= 0 && $diff <= $expWithin); } catch (Throwable $e) { $expiringSoon = false; }
        }
        if ($lowOnly && $lowOnly !== '0' && $lowOnly !== 'false' && !$isLow) { continue; }
        $m['threshold_qty'] = $thr;
        $m['low_stock'] = $isLow ? 1 : 0;
        $m['nearest_expiry'] = $exp;
        $m['expiring_soon'] = $expiringSoon ? 1 : 0;
        $out[] = $m;
    }
    echo json_encode(['success'=>true, 'medicines'=>$out]);
    exit;
}

if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $campus = trim($_POST['campus'] ?? 'Lingayen');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $form = trim($_POST['form'] ?? '');
    $strength = trim($_POST['strength'] ?? '');
    $percent = (int)($_POST['reorder_threshold_percent'] ?? 20);
    if ($name === '') { echo json_encode(['success'=>false,'message'=>'Missing name']); exit; }
    $stmt = $pdo->prepare("INSERT INTO medicines (name, campus, unit, form, strength, quantity, baseline_qty, reorder_threshold_percent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $campus, $unit ?: null, $form ?: null, $strength ?: null, $quantity, $quantity, $percent > 0 ? $percent : 20]);
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    $fields = ['name','campus','unit','form','strength','quantity','baseline_qty','reorder_threshold_percent'];
    $sets = []; $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $_POST)) { $sets[] = "$f = ?"; $val = trim((string)$_POST[$f]); if ($f==='quantity'||$f==='baseline_qty'||$f==='reorder_threshold_percent') $val = $_POST[$f]; $params[] = ($val === '' ? null : $val); }
    }
    if (!$sets) { echo json_encode(['success'=>false,'message'=>'Nothing to update']); exit; }
    $sql = 'UPDATE medicines SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';
    $params[] = $id;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }
    $pdo->prepare('DELETE FROM medicines WHERE id=?')->execute([$id]);
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'add_batch') {
    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $campus = trim($_POST['campus'] ?? 'Lingayen');
    $qty = (int)($_POST['qty'] ?? 0);
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $received_at = trim($_POST['received_at'] ?? '');
    $batch_no = trim($_POST['batch_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if ($medicine_id <= 0 || $qty <= 0) { echo json_encode(['success'=>false,'message'=>'Missing medicine_id or qty']); exit; }
    $stmt = $pdo->prepare('INSERT INTO medicine_batches (medicine_id, campus, qty, expiry_date, received_at, batch_no, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$medicine_id, $campus, $qty, $expiry_date ?: null, $received_at ?: null, $batch_no ?: null, $notes ?: null]);
    // increment medicine quantity and maybe raise baseline
    $pdo->prepare('UPDATE medicines SET quantity = quantity + ?, baseline_qty = GREATEST(COALESCE(baseline_qty,0), quantity + ?) WHERE id = ?')->execute([$qty, $qty, $medicine_id]);
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'list_batches') {
    $medicine_id = (int)($_GET['medicine_id'] ?? $_POST['medicine_id'] ?? 0);
    if ($medicine_id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing medicine_id']); exit; }
    $stmt = $pdo->prepare('SELECT * FROM medicine_batches WHERE medicine_id = ? ORDER BY COALESCE(expiry_date, DATE("9999-12-31")) ASC, id ASC');
    $stmt->execute([$medicine_id]);
    echo json_encode(['success'=>true,'batches'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

if ($action === 'delete_batch') {
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    if ($batch_id <= 0) { echo json_encode(['success'=>false,'message'=>'Missing batch_id']); exit; }
    // adjust quantity by subtracting batch qty
    $q = $pdo->prepare('SELECT medicine_id, qty FROM medicine_batches WHERE id=?'); $q->execute([$batch_id]); $b = $q->fetch(PDO::FETCH_ASSOC);
    if ($b) { $pdo->prepare('UPDATE medicines SET quantity = GREATEST(0, quantity - ?) WHERE id=?')->execute([(int)$b['qty'], (int)$b['medicine_id']]); }
    $pdo->prepare('DELETE FROM medicine_batches WHERE id=?')->execute([$batch_id]);
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'alerts') {
    $campus = trim($_GET['campus'] ?? 'Lingayen');
    $within = (int)($_GET['expiring_within_days'] ?? 60);
    $stmt = $pdo->prepare('SELECT * FROM medicines WHERE campus = ? ORDER BY name');
    $stmt->execute([$campus]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $low = []; $exp = [];
    $now = new DateTime('today');
    foreach ($rows as $m) {
        $thr = thresholdQty($m);
        if ((int)$m['quantity'] < $thr) { $m['threshold_qty'] = $thr; $low[] = $m; }
        $d = nearestExpiry($pdo, (int)$m['id'], $campus);
        if ($d) {
            try { $dd = new DateTime($d); $diff = (int)$now->diff($dd)->format('%r%a'); if ($diff >= 0 && $diff <= $within) { $m['nearest_expiry'] = $d; $m['days_to_expiry'] = $diff; $exp[] = $m; } } catch (Throwable $e) { /* ignore */ }
        }
    }
    echo json_encode(['success'=>true, 'low_stock'=>$low, 'expiring'=>$exp]); exit;
}

if ($action === 'suggestions') {
    // Analyze checkups in last N days and suggest medicines for the top conditions
    $days = (int)($_GET['days'] ?? 30);
    $since = (new DateTime("-$days days"))->format('Y-m-d 00:00:00');
    $conditions = [
        'flu' => ['flu','influenza'],
        'fever' => ['fever','febrile','lagnat'],
        'stomachache' => ['stomachache','abdominal pain','tummy ache','colic','dyspepsia','hyperacidity','gastritis'],
        'cough' => ['cough','ubo'],
        'cold' => ['cold','sipon','rhinitis','runny nose'],
        'diarrhea' => ['diarrhea','loose stool','lbm'],
        'hypertension' => ['hypertension','high blood pressure','bp high','hpn'],
        'vertigo' => ['vertigo','dizziness','imbalance'],
        'diabetes' => ['diabetes','dm','hyperglycemia'],
        'sore throat' => ['sore throat','pharyngitis','tonsillitis'],
        'dermatitis' => ['dermatitis','rashes','eczema','skin inflammation'],
        'eye infection' => ['conjunctivitis','stye','eye infection']
    ];
    $reco = [
        'flu' => [
            'Paracetamol 500mg 100tabs/box (Biogesic)',
            'Phenylephrine HCI+ Chlorphenamine Maleate + Paracetamol 100caps/box (bioflu)',
            'Cetirizine 10mg 100 tabs/box (histacet)',
            'Ibuprofen 200mg  100gelcap/box (FevrAL)'
        ],
        'fever' => [
            'Paracetamol 500mg 100tabs/box (Biogesic)',
            'Ibuprofen 200mg  100gelcap/box (FevrAL)',
            'Ascorbic acid + Zinc 100tabs/box (Myrevit C Plus)',
            'Multivitamins 100 caps/box (skyvit)'
        ],
        'stomachache' => [
            'Dicycloverine 10mg 100 tabs/box',
            'Hyocine-N-Butyl Bromide 100 tabs/box (vonwelt)',
            'Aluminum hydroxide+Magnesium hydroxide 100/box (shelogel)',
            'Omeprazole 40mg 100 caps/box (Inhibita)',
            'Domperidone 10mg 100 tabs/box'
        ],
        'cough' => [
            'Ambroxol 30mg 100tabs/box (mucolax)',
            'Carbocisteine 500mg 100caps/box (mucolief)',
            'Dextromethorphan + Guaifenesin + Phenylpropanolamine + Chlorphenamine Maleate 100caps/box (Mocutoss)',
            'Salbutamol nebule 30 nebules/box (hivent EM)'
        ],
        'cold' => [
            'Cetirizine 10mg 100 tabs/box (histacet)',
            'Phenylephrine HCI+ Chlorphenamine Maleate + Paracetamol  100caps/box (bioflu)',
            'Diphenhydramine 50mg 100 caps/box (histamox)'
        ],
        'diarrhea' => ['Loperamide 2mg 100 caps/box (harvimide)'],
        'hypertension' => ['Amlodipine 5mg 100 caps/box (amlosaph)','Nifedipine 5mg 100 caps/box','Losartan 50mg 100 caps/box (gensartan)','Clonidine 75mcg 100tabs/box (catamed)'],
        'vertigo' => ['Cinnarizine 25mg 100 tabs/box (vertex)'],
        'diabetes' => ['Metformin 500mg 100 caps/box (glycemet)'],
        'sore throat' => ['Co-amoxiclav 625mg 14 tabs/box (asiclav)','Amoxicillin 500mg 100/box (ammocil)','Cloxacillin 500mg 100 caps/box (philclox)','Erythromycin 500mg 100 tabs/box'],
        'dermatitis' => ['Hydrocortisone 10mg/gm topical cream 10gm/tube (kurt)','Mupirocin 20mg/g  ointment 5gm tube (kaptroban)'],
        'eye infection' => ['Tobramycin 3mg/ml (0.3%) ophthalmic drops 5ml/bottle (ramitob)']
    ];

    // Count occurrences from checkups (assessment + present_illness)
    $stats = [];
    foreach ($conditions as $cond => $words) {
        $or = [];
        $params = [];
        foreach ($words as $w) { $or[] = '(LOWER(COALESCE(assessment,\'\')) LIKE ? OR LOWER(COALESCE(present_illness,\'\')) LIKE ?)'; $like = '%' . strtolower($w) . '%'; $params[] = $like; $params[] = $like; }
        $sql = 'SELECT COUNT(*) FROM checkups WHERE created_at >= ? AND (' . implode(' OR ', $or) . ')';
        array_unshift($params, $since);
        try { $q = $pdo->prepare($sql); $q->execute($params); $c = (int)$q->fetchColumn(); } catch (Throwable $e) { $c = 0; }
        $stats[$cond] = $c;
    }
    arsort($stats);
    $top = array_slice($stats, 0, 5, true);

    // Attach recommended medicines with stock
    $suggestions = [];
    foreach ($top as $cond => $count) {
        if ($count <= 0) continue;
        $meds = $reco[$cond] ?? [];
        $list = [];
        foreach ($meds as $mn) {
            $s = $pdo->prepare('SELECT * FROM medicines WHERE name = ? LIMIT 1');
            $s->execute([$mn]);
            $m = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            $list[] = [
                'name' => $mn,
                'in_inventory' => $m ? 1 : 0,
                'quantity' => $m ? (int)$m['quantity'] : 0,
                'low_stock' => $m ? (((int)$m['quantity']) < thresholdQty($m) ? 1 : 0) : null
            ];
        }
        $suggestions[] = [ 'condition' => $cond, 'cases' => $count, 'recommendations' => $list ];
    }

    // Week subset for growth/severity heuristic
    $weekSince = (new DateTime('-7 days'))->format('Y-m-d 00:00:00');
    $weeklyCounts = [];
    foreach ($conditions as $cond => $words) {
        $or = []; $params = [];
        foreach ($words as $w) { $or[] = '(LOWER(COALESCE(assessment,\'\')) LIKE ? OR LOWER(COALESCE(present_illness,\'\')) LIKE ?)'; $like = '%' . strtolower($w) . '%'; $params[] = $like; $params[] = $like; }
        $sql = 'SELECT COUNT(*) FROM checkups WHERE created_at >= ? AND (' . implode(' OR ', $or) . ')';
        array_unshift($params, $weekSince);
        try { $q = $pdo->prepare($sql); $q->execute($params); $c = (int)$q->fetchColumn(); } catch (Throwable $e) { $c = 0; }
        $weeklyCounts[$cond] = $c;
    }

    // Derive actions: orientation / restock / monitor
    $actions = [ 'orientation' => [], 'restock' => [], 'monitor' => [] ];
    $restockSet = [];
    foreach ($suggestions as $s) {
        $cond = $s['condition'];
        $casesMonth = $s['cases'];
        $casesWeek = $weeklyCounts[$cond] ?? 0;
        $growthRatio = $casesWeek > 0 ? ($casesWeek / max(1, $casesMonth)) : 0; // portion of monthly that occurred in last week
        $severity = 'low';
        if ($casesMonth >= 25 || $growthRatio >= 0.6) $severity = 'high';
        elseif ($casesMonth >= 10 || $growthRatio >= 0.4) $severity = 'medium';
        // Orientation if high severity OR medium with growth
        if ($severity === 'high') {
            $actions['orientation'][] = [ 'condition' => $cond, 'month_cases' => $casesMonth, 'week_cases' => $casesWeek, 'reason' => 'High incidence/growth' ];
        } elseif ($severity === 'medium') {
            $actions['monitor'][] = [ 'condition' => $cond, 'month_cases' => $casesMonth, 'week_cases' => $casesWeek, 'reason' => 'Moderate incidence' ];
        } else {
            $actions['monitor'][] = [ 'condition' => $cond, 'month_cases' => $casesMonth, 'week_cases' => $casesWeek, 'reason' => 'Low incidence' ];
        }
        // Restock candidates: in recommendations but not in inventory or low stock
        foreach ($s['recommendations'] as $r) {
            if (!$r['in_inventory'] || $r['low_stock']) {
                if (!isset($restockSet[$r['name']])) {
                    $restockSet[$r['name']] = [ 'name' => $r['name'], 'in_inventory' => $r['in_inventory'], 'low_stock' => $r['low_stock'], 'linked_condition' => $cond ];
                }
            }
        }
    }
    $actions['restock'] = array_values($restockSet);

    // Simple narrative (no external AI call)
    $narrative = '';
    if ($suggestions) {
        $first = $suggestions[0];
        $narrative = 'Top condition in the last ' . $days . ' days: ' . $first['condition'] . ' (' . $first['cases'] . ' cases). '
                   . ' Growth focus uses last 7 days vs month. Orientation suggested for high incidence or rapid growth.';
    } else {
        $narrative = 'No recent condition trends detected in the last ' . $days . ' days.';
    }

    echo json_encode([
        'success'=>true,
        'since'=>$since,
        'week_since'=>$weekSince,
        'stats'=>$stats,
        'weekly'=>$weeklyCounts,
        'suggestions'=>$suggestions,
        'narrative'=>$narrative,
        'actions'=>$actions
    ]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action']);
