<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EvLugarCoordsCsvSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('ev_lugar_mst')) {
            return;
        }

        $path = base_path('EVs.csv');
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);
            return;
        }

        $indexes = $this->resolveIndexes($header);
        if ($indexes === null) {
            fclose($handle);
            return;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $descripcion = $this->stringValue($row, $indexes['descripcion']);
            if ($descripcion === '') {
                continue;
            }
            $lon = $this->floatValue($row, $indexes['longitud']);
            $lat = $this->floatValue($row, $indexes['latitud']);
            if ($lon === null || $lat === null) {
                continue;
            }

            DB::table('ev_lugar_mst')
                ->where('ev_lu-cod', $descripcion)
                ->update([
                    'ev_lu_coo-longitud' => $lon,
                    'ev_lu_coo-latitud' => $lat,
                ]);
        }

        fclose($handle);
    }

    private function resolveIndexes(array $header): ?array
    {
        $map = [];
        foreach ($header as $idx => $label) {
            $name = trim((string) $label);
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
            $name = mb_strtoupper($name, 'UTF-8');
            $map[$name] = $idx;
        }

        $lonIdx = $map['LONGITUD'] ?? null;
        $latIdx = $map['LATITUD'] ?? null;
        $descIdx = $map['DESCRIPCIÓN'] ?? $map['DESCRIPCION'] ?? null;

        if ($lonIdx === null || $latIdx === null || $descIdx === null) {
            return null;
        }

        return [
            'longitud' => $lonIdx,
            'latitud' => $latIdx,
            'descripcion' => $descIdx,
        ];
    }

    private function stringValue(array $row, int $index): string
    {
        $value = $row[$index] ?? '';
        return trim((string) $value);
    }

    private function floatValue(array $row, int $index): ?float
    {
        $raw = trim((string) ($row[$index] ?? ''));
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(',', '.', $raw);
        return is_numeric($raw) ? (float) $raw : null;
    }
}
