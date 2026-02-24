<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('riesgo_cat') || ! Schema::hasTable('ev_lugar_mst')) {
            return;
        }

        if (! Schema::hasColumn('riesgo_cat', 'rie-padre_id-fk')) {
            Schema::table('riesgo_cat', function (Blueprint $table) {
                $table->text('rie-padre_id-fk')->nullable()->after('rie-id');
                $table->index(['rie-padre_id-fk']);
            });
        }

        if (! Schema::hasColumn('riesgo_cat', 'rie-nivel')) {
            Schema::table('riesgo_cat', function (Blueprint $table) {
                $table->string('rie-nivel', 10)->nullable()->after('rie-padre_id-fk');
            });
        }

        if (! Schema::hasTable('ev_lugar_riesgo_trs')) {
            Schema::create('ev_lugar_riesgo_trs', function (Blueprint $table) {
                $table->string('ev_lu_rie-id', 191)->primary();
                $table->text('ev_lu_rie-tenant_id')->nullable();
                $table->text('ev_lu_rie-ev_lu_id-fk')->nullable();
                $table->text('ev_lu_rie-rie_id-fk')->nullable();
                $table->index(['ev_lu_rie-tenant_id']);
                $table->index(['ev_lu_rie-ev_lu_id-fk']);
                $table->index(['ev_lu_rie-rie_id-fk']);
            });
        }

        if (DB::table('ev_lugar_riesgo_trs')->count() > 0) {
            return;
        }

        $path = base_path('260213 Elements vulnerables revisats.xlsx');
        if (! is_file($path)) {
            $path = base_path('../260213 Elements vulnerables revisats.xlsx');
        }

        if (! is_file($path)) {
            throw new RuntimeException('No se encontró 260213 Elements vulnerables revisats.xlsx.');
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);

        $highestRow = $sheet->getHighestRow();
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $normalize = static function (?string $value): string {
            $text = trim((string) $value);
            if ($text === '') {
                return '';
            }
            $text = mb_strtolower($text);
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;
            $text = strtr($text, [
                'à' => 'a',
                'á' => 'a',
                'ä' => 'a',
                'â' => 'a',
                'è' => 'e',
                'é' => 'e',
                'ë' => 'e',
                'ê' => 'e',
                'ì' => 'i',
                'í' => 'i',
                'ï' => 'i',
                'î' => 'i',
                'ò' => 'o',
                'ó' => 'o',
                'ö' => 'o',
                'ô' => 'o',
                'ù' => 'u',
                'ú' => 'u',
                'ü' => 'u',
                'û' => 'u',
                'ç' => 'c',
                'ñ' => 'n',
            ]);

            return $text;
        };

        $detectBase = static function (string $normalized): ?string {
            if ($normalized === 'codi' || $normalized === 'cod') {
                return 'code';
            }
            if ($normalized === 'nom' || $normalized === 'nombre') {
                return 'name';
            }
            if (str_contains($normalized, 'adreca') || str_contains($normalized, 'direccion') || str_contains($normalized, 'direccio')) {
                return 'address';
            }
            if (str_contains($normalized, 'coordenad')) {
                return 'coords';
            }
            if (str_contains($normalized, 'mitja') || str_contains($normalized, 'avis') || str_contains($normalized, 'telefon')) {
                return 'contact';
            }

            return null;
        };

        $headerRow = 1;
        for ($row = 1; $row <= min(5, $highestRow); $row++) {
            $rowValues = $sheet->rangeToArray('A'.$row.':'.Coordinate::stringFromColumnIndex($highestCol).$row, null, true, false)[0] ?? [];
            $hasCode = false;
            $hasName = false;
            foreach ($rowValues as $cell) {
                $normalized = $normalize(is_string($cell) ? $cell : '');
                if ($normalized === 'codi' || $normalized === 'cod') {
                    $hasCode = true;
                }
                if ($normalized === 'nom' || $normalized === 'nombre') {
                    $hasName = true;
                }
            }
            if ($hasCode && $hasName) {
                $headerRow = $row;
                break;
            }
        }

        $headerRows = [
            max(1, $headerRow - 2),
            max(1, $headerRow - 1),
            $headerRow,
        ];

        $headersByRow = [];
        foreach ($headerRows as $row) {
            $values = $sheet->rangeToArray('A'.$row.':'.Coordinate::stringFromColumnIndex($highestCol).$row, null, true, false)[0] ?? [];
            $filled = [];
            $carry = '';
            for ($col = 0; $col < $highestCol; $col++) {
                $raw = $values[$col] ?? '';
                $text = trim((string) $raw);
                if ($text === '' && $carry !== '') {
                    $text = $carry;
                }
                if ($text !== '') {
                    $carry = $text;
                }
                $filled[$col] = $text;
            }
            $headersByRow[$row] = $filled;
        }

        $baseColumns = [];
        $riskColumns = [];
        $riskPaths = [];
        for ($col = 0; $col < $highestCol; $col++) {
            $label = $headersByRow[$headerRow][$col] ?? '';
            $normalized = $normalize($label);
            $baseType = $detectBase($normalized);
            if ($baseType !== null) {
                $baseColumns[$baseType] = $col;

                continue;
            }

            $labels = [];
            foreach ($headerRows as $row) {
                $value = $headersByRow[$row][$col] ?? '';
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $normalizedValue = $normalize($value);
                if ($detectBase($normalizedValue) !== null) {
                    continue;
                }
                $labels[] = $value;
            }

            $labels = array_values(array_unique($labels));
            if (empty($labels)) {
                continue;
            }

            $pathKey = implode(' > ', $labels);
            $riskColumns[$col] = $labels;
            $riskPaths[$pathKey] = $labels;
        }

        $tenantId = null;
        if (Schema::hasTable('tenants')) {
            $tenantId = DB::table('tenants')->value('tenant_id');
        }

        $riskIdMap = [];
        $riskNameParentMap = [];
        $makeRiskId = static function (string $name, ?string $parentId): string {
            $base = Str::slug($name, '_');
            if ($base === '') {
                $base = 'riesgo';
            }
            $hash = substr(sha1(($parentId ?? '').'|'.$name), 0, 6);
            $id = 'RIEV_'.strtoupper($base).'_'.$hash;

            return strlen($id) > 180 ? substr($id, 0, 180) : $id;
        };

        $extractNameAndCode = static function (string $name): array {
            $name = trim($name);
            if (preg_match('/^(.+)\(([^)]+)\)\s*$/u', $name, $m) === 1) {
                $cleanName = trim($m[1] ?? '');
                $code = trim($m[2] ?? '');
                if ($cleanName !== '' && $code !== '') {
                    return [$cleanName, $code];
                }
            }

            return [$name, ''];
        };

        $ensureRisk = function (string $name, ?string $parentId, int $level) use (&$riskNameParentMap, $tenantId, $makeRiskId, $extractNameAndCode): string {
            $key = ($parentId ?? 'root').'|'.$name;
            if (isset($riskNameParentMap[$key])) {
                return $riskNameParentMap[$key];
            }

            $query = DB::table('riesgo_cat')->select('rie-id')->where('rie-nombre', $name);
            if (Schema::hasColumn('riesgo_cat', 'rie-padre_id-fk')) {
                if ($parentId === null) {
                    $query->whereNull('rie-padre_id-fk');
                } else {
                    $query->where('rie-padre_id-fk', $parentId);
                }
            }
            if ($tenantId !== null && Schema::hasColumn('riesgo_cat', 'rie-tenant_id')) {
                $query->where(function ($q) use ($tenantId) {
                    $q->whereNull('rie-tenant_id')->orWhere('rie-tenant_id', $tenantId);
                });
            }
            $existingId = $query->value('rie-id');
            if (is_string($existingId) && trim($existingId) !== '') {
                $riskNameParentMap[$key] = $existingId;
                DB::table('riesgo_cat')->where('rie-id', $existingId)->update([
                    'rie-padre_id-fk' => $parentId,
                    'rie-nivel' => (string) $level,
                ]);

                return $existingId;
            }

            [$finalName, $code] = $extractNameAndCode($name);
            $id = $makeRiskId($finalName, $parentId);
            $insert = [
                'rie-id' => $id,
                'rie-tenant_id' => $tenantId,
                'rie-cod' => $code !== '' ? $code : strtoupper(Str::slug($finalName, '_')),
                'rie-nombre' => $finalName,
                'rie-activo' => 'SI',
                'rie-padre_id-fk' => $parentId,
                'rie-nivel' => (string) $level,
            ];
            DB::table('riesgo_cat')->insert($insert);
            $riskNameParentMap[$key] = $id;

            return $id;
        };

        $riskColumnIdMap = [];
        foreach ($riskPaths as $pathKey => $labels) {
            $parentId = null;
            $level = 0;
            foreach ($labels as $label) {
                $id = $ensureRisk($label, $parentId, $level);
                $parentId = $id;
                $level++;
            }
            $riskColumnIdMap[$pathKey] = $parentId;
        }

        $existingIds = DB::table('ev_lugar_mst')->pluck('ev_lu-id')->all();
        $max = 0;
        foreach ($existingIds as $id) {
            $id = strtoupper(trim((string) $id));
            if (preg_match('/^EVL(\d+)$/', $id, $m) === 1) {
                $n = (int) ($m[1] ?? 0);
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
        $nextEvId = $max + 1;

        $existingRelations = [];
        $dataStart = $headerRow + 1;
        for ($row = $dataStart; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray('A'.$row.':'.Coordinate::stringFromColumnIndex($highestCol).$row, null, true, false)[0] ?? [];
            $code = '';
            if (isset($baseColumns['code'])) {
                $code = trim((string) ($values[$baseColumns['code']] ?? ''));
            }
            $name = '';
            if (isset($baseColumns['name'])) {
                $name = trim((string) ($values[$baseColumns['name']] ?? ''));
            }

            if ($code === '' && $name === '') {
                continue;
            }

            $address = '';
            if (isset($baseColumns['address'])) {
                $address = trim((string) ($values[$baseColumns['address']] ?? ''));
            }

            $coordText = '';
            if (isset($baseColumns['coords'])) {
                $coordText = trim((string) ($values[$baseColumns['coords']] ?? ''));
            }

            $contactText = '';
            if (isset($baseColumns['contact'])) {
                $contactText = trim((string) ($values[$baseColumns['contact']] ?? ''));
            }

            $elementQuery = DB::table('ev_lugar_mst')->select('ev_lu-id');
            if ($code !== '') {
                $elementQuery->where('ev_lu-cod', $code);
            } else {
                $elementQuery->where('ev_lu-nombre', $name);
            }
            if ($tenantId !== null) {
                $elementQuery->where(function ($q) use ($tenantId) {
                    $q->whereNull('ev_lu-tenant_id')->orWhere('ev_lu-tenant_id', $tenantId);
                });
            }
            $elementId = $elementQuery->value('ev_lu-id');

            if (! is_string($elementId) || trim($elementId) === '') {
                $elementId = 'EVL'.str_pad((string) $nextEvId, 3, '0', STR_PAD_LEFT);
                $nextEvId++;
                DB::table('ev_lugar_mst')->insert([
                    'ev_lu-id' => $elementId,
                    'ev_lu-tenant_id' => $tenantId,
                    'ev_lu-cod' => $code !== '' ? $code : $elementId,
                    'ev_lu-nombre' => $name,
                    'ev_lu-direccion' => $address !== '' ? $address : null,
                    'ev_lu-activo' => 'SI',
                ]);
            } else {
                DB::table('ev_lugar_mst')->where('ev_lu-id', $elementId)->update([
                    'ev_lu-tenant_id' => $tenantId,
                    'ev_lu-cod' => $code !== '' ? $code : $elementId,
                    'ev_lu-nombre' => $name,
                    'ev_lu-direccion' => $address !== '' ? $address : null,
                ]);
            }

            if ($coordText !== '') {
                $coords = preg_split('/\s*,\s*/', $coordText);
                $este = $coords[0] ?? null;
                $norte = $coords[1] ?? null;
                DB::table('ev_lugar_coordenada_mst')->where('ev_lu_coo-ev_lu_id-fk', $elementId)->delete();
                if (is_string($este) && trim($este) !== '' && is_string($norte) && trim($norte) !== '') {
                    DB::table('ev_lugar_coordenada_mst')->insert([
                        'ev_lu_coo-id' => (string) Str::uuid(),
                        'ev_lu_coo-tenant_id' => $tenantId,
                        'ev_lu_coo-ev_lu_id-fk' => $elementId,
                        'ev_lu_coo-srid' => '4326',
                        'ev_lu_coo-este' => trim($este),
                        'ev_lu_coo-norte' => trim($norte),
                    ]);
                }
            }

            if ($contactText !== '') {
                $parts = preg_split('/[;,\/]+/', $contactText);
                DB::table('ev_lugar_contacto_mst')->where('ev_lu_con-ev_lu_id-fk', $elementId)->delete();
                foreach ($parts as $part) {
                    $part = trim((string) $part);
                    if ($part === '') {
                        continue;
                    }
                    $type = 'OTRO';
                    if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                        $type = 'EMAIL';
                    } elseif (filter_var($part, FILTER_VALIDATE_URL)) {
                        $type = 'WEB';
                    } elseif (preg_match('/\d+/', $part) === 1) {
                        $type = 'TELEFONO';
                    }
                    DB::table('ev_lugar_contacto_mst')->insert([
                        'ev_lu_con-id' => (string) Str::uuid(),
                        'ev_lu_con-tenant_id' => $tenantId,
                        'ev_lu_con-ev_lu_id-fk' => $elementId,
                        'ev_lu_con-tipo' => $type,
                        'ev_lu_con-valor' => $part,
                        'ev_lu_con-nota' => null,
                    ]);
                }
            }

            foreach ($riskColumns as $col => $labels) {
                $value = strtoupper(trim((string) ($values[$col] ?? '')));
                if ($value === '' || ! in_array($value, ['X', 'SI', '1', 'TRUE'], true)) {
                    continue;
                }
                $pathKey = implode(' > ', $labels);
                $riskId = $riskColumnIdMap[$pathKey] ?? null;
                if (! is_string($riskId) || $riskId === '') {
                    continue;
                }
                $relKey = $elementId.'|'.$riskId;
                if (isset($existingRelations[$relKey])) {
                    continue;
                }
                $existingRelations[$relKey] = true;
                DB::table('ev_lugar_riesgo_trs')->insert([
                    'ev_lu_rie-id' => (string) Str::uuid(),
                    'ev_lu_rie-tenant_id' => $tenantId,
                    'ev_lu_rie-ev_lu_id-fk' => $elementId,
                    'ev_lu_rie-rie_id-fk' => $riskId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ev_lugar_riesgo_trs')) {
            Schema::drop('ev_lugar_riesgo_trs');
        }

        if (Schema::hasTable('riesgo_cat')) {
            Schema::table('riesgo_cat', function (Blueprint $table) {
                if (Schema::hasColumn('riesgo_cat', 'rie-padre_id-fk')) {
                    $table->dropColumn('rie-padre_id-fk');
                }
                if (Schema::hasColumn('riesgo_cat', 'rie-nivel')) {
                    $table->dropColumn('rie-nivel');
                }
            });
        }
    }
};
