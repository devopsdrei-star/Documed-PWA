<?php
require_once '../config/db.php';
header('Content-Type: application/json');

// Ensure we have a database connection
if (!isset($pdo)) {
    jsonResponse([
        'success' => false,
        'message' => 'Database connection error'
    ]);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action === '') {
    echo json_encode(['success' => false, 'message' => 'No action']);
    exit;
}

function jsonResponse($arr) {
    echo json_encode($arr);
    exit;
}

// Ensure reschedule windows table exists
function ensureRescheduleTableExists($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reschedule_windows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appt (appointment_id),
            INDEX idx_active (active)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* ignore */ }
}

// Fallback table creator: used if legacy appointments table has incompatible schema
function ensurePwaTableExists($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS appointments_pwa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        role VARCHAR(50),
        year_course VARCHAR(100),
        department VARCHAR(100),
        purpose TEXT,
        date DATE NOT NULL,
        time TIME NOT NULL,
        status ENUM('scheduled', 'accepted', 'declined', 'completed') DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date_time (date, time)
    ) ENGINE=InnoDB";
    try {
        $pdo->exec($sql);
        // Ensure enum includes 'cancelled' and 'rescheduled' for cancel/reschedule compatibility
        try {
            $pdo->exec("ALTER TABLE appointments_pwa MODIFY status ENUM('scheduled','accepted','declined','completed','cancelled','rescheduled') DEFAULT 'scheduled'");
        } catch (Throwable $e2) { /* ignore if already altered */ }
    } catch (Throwable $e) { /* ignore */ }
}

// Try to ensure primary and fallback tables accept all needed statuses
function ensureStatusEnums($pdo) {
    try {
        // Primary table may already support free-form strings; attempt enum alter best-effort
           $pdo->exec("ALTER TABLE appointments MODIFY status ENUM('scheduled','accepted','declined','completed','cancelled','rescheduled','pending') DEFAULT 'scheduled'");
    } catch (Throwable $e) { /* ignore */ }
    try {
        ensurePwaTableExists($pdo);
           $pdo->exec("ALTER TABLE appointments_pwa MODIFY status ENUM('scheduled','accepted','declined','completed','cancelled','rescheduled','pending') DEFAULT 'scheduled'");
    } catch (Throwable $e2) { /* ignore */ }
}

// Helper: convert time like "09:00 AM" to "09:00" format
function normalizeTime($slot) {
    // Handle "09:00 AM - 10:00 AM" format
    if (preg_match('/(\d{1,2}:\d{2})\s*(AM|PM)/i', $slot, $m)) {
        return date('H:i', strtotime($m[1] . ' ' . strtoupper($m[2])));
    }
    // Handle "09:00" format
    if (preg_match('/^\d{2}:\d{2}$/', $slot)) {
        return $slot;
    }
    return null;
}

