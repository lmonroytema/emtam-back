<?php

declare(strict_types=1);

$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$pass = '';
$db = $argv[1] ?? null;
$table = $argv[2] ?? null;

if (! $db || ! $table) {
    fwrite(STDERR, "Uso: php tools/dump_table_columns.php <db> <table>".PHP_EOL);
    exit(1);
}

$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare("SELECT column_name,column_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position");
$stmt->execute([$db, $table]);
foreach ($stmt as $r) {
    echo $r['column_name'].'|'.$r['column_type'].'|'.$r['is_nullable'].'|'.($r['column_default'] ?? '').PHP_EOL;
}
