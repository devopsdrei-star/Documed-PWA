<?php
header('Content-Type: application/json');
// Compatibility AI analysis endpoint used by admin UI.
// Provides two actions:
//  - action=analyze_aggregate (GET) -> returns a lightweight aggregate result when AI is not configured
//  - action=analyze_text (POST) -> proxies text to chatbot_deepseek.php if available

require_once dirname(__DIR__) . '/config/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'analyze_text') {
    // Expect POST form-encoded with 'text'
    $text = trim($_POST['text'] ?? '') ?: trim(file_get_contents('php://input'));
    if (!$text) {
        echo json_encode(['success' => false, 'message' => 'No text provided']);
        exit;
    }

    // If chatbot_deepseek.php exists, proxy to it (it expects JSON POST with message)
    $proxy = __DIR__ . '/chatbot_deepseek.php';
    if (file_exists($proxy)) {
        $url = dirname($_SERVER['SCRIPT_NAME']) . '/chatbot_deepseek.php';
        // Build absolute URL if possible
        $host = $_SERVER['HTTP_HOST'] ?? null;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if ($host) {
            $full = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/chatbot_deepseek.php';
        } else {
            $full = $url;
        }
        // POST JSON
        $payload = json_encode(['message' => $text]);
        $ch = curl_init($full);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>15]);
        $resp = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($err || !$resp) {
            echo json_encode(['success' => false, 'message' => 'AI provider not reachable', 'error' => $err]);
            exit;
        }
        $j = json_decode($resp, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($j['reply'])) {
            // Map to analysis shape expected by frontend
            $analysis = ['summary' => $j['reply'], 'diagnosis' => null, 'recommended_medicines' => [], 'recommended_tests' => [], 'raw' => $j];
            echo json_encode(['success' => true, 'analysis' => $analysis, 'provider_info' => $j['info'] ?? null]);
            exit;
        }
        // Fallback: return raw provider body
        echo json_encode(['success' => true, 'analysis' => ['summary' => $resp], 'raw_response' => $resp]);
        exit;
    }

    // No provider available; return fallback message
    $analysis = ['summary' => "AI not configured. Please add DeepSeek API key or use local fallback.", 'diagnosis' => null, 'recommended_medicines' => [], 'recommended_tests' => []];
    echo json_encode(['success' => true, 'analysis' => $analysis, 'message' => 'AI not configured']);
    exit;
}

