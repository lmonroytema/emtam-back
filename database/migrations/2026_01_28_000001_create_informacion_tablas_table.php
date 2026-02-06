<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('informacion_tablas')) {
            Schema::create('informacion_tablas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre_tabla')->unique();
                $table->string('contenido', 1000);
                $table->string('finalidad', 1000);
            });
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

        $header = fgetcsv($fh);
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
            if (! is_string($h)) {
                continue;
            }
            $key = $normalizeHeader($h);
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

        while (($row = fgetcsv($fh)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $nombreTabla = trim((string) ($row[$tableIdx] ?? ''));
            if ($nombreTabla === '') {
                continue;
            }

            $contenido = trim((string) ($row[$contenidoIdx] ?? ''));
            $finalidad = trim((string) ($row[$finalidadIdx] ?? ''));

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

    public function down(): void
    {
        Schema::dropIfExists('informacion_tablas');
    }
};
