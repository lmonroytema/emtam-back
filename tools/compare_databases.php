<?php

declare(strict_types=1);

$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$pass = '';
$dbA = $argv[1] ?? 'emta_db';
$dbB = $argv[2] ?? 'emta_prduction';

$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$schemas = $pdo->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name IN ('{$dbA}','{$dbB}')")->fetchAll(PDO::FETCH_COLUMN);
if (! in_array($dbA, $schemas, true) || ! in_array($dbB, $schemas, true)) {
    $all = $pdo->query("SELECT schema_name FROM information_schema.schemata")->fetchAll(PDO::FETCH_COLUMN);
    fwrite(STDERR, "No se encontraron ambas bases: {$dbA}, {$dbB}".PHP_EOL);
    fwrite(STDERR, "Bases disponibles: ".implode(', ', $all).PHP_EOL);
    exit(1);
}

$fetchTables = static function (PDO $pdo, string $db): array {
    $stmt = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'");
    $stmt->execute([$db]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};

$tablesA = $fetchTables($pdo, $dbA);
$tablesB = $fetchTables($pdo, $dbB);

$onlyA = array_values(array_diff($tablesA, $tablesB));
$onlyB = array_values(array_diff($tablesB, $tablesA));
$both = array_values(array_intersect($tablesA, $tablesB));

sort($onlyA);
sort($onlyB);
sort($both);

echo "Tablas solo en {$dbA}: ".count($onlyA).PHP_EOL;
foreach ($onlyA as $t) {
    echo "  - {$t}".PHP_EOL;
}
echo "Tablas solo en {$dbB}: ".count($onlyB).PHP_EOL;
foreach ($onlyB as $t) {
    echo "  - {$t}".PHP_EOL;
}

$fetchColumns = static function (PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare("SELECT column_name, column_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = ? AND table_name = ?");
    $stmt->execute([$db, $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['column_name']] = [
            'type' => $r['column_type'],
            'nullable' => $r['is_nullable'],
            'default' => $r['column_default'],
        ];
    }
    return $map;
};

$columnDiffs = [];
foreach ($both as $table) {
    $colsA = $fetchColumns($pdo, $dbA, $table);
    $colsB = $fetchColumns($pdo, $dbB, $table);
    $onlyColsA = array_values(array_diff(array_keys($colsA), array_keys($colsB)));
    $onlyColsB = array_values(array_diff(array_keys($colsB), array_keys($colsA)));
    $diffProps = [];
    foreach (array_intersect(array_keys($colsA), array_keys($colsB)) as $col) {
        if ($colsA[$col] !== $colsB[$col]) {
            $diffProps[$col] = ['a' => $colsA[$col], 'b' => $colsB[$col]];
        }
    }
    if ($onlyColsA || $onlyColsB || $diffProps) {
        $columnDiffs[$table] = [
            'only_a' => $onlyColsA,
            'only_b' => $onlyColsB,
            'diff' => $diffProps,
        ];
    }
}

echo "Tablas con diferencias de columnas: ".count($columnDiffs).PHP_EOL;
foreach ($columnDiffs as $table => $diff) {
    echo "  - {$table}".PHP_EOL;
    foreach ($diff['only_a'] as $c) {
        echo "      * Solo {$dbA}: {$c}".PHP_EOL;
    }
    foreach ($diff['only_b'] as $c) {
        echo "      * Solo {$dbB}: {$c}".PHP_EOL;
    }
    foreach ($diff['diff'] as $c => $props) {
        $a = $props['a'];
        $b = $props['b'];
        echo "      * Diferente {$c}: {$dbA}({$a['type']},{$a['nullable']},{$a['default']}) vs {$dbB}({$b['type']},{$b['nullable']},{$b['default']})".PHP_EOL;
    }
}

$counts = [];
foreach ($both as $table) {
    $countA = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.`{$table}`")->fetchColumn();
    $countB = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbB}`.`{$table}`")->fetchColumn();
    if ($countA !== $countB) {
        $counts[] = [$table, $countA, $countB];
    }
}

echo "Tablas con conteos distintos: ".count($counts).PHP_EOL;
foreach ($counts as [$table, $countA, $countB]) {
    echo "  - {$table}: {$dbA}={$countA}, {$dbB}={$countB}".PHP_EOL;
}
