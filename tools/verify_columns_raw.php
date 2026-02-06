<?php

$env = file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$cfg = [];
foreach ($env as $line) {
    if ($line[0] === '#') {
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

$stmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, ORDINAL_POSITION');
$stmt->execute(['db' => $db]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tables = [];
$issues = [];

foreach ($rows as $r) {
    $t = $r['TABLE_NAME'];
    $c = $r['COLUMN_NAME'];
    $tables[$t] = true;
    if (preg_match('/[A-Z]/', $c)) {
        $issues[$t][] = $c;
    }
}

echo 'Tables: '.count($tables).PHP_EOL;
echo 'Tables with uppercase columns: '.count($issues).PHP_EOL;
foreach ($issues as $t => $cols) {
    echo $t.': '.implode(',', $cols).PHP_EOL;
}