// analyze_aggregate: return lightweight aggregate if AI not configured.
if ($action === 'analyze_aggregate') {
    $days = (int)($_GET['days'] ?? 30);
    $campus = $_GET['campus'] ?? 'Lingayen';

    // Basic aggregation: count the most common assessment words in recent checkups
    try {
        $since = (new DateTime("-$days days"))->format('Y-m-d 00:00:00');
        $q = $pdo->prepare('SELECT COALESCE(assessment,\'\') as assessment, COALESCE(present_illness,\'\') as present_illness FROM checkups WHERE created_at >= ? AND archived=0');
        $q->execute([$since]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    // Condition keyword groups (simple heuristic)
    $conditions = [
        'flu' => ['flu','influenza','flu-like'],
        'fever' => ['fever','febrile','lagnat'],
        'stomachache' => ['stomachache','abdominal','tummy','colic','dyspepsia','gastritis','stomach'],
        'cough' => ['cough','ubo','tulo','uhing'],
        'cold' => ['cold','sipon','rhinitis','runny nose','sneeze','sneezing'],
        'diarrhea' => ['diarrhea','loose stool','lbm','diare','dysentery'],
        'headache' => ['headache','migraine','sakit ng ulo','head ache','head pain'],
        'backache' => ['backache','low back pain','sakit sa likod','back pain'],
        'sore throat' => ['sore throat','pharyngitis','tonsillitis','throat pain'],
        'dermatitis' => ['dermatitis','rash','rashes','eczema'],
        'eye infection' => ['conjunctivitis','eye infection','red eye']
    ];

    $condCounts = array_fill_keys(array_keys($conditions), 0);
    $totalCases = 0;
    foreach ($rows as $r) {
        $totalCases++;
        $text = strtolower(($r['assessment'] ?? '') . ' ' . ($r['present_illness'] ?? ''));
        $matched = false;
        foreach ($conditions as $cond => $words) {
            foreach ($words as $w) {
                if ($w === '') continue;
                if (strpos($text, strtolower($w)) !== false) { $condCounts[$cond]++; $matched = true; break 2; }
            }
        }
        // if no condition matched, skip
    }

    // compute top conditions and simple narrative
    arsort($condCounts);
    $topConds = array_slice($condCounts, 0, 6, true);
    $narrative = 'No recent checkups found.';
    if ($totalCases > 0) {
        $topNames = array_keys(array_filter($topConds, fn($c)=>$c>0));
        $narrative = 'Top conditions in last ' . $days . ' days: ' . implode(', ', $topNames);
    }

    // actions: compute low-stock restock suggestions from inventory for the campus
    $restock = [];
    try {
        $s = $pdo->prepare('SELECT * FROM medicines WHERE campus = ? ORDER BY name ASC');
        $s->execute([$campus]);
        $meds = $s->fetchAll(PDO::FETCH_ASSOC);
        $now = new DateTime('today');
        foreach ($meds as $m) {
            $percent = (int)($m['reorder_threshold_percent'] ?? 20);
            $baseline = isset($m['baseline_qty']) && $m['baseline_qty'] !== null ? (int)$m['baseline_qty'] : (int)($m['quantity'] ?? 0);
            $thr = (int)floor(($baseline * max(1, $percent)) / 100);
            if ($thr < 1) $thr = 1;
            $qty = (int)($m['quantity'] ?? 0);
            if ($qty < $thr) {
                $restock[] = [
                    'id' => $m['id'] ?? null,
                    'name' => $m['name'] ?? '',
                    'in_inventory' => 1,
                    'low_stock' => 1,
                    'quantity' => $qty,
                    'threshold_qty' => $thr,
                    'campus' => $m['campus'] ?? $campus,
                    'reason' => 'Quantity below reorder threshold'
                ];
            }
        }
    } catch (Throwable $e) {
        // ignore inventory errors and leave restock empty
        $restock = [];
    }
    // Build orientation/monitor actions based on condition percentages
    $orientation = [];
    $monitor = [];
    try {
        // Compute weekly counts too
        $sinceWeek = (new DateTime('-7 days'))->format('Y-m-d 00:00:00');
        $qWeek = $pdo->prepare('SELECT COALESCE(assessment,\'\') as assessment, COALESCE(present_illness,\'\') as present_illness FROM checkups WHERE created_at >= ? AND archived=0');
        $qWeek->execute([$sinceWeek]);
        $rowsWeek = $qWeek->fetchAll(PDO::FETCH_ASSOC);
        $weekCounts = array_fill_keys(array_keys($conditions), 0);
        $weekTotal = 0;
        foreach ($rowsWeek as $r) {
            $weekTotal++;
            $text = strtolower(($r['assessment'] ?? '') . ' ' . ($r['present_illness'] ?? ''));
            foreach ($conditions as $cond => $words) {
                foreach ($words as $w) {
                    if ($w==='' ) continue;
                    if (strpos($text, strtolower($w)) !== false) { $weekCounts[$cond]++; break 2; }
                }
            }
        }

        foreach ($condCounts as $cond => $count) {
            if ($totalCases <= 0) continue;
            $pct = ($count / max(1, $totalCases)) * 100.0;
            $weekC = $weekCounts[$cond] ?? 0;
            if ($pct >= 60.0) {
                // high concentration — ask DeepSeek (via chatbot_deepseek.php) for recommended medicines and advice
                $medList = [];
                $modelReply = null;
                $proxy = __DIR__ . '/chatbot_deepseek.php';
                if (file_exists($proxy)) {
                    // Build a JSON-instruction prompt asking for JSON output
                    $prompt = "You are an assistant for a campus medical clinic. A condition named '$cond' has been detected in $pct% of recent cases at campus $campus (month_cases=$count, week_cases=$weekC). Provide up to 5 recommended symptomatic or supportive medicine names (no dosing), a one-line rationale per medicine, and a short actionable advice paragraph for clinic administrators (orientation/education + inventory actions).\n\nRespond ONLY with a JSON object with keys: recommended_medicines (array of {name, rationale}), advice (string). Do NOT include PHI, dosing, or specific prescription instructions. Example:\n{\"recommended_medicines\": [{\"name\":\"Paracetamol\",\"rationale\":\"for symptomatic relief of fever\"}],\"advice\":\"...\"}\n";
                    // Proxy to chatbot_deepseek.php on same host
                    $host = $_SERVER['HTTP_HOST'] ?? null;
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    if ($host) { $full = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/chatbot_deepseek.php'; }
                    else { $full = dirname($_SERVER['SCRIPT_NAME']) . '/chatbot_deepseek.php'; }
                    $payload = json_encode(['message' => $prompt]);
                    $ch = curl_init($full);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>20]);
                    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
                    if (!$err && $resp) {
                        $j = json_decode($resp, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($j['reply'])) {
                            $modelReply = $j['reply'];
                        } else {
                            // If chatbot_deepseek returned non-structured data, try to parse the raw response body
                            $modelReply = is_string($resp) ? $resp : null;
                        }
                    }
                }

                $parsedMeds = null;
                if ($modelReply) {
                    // Attempt to decode JSON from the model reply
                    $decoded = json_decode($modelReply, true);
                    if (json_last_error() === JSON_ERROR_NONE && !empty($decoded['recommended_medicines'])) {
                        $parsedMeds = $decoded;
                    } else {
                        // Try to extract lines with bullets as fallback
                        $lines = preg_split('/\r?\n/', $modelReply);
                        $list = [];
                        foreach ($lines as $ln) {
                            $ln = trim($ln);
                            if (preg_match('/^-\s*(.+)$/', $ln, $mline) || preg_match('/^\d+\.\s*(.+)$/', $ln, $mline)) {
                                $name = $mline[1];
                                // strip rationale in parentheses
                                $nameOnly = preg_replace('/\s*\(.*\)$/', '', $name);
                                $list[] = ['name' => trim($nameOnly), 'rationale' => ''];
                            }
                        }
                        if ($list) {
                            $parsedMeds = ['recommended_medicines' => $list, 'advice' => 'See recommended medicines list.'];
                        }
                    }
                }

                if (is_array($parsedMeds) && !empty($parsedMeds['recommended_medicines'])) {
                    foreach ($parsedMeds['recommended_medicines'] as $rm) {
                        $mn = trim($rm['name'] ?? '');
                        $rat = trim($rm['rationale'] ?? '');
                        $matched = null;
                        try {
                            $s2 = $pdo->prepare('SELECT * FROM medicines WHERE LOWER(name) LIKE ? LIMIT 1');
                            $like = '%' . strtolower($mn) . '%';
                            $s2->execute([$like]);
                            $m = $s2->fetch(PDO::FETCH_ASSOC) ?: null;
                            if ($m) $matched = ['id'=>$m['id'],'name'=>$m['name'],'quantity'=>$m['quantity']];
                        } catch (Throwable $_) { $m = null; }
                        $medList[] = ['name' => $mn, 'rationale' => $rat, 'in_inventory' => $matched ? 1 : 0, 'matched' => $matched];
                    }
                    $advice = $parsedMeds['advice'] ?? 'Consider targeted health education and ensure adequate stock of symptomatic medicines.';
                } else {
                    // Fallback to simple heuristic map if model didn't provide usable output
                    $recoMap = [
                        'flu' => ['Paracetamol','Cetirizine'],
                        'fever' => ['Paracetamol','Ibuprofen'],
                        'cough' => ['Ambroxol','Dextromethorphan'],
                        'cold' => ['Cetirizine','Phenylephrine'],
                        'diarrhea' => ['Loperamide','Oral Rehydration Solution'],
                        'sore throat' => ['Amoxicillin (if bacterial)','Analgesic'],
                        'headache' => ['Paracetamol','Ibuprofen'],
                        'backache' => ['Paracetamol','Topical NSAID'],
                        'stomachache' => ['Antacid','Omeprazole'],
                        'dermatitis' => ['Topical hydrocortisone','Mupirocin'],
                        'eye infection' => ['Tobramycin eye drops']
                    ];
                    $recoNames = $recoMap[$cond] ?? [];
                    foreach ($recoNames as $mn) {
                        try { $s2 = $pdo->prepare('SELECT * FROM medicines WHERE LOWER(name) LIKE ? LIMIT 1'); $like = '%' . strtolower($mn) . '%'; $s2->execute([$like]); $m = $s2->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $_) { $m = null; }
                        $medList[] = ['name'=>$mn,'rationale'=>'','in_inventory'=>$m?1:0,'matched'=>$m?['id'=>$m['id'],'name'=>$m['name'],'quantity'=>$m['quantity']]:null];
                    }
                    $advice = 'Consider targeted health education and ensure adequate stock of symptomatic medicines.';
                }
                $orientation[] = [ 'condition' => $cond, 'month_cases' => $count, 'week_cases' => $weekC, 'percent' => $pct, 'reason' => 'Condition comprises >=60% of cases', 'recommended_medicines' => $medList, 'advice' => $advice ];
            } elseif ($pct >= 30.0) {
                $monitor[] = [ 'condition' => $cond, 'month_cases' => $count, 'week_cases' => $weekC, 'percent' => $pct, 'reason' => 'Moderate representation — monitor for growth' ];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $result = ['analysis' => ['narrative' => $narrative], 'actions' => ['orientation' => $orientation, 'restock' => $restock, 'monitor' => $monitor]];
    echo json_encode(['success' => true, 'result' => $result]);
    exit;
}

// Default: explain usage
echo json_encode(['success' => false, 'message' => 'No action specified. Use action=analyze_aggregate (GET) or action=analyze_text (POST).']);
