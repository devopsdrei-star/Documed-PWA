<?php
// One-time database bootstrapper for Aiven/MySQL
// Usage examples:
//  - /backend/api/db_install.php               -> install schema into current DB (from DSN)
//  - /backend/api/db_install.php?db=db_med     -> switch session to db_med and install (requires privilege)
//  - /backend/api/db_install.php?createDb=1&db=db_med -> create db_med if missing, then install
//  - /backend/api/db_install.php?seed=medicines -> also run seed_medicines.sql
//  - /backend/api/db_install.php?seed=all       -> run available seeds (medicines, reports)

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config/db.php'; // provides $pdo

function respond($arr) { echo json_encode($arr); exit; }

// Determine target database
$targetDb = isset($_GET['db']) ? trim($_GET['db']) : null;
$createDb = isset($_GET['createDb']) && ($_GET['createDb']==='1' || strtolower($_GET['createDb'])==='true');
$seed     = strtolower(trim($_GET['seed'] ?? ''));

try {
    // If db param provided, optionally create and switch
    if ($targetDb !== null && $targetDb !== '') {
        if ($createDb) {
            try { $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`','',$targetDb) . '`'); } catch (Throwable $e) { /* ignore */ }
        }
        try { $pdo->exec('USE `' . str_replace('`','',$targetDb) . '`'); } catch (Throwable $e) { /* ignore - may not have privilege */ }
    }
    // Detect current database after potential USE
    $curDb = null; try { $curDb = $pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) {}

    // Read and normalize schema.sql
    $schemaPath = dirname(__DIR__) . '/config/schema.sql';
    if (!is_readable($schemaPath)) {
        respond(['success'=>false,'message'=>'schema.sql not found','path'=>$schemaPath]);
    }
    $sqlRaw = file_get_contents($schemaPath);
    // Replace "use db_med;" with current db if set; otherwise remove it
    if ($curDb) {
        $sqlRaw = preg_replace('/\buse\s+db_med\s*;?/i', 'USE `'.$curDb.'`;', $sqlRaw);
    } else {
        $sqlRaw = preg_replace('/\buse\s+db_med\s*;?/i', '', $sqlRaw);
    }
    // Remove /* ... */ comments
    $sqlRaw = preg_replace('#/\*.*?\*/#s', '', $sqlRaw);
    // Remove -- line comments
    $lines = preg_split("/\r?\n/", $sqlRaw);
    $buf = [];
    foreach ($lines as $ln) {
        $trim = ltrim($ln);
        if (strpos($trim, '--') === 0) continue;
        $buf[] = $ln;
    }
    $sqlClean = implode("\n", $buf);
    // Split by semicolon at end of statements
    $stmts = array_filter(array_map('trim', preg_split('/;\s*\n|;\s*$/m', $sqlClean)));

    $executed = 0; $errors = [];
    foreach ($stmts as $s) {
        if ($s === '') continue;
        try { $pdo->exec($s); $executed++; } catch (Throwable $e) { $errors[] = ['stmt'=>substr($s,0,160).'...', 'error'=>$e->getMessage()]; }
    }

    // Optional seeds
    $seeded = [];
    $cfgDir = dirname(__DIR__) . '/config/';
    $maybeRun = function($file) use ($pdo, $cfgDir, &$seeded) {
        $p = $cfgDir . $file;
        if (!is_readable($p)) return;
        $raw = file_get_contents($p);
        // basic cleanup
        $raw = preg_replace('#/\*.*?\*/#s', '', $raw);
        $parts = array_filter(array_map('trim', preg_split('/;\s*\n|;\s*$/m', $raw)));
        $cnt = 0; foreach ($parts as $st) { if ($st==='') continue; try { $pdo->exec($st); $cnt++; } catch (Throwable $e) { /* ignore */ } }
        $seeded[] = [$file, $cnt];
    };
    if ($seed === 'medicines' || $seed === 'all' || $seed === 'basic') { $maybeRun('seed_medicines.sql'); }
    if ($seed === 'reports'   || $seed === 'all') { $maybeRun('seed_reports.sql'); }

    respond([
        'success'=> true,
        'database'=> $curDb,
        'executedStatements'=> $executed,
        'errors'=> $errors,
        'seeded'=> $seeded
    ]);
} catch (Throwable $e) {
    respond(['success'=>false,'message'=>'Install failed','error'=>$e->getMessage()]);
}
?>
