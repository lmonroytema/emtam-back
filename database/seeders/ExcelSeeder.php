<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('260114 AppTabs.xlsx');

        if (! is_file($path)) {
            $path = base_path('../260114 AppTabs.xlsx');
        }

        if (! is_file($path)) {
            return;
        }

        $spreadsheet = IOFactory::load($path);
        $dictionary = $spreadsheet->getSheetByName('DICCIONARIO_DATOS');

        if ($dictionary === null) {
            return;
        }

        $highestCol = $dictionary->getHighestColumn();
        $headers = $dictionary->rangeToArray('A1:'.$highestCol.'1', null, true, false)[0] ?? [];
        $headerIndex = [];

        foreach ($headers as $i => $name) {
            if ($name !== null) {
                $headerIndex[(string) $name] = $i;
            }
        }

        foreach (['tabla', 'nombre_tabla'] as $required) {
            if (! array_key_exists($required, $headerIndex)) {
                return;
            }
        }

        $pkIndex = $headerIndex['pk'] ?? null;
        $fieldIndex = $headerIndex['nom_campo_nuevo'] ?? null;

        $sheetToTable = [];
        $sheetToPkColumns = [];
        $highestRow = $dictionary->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $values = $dictionary->rangeToArray('A'.$row.':'.$highestCol.$row, null, true, false)[0] ?? [];
            $sheetName = $values[$headerIndex['tabla']] ?? null;
            $tableName = $values[$headerIndex['nombre_tabla']] ?? null;

            if ($sheetName === null || $tableName === null) {
                continue;
            }

            $sheetName = trim((string) $sheetName);
            $tableName = trim((string) $tableName);

            if ($sheetName === '' || $tableName === '') {
                continue;
            }

            $sheetToTable[$sheetName] = $tableName;

            if ($pkIndex !== null && $fieldIndex !== null) {
                $isPk = (string) ($values[$pkIndex] ?? '') === 'SI';
                $field = $values[$fieldIndex] ?? null;

                if ($isPk && $field !== null) {
                    $field = strtolower(trim((string) $field));
                    if ($field !== '') {
                        $sheetToPkColumns[$sheetName][$field] = true;
                    }
                }
            }
        }

        $skipSheets = [
            'Notas Generales',
            'Reglas_Por_Tabla',
            'INDICE',
            'DICCIONARIO_DATOS',
        ];

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        foreach ($spreadsheet->getSheetNames() as $name) {
            if (in_array($name, $skipSheets, true)) {
                continue;
            }

            if (! array_key_exists($name, $sheetToTable)) {
                continue;
            }

            $table = $sheetToTable[$name];
            $sheet = $spreadsheet->getSheetByName($name);

            if ($sheet === null) {
                continue;
            }

            $maxRow = $sheet->getHighestRow();
            $maxCol = $sheet->getHighestColumn();

            $headerRow = $sheet->rangeToArray('A1:'.$maxCol.'1', null, true, false)[0] ?? [];
            $headers = array_values(array_filter(array_map(
                static fn ($h) => $h === null ? null : strtolower(trim((string) $h)),
                $headerRow,
            ), static fn ($h) => $h !== null && $h !== ''));

            if (empty($headers)) {
                continue;
            }

            $rows = [];
            $pkColumns = array_keys($sheetToPkColumns[$name] ?? []);

            for ($r = 2; $r <= $maxRow; $r++) {
                $values = $sheet->rangeToArray('A'.$r.':'.$maxCol.$r, null, true, false)[0] ?? [];

                $data = [];
                foreach ($headers as $i => $header) {
                    $data[$header] = $values[$i] ?? null;
                }

                if (count(array_filter($data, static fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }

                if (! empty($pkColumns)) {
                    $valid = true;
                    foreach ($pkColumns as $pk) {
                        $value = $data[$pk] ?? null;

                        if ($value === null) {
                            $valid = false;
                            break;
                        }

                        if (is_string($value)) {
                            $trimmed = trim($value);
                            if ($trimmed === '' || str_starts_with(mb_strtoupper($trimmed), 'FK')) {
                                $valid = false;
                                break;
                            }
                        }
                    }

                    if (! $valid) {
                        continue;
                    }
                }

                $rows[] = $data;
            }

            if (empty($rows)) {
                continue;
            }

            DB::table($table)->truncate();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