// Book new appointment
if ($action === 'add') {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $year_course = $_POST['year_course'] ?? '';
    $department = $_POST['department'] ?? '';
    $purpose = $_POST['purpose'] ?? '';
    $date = $_POST['appointment_date'] ?? '';
    $timeSlot = $_POST['time_slot'] ?? '';
    $recaptcha_token = $_POST['g-recaptcha-response'] ?? '';
    // Verify reCAPTCHA v2
    $recaptcha_secret = '6LeRSQ8sAAAAADjtMmXzP93bITGN7Z1duLdESN3A';
    $recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
    $recaptcha = file_get_contents($recaptcha_url . '?secret=' . urlencode($recaptcha_secret) . '&response=' . urlencode($recaptcha_token));
    $recaptcha = json_decode($recaptcha, true);
    if (!$recaptcha || empty($recaptcha['success'])) {
        jsonResponse([
            'success' => false,
            'message' => 'reCAPTCHA validation failed.'
        ]);
    }

    // Validate required fields
    if (!$name || !$email || !$date || !$timeSlot) {
        jsonResponse([
            'success' => false, 
            'message' => 'Please fill in all required fields (name, email, date, and time)'
        ]);
    }

    // Ensure enums include new states (pending/rescheduled)
    try { ensureStatusEnums($pdo); } catch (Throwable $e) { /* ignore */ }

    // Prevent multiple bookings when previous booking not yet accepted
    try {
        $block = 0; $statuses = [ 'scheduled','pending','rescheduled' ];
        // Primary table
        try {
            $in = implode(',', array_fill(0, count($statuses), '?'));
            $p = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE LOWER(email)=LOWER(?) AND status IN ($in)");
            $p->execute(array_merge([$email], $statuses));
            $block += (int)$p->fetchColumn();
        } catch (Throwable $e1) {}
        // Fallback table
        try {
            ensurePwaTableExists($pdo);
            $in2 = implode(',', array_fill(0, count($statuses), '?'));
            $p2 = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE LOWER(email)=LOWER(?) AND status IN ($in2)");
            $p2->execute(array_merge([$email], $statuses));
            $block += (int)$p2->fetchColumn();
        } catch (Throwable $e2) {}
        if ($block > 0) {
            jsonResponse([
                'success' => false,
                'message' => 'You already have a pending or rescheduled booking awaiting confirmation. Please wait for it to be accepted or completed before booking another.'
            ]);
        }
    } catch (Throwable $eB) { /* ignore */ }

    // Normalize time slot
    $time = normalizeTime($timeSlot);
    if (!$time) {
        jsonResponse([
            'success' => false, 
            'message' => 'Invalid time slot format'
        ]);
    }

    try {
        // Enforce daily cap (10 per day) across both tables
        $dayLimit = 10;
        $totalForDay = 0;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE date = ?");
            $stmt->execute([$date]);
            $totalForDay += (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        try {
            ensurePwaTableExists($pdo);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE date = ?");
            $stmt->execute([$date]);
            $totalForDay += (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}
        if ($totalForDay >= $dayLimit) {
            jsonResponse([
                'success' => false,
                'message' => 'This date has reached its maximum of 10 bookings. Please choose another day.'
            ]);
        }
        // Check if hour is fully booked (max 2 per hour) across both tables
        $count = 0;
        $hour = hourBucket($time);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND DATE_FORMAT(time,'%H:00') = ?");
            $stmt->execute([$date, $hour]);
            $count += (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // primary table may not exist yet
        }
        try {
            ensurePwaTableExists($pdo);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE date = ? AND DATE_FORMAT(time,'%H:00') = ?");
            $stmt->execute([$date, $hour]);
            $count += (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // fallback table may not exist yet
        }
        if ($count >= 2) {
            jsonResponse([
                'success' => false,
                'message' => 'This time slot is already fully booked. Please select another time.'
            ]);
        }

        // Try insert into primary table first
        try {
            $stmt = $pdo->prepare("
                INSERT INTO appointments (
                    name, email, role, year_course, department, purpose,
                    date, time, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $email, $role, $year_course, $department, $purpose,
                $date, $time, 'scheduled'
            ]);
            jsonResponse([
                'success' => true,
                'message' => 'Your appointment has been booked successfully!'
            ]);
        } catch (Throwable $primaryInsertError) {
            // Fall back to appointments_pwa
            try {
                ensurePwaTableExists($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO appointments_pwa (
                        name, email, role, year_course, department, purpose,
                        date, time, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name, $email, $role, $year_course, $department, $purpose,
                    $date, $time, 'scheduled'
                ]);
                jsonResponse([
                    'success' => true,
                    'message' => 'Your appointment has been booked successfully!'
                ]);
            } catch (Throwable $fallbackInsertError) {
                error_log('Booking error (primary+fallback failed): ' . $primaryInsertError->getMessage() . ' | ' . $fallbackInsertError->getMessage());
                jsonResponse([
                    'success' => false,
                    'message' => 'There was a problem booking your appointment. Please try again.'
                ]);
            }
        }

    } catch (Throwable $e) {
        error_log('Booking error: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'There was a problem booking your appointment. Please try again.'
        ]);
    }
}

// Check slot availability for a date (returns booked slots map)
if ($action === 'check_slots') {
    $date = $_POST['date'] ?? ($_GET['date'] ?? '');
    if (!$date) {
        jsonResponse(['success' => false, 'message' => 'Please select a date']);
    }
    try {
        $counts = [];
        $dayTotal = 0; $dayLimit = 10;
        // Primary table (group by hour)
        try {
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(time,'%H:00') AS hour, COUNT(*) as cnt FROM appointments WHERE date = ? GROUP BY DATE_FORMAT(time,'%H:00')");
            $stmt->execute([$date]);
            while ($row = $stmt->fetch()) {
                $h = substr($row['hour'], 0, 5);
                $counts[$h] = ($counts[$h] ?? 0) + (int)$row['cnt'];
                $counts[substr($h,0,2).':30'] = ($counts[substr($h,0,2).':30'] ?? 0) + (int)$row['cnt'];
            }
            // day total from primary
            try { $s = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE date = ?"); $s->execute([$date]); $dayTotal += (int)$s->fetchColumn(); } catch (Throwable $eX) {}
        } catch (Throwable $e1) { /* ignore */ }
        // Fallback table
        try {
            ensurePwaTableExists($pdo);
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(time,'%H:00') AS hour, COUNT(*) as cnt FROM appointments_pwa WHERE date = ? GROUP BY DATE_FORMAT(time,'%H:00')");
            $stmt->execute([$date]);
            while ($row = $stmt->fetch()) {
                $h = substr($row['hour'], 0, 5);
                $counts[$h] = ($counts[$h] ?? 0) + (int)$row['cnt'];
                $counts[substr($h,0,2).':30'] = ($counts[substr($h,0,2).':30'] ?? 0) + (int)$row['cnt'];
            }
            // day total from fallback
            try { $s2 = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE date = ?"); $s2->execute([$date]); $dayTotal += (int)$s2->fetchColumn(); } catch (Throwable $eY) {}
        } catch (Throwable $e2) { /* ignore */ }

        jsonResponse(['success' => true, 'booked_slots' => $counts, 'day_total' => $dayTotal, 'day_limit' => $dayLimit]);

    } catch (PDOException $e) {
        error_log('Error checking slots: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Error checking slot availability'
        ]);
    }
}

// Check availability for a specific date/time (optional exclude_id)
if ($action === 'check_availability') {
    $date = $_POST['date'] ?? ($_GET['date'] ?? '');
    $timeSlot = $_POST['time'] ?? ($_GET['time'] ?? '');
    $excludeId = $_POST['exclude_id'] ?? ($_GET['exclude_id'] ?? '');
    if (!$date || !$timeSlot) {
        jsonResponse(['success' => false, 'message' => 'Missing date/time']);
    }
    $time = normalizeTime($timeSlot) ?: $timeSlot;
    $cnt = countBookingsHour($pdo, $date, hourBucket($time), $excludeId ?: null);
    $limit = 2;
    jsonResponse(['success' => true, 'available' => ($cnt < $limit), 'booked_count' => $cnt, 'limit' => $limit]);
}

// List appointments (for doc/nurse view)
if ($action === 'list') {
    try {
        // Try union of both tables; fallback to primary only on error
        $appointments = [];
        try {
            // Attempt UNION ALL if fallback exists
            ensurePwaTableExists($pdo);
            $sql = "
                SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments
                UNION ALL
                SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments_pwa
                ORDER BY date DESC, time ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e1) {
            // Fallback: only primary table
            $stmt = $pdo->prepare("
                SELECT id, name, email, role, year_course, department,
                       purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments
                ORDER BY date DESC, time ASC
            ");
            $stmt->execute();
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        jsonResponse([
            'success' => true,
            'appointments' => $appointments
        ]);

    } catch (PDOException $e) {
        error_log('Error listing appointments: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Error loading appointments'
        ]);
    }
}

// List user-specific appointments (enhanced: supports email OR user_id, case-insensitive, multiple candidates)
if ($action === 'my_appointments') {
    $rawEmail = trim($_POST['email'] ?? ($_GET['email'] ?? ''));
    $userId = trim($_POST['user_id'] ?? ($_GET['user_id'] ?? ''));
    $studentFacultyId = trim($_POST['student_faculty_id'] ?? ($_GET['student_faculty_id'] ?? ''));

    $candidateEmails = [];
    if ($rawEmail !== '') {
        $candidateEmails[] = strtolower($rawEmail);
    }
    // If userId or student_faculty_id provided, look up email(s)
    try {
        if ($userId !== '') {
            $u = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
            $u->execute([$userId]);
            if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
                $e = strtolower(trim($row['email']));
                if ($e !== '' && !in_array($e, $candidateEmails, true)) $candidateEmails[] = $e;
            }
        }
        if ($studentFacultyId !== '') {
            $u2 = $pdo->prepare("SELECT email FROM users WHERE student_faculty_id = ? LIMIT 1");
            $u2->execute([$studentFacultyId]);
            if ($row2 = $u2->fetch(PDO::FETCH_ASSOC)) {
                $e2 = strtolower(trim($row2['email']));
                if ($e2 !== '' && !in_array($e2, $candidateEmails, true)) $candidateEmails[] = $e2;
            }
        }
    } catch (Throwable $ignoreLookup) { /* ignore lookup errors */ }

    if (empty($candidateEmails)) {
        jsonResponse(['success'=>false,'message'=>'Missing user identifier (email or user_id)','appointments'=>[]]);
    }

    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($candidateEmails), '?'));

    try {
        $appointments = [];
        try {
            ensurePwaTableExists($pdo);
            // Use LOWER(email) IN (...) for case-insensitive match; also return original email
            $sql = "
                SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments WHERE LOWER(email) IN ($placeholders)
                UNION ALL
                SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments_pwa WHERE LOWER(email) IN ($placeholders)
                ORDER BY date DESC, time ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($candidateEmails, $candidateEmails));
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e1) {
            $sql = "SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at FROM appointments WHERE LOWER(email) IN ($placeholders) ORDER BY date DESC, time ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($candidateEmails);
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // De-duplicate appointments by (id,email,date,time) in case of overlapping entries
        $unique = [];
        $deduped = [];
        foreach ($appointments as $a) {
            $key = ($a['id'] ?? '') . '|' . ($a['email'] ?? '') . '|' . ($a['date'] ?? '') . '|' . ($a['time'] ?? '');
            if (!isset($unique[$key])) {
                $unique[$key] = true;
                $deduped[] = $a;
            }
        }

        jsonResponse([
            'success'=>true,
            'appointments'=>$deduped,
            'emails_used'=>$candidateEmails,
            'count'=>count($deduped)
        ]);
    } catch (Throwable $e) {
        error_log('my_appointments error: '.$e->getMessage());
        jsonResponse(['success'=>false,'message'=>'Error loading user appointments']);
    }
}

// Update appointment status (for doc/nurse actions)
if ($action === 'update_status') {
    $id = $_POST['id'] ?? '';
    $status = $_POST['status'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    
    if (!$id || !in_array($status, ['accepted', 'declined', 'completed'])) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid appointment ID or status'
        ]);
    }

    try {
        ensureStatusEnums($pdo);
        // Try update primary; if no rows affected, try fallback table
        $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) {
            try {
                ensurePwaTableExists($pdo);
                $stmt2 = $pdo->prepare("UPDATE appointments_pwa SET status = ? WHERE id = ?");
                $stmt2->execute([$status, $id]);
            } catch (Throwable $e2) { /* ignore */ }
        }

        // Record to audit_trail only if called by an admin (admin_id provided)
        $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
        if ($admin_id > 0) {
            if (in_array($status, ['declined'])) {
                try {
                    $a = $pdo->prepare('INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)');
                    $a->execute([$admin_id, 'Declined appointment', 'Appt ID: '.$id.' | Reason: '.($reason ?: 'n/a')]);
                } catch (Throwable $e3) {}
            }
            if ($status === 'accepted') {
                try {
                    $a = $pdo->prepare('INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)');
                    $a->execute([$admin_id, 'Accepted appointment', 'Appt ID: '.$id]);
                } catch (Throwable $e4) {}
            }
        }

        jsonResponse([
            'success' => true,
            'message' => 'Appointment status updated successfully'
        ]);

    } catch (PDOException $e) {
        error_log('Error updating status: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Error updating appointment status'
        ]);
    }
}

