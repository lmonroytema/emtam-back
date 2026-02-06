<?php

$env = file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$cfg = [];
foreach ($env as $line) {
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $parts = explode('=', $line, 2);
    if (count($parts) === 2) {
        $cfg[$parts[0]] = trim($parts[1], "\"'");
    }
}

$host = $cfg['DB_HOST'] ?? '127.0.0.1';
$port = (int) ($cfg['DB_PORT'] ?? 3306);
$db = $cfg['DB_DATABASE'] ?? 'emta_db';
$user = $cfg['DB_USERNAME'] ?? 'root';
$pass = $cfg['DB_PASSWORD'] ?? '';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$systemTables = array_fill_keys([
    'cache',
    'cache_locks',
    'failed_jobs',
    'job_batches',
    'jobs',
    'migrations',
    'password_reset_tokens',
    'personal_access_tokens',
    'sessions',
    'tenants',
    'tenant_languages',
    'users',
], true);

$tablesStmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME');
$tablesStmt->execute(['db' => $db]);
$tableNames = array_map(static fn ($r) => $r['TABLE_NAME'], $tablesStmt->fetchAll(PDO::FETCH_ASSOC));

$columnsStmt = $pdo->prepare('
    SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = :db
    ORDER BY TABLE_NAME, ORDINAL_POSITION
');
$columnsStmt->execute(['db' => $db]);
$columnsRows = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);

$columnsByTable = [];
foreach ($columnsRows as $r) {
    $t = $r['TABLE_NAME'];
    $columnsByTable[$t][] = [
        'name' => $r['COLUMN_NAME'],
        'type' => $r['DATA_TYPE'],
        'nullable' => $r['IS_NULLABLE'] === 'YES',
        'key' => $r['COLUMN_KEY'] ?: null,
    ];
}

$pkStmt = $pdo->prepare('
    SELECT TABLE_NAME, COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = :db AND CONSTRAINT_NAME = \'PRIMARY\'
    ORDER BY TABLE_NAME, ORDINAL_POSITION
');
$pkStmt->execute(['db' => $db]);
$pkRows = $pkStmt->fetchAll(PDO::FETCH_ASSOC);

$pkByTable = [];
foreach ($pkRows as $r) {
    $pkByTable[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
}

$fkStmt = $pdo->prepare('
    SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION
');
$fkStmt->execute(['db' => $db]);
$fkRows = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

$relationships = array_map(static fn ($r) => [
    'constraint_name' => $r['CONSTRAINT_NAME'],
    'table' => $r['TABLE_NAME'],
    'column' => $r['COLUMN_NAME'],
    'referenced_table' => $r['REFERENCED_TABLE_NAME'],
    'referenced_column' => $r['REFERENCED_COLUMN_NAME'],
], $fkRows);

function detectModule(string $tableName, array $systemTables): string
{
    if (array_key_exists($tableName, $systemTables)) {
        return 'system';
    }

    if (preg_match('/_(cfg|cat|mst|trs)$/', $tableName, $m) === 1) {
        return $m[1];
    }

    return 'otros';
}

$tables = [];
foreach ($tableNames as $tableName) {
    $module = detectModule($tableName, $systemTables);
    $columns = $columnsByTable[$tableName] ?? [];
    $primaryKey = $pkByTable[$tableName] ?? [];

    $rowsCount = null;
    try {
        $countStmt = $pdo->query('SELECT COUNT(*) AS c FROM `'.str_replace('`', '``', $tableName).'`');
        $rowsCount = (int) (($countStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0));
    } catch (Throwable $e) {
        $rowsCount = null;
    }

    $tables[] = [
        'name' => $tableName,
        'module' => $module,
        'columns_count' => count($columns),
        'rows_count' => $rowsCount,
        'primary_key' => $primaryKey,
        'columns' => $columns,
    ];
}

$payload = [
    'db' => $db,
    'generated_at' => gmdate('c'),
    'tables' => $tables,
    'relationships' => $relationships,
];

$outDir = __DIR__.'/../er-web/data';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$outPath = $outDir.'/schema.json';
file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

echo $outPath.PHP_EOL;
