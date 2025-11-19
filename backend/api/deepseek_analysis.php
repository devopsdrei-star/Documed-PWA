<?php
header('Content-Type: application/json; charset=utf-8');
// DeepSeek integration endpoint
// Actions:
//  - analyze_text : POST { text | checkup_id | student_faculty_id, admin_id }
//  - create_restock_request : POST { medicine_name, matched_medicine_id?, requestor_id? }

require_once __DIR__ . '/../config/db.php'; // provides $pdo (PDO)

$action = $_REQUEST['action'] ?? '';

// try to load a DeepSeek API key and URL if present (do NOT echo them back)
$deepseek_key = null; $deepseek_url = null;
if (file_exists(__DIR__ . '/../config/deepseek_key.php')) {
    try { include __DIR__ . '/../config/deepseek_key.php'; } catch (Exception $e) { /* ignore */ }
}

// Best-effort migrations for required tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_analysis_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requestor_id INT NULL,
        requestor_ip VARCHAR(60) NULL,
        checkup_id INT NULL,
        student_faculty_id VARCHAR(50) NULL,
        redacted_input TEXT,
        response_json LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (Throwable $e) { }
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_restock_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        medicine_name VARCHAR(255) NOT NULL,
        matched_medicine_id INT NULL,
        requestor_id INT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (Throwable $e) { }
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(60) NOT NULL,
        window_start INT NOT NULL,
        cnt INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_ip_window (ip, window_start)
    ) ENGINE=InnoDB");
} catch (Throwable $e) { }

function client_ip(){
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function rate_limited($pdo, $ip, $limit = 8, $window_seconds = 60){
    $start = (int)(time() / $window_seconds) * $window_seconds;
    try {
        $stmt = $pdo->prepare('SELECT id, cnt FROM ai_rate_limits WHERE ip = ? AND window_start = ? LIMIT 1');
        $stmt->execute([$ip, $start]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $ins = $pdo->prepare('INSERT INTO ai_rate_limits (ip, window_start, cnt) VALUES (?, ?, 1)');
            $ins->execute([$ip, $start]);
            return false;
        }
        if ((int)$row['cnt'] >= $limit) return true;
        $upd = $pdo->prepare('UPDATE ai_rate_limits SET cnt = cnt + 1 WHERE id = ?');
        $upd->execute([(int)$row['id']]);
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function redact_phi($s){
    if (!$s) return $s;
    // redact emails
    $s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $s);
    // redact long digit sequences (IDs)
    $s = preg_replace('/\b\d{5,}\b/', '[REDACTED_ID]', $s);
    // redact phone-like patterns
    $s = preg_replace('/(\+?\d[\d \-()]{6,}\d)/', '[REDACTED_PHONE]', $s);
    return $s;
}

function call_external_api($url, $key, $payloadJson){
    $attempt = 0; $max = 3; $delay = 500000; // microseconds
    while ($attempt < $max) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = ['Content-Type: application/json'];
        if ($key) $headers[] = 'Authorization: Bearer ' . $key;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $status >=200 && $status < 300) return ['ok'=>true,'status'=>$status,'body'=>$resp];
        if ($status >= 500 || $resp === false) {
            usleep($delay);
            $delay *= 2; $attempt++; continue;
        }
        return ['ok'=>false,'status'=>$status,'body'=>$resp,'err'=>$err];
    }
    return ['ok'=>false,'status'=>0,'body'=>null,'err'=>'max_retries'];
}

function fallback_analysis($text) {
    $lower = strtolower($text);
    $diag = 'General illness';
    if (strpos($lower, 'fever') !== false) $diag = 'Fever';
    if (strpos($lower, 'cough') !== false) $diag = 'Cough / Respiratory infection';
    if (strpos($lower, 'abdominal') !== false || strpos($lower, 'stomach') !== false) $diag = 'Abdominal complaint';

    $recs = [];
    if (strpos($lower, 'fever') !== false || strpos($lower, 'pain') !== false) {
        $recs[] = ['name' => 'Paracetamol 500mg', 'rationale' => 'For fever and pain control (symptomatic)'];
    }
    if (strpos($lower, 'cough') !== false) {
        $recs[] = ['name' => 'Dextromethorphan syrup', 'rationale' => 'Symptomatic cough relief'];
    }
    if (strpos($lower, 'sore throat') !== false) {
        $recs[] = ['name' => 'Benzydamine mouthwash or analgesic lozenges', 'rationale' => 'Local throat pain relief'];
    }

    return [
        'diagnosis' => $diag,
        'urgency' => 'low',
        'summary' => mb_substr($text, 0, 400),
        'recommended_medicines' => $recs,
        'recommended_tests' => []
    ];
}