// Admin: reschedule appointment (change date/time)
if ($action === 'admin_reschedule') {
    $id = $_POST['id'] ?? '';
    $date = $_POST['date'] ?? '';
    $timeSlot = $_POST['time'] ?? '';
    if (!$id || !$date || !$timeSlot) {
        jsonResponse(['success'=>false,'message'=>'Missing id/date/time']);
    }
    $time = normalizeTime($timeSlot) ?: $timeSlot;
    try {
        ensureStatusEnums($pdo);
        $updated = 0;
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET date=?, time=? WHERE id=?");
            $stmt->execute([$date, $time, $id]);
            $updated = $stmt->rowCount();
        } catch (Throwable $e1) { /* ignore */ }
        // Enforce availability limit per hour (exclude current id)
        $cnt = countBookingsHour($pdo, $date, hourBucket($time), $id);
        if ($cnt >= 2) {
            jsonResponse(['success' => false, 'message' => 'This time slot is already fully booked. Please select another time.']);
        }
        if ($updated === 0) {
            try {
                ensurePwaTableExists($pdo);
                $stmt = $pdo->prepare("UPDATE appointments_pwa SET date=?, time=? WHERE id=?");
                $stmt->execute([$date, $time, $id]);
            } catch (Throwable $e2) { /* ignore */ }
        }
        // Deactivate existing windows then create a 1-day active window for the chosen date
        try { ensureRescheduleTableExists($pdo); $pdo->prepare('UPDATE reschedule_windows SET active=0 WHERE appointment_id = ? AND active = 1')->execute([$id]); } catch (Throwable $eW0) {}
        try { ensureRescheduleTableExists($pdo); $pdo->prepare('INSERT INTO reschedule_windows (appointment_id,start_date,end_date,active) VALUES (?,?,?,1)')->execute([$id, $date, $date]); } catch (Throwable $eW1) {}
        // Set status to 'pending' until user selects a new time
        try { $pdo->prepare("UPDATE appointments SET status='pending' WHERE id=?")->execute([$id]); } catch (Throwable $eS1) {}
        try { ensurePwaTableExists($pdo); $pdo->prepare("UPDATE appointments_pwa SET status='pending' WHERE id=?")->execute([$id]); } catch (Throwable $eS2) {}
        // Audit (admin only)
        try {
            $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
            if ($admin_id > 0) {
                $a = $pdo->prepare('INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)');
                $a->execute([$admin_id, 'Proposed reschedule window', 'Appt ID: '.$id.' | Range: '.$date.' to '.$date]);
            }
        } catch (Throwable $e3) {}
        jsonResponse(['success'=>true]);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Reschedule failed']);
    }
}

