<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;

return new class extends Migration
{
    public function up(): void
    {
        $path = base_path('260114 AppTabs.xlsx');

        if (! is_file($path)) {
            $path = base_path('../260114 AppTabs.xlsx');
        }

        if (! is_file($path)) {
            throw new RuntimeException('No se encontró 260114 AppTabs.xlsx.');
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('DICCIONARIO_DATOS');

        if ($sheet === null) {
            throw new RuntimeException('No existe la hoja DICCIONARIO_DATOS en el Excel.');
        }

        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();

        $headers = $sheet->rangeToArray('A1:'.$highestCol.'1', null, true, false)[0] ?? [];
        $headerIndex = [];

        foreach ($headers as $i => $name) {
            if ($name !== null) {
                $headerIndex[(string) $name] = $i;
            }
        }

        foreach (['nombre_tabla', 'nom_campo_nuevo', 'pk'] as $required) {
            if (! array_key_exists($required, $headerIndex)) {
                throw new RuntimeException('Falta la columna '.$required.' en DICCIONARIO_DATOS.');
            }
        }

        $tables = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray('A'.$row.':'.$highestCol.$row, null, true, false)[0] ?? [];
            $tableName = $values[$headerIndex['nombre_tabla']] ?? null;
            $columnName = $values[$headerIndex['nom_campo_nuevo']] ?? null;
            $pk = $values[$headerIndex['pk']] ?? null;

            if ($tableName === null || $columnName === null) {
                continue;
            }

            $tableName = strtolower(trim((string) $tableName));
            $columnName = strtolower(trim((string) $columnName));

            if ($tableName === '' || $columnName === '') {
                continue;
            }

            $tables[$tableName]['columns'][$columnName] = true;

            if ((string) $pk === 'SI') {
                $tables[$tableName]['primary'][] = $columnName;
            }
        }

        foreach ($tables as $tableName => $definition) {
            if (Schema::hasTable($tableName)) {
                continue;
            }

            $columns = array_keys($definition['columns'] ?? []);
            $primary = array_values(array_unique($definition['primary'] ?? []));
            $primaryLookup = array_fill_keys($primary, true);

            Schema::create($tableName, function (Blueprint $table) use ($columns, $primary, $primaryLookup) {
                foreach ($columns as $column) {
                    if (array_key_exists($column, $primaryLookup)) {
                        $table->string($column, 191);
                    } else {
                        $table->text($column)->nullable();
                    }
                }

                if (! empty($primary)) {
                    $table->primary($primary);
                }
            });
        }
    }

    public function down(): void
    {
        $path = base_path('260114 AppTabs.xlsx');

        if (! is_file($path)) {
            $path = base_path('../260114 AppTabs.xlsx');
        }

        if (! is_file($path)) {
            return;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('DICCIONARIO_DATOS');

        if ($sheet === null) {
            return;
        }

        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        $headers = $sheet->rangeToArray('A1:'.$highestCol.'1', null, true, false)[0] ?? [];
        $headerIndex = [];

        foreach ($headers as $i => $name) {
            if ($name !== null) {
                $headerIndex[(string) $name] = $i;
            }
        }

        if (! array_key_exists('nombre_tabla', $headerIndex)) {
            return;
        }

        $tables = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray('A'.$row.':'.$highestCol.$row, null, true, false)[0] ?? [];
            $tableName = $values[$headerIndex['nombre_tabla']] ?? null;

            if ($tableName === null) {
                continue;
            }

            $tableName = trim((string) $tableName);

            if ($tableName !== '') {
                $tables[$tableName] = true;
            }
        }

        foreach (array_keys($tables) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
