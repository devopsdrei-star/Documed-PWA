<?php
// Simple importer: reads a CSV and inserts rows into `checkups`.
// Usage: php import_checkups.php /path/to/checkups.csv

ini_set('display_errors', 1);
error_reporting(E_ALL);

$csvPath = $argv[1] ?? '';
if (!$csvPath) {
    echo "Usage: php import_checkups.php /full/path/to/checkups.csv\n";
    exit(1);
}

if (!is_readable($csvPath)) {
    echo "CSV file not readable: $csvPath\n";
    exit(1);
}

require_once __DIR__ . '/../config/db.php';

$fh = fopen($csvPath, 'r');
if (!$fh) {
    echo "Failed to open CSV file.\n";
    exit(1);
}

// Read header
$header = fgetcsv($fh);
if ($header === false) {
    echo "Empty CSV or invalid format.\n";
    exit(1);
}

// Normalize header names (trim and lowercase)
foreach ($header as &$h) { $h = trim($h); }

// Columns we will attempt to insert (map CSV header to DB columns)
$allowed = [
    'student_faculty_id','client_type','exam_category','name','age','address','civil_status','nationality','religion','date_of_birth','place_of_birth','year_and_course','department','contact_person','contact_number','history_past_illness','present_illness','operations_hospitalizations','immunization_history','social_environmental_history','ob_gyne_history','physical_exam_general_survey','physical_exam_skin','physical_exam_heart','physical_exam_chest_lungs','physical_exam_abdomen','physical_exam_genitourinary','physical_exam_musculoskeletal','neurological_exam','laboratory_results','assessment','remarks','photo','doctor_nurse','doc_nurse_id','follow_up','created_at','archived','follow_up_date'
];

// Map header index to column name if allowed
$map = [];
foreach ($header as $i => $col) {
    // strip quotes around header if present
    $colClean = trim($col, "\"' ");
    $low = $colClean;
    if (in_array($low, $allowed)) $map[$i] = $low;
}

$inserted = 0; $errors = [];

// Prepare dynamic insert statement
$cols = $allowed; // we'll build dynamic based on each row (to allow missing columns)

try {
    $pdo->beginTransaction();
    while (($row = fgetcsv($fh)) !== false) {
        $data = [];
        $placeholders = [];
        foreach ($map as $i => $col) {
            $val = array_key_exists($i, $row) ? trim($row[$i]) : null;
            // treat literal NULL (string) as null
            if ($val === '' || strtoupper($val) === 'NULL') $val = null;
            // Cast integer-like fields
            if (in_array($col, ['age','physical_exam_general_survey','physical_exam_skin','physical_exam_heart','physical_exam_chest_lungs','physical_exam_abdomen','physical_exam_genitourinary','physical_exam_musculoskeletal','doc_nurse_id','follow_up','archived'])) {
                $val = $val === null ? null : intval($val);
            }
            // Keep created_at as-is if provided
            $data[$col] = $val;
            $placeholders[] = '?';
        }

        if (empty($data['name']) || empty($data['student_faculty_id'])) {
            // Skip rows without required identifying info
            $errors[] = ['row' => $row, 'error' => 'missing name or student_faculty_id'];
            continue;
        }

        $colsList = implode(',', array_keys($data));
        $phList = implode(',', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO checkups ($colsList) VALUES ($phList)";
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute(array_values($data));
            $inserted++;
        } catch (PDOException $e) {
            $errors[] = ['row' => $row, 'error' => $e->getMessage()];
            // continue to next row
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $_) {}
    echo "Import failed: " . $e->getMessage() . "\n";
    exit(1);
}

fclose($fh);

echo "Import complete. Inserted: $inserted. Errors: " . count($errors) . "\n";
if (count($errors) > 0) {
    foreach ($errors as $err) {
        echo "Row error: " . ($err['error'] ?? 'unknown') . "\n";
    }
}

exit(0);