// Admin/Doctor: propose a 3-day reschedule window for a user to choose
if ($action === 'propose_reschedule_window') {
    $id = $_POST['id'] ?? '';
    $start = $_POST['start_date'] ?? '';
    if (!$id || !$start) { jsonResponse(['success'=>false,'message'=>'Missing id/start_date']); }
    try {
        ensureRescheduleTableExists($pdo);
        // compute end_date = start + 2 days
        $sd = date_create($start);
        if (!$sd) jsonResponse(['success'=>false,'message'=>'Invalid start_date']);
        $ed = clone $sd; $ed->modify('+2 days');
        $start_date = $sd->format('Y-m-d');
        $end_date = $ed->format('Y-m-d');
        // deactivate existing active windows for this appt
        try {
            $d = $pdo->prepare('UPDATE reschedule_windows SET active=0 WHERE appointment_id = ? AND active = 1');
            $d->execute([$id]);
        } catch (Throwable $e1) {}
    // insert new active window
        $ins = $pdo->prepare('INSERT INTO reschedule_windows (appointment_id,start_date,end_date,active) VALUES (?,?,?,1)');
        $ins->execute([$id, $start_date, $end_date]);
    // mark appointment as pending while waiting for user selection
    try { $pdo->prepare("UPDATE appointments SET status='pending' WHERE id=?")->execute([$id]); } catch (Throwable $eP1) {}
    try { ensurePwaTableExists($pdo); $pdo->prepare("UPDATE appointments_pwa SET status='pending' WHERE id=?")->execute([$id]); } catch (Throwable $eP2) {}
        // audit log (admin only)
        try {
            $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
            if ($admin_id > 0) {
                $a = $pdo->prepare('INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)');
                $a->execute([$admin_id, 'Proposed reschedule window', 'Appt ID: '.$id.' | Range: '.$start_date.' to '.$end_date]);
            }
        } catch (Throwable $e2) {}
        jsonResponse(['success'=>true, 'start_date'=>$start_date, 'end_date'=>$end_date]);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Failed to propose window']);
    }
}

