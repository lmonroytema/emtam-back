<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class TableSchemaService
{
    private const EXCLUDED_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
        'users',
        'tenants',
        'tenant_languages',
        'informacion_tablas',
    ];

    public function allowedTables(): array
    {
        $this->assertMysql();

        $db = DB::getDatabaseName();

        return DB::table('information_schema.tables')
            ->where('table_schema', $db)
            ->where('table_type', 'BASE TABLE')
            ->whereNotIn('table_name', self::EXCLUDED_TABLES)
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();
    }

    public function tableLabels(array $tables): array
    {
        return $this->tableLabelsForLocale($tables, 'es');
    }

    public function tableLabelsForLocale(array $tables, ?string $locale): array
    {
        $locale = $this->normalizeLocale($locale);

        $overridesEs = self::TABLE_LABEL_OVERRIDES['es'] ?? [];
        $overrides = self::TABLE_LABEL_OVERRIDES[$locale] ?? [];

        $index = $this->loadIndexFinalidadByTable();

        $labels = [];
        foreach ($tables as $table) {
            $key = strtolower(trim((string) $table));
            $label = $overrides[$key] ?? null;
            if (! is_string($label) || trim($label) === '') {
                $fallbackEs = $overridesEs[$key] ?? null;
                if (is_string($fallbackEs) && trim($fallbackEs) !== '') {
                    $label = $this->translateLabel($fallbackEs, $locale);
                }
            }

            if (! is_string($label) || trim($label) === '') {
                $finalidad = $index[$key] ?? null;
                if (is_string($finalidad) && trim($finalidad) !== '') {
                    $label = $this->translateLabel($this->shortLabelFromFinalidad($finalidad), $locale);
                } else {
                    $label = $this->translateLabel($this->humanizeTable((string) $table), $locale);
                }
            }

            $labels[(string) $table] = $label;
        }

        return $labels;
    }

    public function columns(string $table): array
    {
        $this->assertMysql();
        $this->assertAllowed($table);

        $db = DB::getDatabaseName();

        return DB::table('information_schema.columns')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
    }

    public function columnMeta(string $table): array
    {
        $this->assertMysql();
        $this->assertAllowed($table);

        $db = DB::getDatabaseName();

        $rows = DB::table('information_schema.columns')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->get([
                'column_name',
                'is_nullable',
                'data_type',
                'column_default',
                'extra',
                'character_maximum_length',
                'numeric_precision',
                'numeric_scale',
            ]);

        $meta = [];
        foreach ($rows as $r) {
            $col = (string) ($r->column_name ?? '');
            if ($col === '') {
                continue;
            }

            $meta[$col] = [
                'is_nullable' => (string) ($r->is_nullable ?? ''),
                'data_type' => (string) ($r->data_type ?? ''),
                'column_default' => $r->column_default,
                'extra' => (string) ($r->extra ?? ''),
                'character_maximum_length' => $r->character_maximum_length !== null ? (int) $r->character_maximum_length : null,
                'numeric_precision' => $r->numeric_precision !== null ? (int) $r->numeric_precision : null,
                'numeric_scale' => $r->numeric_scale !== null ? (int) $r->numeric_scale : null,
            ];
        }

        return $meta;
    }

    public function primaryKeyColumns(string $table): array
    {
        $this->assertMysql();
        $this->assertAllowed($table);

        $db = DB::getDatabaseName();

        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('constraint_name', 'PRIMARY')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
    }

    public function foreignKeys(string $table, array $columns): array
    {
        $this->assertMysql();
        $this->assertAllowed($table);

        $db = DB::getDatabaseName();

        $map = [];
        $rows = DB::table('information_schema.key_column_usage')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->whereNotNull('referenced_table_name')
            ->get(['column_name', 'referenced_table_name', 'referenced_column_name']);

        foreach ($rows as $r) {
            $column = (string) ($r->column_name ?? '');
            $refTable = (string) ($r->referenced_table_name ?? '');
            $refColumn = (string) ($r->referenced_column_name ?? '');
            if ($column === '' || $refTable === '' || $refColumn === '') {
                continue;
            }
            $map[$column] = [
                'referenced_table' => $refTable,
                'referenced_column' => $refColumn,
            ];
        }

        $tokenIndex = $this->loadPrimaryKeyTokenIndex();
        foreach ($columns as $col) {
            $col = (string) $col;
            if ($col === '' || array_key_exists($col, $map)) {
                continue;
            }
            $token = $this->inferTokenFromFkColumn($col);
            if ($token === null) {
                continue;
            }
            $ref = $tokenIndex[$token] ?? null;
            if (! is_array($ref)) {
                continue;
            }
            $refTable = (string) ($ref['table'] ?? '');
            $refColumn = (string) ($ref['column'] ?? '');
            if ($refTable === '' || $refColumn === '') {
                continue;
            }
            $map[$col] = [
                'referenced_table' => $refTable,
                'referenced_column' => $refColumn,
            ];
        }

        return $map;
    }

    public function tenantColumn(?array $columns): ?string
    {
        if (empty($columns)) {
            return null;
        }

        if (in_array('id_tenant', $columns, true)) {
            return 'id_tenant';
        }

        if (in_array('tenant_id', $columns, true)) {
            return 'tenant_id';
        }

        foreach ($columns as $column) {
            if (str_ends_with($column, 'tenant_id')) {
                return $column;
            }
        }

        return null;
    }

    public function columnLabels(string $table, array $columns): array
    {
        return $this->columnLabelsForLocale($table, $columns, 'es');
    }

    public function columnLabelsForLocale(string $table, array $columns, ?string $locale): array
    {
        $locale = $this->normalizeLocale($locale);

        $dict = $this->loadExcelDictionary();
        $byTable = $dict[strtolower($table)] ?? [];

        $labels = [];
        foreach ($columns as $col) {
            $key = strtolower((string) $col);
            $label = $byTable[$key] ?? null;
            if (! is_string($label) || trim($label) === '') {
                $label = $this->humanizeColumn((string) $col);
            }
            $labels[(string) $col] = $this->translateLabel((string) $label, $locale);
        }

        return $labels;
    }

    public function normalizeLocale(?string $localeOrHeader): string
    {
        if (! is_string($localeOrHeader) || trim($localeOrHeader) === '') {
            return 'es';
        }

        $raw = strtolower(trim((string) $localeOrHeader));
        $raw = explode(',', $raw, 2)[0] ?? $raw;
        $raw = trim($raw);

        if (str_starts_with($raw, 'ca')) {
            return 'ca';
        }
        if (str_starts_with($raw, 'en')) {
            return 'en';
        }

        return 'es';
    }

    public function assertAllowed(string $table): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $table)) {
            abort(404);
        }

        if (! in_array($table, $this->allowedTables(), true)) {
            abort(404);
        }
    }

    private function assertMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            abort(500, 'This endpoint requires MySQL.');
        }
    }

    private function loadPrimaryKeyTokenIndex(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $index = [];
        foreach ($this->allowedTables() as $table) {
            $pks = $this->primaryKeyColumnsUnsafe((string) $table);
            foreach ($pks as $pk) {
                $token = $this->tokenFromPrimaryKey((string) $pk);
                if ($token === null) {
                    continue;
                }
                if (array_key_exists($token, $index)) {
                    continue;
                }
                $index[$token] = [
                    'table' => (string) $table,
                    'column' => (string) $pk,
                ];
            }
        }

        $cache = $index;

        return $cache;
    }

    private function primaryKeyColumnsUnsafe(string $table): array
    {
        $db = DB::getDatabaseName();

        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('constraint_name', 'PRIMARY')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
    }

    private function tokenFromPrimaryKey(string $pkColumn): ?string
    {
        $pk = strtolower(trim($pkColumn));
        if ($pk === '') {
            return null;
        }
        if (preg_match('/^([a-z0-9_]+)-id$/', $pk, $m)) {
            return (string) ($m[1] ?? '');
        }

        return null;
    }

    private function inferTokenFromFkColumn(string $column): ?string
    {
        $col = strtolower(trim($column));
        if ($col === '') {
            return null;
        }

        if (preg_match('/([a-z0-9_]+)_id-fk\b/', $col, $m)) {
            return (string) ($m[1] ?? '');
        }
        if (preg_match('/([a-z0-9_]+)_id_fk\b/', $col, $m)) {
            return (string) ($m[1] ?? '');
        }

        return null;
    }

    private function loadIndexFinalidadByTable(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $paths = [
            base_path('260115 AppTabs.xlsx'),
            base_path('../260115 AppTabs.xlsx'),
            base_path('260114 AppTabs.xlsx'),
            base_path('../260114 AppTabs.xlsx'),
        ];

        $path = null;
        foreach ($paths as $p) {
            if (is_file($p)) {
                $path = $p;
                break;
            }
        }

        if ($path === null) {
            $cache = [];

            return $cache;
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            $cache = [];

            return $cache;
        }

        $sheet = $spreadsheet->getSheetByName('INDICE');
        if ($sheet === null) {
            $cache = [];

            return $cache;
        }

        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();

        $headers = $sheet->rangeToArray('A1:'.$highestCol.'1', null, true, false)[0] ?? [];
        $headerIndex = [];
        foreach ($headers as $i => $name) {
            if ($name === null) {
                continue;
            }
            $key = strtolower(trim((string) $name));
            $key = trim(preg_replace('/\s+/', ' ', $key) ?? $key);
            if ($key === '') {
                continue;
            }
            $headerIndex[$key] = $i;
        }

        $tableIdx = null;
        $finalidadIdx = null;
        foreach ($headerIndex as $k => $i) {
            if ($tableIdx === null && str_starts_with($k, 'nombre de tabla')) {
                $tableIdx = $i;
            }
            if ($finalidadIdx === null && str_starts_with($k, 'finalidad')) {
                $finalidadIdx = $i;
            }
        }

        if ($tableIdx === null || $finalidadIdx === null) {
            $cache = [];

            return $cache;
        }

        $map = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray('A'.$row.':'.$highestCol.$row, null, true, false)[0] ?? [];
            $table = $values[$tableIdx] ?? null;
            $finalidad = $values[$finalidadIdx] ?? null;

            if ($table === null || $finalidad === null) {
                continue;
            }

            $tableKey = strtolower(trim((string) $table));
            $finalidadText = trim((string) $finalidad);
            $finalidadText = trim(preg_replace('/\s+/', ' ', $finalidadText) ?? $finalidadText);
            $finalidadText = rtrim($finalidadText, '.');

            if ($tableKey === '' || $finalidadText === '') {
                continue;
            }

            $map[$tableKey] = $finalidadText;
        }

        $cache = $map;

        return $cache;
    }

    private function loadExcelDictionary(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $paths = [
            base_path('260115 AppTabs.xlsx'),
            base_path('../260115 AppTabs.xlsx'),
            base_path('260114 AppTabs.xlsx'),
            base_path('../260114 AppTabs.xlsx'),
        ];

        $path = null;
        foreach ($paths as $p) {
            if (is_file($p)) {
                $path = $p;
                break;
            }
        }

        if ($path === null) {
            $cache = [];

            return $cache;
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            $cache = [];

            return $cache;
        }

        $sheet = $spreadsheet->getSheetByName('DICCIONARIO_DATOS');
        if ($sheet === null) {
            $cache = [];

            return $cache;
        }

        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();

        $headers = $sheet->rangeToArray('A1:'.$highestCol.'1', null, true, false)[0] ?? [];
        $headerIndex = [];
        foreach ($headers as $i => $name) {
            if ($name === null) {
                continue;
            }
            $key = strtolower(trim((string) $name));
            if ($key === '') {
                continue;
            }
            $headerIndex[$key] = $i;
        }

        if (! array_key_exists('nombre_tabla', $headerIndex) || ! array_key_exists('nom_campo_nuevo', $headerIndex)) {
            $cache = [];

            return $cache;
        }

        $labelIndex = $headerIndex['descripcion_recomendada'] ?? null;
        $map = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $values = $sheet->rangeToArray('A'.$row.':'.$highestCol.$row, null, true, false)[0] ?? [];
            $tableName = $values[$headerIndex['nombre_tabla']] ?? null;
            $columnName = $values[$headerIndex['nom_campo_nuevo']] ?? null;

            if ($tableName === null || $columnName === null) {
                continue;
            }

            $tableKey = strtolower(trim((string) $tableName));
            $columnKey = strtolower(trim((string) $columnName));
            if ($tableKey === '' || $columnKey === '') {
                continue;
            }

            $label = null;
            if ($labelIndex !== null) {
                $candidate = $values[$labelIndex] ?? null;
                if ($candidate !== null) {
                    $clean = trim((string) $candidate);
                    $clean = trim(preg_replace('/\\s+/', ' ', $clean) ?? $clean);
                    $clean = rtrim($clean, '.');
                    if ($clean !== '') {
                        $label = $clean;
                    }
                }
            }

            if ($label === null) {
                continue;
            }

            $map[$tableKey] ??= [];
            $map[$tableKey][$columnKey] = $label;
        }

        $cache = $map;

        return $cache;
    }

    private function shortLabelFromFinalidad(string $finalidad): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $finalidad) ?? $finalidad);
        $text = preg_replace('/\([^)]*\)/u', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $text = rtrim($text, '.');

        $firstSentence = preg_split('/[.!?]/u', $text, 2)[0] ?? $text;
        $firstSentence = trim((string) $firstSentence);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $firstSentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = array_fill_keys([
            'a', 'al', 'con', 'como', 'de', 'del', 'dentro', 'el', 'en', 'es', 'esta', 'este', 'estos', 'estas',
            'la', 'las', 'lo', 'los', 'o', 'para', 'por', 'que', 'se', 'sin', 'su', 'sus', 'un', 'una', 'uno',
            'y', 'e', 'u', 'the', 'of', 'to', 'in', 'within', 'model', 'modelo',
        ], true);

        $filtered = [];
        foreach ($words as $w) {
            $lw = strtolower((string) $w);
            if (array_key_exists($lw, $stop)) {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($lw, 'UTF-8') : strlen($lw);
            if ($len < 3 && ! ctype_digit($lw)) {
                continue;
            }
            $filtered[] = (string) $w;
        }

        $pick = array_slice($filtered, 0, 4);
        if (empty($pick)) {
            $pick = array_slice($words, 0, 4);
        }

        $label = trim(implode(' ', $pick));
        if ($label === '') {
            return trim($finalidad) !== '' ? trim($finalidad) : 'Tabla';
        }

        if (function_exists('mb_convert_case')) {
            return (string) mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
        }

        $parts = preg_split('/\s+/', $label) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $out[] = ucfirst(strtolower($p));
        }

        return implode(' ', $out);
    }

    private function translateLabel(string $text, string $locale): string
    {
        $t = trim($text);
        if ($t === '' || $locale === 'es') {
            return $text;
        }

        $full = self::FULL_LABEL_TRANSLATIONS[$locale][$t] ?? null;
        if (is_string($full) && trim($full) !== '') {
            return $full;
        }

        $out = $t;
        foreach (self::WORD_TRANSLATIONS[$locale] ?? [] as $from => $to) {
            $pattern = '/\b'.preg_quote((string) $from, '/').'\b/iu';
            $out = preg_replace_callback($pattern, function (array $m) use ($to) {
                $orig = (string) ($m[0] ?? '');
                $rep = (string) $to;

                if ($orig !== '' && strtoupper($orig) === $orig) {
                    return strtoupper($rep);
                }

                $first = function_exists('mb_substr') ? mb_substr($orig, 0, 1, 'UTF-8') : substr($orig, 0, 1);
                if ($first !== '' && strtoupper($first) === $first) {
                    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
                        return mb_strtoupper(mb_substr($rep, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($rep, 1, null, 'UTF-8');
                    }

                    return ucfirst($rep);
                }

                if (function_exists('mb_strtolower')) {
                    return mb_strtolower($rep, 'UTF-8');
                }

                return strtolower($rep);
            }, $out) ?? $out;
        }

        return $out;
    }

    private function humanizeTable(string $table): string
    {
        $t = trim($table);
        if ($t === '') {
            return $table;
        }

        $base = $t;
        foreach (['_cfg', '_cat', '_mst', '_trs'] as $suffix) {
            if (str_ends_with($base, $suffix)) {
                $base = substr($base, 0, -strlen($suffix));
                break;
            }
        }

        $base = str_replace('_', ' ', $base);
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? $base);
        if ($base === '') {
            return $table;
        }

        if (function_exists('mb_convert_case')) {
            return (string) mb_convert_case($base, MB_CASE_TITLE, 'UTF-8');
        }

        $parts = preg_split('/\s+/', $base) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $out[] = ucfirst(strtolower($p));
        }

        return implode(' ', $out);
    }

    private function humanizeColumn(string $column): string
    {
        $col = trim($column);
        if ($col === '') {
            return $column;
        }

        $base = $col;
        if (str_contains($base, '-')) {
            $parts = explode('-', $base);
            $base = (string) end($parts);
        }

        $base = str_replace(['_', '-'], ' ', $base);
        $base = preg_replace('/\\s+/', ' ', $base) ?? $base;
        $base = trim($base);
        if ($base === '') {
            return $column;
        }

        $tokens = array_map('strtolower', explode(' ', $base));
        $mapped = [];
        foreach ($tokens as $t) {
            $mapped[] = match ($t) {
                'id' => 'ID',
                'cod' => 'Código',
                'nombre' => 'Nombre',
                'descrip', 'descripcion' => 'Descripción',
                'activo' => 'Activo',
                'orden' => 'Orden',
                'tenant', 'tenantid', 'tenant_id' => 'Tenant',
                'fk' => 'FK',
                'ts' => 'Fecha/hora',
                'fech', 'fecha' => 'Fecha',
                'hora' => 'Hora',
                default => ucfirst($t),
            };
        }

        return implode(' ', $mapped);
    }

    private const TABLE_LABEL_OVERRIDES = [
        'es' => [
            'persona_mst' => 'Personas',
            'ev_lugar_mst' => 'Elementos vulnerables',
            'ev_lugar_contacto_mst' => 'Contactos del elemento vulnerable',
            'ev_lugar_coordenada_mst' => 'Coordenadas elemento vulnerable',
            'tipo_emergencia_cat' => 'Tipo de emergencia',
            'tipo_riesgo_cat' => 'Tipo de riesgo',
            'riesgo_cat' => 'Riesgos',
            'rol_cat' => 'Roles',
            'grupo_operativo_cat' => 'Grupo operativo',
            'nivel_emergencia_cat' => 'Nivel de emergencia',
            'nivel_alerta_cat' => 'Nivel de alerta',
            'fase_activacion_cat' => 'Fase de activación',
            'canal_comunicacion_cat' => 'Canal de comunicación',
            'lugar_tipo_cat' => 'Tipo de lugares',
            'dominio_enum_cat' => 'Dominios valores posibles',
            'persona_rol_cfg' => 'Rol de personas',
            'persona_rol_grupo_cfg' => 'Relación personas rol',
            'accion_operativa_cfg' => 'Acción operativa',
            'criterio_riesgo_cfg' => 'Criterios de riesgo',
            'riesgo_nivel_accion_set_cfg' => 'Nivel de riesgo - Set de acciones',
            'tipo_riesgo_nivel_accion_set_cfg' => 'Nivel tipo riesgo - Set de acciones',
            'accion_set_cfg' => 'Set de acciones',
            'accion_set_detalle_cfg' => 'Set de acciones detalladas',
            'accion_set_detalle_canal_cfg' => 'Acciones detalladas - canales comunicación',
            'directorio_grupo_cat' => 'Grupos directorio telefónico',
            'directorio_contacto_mst' => 'Directorio telefónico',
            'grupos_directorio_cfg' => 'Grupos directorio dinámico',
            'dato_grupo_directorio_cfg' => 'Cabeceras directorio dinámico',
            'dato_directorio_cat' => 'Datos directorio dinámico',
            'activacion_del_plan_trs' => 'Activación de Plan',
            'activacion_nivel_hist_trs' => 'Historial de activación de Plan',
            'asignacion_en_funciones_trs' => 'Asignación de funciones',
            'notificacion_envio_trs' => 'Notificaciones envío',
            'notificacion_confirmacion_trs' => 'Confirmación de notificaciones',
            'ejecucion_accion_trs' => 'Ejecución acción',
            'notas_operativas_trs' => 'Notas operativas',
            'cronologia_emergencia_trs' => 'Cronología Emergencia',
        ],
        'ca' => [
            'persona_mst' => 'Persones',
            'ev_lugar_mst' => 'Elements vulnerables',
            'ev_lugar_contacto_mst' => "Contactes de l'element vulnerable",
            'ev_lugar_coordenada_mst' => "Coordenades de l'element vulnerable",
            'tipo_emergencia_cat' => "Tipus d'emergència",
            'tipo_riesgo_cat' => 'Tipus de risc',
            'riesgo_cat' => 'Riscos',
            'rol_cat' => 'Rols',
            'grupo_operativo_cat' => 'Grup operatiu',
            'nivel_emergencia_cat' => "Nivell d'emergència",
            'nivel_alerta_cat' => "Nivell d'alerta",
            'fase_activacion_cat' => "Fase d'activació",
            'canal_comunicacion_cat' => 'Canal de comunicació',
            'lugar_tipo_cat' => 'Tipus de llocs',
            'dominio_enum_cat' => 'Dominis de valors possibles',
            'persona_rol_cfg' => 'Rol de persones',
            'persona_rol_grupo_cfg' => 'Relació persones-rol',
            'accion_operativa_cfg' => 'Acció operativa',
            'criterio_riesgo_cfg' => 'Criteris de risc',
            'riesgo_nivel_accion_set_cfg' => "Nivell de risc - Conjunt d'accions",
            'tipo_riesgo_nivel_accion_set_cfg' => "Nivell de tipus de risc - Conjunt d'accions",
            'accion_set_cfg' => "Conjunt d'accions",
            'accion_set_detalle_cfg' => "Conjunt d'accions detallades",
            'accion_set_detalle_canal_cfg' => 'Accions detallades - canals de comunicació',
            'directorio_grupo_cat' => 'Grups directori telefònic',
            'directorio_contacto_mst' => 'Directori telefònic',
            'grupos_directorio_cfg' => 'Grups directori dinàmic',
            'dato_grupo_directorio_cfg' => 'Capçaleres directori dinàmic',
            'dato_directorio_cat' => 'Dades directori dinàmic',
            'activacion_del_plan_trs' => 'Activació del pla',
            'activacion_nivel_hist_trs' => "Historial d'activació del pla",
            'asignacion_en_funciones_trs' => 'Assignació de funcions',
            'notificacion_envio_trs' => 'Enviament de notificacions',
            'notificacion_confirmacion_trs' => 'Confirmació de notificacions',
            'ejecucion_accion_trs' => "Execució d'acció",
            'notas_operativas_trs' => 'Notes operatives',
            'cronologia_emergencia_trs' => "Cronologia d'emergència",
        ],
        'en' => [
            'persona_mst' => 'People',
            'ev_lugar_mst' => 'Vulnerable Elements',
            'ev_lugar_contacto_mst' => 'Vulnerable Element Contacts',
            'ev_lugar_coordenada_mst' => 'Vulnerable Element Coordinates',
            'tipo_emergencia_cat' => 'Emergency Type',
            'tipo_riesgo_cat' => 'Risk Type',
            'riesgo_cat' => 'Risks',
            'rol_cat' => 'Roles',
            'grupo_operativo_cat' => 'Operational Group',
            'nivel_emergencia_cat' => 'Emergency Level',
            'nivel_alerta_cat' => 'Alert Level',
            'fase_activacion_cat' => 'Activation Phase',
            'canal_comunicacion_cat' => 'Communication Channel',
            'lugar_tipo_cat' => 'Place Types',
            'dominio_enum_cat' => 'Possible Values Domains',
            'persona_rol_cfg' => 'People Roles',
            'persona_rol_grupo_cfg' => 'People-Role Relationship',
            'accion_operativa_cfg' => 'Operational Action',
            'criterio_riesgo_cfg' => 'Risk Criteria',
            'riesgo_nivel_accion_set_cfg' => 'Risk Level - Action Set',
            'tipo_riesgo_nivel_accion_set_cfg' => 'Risk Type Level - Action Set',
            'accion_set_cfg' => 'Action Set',
            'accion_set_detalle_cfg' => 'Detailed Action Set',
            'accion_set_detalle_canal_cfg' => 'Detailed Actions - Communication Channels',
            'directorio_grupo_cat' => 'Phone Directory Groups',
            'directorio_contacto_mst' => 'Phone Directory',
            'grupos_directorio_cfg' => 'Dynamic Directory Groups',
            'dato_grupo_directorio_cfg' => 'Dynamic Directory Headers',
            'dato_directorio_cat' => 'Dynamic Directory Data',
            'activacion_del_plan_trs' => 'Plan Activation',
            'activacion_nivel_hist_trs' => 'Plan Activation History',
            'asignacion_en_funciones_trs' => 'Function Assignment',
            'notificacion_envio_trs' => 'Notification Sending',
            'notificacion_confirmacion_trs' => 'Notification Confirmation',
            'ejecucion_accion_trs' => 'Action Execution',
            'notas_operativas_trs' => 'Operational Notes',
            'cronologia_emergencia_trs' => 'Emergency Timeline',
        ],
    ];

    private const FULL_LABEL_TRANSLATIONS = [
        'ca' => [
            'Código' => 'Codi',
            'Nombre' => 'Nom',
            'Descripción' => 'Descripció',
            'Activo' => 'Actiu',
            'Orden' => 'Ordre',
            'Fecha' => 'Data',
            'Hora' => 'Hora',
            'Fecha/hora' => 'Data/hora',
            'Estado' => 'Estat',
        ],
        'en' => [
            'Código' => 'Code',
            'Nombre' => 'Name',
            'Descripción' => 'Description',
            'Activo' => 'Active',
            'Orden' => 'Order',
            'Fecha' => 'Date',
            'Hora' => 'Time',
            'Fecha/hora' => 'Date/Time',
            'Estado' => 'Status',
        ],
    ];

    private const WORD_TRANSLATIONS = [
        'ca' => [
            'Código' => 'Codi',
            'Nombre' => 'Nom',
            'Descripción' => 'Descripció',
            'Activo' => 'Actiu',
            'Orden' => 'Ordre',
            'Fecha/hora' => 'Data/hora',
            'Fecha' => 'Data',
            'Hora' => 'Hora',
            'Dirección' => 'Adreça',
            'Email' => 'Correu',
            'Teléfono' => 'Telèfon',
            'Rol' => 'Rol',
            'Roles' => 'Rols',
            'Grupo' => 'Grup',
            'Operativo' => 'Operatiu',
            'Riesgo' => 'Risc',
            'Riesgos' => 'Riscos',
            'Emergencia' => 'Emergència',
            'Alerta' => 'Alerta',
            'Nivel' => 'Nivell',
            'Fase' => 'Fase',
            'Activación' => 'Activació',
            'Plan' => 'Pla',
            'Canal' => 'Canal',
            'Comunicación' => 'Comunicació',
            'Contacto' => 'Contacte',
            'Contactos' => 'Contactes',
            'Coordenadas' => 'Coordenades',
            'Personas' => 'Persones',
            'Persona' => 'Persona',
            'Acción' => 'Acció',
            'Acciones' => 'Accions',
            'Historial' => 'Historial',
            'Cronología' => 'Cronologia',
            'Estado' => 'Estat',
            'Observación' => 'Observació',
            'Observaciones' => 'Observacions',
            'Notas' => 'Notes',
        ],
        'en' => [
            'Código' => 'Code',
            'Nombre' => 'Name',
            'Descripción' => 'Description',
            'Activo' => 'Active',
            'Orden' => 'Order',
            'Fecha/hora' => 'Date/Time',
            'Fecha' => 'Date',
            'Hora' => 'Time',
            'Dirección' => 'Address',
            'Email' => 'Email',
            'Teléfono' => 'Phone',
            'Rol' => 'Role',
            'Roles' => 'Roles',
            'Grupo' => 'Group',
            'Operativo' => 'Operational',
            'Riesgo' => 'Risk',
            'Riesgos' => 'Risks',
            'Emergencia' => 'Emergency',
            'Alerta' => 'Alert',
            'Nivel' => 'Level',
            'Fase' => 'Phase',
            'Activación' => 'Activation',
            'Plan' => 'Plan',
            'Canal' => 'Channel',
            'Comunicación' => 'Communication',
            'Contacto' => 'Contact',
            'Contactos' => 'Contacts',
            'Coordenadas' => 'Coordinates',
            'Personas' => 'People',
            'Persona' => 'Person',
            'Acción' => 'Action',
            'Acciones' => 'Actions',
            'Historial' => 'History',
            'Cronología' => 'Timeline',
            'Estado' => 'Status',
            'Observación' => 'Note',
            'Observaciones' => 'Notes',
            'Notas' => 'Notes',
        ],
    ];
}