try {
    $ip = client_ip();
    if (rate_limited($pdo, $ip, 8, 60)) {
        header('HTTP/1.1 429 Too Many Requests');
        header('Retry-After: 60');
        echo json_encode(['success'=>false,'code'=>429,'message'=>'Rate limit exceeded']);
        exit;
    }

    if ($action === 'analyze_text') {
        $checkup_id = isset($_POST['checkup_id']) ? intval($_POST['checkup_id']) : null;
        $student_faculty_id = isset($_POST['student_faculty_id']) ? trim($_POST['student_faculty_id']) : null;
        $user_id = isset($_POST['admin_id']) ? intval($_POST['admin_id']) : null;

        $text = trim($_POST['text'] ?? '');
        if (!$text && $checkup_id) {
            $stmt = $pdo->prepare('SELECT present_illness, history_past_illness, assessment, remarks FROM checkups WHERE id = ? LIMIT 1');
            $stmt->execute([$checkup_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $parts = [];
                if (!empty($r['present_illness'])) $parts[] = $r['present_illness'];
                if (!empty($r['history_past_illness'])) $parts[] = $r['history_past_illness'];
                if (!empty($r['assessment'])) $parts[] = $r['assessment'];
                if (!empty($r['remarks'])) $parts[] = $r['remarks'];
                $text = implode("\n\n", $parts);
            }
        } elseif (!$text && $student_faculty_id) {
            $stmt = $pdo->prepare('SELECT present_illness, history_past_illness, assessment, remarks FROM checkups WHERE student_faculty_id = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$student_faculty_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $parts = [];
                if (!empty($r['present_illness'])) $parts[] = $r['present_illness'];
                if (!empty($r['history_past_illness'])) $parts[] = $r['history_past_illness'];
                if (!empty($r['assessment'])) $parts[] = $r['assessment'];
                if (!empty($r['remarks'])) $parts[] = $r['remarks'];
                $text = implode("\n\n", $parts);
            }
        }

        if ($text === '') {
            echo json_encode(['success' => false, 'message' => 'No note available for analysis']);
            exit;
        }

        $redacted = redact_phi($text);

        if ($deepseek_url) {
            $payload = json_encode(['prompt' => "Provide a concise structured JSON analysis for a clinical note. Return JSON with keys: diagnosis (string), urgency (low|medium|high), summary (string), recommended_medicines (array of {name,rationale}), recommended_tests (array of strings). Do NOT include PHI. The clinical note is provided in the 'note' field.", 'note' => $redacted, 'max_tokens' => 1200]);
            $resp = call_external_api($deepseek_url, $deepseek_key, $payload);
            if ($resp['ok']) {
                $json = json_decode($resp['body'], true);
            } else {
                $json = null;
            }
        } else {
            $json = null;
        }

        if ($json && isset($json['analysis']) && is_array($json['analysis'])) {
            $analysis = $json['analysis'];
        } elseif ($json && isset($json['diagnosis'])) {
            $analysis = $json;
        } else {
            $analysis = fallback_analysis($text);
        }

        $analysis['recommended_medicines'] = $analysis['recommended_medicines'] ?? [];
        $analysis['recommended_tests'] = $analysis['recommended_tests'] ?? [];

        try {
            foreach ($analysis['recommended_medicines'] as &$rec) {
                $recName = trim($rec['name'] ?? '');
                $rec['in_inventory'] = false;
                $rec['matched'] = null;
                if ($recName === '') continue;
                $pattern = '%' . strtolower(preg_replace('/[^a-z0-9 ]+/', ' ', $recName)) . '%';
                $stmt = $pdo->prepare('SELECT id, name, quantity FROM medicines WHERE LOWER(name) LIKE ? LIMIT 1');
                $stmt->execute([$pattern]);
                $m = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($m) {
                    $rec['in_inventory'] = true;
                    $rec['matched'] = ['id' => (int)$m['id'], 'name' => $m['name'], 'quantity' => (int)$m['quantity']];
                }
            }
            unset($rec);
        } catch (Exception $e) { }

        try {
            $ins = $pdo->prepare('INSERT INTO ai_analysis_logs (requestor_id, requestor_ip, checkup_id, student_faculty_id, redacted_input, response_json) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([$user_id, $ip, $checkup_id, $student_faculty_id, $redacted, json_encode($analysis)]);
        } catch (Exception $e) { }

        echo json_encode(['success' => true, 'analysis' => $analysis]);
        exit;
    }

    if ($action === 'analyze_aggregate') {
        // aggregate recent checkups and inventory into a single prompt for the model
        $days = isset($_GET['days']) ? intval($_GET['days']) : (isset($_POST['days']) ? intval($_POST['days']) : 30);
        $campus = isset($_GET['campus']) ? trim($_GET['campus']) : (isset($_POST['campus']) ? trim($_POST['campus']) : 'Lingayen');

        // fetch recent checkups (non-archived)
        try {
            $stmt = $pdo->prepare('SELECT student_faculty_id, created_at, present_illness, assessment FROM checkups WHERE archived = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY created_at DESC LIMIT 500');
            $stmt->execute([$days]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $rows = []; }

        $checkup_summary = "Recent checkups (last $days days):\n";
        foreach ($rows as $r) {
            $d = $r['created_at'] ?? '';
            $pi = trim($r['present_illness'] ?? '');
            $ass = trim($r['assessment'] ?? '');
            $checkup_summary .= "- [{$d}] " . ($pi ? substr($pi,0,200) : ($ass?substr($ass,0,200):'no note')) . "\n";
        }

        // fetch inventory snapshot
        try {
            $stmt = $pdo->prepare('SELECT name, quantity FROM medicines WHERE campus = ? ORDER BY name LIMIT 1000');
            $stmt->execute([$campus]);
            $meds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $meds = []; }

        $inv_summary = "Inventory snapshot for $campus:\n";
        foreach ($meds as $m) { $inv_summary .= "- {$m['name']}: {$m['quantity']}\n"; }

        $prompt_text = "You are provided two sections: INVENTORY and CHECKUPS. Analyze trends and recommend actions. Return JSON with keys: analysis (diagnosis_summary, trends, narrative), actions (orientation: [{condition, month_cases, week_cases, reason}], restock: [{name, reason, linked_condition, in_inventory, matched_name}], monitor: [...]). Do not include PHI.\n\nINVENTORY:\n" . $inv_summary . "\n\nCHECKUPS:\n" . $checkup_summary . "\n\nPlease produce concise JSON as described.";

        $redacted = redact_phi($prompt_text);
        if ($deepseek_url) {
            $payload = json_encode(['prompt' => $prompt_text, 'note' => $redacted, 'max_tokens' => 1600]);
            $resp = call_external_api($deepseek_url, $deepseek_key, $payload);
            if ($resp['ok']) {
                $json = json_decode($resp['body'], true);
            } else {
                $json = null;
            }
        } else {
            $json = null;
        }

        if ($json && isset($json['analysis'])) {
            $result = $json;
        } elseif ($json && isset($json['diagnosis'])) {
            $result = ['analysis'=>$json, 'actions'=>[]];
        } else {
            // fallback: simple heuristic to suggest restock for top missing meds in recommendations from recent checkups
            $result = ['analysis'=>['diagnosis'=>'Aggregate heuristic','narrative'=>'No model available - heuristic used.'],'actions'=>['restock'=>[],'orientation'=>[],'monitor'=>[]]];
        }

        // persist aggregated audit
        try {
            $ins = $pdo->prepare('INSERT INTO ai_analysis_logs (requestor_id, requestor_ip, checkup_id, student_faculty_id, redacted_input, response_json) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute([null, client_ip(), null, null, substr($redacted,0,4000), json_encode($result)]);
        } catch (Exception $e) { }

        echo json_encode(['success'=>true,'result'=>$result]);
        exit;
    }

    if ($action === 'create_restock_request') {
        $medicine_name = trim($_POST['medicine_name'] ?? '');
        $matched_id = isset($_POST['matched_medicine_id']) && is_numeric($_POST['matched_medicine_id']) ? intval($_POST['matched_medicine_id']) : null;
        $requestor_id = isset($_POST['requestor_id']) ? intval($_POST['requestor_id']) : null;
        if ($medicine_name === '') { echo json_encode(['success'=>false,'message'=>'medicine_name required']); exit; }
        try {
            $ins = $pdo->prepare('INSERT INTO ai_restock_requests (medicine_name, matched_medicine_id, requestor_id) VALUES (?, ?, ?)');
            $ins->execute([$medicine_name, $matched_id, $requestor_id]);
            echo json_encode(['success'=>true,'request_id'=>$pdo->lastInsertId()]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>'DB error']); exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}

 