// User: get active reschedule windows (by appt id or by email owner)
if ($action === 'get_reschedule_windows') {
    $email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));
    $apptId = trim($_GET['id'] ?? ($_POST['id'] ?? ''));
    try {
        ensureRescheduleTableExists($pdo);
        $rows = [];
        if ($apptId !== '') {
            $stmt = $pdo->prepare('SELECT w.id, w.appointment_id, w.start_date, w.end_date, w.active FROM reschedule_windows w WHERE w.appointment_id = ? AND w.active = 1');
            $stmt->execute([$apptId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } elseif ($email !== '') {
            // join by user's appointments
            $rows = [];
            // primary table
            $q1 = $pdo->prepare('SELECT a.id, a.purpose FROM appointments a WHERE LOWER(a.email)=LOWER(?)');
            $q1->execute([$email]);
            $apps1 = $q1->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ids = array_map(fn($r) => (int)$r['id'], $apps1);
            try {
                ensurePwaTableExists($pdo);
                $q2 = $pdo->prepare('SELECT p.id, p.purpose FROM appointments_pwa p WHERE LOWER(p.email)=LOWER(?)');
                $q2->execute([$email]);
                $apps2 = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($apps2 as $r) { $ids[] = (int)$r['id']; $apps1[] = $r; }
            } catch (Throwable $e2) {}
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT w.id, w.appointment_id, w.start_date, w.end_date, w.active FROM reschedule_windows w WHERE w.active = 1 AND w.appointment_id IN ($ph)");
                $st->execute($ids);
                $wins = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                // attach purpose
                $purposeById = [];
                foreach ($apps1 as $r) { $purposeById[(int)$r['id']] = $r['purpose'] ?? 'Appointment'; }
                foreach ($wins as &$w) { $w['purpose'] = $purposeById[(int)$w['appointment_id']] ?? 'Appointment'; }
                $rows = $wins;
            }
        }
        jsonResponse(['success'=>true, 'windows'=>$rows]);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Failed to load windows','windows'=>[]]);
    }
}

// User: select a new slot within window
if ($action === 'user_select_reschedule') {
    $id = $_POST['id'] ?? '';
    $date = $_POST['date'] ?? '';
    $timeSlot = $_POST['time'] ?? '';
    if (!$id || !$date || !$timeSlot) { jsonResponse(['success'=>false,'message'=>'Missing id/date/time']); }
    try {
        ensureRescheduleTableExists($pdo);
        ensureStatusEnums($pdo);
        // verify active window exists and date within [start,end]
        $w = $pdo->prepare('SELECT start_date,end_date FROM reschedule_windows WHERE appointment_id = ? AND active = 1 ORDER BY id DESC LIMIT 1');
        $w->execute([$id]);
        $win = $w->fetch(PDO::FETCH_ASSOC);
        if (!$win) { jsonResponse(['success'=>false,'message'=>'No active reschedule window']); }
        if ($date < $win['start_date'] || $date > $win['end_date']) {
            jsonResponse(['success'=>false,'message'=>'Date not within allowed range']);
        }
        $time = normalizeTime($timeSlot) ?: $timeSlot;
        // enforce 2/hour limit
        $cnt = countBookingsHour($pdo, $date, hourBucket($time), $id);
        if ($cnt >= 2) { jsonResponse(['success'=>false,'message'=>'Selected hour is full. Pick another time.']); }
        // perform update on either table
        $updated = 0;
        try {
            $stmt = $pdo->prepare('UPDATE appointments SET date=?, time=? WHERE id=?');
            $stmt->execute([$date, $time, $id]);
            $updated = $stmt->rowCount();
        } catch (Throwable $e1) { /* ignore */ }
        if ($updated === 0) {
            try {
                ensurePwaTableExists($pdo);
                $stmt = $pdo->prepare('UPDATE appointments_pwa SET date=?, time=? WHERE id=?');
                $stmt->execute([$date, $time, $id]);
            } catch (Throwable $e2) { /* ignore */ }
        }
        // deactivate window
        try { $pdo->prepare('UPDATE reschedule_windows SET active=0 WHERE appointment_id=?')->execute([$id]); } catch (Throwable $e3) {}
        // set status to rescheduled
        try { $pdo->prepare("UPDATE appointments SET status='rescheduled' WHERE id=?")->execute([$id]); } catch (Throwable $eS1) {}
        try { ensurePwaTableExists($pdo); $pdo->prepare("UPDATE appointments_pwa SET status='rescheduled' WHERE id=?")->execute([$id]); } catch (Throwable $eS2) {}
        // audit log (admin only) - users changing schedule shouldn't appear in admin activity log
        try {
            $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
            if ($admin_id > 0) {
                $a = $pdo->prepare('INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)');
                $a->execute([$admin_id, 'Rescheduled appointment', 'Appt ID: '.$id.' => '.$date.' '.$time]);
            }
        } catch (Throwable $e4) {}
        jsonResponse(['success'=>true]);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Failed to apply selection']);
    }
}

// Admin/Doctor: cancel a pending reschedule request (clear active window and revert status)
if ($action === 'cancel_reschedule_request') {
    $id = $_POST['id'] ?? '';
    if (!$id) { jsonResponse(['success'=>false,'message'=>'Missing id']); }
    try {
        ensureRescheduleTableExists($pdo);
        // Deactivate any active windows for this appointment
        try { $pdo->prepare('UPDATE reschedule_windows SET active=0 WHERE appointment_id=?')->execute([$id]); } catch (Throwable $e1) {}
        // Revert status: prefer 'accepted' if it was accepted before; fallback to 'scheduled'
        // We can't easily know prior state here; default to 'accepted' to preserve flow
        try { $pdo->prepare("UPDATE appointments SET status='accepted' WHERE id=?")->execute([$id]); } catch (Throwable $e2) {}
        try { ensurePwaTableExists($pdo); $pdo->prepare("UPDATE appointments_pwa SET status='accepted' WHERE id=?")->execute([$id]); } catch (Throwable $e3) {}
        // Audit (admin only)
        try {
            $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
            if ($admin_id > 0) {
                $a = $pdo->prepare('INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)');
                $a->execute([$admin_id, 'Cancelled reschedule request', 'Appt ID: '.$id]);
            }
        } catch (Throwable $e4) {}
        jsonResponse(['success'=>true]);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Failed to cancel reschedule request']);
    }
}

