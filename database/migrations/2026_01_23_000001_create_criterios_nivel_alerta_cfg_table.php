<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('criterios_nivel_alerta_cfg')) {
            Schema::create('criterios_nivel_alerta_cfg', function (Blueprint $table) {
                $table->increments('id');
                $table->string('rie_cod', 255);
                $table->text('descripcion_criterios');
                $table->string('ni_al_nombre', 255);
                $table->integer('criterio_orden')->default(0);
                $table->integer('activo')->default(1);
                $table->index(['rie_cod', 'activo']);
            });
        }

        $paths = [
            base_path('CSV/Criterios_alerta.csv'),
            base_path('../CSV/Criterios_alerta.csv'),
            base_path('CSV/criterios_alerta.csv'),
            base_path('../CSV/criterios_alerta.csv'),
        ];

        $path = null;
        foreach ($paths as $p) {
            if (is_file($p)) {
                $path = $p;
                break;
            }
        }

        if ($path === null) {
            throw new \RuntimeException('No se encontró CSV/Criterios_alerta.csv.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines) || count($lines) < 2) {
            return;
        }

        $headers = str_getcsv((string) $lines[0], ';');
        $headerIndex = [];
        foreach ($headers as $i => $h) {
            $key = strtolower(trim((string) $h));
            if ($key !== '') {
                $headerIndex[$key] = $i;
            }
        }

        foreach (['rie-cod', 'ni_al-nombre', 'descripcion_criterios'] as $required) {
            if (! array_key_exists($required, $headerIndex)) {
                throw new \RuntimeException('Falta la columna '.$required.' en Criterios_alerta.csv.');
            }
        }

        $toUtf8 = static function (?string $text): string {
            $t = (string) $text;
            if ($t === '') {
                return '';
            }
            if (function_exists('mb_check_encoding') && mb_check_encoding($t, 'UTF-8')) {
                return $t;
            }
            if (function_exists('mb_convert_encoding')) {
                foreach (['Windows-1252', 'ISO-8859-1'] as $from) {
                    $converted = @mb_convert_encoding($t, 'UTF-8', $from);
                    if (is_string($converted) && $converted !== '') {
                        return $converted;
                    }
                }
            }

            return $t;
        };

        $alreadyHasRows = (int) DB::table('criterios_nivel_alerta_cfg')->count() > 0;
        if ($alreadyHasRows) {
            return;
        }

        $orderByGroup = [];
        $batch = [];

        for ($i = 1; $i < count($lines); $i++) {
            $row = trim((string) $lines[$i]);
            if ($row === '') {
                continue;
            }

            $values = str_getcsv($row, ';');
            $rieCod = $toUtf8($values[$headerIndex['rie-cod']] ?? null);
            $nivelNombre = $toUtf8($values[$headerIndex['ni_al-nombre']] ?? null);
            $descripcion = $toUtf8($values[$headerIndex['descripcion_criterios']] ?? null);

            $rieCod = trim($rieCod);
            $nivelNombre = trim($nivelNombre);
            $descripcion = trim($descripcion);

            if ($rieCod === '' || $nivelNombre === '' || $descripcion === '') {
                continue;
            }

            $groupKey = $rieCod.'|'.$nivelNombre;
            $orderByGroup[$groupKey] = ($orderByGroup[$groupKey] ?? 0) + 1;

            $batch[] = [
                'rie_cod' => $rieCod,
                'descripcion_criterios' => $descripcion,
                'ni_al_nombre' => $nivelNombre,
                'criterio_orden' => $orderByGroup[$groupKey],
                'activo' => 1,
            ];

            if (count($batch) >= 500) {
                DB::table('criterios_nivel_alerta_cfg')->insert($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            DB::table('criterios_nivel_alerta_cfg')->insert($batch);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('criterios_nivel_alerta_cfg');
    }
};
