<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('informacion_tablas')) {
            return;
        }

        $csvPath = base_path('..'.DIRECTORY_SEPARATOR.'Indice.csv');
        if (! is_file($csvPath)) {
            $csvPath = base_path('..'.DIRECTORY_SEPARATOR.'indice.csv');
        }
        if (! is_file($csvPath)) {
            return;
        }

        $fh = fopen($csvPath, 'rb');
        if ($fh === false) {
            return;
        }

        $readRow = static function ($fh, string $delimiter): array|false {
            $row = fgetcsv($fh, 0, $delimiter);
            if ($row === false) {
                return false;
            }

            return array_map(static fn ($v) => is_string($v) ? $v : '', $row);
        };

        $header = $readRow($fh, ';');
        if (! is_array($header) || count($header) < 2) {
            rewind($fh);
            $header = $readRow($fh, ',');
        }
        if (! is_array($header)) {
            fclose($fh);

            return;
        }

        $normalizeHeader = static function (string $h): string {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
            $h = strtolower(trim($h));
            $h = preg_replace('/\s+/', ' ', $h) ?? $h;

            return $h;
        };

        $index = [];
        foreach ($header as $i => $h) {
            $key = $normalizeHeader((string) $h);
            if ($key !== '') {
                $index[$key] = (int) $i;
            }
        }

        $tableIdx = $index['nombre de tabla'] ?? null;
        $contenidoIdx = $index['contenido'] ?? null;
        $finalidadIdx = $index['finalidad'] ?? null;

        if ($tableIdx === null || $contenidoIdx === null || $finalidadIdx === null) {
            fclose($fh);

            return;
        }

        $fixEncoding = static function (string $s): string {
            $s = trim($s);
            if ($s === '') {
                return '';
            }
            if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
                if (! mb_check_encoding($s, 'UTF-8')) {
                    $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
                }
            }
            $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

            return $s;
        };

        rewind($fh);
        $headerLine = fgets($fh);
        if ($headerLine === false) {
            fclose($fh);

            return;
        }
        $delimiter = str_contains($headerLine, ';') ? ';' : ',';

        while (($row = $readRow($fh, $delimiter)) !== false) {
            $nombreTabla = strtolower(trim((string) ($row[$tableIdx] ?? '')));
            if ($nombreTabla === '') {
                continue;
            }

            $contenido = $fixEncoding((string) ($row[$contenidoIdx] ?? ''));
            $finalidad = $fixEncoding((string) ($row[$finalidadIdx] ?? ''));

            if ($contenido === '' && $finalidad === '') {
                continue;
            }

            $contenido = mb_substr($contenido, 0, 1000);
            $finalidad = mb_substr($finalidad, 0, 1000);

            DB::table('informacion_tablas')->updateOrInsert(
                ['nombre_tabla' => $nombreTabla],
                ['contenido' => $contenido, 'finalidad' => $finalidad]
            );
        }

        fclose($fh);
    }

    public function down(): void {}
};