// Admin: cancel appointment
if ($action === 'admin_cancel') {
    $id = $_POST['id'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if (!$id) { jsonResponse(['success'=>false,'message'=>'Missing id']); }
    try {
        $updated = 0;
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status='cancelled' WHERE id=?");
            $stmt->execute([$id]);
            $updated = $stmt->rowCount();
        } catch (Throwable $e1) { /* ignore */ }
        if ($updated === 0) {
            try {
                ensurePwaTableExists($pdo);
                $stmt = $pdo->prepare("UPDATE appointments_pwa SET status='cancelled' WHERE id=?");
                $stmt->execute([$id]);
            } catch (Throwable $e2) { /* ignore */ }
        }
        // Audit (admin only)
        try {
            $admin_id = intval($_POST['admin_id'] ?? ($_GET['admin_id'] ?? 0));
            if ($admin_id > 0) {
                $a = $pdo->prepare('INSERT INTO audit_trail (admin_id, action, details) VALUES (?, ?, ?)');
                $a->execute([$admin_id, 'Cancelled appointment', 'Appt ID: '.$id.' | Reason: '.($reason ?: 'n/a')]);
            }
        } catch (Throwable $e3) {}
        jsonResponse(['success'=>true]);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Cancel failed']);
    }
}

// Fetch notifications (appointments statuses + announcements)
if ($action === 'notifications') {
    $email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));
    $limit = intval($_GET['limit'] ?? 20);
    if ($email === '') { jsonResponse(['success'=>false,'message'=>'Missing email']); }
    $out = ['appointments'=>[], 'announcements'=>[], 'events'=>[]];
    try {
        // Appointments for this email from both tables
        try {
            ensurePwaTableExists($pdo);
            $sql = "
                SELECT id, purpose, date, DATE_FORMAT(time,'%H:%i') AS time, status, created_at
                FROM appointments WHERE LOWER(email)=LOWER(?)
                UNION ALL
                SELECT id, purpose, date, DATE_FORMAT(time,'%H:%i') AS time, status, created_at
                FROM appointments_pwa WHERE LOWER(email)=LOWER(?)
                ORDER BY created_at DESC
                LIMIT ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $email);
            $stmt->bindValue(2, $email);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $out['appointments'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("SELECT id, purpose, date, DATE_FORMAT(time,'%H:%i') AS time, status, created_at FROM appointments WHERE LOWER(email)=LOWER(?) ORDER BY created_at DESC LIMIT ?");
            $stmt->bindValue(1, $email);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $out['appointments'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        // Announcements (latest)
        try {
            $stmt = $pdo->prepare('SELECT id,message,audience,created_at FROM announcements ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $out['announcements'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $out['announcements'] = []; }

        // Reschedule / Cancel / Decline / Accepted events from audit_trail (if table exists)
        try {
            $events = [];
            // Window proposed
            try {
                ensureRescheduleTableExists($pdo);
                $s = $pdo->prepare("SELECT id, action, details, timestamp FROM audit_trail WHERE action LIKE 'Proposed reschedule window%' ORDER BY id DESC LIMIT ?");
                $s->bindValue(1, $limit, PDO::PARAM_INT);
                $s->execute();
                $logs0 = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($logs0 as $log) {
                    if (!preg_match('/Appt ID:\s*(\d+)/', $log['details'] ?? '', $m)) continue;
                    $aid = (int)$m[1];
                    // check belongs to email
                    $belongs = false; $purpose='Appointment';
                    try { $s1 = $pdo->prepare('SELECT email, purpose FROM appointments WHERE id=?'); $s1->execute([$aid]); if ($r1=$s1->fetch(PDO::FETCH_ASSOC)) { if (strcasecmp($r1['email']??'', $email)===0) { $belongs=true; $purpose=$r1['purpose']??$purpose; } } } catch (Throwable $e1) {}
                    if (!$belongs) { try { ensurePwaTableExists($pdo); $s2=$pdo->prepare('SELECT email, purpose FROM appointments_pwa WHERE id=?'); $s2->execute([$aid]); if ($r2=$s2->fetch(PDO::FETCH_ASSOC)) { if (strcasecmp($r2['email']??'', $email)===0) { $belongs=true; $purpose=$r2['purpose']??$purpose; } } } catch (Throwable $e2) {} }
                    if ($belongs) {
                        // parse range from details
                        $range = null;
                        if (preg_match('/Range:\s*([\d-]{8,})\s*to\s*([\d-]{8,})/', $log['details'], $mr)) { $range = [$mr[1], $mr[2]]; }
                        $events[] = [ 'type'=>'reschedule_window', 'appointment_id'=>$aid, 'purpose'=>$purpose, 'range'=>$range, 'created_at'=>$log['timestamp'] ?? null ];
                    }
                }
            } catch (Throwable $e0) {}
            // Rescheduled
            $stmt = $pdo->prepare("SELECT id, action, details, timestamp FROM audit_trail WHERE action LIKE 'Rescheduled appointment%' ORDER BY id DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($logs as $log) {
                if (!isset($log['details'])) continue;
                if (!preg_match('/Appt ID:\s*(\d+)/', $log['details'], $m)) continue;
                $aid = (int)$m[1];
                $belongs = false; $date=null; $time=null; $purpose='Appointment';
                try {
                    $s1 = $pdo->prepare('SELECT email, purpose, date, time FROM appointments WHERE id = ?');
                    $s1->execute([$aid]);
                    if ($r1 = $s1->fetch(PDO::FETCH_ASSOC)) {
                        if (strcasecmp($r1['email'] ?? '', $email) === 0) { $belongs = true; $purpose = $r1['purpose'] ?? $purpose; $date = $r1['date'] ?? null; $time = $r1['time'] ?? null; }
                    }
                } catch (Throwable $e1) {}
                if (!$belongs) {
                    try {
                        ensurePwaTableExists($pdo);
                        $s2 = $pdo->prepare('SELECT email, purpose, date, time FROM appointments_pwa WHERE id = ?');
                        $s2->execute([$aid]);
                        if ($r2 = $s2->fetch(PDO::FETCH_ASSOC)) {
                            if (strcasecmp($r2['email'] ?? '', $email) === 0) { $belongs = true; $purpose = $r2['purpose'] ?? $purpose; $date = $r2['date'] ?? null; $time = $r2['time'] ?? null; }
                        }
                    } catch (Throwable $e2) {}
                }
                if ($belongs) {
                    $newWhen = null;
                    if (preg_match('/=>\s*([\d-]{8,}\s+[\d:]{4,})/', $log['details'], $m2)) { $newWhen = $m2[1]; }
                    $events[] = [
                        'type' => 'rescheduled',
                        'appointment_id' => $aid,
                        'purpose' => $purpose,
                        'old_date' => $date,
                        'old_time' => is_string($time) ? substr($time,0,5) : $time,
                        'new_when' => $newWhen,
                        'created_at' => $log['timestamp'] ?? null
                    ];
                }
            }
            // Cancelled / Declined
            $stmt2 = $pdo->prepare("SELECT id, action, details, timestamp FROM audit_trail WHERE (action LIKE 'Cancelled appointment%' OR action LIKE 'Declined appointment%') ORDER BY id DESC LIMIT ?");
            $stmt2->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt2->execute();
            $logs2 = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($logs2 as $log) {
                if (!isset($log['details'])) continue;
                if (!preg_match('/Appt ID:\s*(\d+)/', $log['details'], $m)) continue;
                $aid = (int)$m[1];
                $belongs = false; $purpose='Appointment'; $date=null; $time=null;
                try {
                    $s1 = $pdo->prepare('SELECT email, purpose, date, time FROM appointments WHERE id = ?');
                    $s1->execute([$aid]);
                    if ($r1 = $s1->fetch(PDO::FETCH_ASSOC)) {
                        if (strcasecmp($r1['email'] ?? '', $email) === 0) { $belongs = true; $purpose = $r1['purpose'] ?? $purpose; $date = $r1['date'] ?? null; $time = $r1['time'] ?? null; }
                    }
                } catch (Throwable $e1) {}
                if (!$belongs) {
                    try {
                        ensurePwaTableExists($pdo);
                        $s2 = $pdo->prepare('SELECT email, purpose, date, time FROM appointments_pwa WHERE id = ?');
                        $s2->execute([$aid]);
                        if ($r2 = $s2->fetch(PDO::FETCH_ASSOC)) {
                            if (strcasecmp($r2['email'] ?? '', $email) === 0) { $belongs = true; $purpose = $r2['purpose'] ?? $purpose; $date = $r2['date'] ?? null; $time = $r2['time'] ?? null; }
                        }
                    } catch (Throwable $e2) {}
                }
                if ($belongs) {
                    $reason = null;
                    if (preg_match('/Reason:\s*(.*)$/', $log['details'], $mr)) { $reason = trim($mr[1]); }
                    $events[] = [
                        'type' => (stripos($log['action'],'Declined')!==false?'declined':'cancelled'),
                        'appointment_id' => $aid,
                        'purpose' => $purpose,
                        'when' => [ $date, is_string($time)? substr($time,0,5):$time ],
                        'reason' => $reason,
                        'created_at' => $log['timestamp'] ?? null
                    ];
                }
            }
            // Accepted
            try {
                $stmt3 = $pdo->prepare("SELECT id, action, details, timestamp FROM audit_trail WHERE action LIKE 'Accepted appointment%' ORDER BY id DESC LIMIT ?");
                $stmt3->bindValue(1, $limit, PDO::PARAM_INT);
                $stmt3->execute();
                $logs3 = $stmt3->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($logs3 as $log) {
                    if (!isset($log['details'])) continue;
                    if (!preg_match('/Appt ID:\s*(\d+)/', $log['details'], $m)) continue;
                    $aid = (int)$m[1];
                    $belongs = false; $purpose='Appointment'; $date=null; $time=null;
                    try {
                        $s1 = $pdo->prepare('SELECT email, purpose, date, time FROM appointments WHERE id = ?');
                        $s1->execute([$aid]);
                        if ($r1 = $s1->fetch(PDO::FETCH_ASSOC)) {
                            if (strcasecmp($r1['email'] ?? '', $email) === 0) { $belongs = true; $purpose = $r1['purpose'] ?? $purpose; $date = $r1['date'] ?? null; $time = $r1['time'] ?? null; }
                        }
                    } catch (Throwable $e1) {}
                    if (!$belongs) {
                        try {
                            ensurePwaTableExists($pdo);
                            $s2 = $pdo->prepare('SELECT email, purpose, date, time FROM appointments_pwa WHERE id = ?');
                            $s2->execute([$aid]);
                            if ($r2 = $s2->fetch(PDO::FETCH_ASSOC)) {
                                if (strcasecmp($r2['email'] ?? '', $email) === 0) { $belongs = true; $purpose = $r2['purpose'] ?? $purpose; $date = $r2['date'] ?? null; $time = $r2['time'] ?? null; }
                            }
                        } catch (Throwable $e2) {}
                    }
                    if ($belongs) {
                        $events[] = [
                            'type' => 'accepted',
                            'appointment_id' => $aid,
                            'purpose' => $purpose,
                            'when' => [ $date, is_string($time)? substr($time,0,5):$time ],
                            'created_at' => $log['timestamp'] ?? null
                        ];
                    }
                }
            } catch (Throwable $eAcc) { /* ignore */ }
            $out['events'] = $events;
        } catch (Throwable $e) { /* ignore if audit_trail missing */ }
        jsonResponse(['success'=>true] + $out);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Failed to load notifications']);
    }
}

// Count bookings across both tables for a date/time; optionally exclude an appointment id
function countBookings($pdo, $date, $time, $excludeId = null) {
    $count = 0;
    try {
        if ($excludeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND time = ? AND id <> ?");
            $stmt->execute([$date, $time, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND time = ?");
            $stmt->execute([$date, $time]);
        }
        $count += (int)$stmt->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        ensurePwaTableExists($pdo);
        if ($excludeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE date = ? AND time = ? AND id <> ?");
            $stmt->execute([$date, $time, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE date = ? AND time = ?");
            $stmt->execute([$date, $time]);
        }
        $count += (int)$stmt->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    return $count;
}

// List only today's appointments (by scheduled date = CURDATE())
if ($action === 'list_today') {
    try {
        $appointments = [];
        try {
            // Try union across primary and fallback tables
            ensurePwaTableExists($pdo);
            $sql = "
                SELECT id, name, email, role, year_course, department,
                       purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments
                WHERE date = CURDATE()
                UNION ALL
                SELECT id, name, email, role, year_course, department,
                       purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments_pwa
                WHERE date = CURDATE()
                ORDER BY date DESC, time ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e1) {
            // Fallback to primary only
            $stmt = $pdo->prepare("
                SELECT id, name, email, role, year_course, department,
                       purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments
                WHERE date = CURDATE()
                ORDER BY date DESC, time ASC
            ");
            $stmt->execute();
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        jsonResponse([
            'success' => true,
            'appointments' => $appointments
        ]);

    } catch (Throwable $e) {
        error_log('Error listing today appointments: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'Error loading today\'s appointments'
        ]);
    }
}

// Helper: truncate time to hour bucket (e.g., 09:30 -> 09:00)
function hourBucket($time) {
    if (!$time) return $time;
    try {
        $dt = date_create_from_format('H:i', substr($time, 0, 5));
        return $dt ? $dt->format('H:00') : substr($time, 0, 2) . ':00';
    } catch (Throwable $e) {
        return substr($time, 0, 2) . ':00';
    }
}

// Count bookings across both tables for a date/hour bucket; optionally exclude an appointment id
function countBookingsHour($pdo, $date, $hour, $excludeId = null) {
    $count = 0;
    try {
        if ($excludeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND DATE_FORMAT(time,'%H:00') = ? AND id <> ?");
            $stmt->execute([$date, $hour, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE date = ? AND DATE_FORMAT(time,'%H:00') = ?");
            $stmt->execute([$date, $hour]);
        }
        $count += (int)$stmt->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        ensurePwaTableExists($pdo);
        if ($excludeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE date = ? AND DATE_FORMAT(time,'%H:00') = ? AND id <> ?");
            $stmt->execute([$date, $hour, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_pwa WHERE date = ? AND DATE_FORMAT(time,'%H:00') = ?");
            $stmt->execute([$date, $hour]);
        }
        $count += (int)$stmt->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    return $count;
}

// Unified notifications feed for user bell
if ($action === 'notifications') {
    $email = strtolower(trim($_GET['email'] ?? ($_POST['email'] ?? '')));
    $limit = intval($_GET['limit'] ?? 20);
    if ($email === '') { jsonResponse(['success'=>false,'message'=>'Missing email']); }
    try {
        // Load latest appointments for this email (primary + fallback)
        $appointments = [];
        try {
            ensurePwaTableExists($pdo);
            $sql = "
                SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments WHERE LOWER(email)=LOWER(?)
                UNION ALL
                SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at
                FROM appointments_pwa WHERE LOWER(email)=LOWER(?)
                ORDER BY created_at DESC
                LIMIT ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $email);
            $stmt->bindValue(2, $email);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e1) {
            $stmt = $pdo->prepare("SELECT id, name, email, role, year_course, department, purpose, date, DATE_FORMAT(time, '%H:%i') AS time, status, created_at FROM appointments WHERE LOWER(email)=LOWER(?) ORDER BY created_at DESC LIMIT ?");
            $stmt->bindValue(1, $email);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        // Load announcements (non-expired)
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS announcements ( id INT AUTO_INCREMENT PRIMARY KEY, message TEXT NOT NULL, audience VARCHAR(50) DEFAULT 'All', expires_at DATE NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB"); } catch (Throwable $e0) {}
        $a = $pdo->prepare('SELECT id, message, audience, expires_at, created_at FROM announcements WHERE audience IN (\'All\') AND (expires_at IS NULL OR expires_at >= CURDATE()) ORDER BY id DESC LIMIT ?');
        $a->bindValue(1, $limit, PDO::PARAM_INT);
        $a->execute();
        $announcements = $a->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Events placeholder: in a fuller impl, push reschedule/cancel events etc.; keep empty here
        $events = [];
        jsonResponse(['success'=>true,'appointments'=>$appointments,'announcements'=>$announcements,'events'=>$events]);
    } catch (Throwable $e) {
        jsonResponse(['success'=>false,'message'=>'Failed to load notifications','appointments'=>[],'announcements'=>[],'events'=>[]]);
    }
}