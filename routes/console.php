<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('activaciones:reset {--force}', function () {
    $tables = [
        'notificacion_confirmacion_trs',
        'notificacion_envio_trs',
        'ejecucion_accion_trs',
        'asignacion_en_funciones_trs',
        'notas_operativas_trs',
        'cronologia_emergencia_trs',
        'activacion_nivel_hist_trs',
        'activacion_del_plan_trs',
    ];

    $existing = [];
    $missing = [];

    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            $count = DB::table($table)->count();
            $existing[] = ['name' => $table, 'count' => $count];
        } else {
            $missing[] = $table;
        }
    }

    if (empty($existing)) {
        $this->error('No hay tablas para reiniciar.');

        return 1;
    }

    $this->info('Tablas a reiniciar:');
    foreach ($existing as $row) {
        $this->line('- '.$row['name'].' (filas: '.$row['count'].')');
    }

    if (! empty($missing)) {
        $this->warn('Tablas no encontradas:');
        foreach ($missing as $table) {
            $this->line('- '.$table);
        }
    }

    if (! $this->option('force')) {
        if (! $this->confirm('¿Deseas continuar y eliminar estos registros?')) {
            $this->line('Cancelado.');

            return 0;
        }
    }

    DB::transaction(function () use ($existing): void {
        foreach ($existing as $row) {
            DB::table($row['name'])->delete();
        }
    });

    $this->info('Reinicio completado.');

    return 0;
})->purpose('Reinicia las tablas de planes activados');

