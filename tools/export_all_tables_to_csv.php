<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$defaultOutputDir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'CSV-Hosting';
$outputDir = $argv[1] ?? $defaultOutputDir;
$outputDir = rtrim($outputDir, "\\/ \t\n\r\0\x0B");

if ($outputDir === '') {
    fwrite(STDERR, "Directorio de salida inválido.\n");
    exit(1);
}

if (! is_dir($outputDir) && ! mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "No se pudo crear directorio: {$outputDir}\n");
    exit(1);
}

$driver = DB::getDriverName();
$database = DB::getDatabaseName();
$tables = [];

if ($driver === 'mysql') {
    $tables = DB::table('information_schema.tables')
        ->where('table_schema', $database)
        ->orderBy('table_name')
        ->pluck('table_name')
        ->map(static fn ($t) => (string) $t)
        ->all();
} elseif ($driver === 'pgsql') {
    $rows = DB::select("
        SELECT tablename
        FROM pg_catalog.pg_tables
        WHERE schemaname = 'public'
        ORDER BY tablename
    ");
    $tables = array_map(static fn ($r) => (string) ($r->tablename ?? ''), $rows);
} elseif ($driver === 'sqlite') {
    $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $tables = array_map(static fn ($r) => (string) ($r->name ?? ''), $rows);
} else {
    fwrite(STDERR, "Driver no soportado: {$driver}\n");
    exit(1);
}

$tables = array_values(array_filter($tables, static fn ($t) => $t !== ''));

$exported = 0;
$failed = [];

foreach ($tables as $table) {
    try {
        $columns = Schema::getColumnListing($table);
        $path = $outputDir.DIRECTORY_SEPARATOR.$table.'.csv';
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException("No se pudo abrir archivo {$path}");
        }

        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $columns);

        foreach (DB::table($table)->cursor() as $row) {
            $raw = get_object_vars($row);
            $line = [];
            foreach ($columns as $col) {
                $value = $raw[$col] ?? null;
                if (is_bool($value)) {
                    $line[] = $value ? '1' : '0';
                } elseif (is_scalar($value) || $value === null) {
                    $line[] = $value;
                } else {
                    $line[] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            fputcsv($fh, $line);
        }

        fclose($fh);
        $exported++;
    } catch (Throwable $e) {
        $failed[] = ['table' => $table, 'error' => $e->getMessage()];
    }
}

$summary = [
    'driver' => $driver,
    'database' => $database,
    'output_dir' => $outputDir,
    'tables_total' => count($tables),
    'tables_exported' => $exported,
    'tables_failed' => count($failed),
    'failed' => $failed,
];

file_put_contents(
    $outputDir.DIRECTORY_SEPARATOR.'_export_summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