Artisan::command('csv:validate {--dir=} {--limit=0} {--json} {--all} {--mode=}', function () {
    $limit = (int) $this->option('limit');
    $asJson = (bool) $this->option('json');
    $mode = strtolower(trim((string) $this->option('mode')));
    $showAll = $this->option('all') || $mode === 'all' || $mode === 'todas';
    $onlyDiff = ! $showAll;
    $dirsOption = trim((string) $this->option('dir'));

    $isAbsolute = static function (string $path): bool {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/');
    };

    $addCsvFile = static function (string $path, array &$files): void {
        if (! is_file($path)) {
            return;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return;
        }
        $files[] = $path;
    };

    $directories = [];
    $csvFiles = [];

    if ($dirsOption !== '') {
        foreach (array_filter(array_map('trim', explode(',', $dirsOption))) as $raw) {
            $candidatePaths = [];
            if ($isAbsolute($raw)) {
                $candidatePaths[] = $raw;
            } else {
                $candidatePaths[] = base_path($raw);
                $candidatePaths[] = base_path('..'.DIRECTORY_SEPARATOR.$raw);
            }
            foreach ($candidatePaths as $path) {
                if (is_dir($path)) {
                    $directories[] = $path;
                } elseif (is_file($path)) {
                    $addCsvFile($path, $csvFiles);
                }
            }
        }
    } else {
        foreach ([base_path('CSV'), base_path('..'.DIRECTORY_SEPARATOR.'CSV')] as $dir) {
            if (is_dir($dir)) {
                $directories[] = $dir;
            }
        }
        foreach ([base_path('Indice.csv'), base_path('..'.DIRECTORY_SEPARATOR.'Indice.csv')] as $file) {
            if (is_file($file)) {
                $addCsvFile($file, $csvFiles);
            }
        }
    }

    foreach ($directories as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $addCsvFile($path, $csvFiles);
        }
    }

    $csvFiles = array_values(array_unique($csvFiles));
    sort($csvFiles);

    if (empty($csvFiles)) {
        $this->error('No se encontraron CSVs.');

        return 1;
    }

    $driver = DB::getDriverName();
    $dbName = DB::getDatabaseName();
    $tables = [];

    if ($driver === 'mysql') {
        $tables = DB::table('information_schema.tables')
            ->select('table_name')
            ->where('table_schema', $dbName)
            ->pluck('table_name')
            ->all();
    } elseif ($driver === 'sqlite') {
        $tables = array_map(
            static fn ($r) => $r->name,
            DB::select("SELECT name FROM sqlite_master WHERE type='table'")
        );
    } elseif ($driver === 'pgsql') {
        $tables = array_map(
            static fn ($r) => $r->tablename,
            DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public'")
        );
    }

    $tablesLookup = array_fill_keys($tables, true);
    $suffixes = ['_cat', '_cfg', '_mst', '_trs'];
    $explicitMap = [
        'criterios_alerta' => 'criterios_nivel_alerta_cfg',
        'indice' => 'informacion_tablas',
        'informacion_tablas' => 'informacion_tablas',
        'riesgo_nivel_accion_set' => 'riesgo_nivel_accion_set_cfg',
        'tipo_riesgo_nivel_accion_set' => 'tipo_riesgo_nivel_accion_set_cfg',
    ];

    $normalizeBase = static function (string $name): string {
        $base = strtolower(trim($name));
        $base = preg_replace('/\.csv$/', '', $base) ?? $base;
        $base = preg_replace('/\s+/', '_', $base) ?? $base;
        $base = preg_replace('/[^a-z0-9_]+/', '_', $base) ?? $base;
        $base = preg_replace('/^\\d+_+/', '', $base) ?? $base;
        $base = preg_replace('/_+/', '_', $base) ?? $base;
        $base = trim($base, '_');
        $tokens = array_filter(explode('_', $base), static fn ($t) => $t !== '');
        $filtered = [];
        foreach ($tokens as $t) {
            if (in_array($t, ['apptabs', 'app', 'tabs', 'dataset', 'data', 'notebooklm', 'emta', 'main', 'df', 'general', 'resum'], true)) {
                continue;
            }
            $filtered[] = $t;
        }
        $base = implode('_', $filtered);
        $base = preg_replace('/_+/', '_', $base) ?? $base;

        return trim($base, '_');
    };

    $normalizeCol = static function (string $col): string {
        $col = preg_replace('/^\xEF\xBB\xBF/', '', $col) ?? $col;
        $col = trim($col);
        $col = trim($col, " \t\n\r\0\x0B\"'");
        $col = strtolower($col);
        $col = preg_replace('/\s+/', ' ', $col) ?? $col;

        return $col;
    };

    $isIgnoredColumn = static function (string $col): bool {
        return preg_match('/(^|[-_])id$/', $col) === 1;
    };

    $resolveTable = static function (string $base) use ($tables, $tablesLookup, $suffixes, $explicitMap): array {
        if (isset($explicitMap[$base])) {
            $table = $explicitMap[$base];

            return ['table' => $table, 'ambiguous' => []];
        }
        if (isset($tablesLookup[$base])) {
            return ['table' => $base, 'ambiguous' => []];
        }
        foreach ($suffixes as $suffix) {
            $candidate = $base.$suffix;
            if (isset($tablesLookup[$candidate])) {
                return ['table' => $candidate, 'ambiguous' => []];
            }
        }
        $matches = [];
        foreach ($tables as $table) {
            $candidate = preg_replace('/_(cat|cfg|mst|trs)$/', '', $table) ?? $table;
            if ($candidate === $base) {
                $matches[] = $table;
            }
        }
        if (count($matches) === 1) {
            return ['table' => $matches[0], 'ambiguous' => []];
        }

        return ['table' => null, 'ambiguous' => $matches];
    };

    $report = [];

    foreach ($csvFiles as $file) {
        $headerLine = null;
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            $report[] = ['file' => $file, 'error' => 'No se pudo abrir el archivo.'];

            continue;
        }
        while (($line = fgets($fh)) !== false) {
            if (trim($line) !== '') {
                $headerLine = $line;
                break;
            }
        }
        if ($headerLine === null) {
            fclose($fh);
            $report[] = ['file' => $file, 'error' => 'Archivo vacío.'];

            continue;
        }

        $delimiterScores = [
            ';' => substr_count($headerLine, ';'),
            ',' => substr_count($headerLine, ','),
            "\t" => substr_count($headerLine, "\t"),
        ];
        arsort($delimiterScores);
        $delimiter = array_key_first($delimiterScores) ?: ';';
        $headers = str_getcsv($headerLine, $delimiter);

        $csvNormalized = [];
        $csvOriginal = [];
        $duplicates = [];
        $emptyHeaders = 0;

        foreach ($headers as $h) {
            if (! is_string($h)) {
                $emptyHeaders++;

                continue;
            }
            $normalized = $normalizeCol($h);
            if ($normalized === '') {
                $emptyHeaders++;

                continue;
            }
            if (array_key_exists($normalized, $csvOriginal)) {
                $duplicates[$normalized] = true;
            }
            $csvOriginal[$normalized] = trim($h);
            $csvNormalized[$normalized] = true;
        }

        $rowCount = 0;
        if ($limit > 0) {
            while (($line = fgets($fh)) !== false && $rowCount < $limit) {
                if (trim($line) !== '') {
                    $rowCount++;
                }
            }
        }

        fclose($fh);

        $base = $normalizeBase(pathinfo($file, PATHINFO_FILENAME));
        $resolved = $resolveTable($base);
        $table = $resolved['table'];
        $ambiguous = $resolved['ambiguous'];

        $missingInDb = [];
        $missingInCsv = [];
        $dbCols = [];

        if ($table && Schema::hasTable($table)) {
            $dbCols = Schema::getColumnListing($table);
            $dbNormalized = array_values(array_filter(array_map($normalizeCol, $dbCols), static function (string $col) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($col);
            }));
            $dbSet = array_fill_keys($dbNormalized, true);
            $csvSet = array_filter($csvNormalized, static function (bool $value, string $key) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($key);
            }, ARRAY_FILTER_USE_BOTH);
            $missingInDb = array_values(array_diff(array_keys($csvSet), array_keys($dbSet)));
            $missingInCsv = array_values(array_diff(array_keys($dbSet), array_keys($csvSet)));
        } elseif ($table) {
            $missingInDb = array_values(array_filter(array_keys($csvNormalized), static function (string $col) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($col);
            }));
        } else {
            $missingInDb = array_values(array_filter(array_keys($csvNormalized), static function (string $col) use ($isIgnoredColumn): bool {
                return ! $isIgnoredColumn($col);
            }));
        }

        $report[] = [
            'file' => $file,
            'table' => $table,
            'ambiguous' => $ambiguous,
            'delimiter' => $delimiter,
            'csv_columns' => array_values(array_map(static fn ($c) => $c, $csvOriginal)),
            'db_columns' => $dbCols,
            'missing_in_db' => $missingInDb,
            'missing_in_csv' => $missingInCsv,
            'duplicate_headers' => array_keys($duplicates),
            'empty_headers' => $emptyHeaders,
            'rows_sampled' => $rowCount,
        ];
    }

    $filteredReport = $report;
    if ($onlyDiff) {
        $filteredReport = array_values(array_filter($report, static function (array $entry): bool {
            if (isset($entry['error'])) {
                return true;
            }
            if (empty($entry['table'])) {
                return true;
            }

            return ! empty($entry['ambiguous'])
                || ! empty($entry['missing_in_db'])
                || ! empty($entry['missing_in_csv'])
                || ! empty($entry['duplicate_headers'])
                || (($entry['empty_headers'] ?? 0) > 0);
        }));
    }

    if ($asJson) {
        $this->line(json_encode($filteredReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    $this->line($onlyDiff ? 'Archivos con diferencias: '.count($filteredReport) : 'Archivos verificados: '.count($filteredReport));

    foreach ($filteredReport as $entry) {
        $this->line('');
        $this->line($entry['file']);
        if (isset($entry['error'])) {
            $this->error('  '.$entry['error']);

            continue;
        }
        $table = $entry['table'] ?? null;
        $this->line('  Tabla: '.($table ?: 'sin resolver'));
        if (! empty($entry['ambiguous'])) {
            $this->warn('  Coincidencias posibles: '.implode(', ', $entry['ambiguous']));
        }
        if (! empty($entry['missing_in_db'])) {
            $this->warn('  Columnas en CSV no presentes en BD: '.implode(', ', $entry['missing_in_db']));
        }
        if (! empty($entry['missing_in_csv'])) {
            $this->warn('  Columnas en BD no presentes en CSV: '.implode(', ', $entry['missing_in_csv']));
        }
        if (! empty($entry['duplicate_headers'])) {
            $this->warn('  Encabezados duplicados: '.implode(', ', $entry['duplicate_headers']));
        }
        if (($entry['empty_headers'] ?? 0) > 0) {
            $this->warn('  Encabezados vacíos: '.$entry['empty_headers']);
        }
    }

    return 0;
})->purpose('Validar CSVs contra el esquema de la base de datos');
