<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TableSchemaService;
use App\Services\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use JsonException;

class TableCrudController extends Controller
{
    public function __construct(
        private readonly TableSchemaService $schema,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function tables(Request $request): JsonResponse
    {
        $tables = $this->schema->allowedTables();
        $locale = $this->schema->normalizeLocale($request->header('Accept-Language'));

        return response()->json([
            'tables' => $tables,
            'labels' => $this->schema->tableLabelsForLocale($tables, $locale),
        ]);
    }

    public function schema(Request $request, string $table): JsonResponse
    {
        $this->schema->assertAllowed($table);

        $columns = $this->schema->columns($table);
        $columnMeta = $this->schema->columnMeta($table);
        $pkColumns = $this->schema->primaryKeyColumns($table);
        $tenantColumn = $this->schema->tenantColumn($columns);
        $locale = $this->schema->normalizeLocale($request->header('Accept-Language'));
        $labels = $this->schema->columnLabelsForLocale($table, $columns, $locale);
        $foreignKeys = $this->schema->foreignKeys($table, $columns);

        $metadata = null;
        if (Schema::hasTable('informacion_tablas')) {
            $hasContenidoCa = Schema::hasColumn('informacion_tablas', 'contenido_ca');
            $hasFinalidadCa = Schema::hasColumn('informacion_tablas', 'finalidad_ca');
            $hasContenidoEn = Schema::hasColumn('informacion_tablas', 'contenido_en');
            $hasFinalidadEn = Schema::hasColumn('informacion_tablas', 'finalidad_en');
            $select = ['contenido', 'finalidad'];
            if ($hasContenidoCa) {
                $select[] = 'contenido_ca';
            }
            if ($hasFinalidadCa) {
                $select[] = 'finalidad_ca';
            }
            if ($hasContenidoEn) {
                $select[] = 'contenido_en';
            }
            if ($hasFinalidadEn) {
                $select[] = 'finalidad_en';
            }
            $row = DB::table('informacion_tablas')
                ->where('nombre_tabla', $table)
                ->first($select);

            if ($row !== null) {
                $contenidoEs = is_string($row->contenido ?? null) ? trim((string) $row->contenido) : '';
                $finalidadEs = is_string($row->finalidad ?? null) ? trim((string) $row->finalidad) : '';
                $contenidoCa = $hasContenidoCa && is_string($row->contenido_ca ?? null) ? trim((string) $row->contenido_ca) : '';
                $finalidadCa = $hasFinalidadCa && is_string($row->finalidad_ca ?? null) ? trim((string) $row->finalidad_ca) : '';
                $contenidoEn = $hasContenidoEn && is_string($row->contenido_en ?? null) ? trim((string) $row->contenido_en) : '';
                $finalidadEn = $hasFinalidadEn && is_string($row->finalidad_en ?? null) ? trim((string) $row->finalidad_en) : '';
                $contenido = match ($locale) {
                    'ca' => $contenidoCa !== '' ? $contenidoCa : $contenidoEs,
                    'en' => $contenidoEn !== '' ? $contenidoEn : $contenidoEs,
                    default => $contenidoEs,
                };
                $finalidad = match ($locale) {
                    'ca' => $finalidadCa !== '' ? $finalidadCa : $finalidadEs,
                    'en' => $finalidadEn !== '' ? $finalidadEn : $finalidadEs,
                    default => $finalidadEs,
                };
                $metadata = [
                    'contenido' => $contenido !== '' ? $contenido : null,
                    'finalidad' => $finalidad !== '' ? $finalidad : null,
                ];
            }
        }

        return response()->json([
            'table' => $table,
            'columns' => $columns,
            'column_meta' => $columnMeta,
            'primary_key' => $pkColumns,
            'tenant_column' => $tenantColumn,
            'labels' => $labels,
            'foreign_keys' => $foreignKeys,
            'metadata' => $metadata,
        ]);
    }

    public function index(Request $request, string $table): JsonResponse
    {
        $this->schema->assertAllowed($table);

        $columns = $this->schema->columns($table);
        $tenantColumn = $this->schema->tenantColumn($columns);

        $query = DB::table($table);
        $this->applyTenantScope($query, $tenantColumn);

        $perPage = (int) $request->query('per_page', 5);
        $perPage = max(1, min(500, $perPage));

        $filters = Arr::except($request->query(), ['page', 'per_page']);
        foreach ($filters as $key => $value) {
            if (in_array($key, $columns, true)) {
                $query->where($key, $value);
            }
        }

        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();

        if ($table === 'accion_operativa_cfg' && in_array('ac_op-id', $columns, true)) {
            $driver = DB::connection()->getDriverName();
            $expr = match ($driver) {
                'pgsql' => 'CAST(SUBSTRING("ac_op-id" FROM 3) AS INTEGER)',
                'sqlsrv' => 'CAST(SUBSTRING([ac_op-id], 3, 50) AS INT)',
                'sqlite' => 'CAST(SUBSTR("ac_op-id", 3) AS INTEGER)',
                default => 'CAST(SUBSTR(`ac_op-id`, 3) AS UNSIGNED)',
            };

            $query->orderByRaw($expr.' ASC')->orderBy('ac_op-id');
        }

        if ($table === 'accion_set_detalle_cfg') {
            $driver = DB::connection()->getDriverName();

            if (in_array('ac_se_de-ord_ejec', $columns, true)) {
                $ordExpr = match ($driver) {
                    'pgsql' => 'CAST(COALESCE(NULLIF(BTRIM("ac_se_de-ord_ejec"), \'\'), \'999999\') AS INTEGER)',
                    'sqlsrv' => 'CAST(COALESCE(NULLIF(LTRIM(RTRIM([ac_se_de-ord_ejec])), \'\'), \'999999\') AS INT)',
                    'sqlite' => 'CAST(COALESCE(NULLIF(TRIM("ac_se_de-ord_ejec"), \'\'), \'999999\') AS INTEGER)',
                    default => 'CAST(COALESCE(NULLIF(TRIM(`ac_se_de-ord_ejec`), \'\'), \'999999\') AS UNSIGNED)',
                };
                $query->orderByRaw($ordExpr.' ASC');
            }

            if (in_array('ac_se_de-id', $columns, true)) {
                $idExpr = match ($driver) {
                    'pgsql' => 'CAST(SUBSTRING("ac_se_de-id" FROM 4) AS INTEGER)',
                    'sqlsrv' => 'CAST(SUBSTRING([ac_se_de-id], 4, 50) AS INT)',
                    'sqlite' => 'CAST(SUBSTR("ac_se_de-id", 4) AS INTEGER)',
                    default => 'CAST(SUBSTR(`ac_se_de-id`, 4) AS UNSIGNED)',
                };
                $query->orderByRaw($idExpr.' ASC')->orderBy('ac_se_de-id');
            }
        }

        $data = $query
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        if ($table === 'riesgo_cat') {
            $tenantId = $this->tenantContext->tenantId();
            if ($tenantId !== null) {
                foreach ($data as $row) {
                    $riesgoId = trim((string) ($row?->{'rie-id'} ?? ''));
                    if ($riesgoId === '') {
                        continue;
                    }

                    $dir = 'repositorio_riesgo/'.$tenantId.'/'.$riesgoId;
                    if (! Storage::disk('local')->exists($dir)) {
                        Storage::disk('local')->makeDirectory($dir);
                    }

                    $files = array_values(array_filter(
                        Storage::disk('local')->files($dir),
                        static fn ($p) => is_string($p) && trim($p) !== '' && ! str_ends_with($p, '/')
                    ));

                    if (! empty($files)) {
                        continue;
                    }

                    $seed = [
                        [
                            'name' => '01-descripcion-del-riesgo.txt',
                            'content' => "Riesgo: {$riesgoId}\n\nDocumento de prueba.\n",
                        ],
                        [
                            'name' => '02-protocolo-de-actuacion.txt',
                            'content' => "Riesgo: {$riesgoId}\n\nProtocolo de actuación (prueba).\n",
                        ],
                        [
                            'name' => '03-checklist-operativo.txt',
                            'content' => "Riesgo: {$riesgoId}\n\nChecklist operativo (prueba).\n",
                        ],
                    ];

                    foreach ($seed as $doc) {
                        Storage::disk('local')->put($dir.'/'.$doc['name'], $doc['content']);
                    }
                }
            }
        }

        return response()->json([
            'table' => $table,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'data' => $data,
        ]);
    }

    public function show(Request $request, string $table): JsonResponse
    {
        $this->schema->assertAllowed($table);

        $columns = $this->schema->columns($table);
        $pkColumns = $this->schema->primaryKeyColumns($table);
        $tenantColumn = $this->schema->tenantColumn($columns);

        if (empty($pkColumns)) {
            return response()->json(['message' => 'Table has no primary key.'], 422);
        }

        $query = DB::table($table);
        $this->applyTenantScope($query, $tenantColumn);
        $this->applyPkWhere($query, $request, $pkColumns);

        $row = $query->first();

        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'table' => $table,
            'data' => $row,
        ]);
    }

    public function store(Request $request, string $table): JsonResponse
    {
        $this->schema->assertAllowed($table);

        $columns = $this->schema->columns($table);
        $pkColumns = $this->schema->primaryKeyColumns($table);
        $tenantColumn = $this->schema->tenantColumn($columns);

        $payload = $this->payload($request);

        $unknown = array_diff(array_keys($payload), $columns);
        if (! empty($unknown)) {
            return response()->json([
                'message' => 'Unknown columns: '.implode(', ', array_values($unknown)),
            ], 422);
        }

        if ($tenantColumn !== null) {
            $tenantId = $this->tenantContext->tenantId();
            if ($tenantId === null) {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $payload[$tenantColumn] = $tenantId;
        }

        if ($table === 'persona_mst' && in_array('per-id', $columns, true)) {
            unset($payload['per-id']);

            $required = array_values(array_filter(
                ['per-nombre', 'per-apellido_1', 'per-num_doc', 'per-email'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }

            $email = trim((string) ($payload['per-email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return response()->json([
                    'message' => 'Invalid email.',
                ], 422);
            }
        }

        if ($table === 'ev_lugar_contacto_mst' && in_array('ev_lu_con-id', $columns, true)) {
            unset($payload['ev_lu_con-id']);

            $required = array_values(array_filter(
                ['ev_lu_con-ev_lu_id-fk', 'ev_lu_con-tipo', 'ev_lu_con-valor'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }

            $tipo = strtoupper(trim((string) ($payload['ev_lu_con-tipo'] ?? '')));
            $allowed = ['TELEFONO', 'EMAIL', 'WEB', 'WHATSAPP', 'SMS', 'FAX', 'RADIO', 'OTRO'];
            if ($tipo !== '' && ! in_array($tipo, $allowed, true)) {
                return response()->json([
                    'message' => 'Invalid contact type.',
                ], 422);
            }

            $valor = trim((string) ($payload['ev_lu_con-valor'] ?? ''));
            if ($tipo === 'EMAIL' && $valor !== '' && filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
                return response()->json([
                    'message' => 'Invalid email.',
                ], 422);
            }

            if ($tipo === 'WEB' && $valor !== '' && filter_var($valor, FILTER_VALIDATE_URL) === false) {
                return response()->json([
                    'message' => 'Invalid URL.',
                ], 422);
            }
        }

        if ($table === 'ev_lugar_coordenada_mst' && in_array('ev_lu_coo-id', $columns, true)) {
            unset($payload['ev_lu_coo-id']);

            $required = array_values(array_filter(
                ['ev_lu_coo-ev_lu_id-fk', 'ev_lu_coo-srid', 'ev_lu_coo-este', 'ev_lu_coo-norte'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'ev_lugar_mst' && in_array('ev_lu-id', $columns, true)) {
            unset($payload['ev_lu-id']);

            if (in_array('ev_lu-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ev_lu-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ev_lu-activo'] = 'SI';
                }
            }

            $required = array_values(array_filter(
                ['ev_lu-cod', 'ev_lu-nombre'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'canal_comunicacion_cat' && in_array('ca_co-id', $columns, true)) {
            unset($payload['ca_co-id']);

            if (in_array('ca_co-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ca_co-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ca_co-activo'] = 'SI';
                }
            }

            $required = array_values(array_filter(
                ['ca_co-cod', 'ca_co-nombre', 'ca_co-prioridad', 'ca_co-descrip', 'ca_co-activo'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'grupo_operativo_cat' && in_array('gr_op-id', $columns, true)) {
            unset($payload['gr_op-id']);

            if (in_array('gr_op-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['gr_op-activo'] ?? '')));
                if ($activo === '') {
                    $payload['gr_op-activo'] = 'SI';
                }
            }

            $required = array_values(array_filter(
                ['gr_op-cod', 'gr_op-nombre'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'lugar_tipo_cat' && in_array('lu_ti-id', $columns, true)) {
            unset($payload['lu_ti-id']);

            $required = array_values(array_filter(
                ['lu_ti-cod', 'lu_ti-nombre'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'nivel_alerta_cat' && in_array('ni_al-id', $columns, true)) {
            unset($payload['ni_al-id']);

            if (in_array('ni_al-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ni_al-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ni_al-activo'] = 'SI';
                }
            }

            $required = array_values(array_filter(
                $columns,
                fn ($c) => ! in_array($c, $pkColumns, true) && $c !== $tenantColumn
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'nivel_emergencia_cat' && in_array('ni_em-id', $columns, true)) {
            unset($payload['ni_em-id']);

            if (in_array('ni_em-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ni_em-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ni_em-activo'] = 'SI';
                }
            }

            if (in_array('ni_em-activa_plan', $columns, true)) {
                $activaPlan = strtoupper(trim((string) ($payload['ni_em-activa_plan'] ?? '')));
                if ($activaPlan !== '') {
                    if (! in_array($activaPlan, ['SI', 'NO'], true)) {
                        return response()->json([
                            'message' => 'Invalid value for ni_em-activa_plan. Allowed: SI, NO.',
                        ], 422);
                    }
                    $payload['ni_em-activa_plan'] = $activaPlan;
                }
            }

            $required = array_values(array_filter(
                $columns,
                fn ($c) => ! in_array($c, $pkColumns, true) && $c !== $tenantColumn
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'riesgo_cat' && in_array('rie-id', $columns, true)) {
            unset($payload['rie-id']);

            if (in_array('rie-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['rie-activo'] ?? '')));
                if ($activo === '') {
                    $payload['rie-activo'] = 'SI';
                }
            }

            if (in_array('rie-plan_espec', $columns, true)) {
                $planEspec = strtoupper(trim((string) ($payload['rie-plan_espec'] ?? '')));
                if ($planEspec !== '') {
                    if (! in_array($planEspec, ['SI', 'NO'], true)) {
                        return response()->json([
                            'message' => 'Invalid value for rie-plan_espec. Allowed: SI, NO.',
                        ], 422);
                    }
                    $payload['rie-plan_espec'] = $planEspec;
                }
            }

            $required = array_values(array_filter(
                $columns,
                fn ($c) => ! in_array($c, $pkColumns, true) && $c !== $tenantColumn && $c !== 'rie-orden'
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'criterios_nivel_alerta_cfg' && in_array('id', $columns, true)) {
            unset($payload['id']);

            if (in_array('activo', $columns, true)) {
                $raw = $payload['activo'] ?? null;
                $activo = strtoupper(trim((string) ($raw ?? '')));
                $normalized = match ($activo) {
                    '', '1', 'SI' => 'SI',
                    '0', 'NO' => 'NO',
                    default => null,
                };

                if ($normalized === null) {
                    return response()->json([
                        'message' => "Invalid value for activo. Allowed: 'SI', 'NO'.",
                    ], 422);
                }

                $payload['activo'] = $normalized;
            }

            if (in_array('criterio_orden', $columns, true) && array_key_exists('criterio_orden', $payload)) {
                $ord = trim((string) ($payload['criterio_orden'] ?? ''));
                if ($ord !== '' && preg_match('/^\d+$/', $ord) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for criterio_orden. Must be a number.',
                    ], 422);
                }
            }
        }

        if ($table === 'rol_cat' && in_array('rol-id', $columns, true)) {
            unset($payload['rol-id']);

            if (in_array('rol-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['rol-activo'] ?? '')));
                if ($activo === '') {
                    $payload['rol-activo'] = 'SI';
                }
            }

            if (in_array('rol-correl', $columns, true)) {
                $correl = trim((string) ($payload['rol-correl'] ?? ''));
                if ($correl !== '' && preg_match('/^\d+$/', $correl) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for rol-correl. Must be a number.',
                    ], 422);
                }
            }

            $required = array_values(array_filter(
                $columns,
                fn ($c) => ! in_array($c, $pkColumns, true) && $c !== $tenantColumn && $c !== 'rol-descrip' && $c !== 'rol-correl'
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'accion_set_cfg' && in_array('ac_se-id', $columns, true)) {
            unset($payload['ac_se-id']);

            if (in_array('ac_se-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ac_se-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ac_se-activo'] = 'SI';
                } elseif (! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for ac_se-activo. Allowed: SI, NO.',
                    ], 422);
                } else {
                    $payload['ac_se-activo'] = $activo;
                }
            }

            if (in_array('ac_se-orden', $columns, true) && array_key_exists('ac_se-orden', $payload)) {
                $ord = trim((string) ($payload['ac_se-orden'] ?? ''));
                if ($ord !== '' && preg_match('/^\d+$/', $ord) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for ac_se-orden. Must be a number.',
                    ], 422);
                }
            }

            $required = array_values(array_filter(
                $columns,
                fn ($c) => ! in_array($c, $pkColumns, true) && $c !== $tenantColumn && $c !== 'ac_se-orden'
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'accion_operativa_cfg' && in_array('ac_op-id', $columns, true)) {
            unset($payload['ac_op-id']);

            if (in_array('ac_op-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ac_op-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ac_op-activo'] = 'SI';
                }
            }
        }

        if ($table === 'accion_set_detalle_cfg' && in_array('ac_se_de-id', $columns, true)) {
            unset($payload['ac_se_de-id']);

            if (in_array('ac_se_de-obligatoria', $columns, true)) {
                $obligatoria = strtoupper(trim((string) ($payload['ac_se_de-obligatoria'] ?? '')));
                if ($obligatoria === '') {
                    $payload['ac_se_de-obligatoria'] = 'SI';
                }
            }

            if (in_array('ac_se_de-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ac_se_de-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ac_se_de-activo'] = 'SI';
                }
            }
        }

        if ($table === 'accion_set_detalle_canal_cfg' && in_array('ac_se_de_ca-id', $columns, true)) {
            unset($payload['ac_se_de_ca-id']);

            if (in_array('ac_se_de_ca-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ac_se_de_ca-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ac_se_de_ca-activo'] = 'SI';
                }
            }
        }

        if ($table === 'persona_rol_cfg' && in_array('pe_ro-id', $columns, true)) {
            unset($payload['pe_ro-id']);

            if (in_array('pe_ro-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['pe_ro-activo'] ?? '')));
                if ($activo === '') {
                    $payload['pe_ro-activo'] = 'SI';
                } elseif (! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for pe_ro-activo. Allowed: SI, NO.',
                    ], 422);
                } else {
                    $payload['pe_ro-activo'] = $activo;
                }
            }

            $required = array_values(array_filter(
                ['pe_ro-per_id-fk', 'pe_ro-rol_id-fk'],
                static fn ($c) => in_array($c, $columns, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'persona_rol_grupo_cfg' && in_array('pe_ro_gr-id', $columns, true)) {
            unset($payload['pe_ro_gr-id']);

            $fechFin = trim((string) ($payload['pe_ro_gr-fech_fin'] ?? ''));

            if (in_array('pe_ro_gr-activo', $columns, true)) {
                if ($fechFin !== '') {
                    $payload['pe_ro_gr-activo'] = 'NO';
                } else {
                    $activo = strtoupper(trim((string) ($payload['pe_ro_gr-activo'] ?? '')));
                    if ($activo === '') {
                        $payload['pe_ro_gr-activo'] = 'SI';
                    } elseif (! in_array($activo, ['SI', 'NO'], true)) {
                        return response()->json([
                            'message' => 'Invalid value for pe_ro_gr-activo. Allowed: SI, NO.',
                        ], 422);
                    } else {
                        $payload['pe_ro_gr-activo'] = $activo;
                    }
                }
            }

            if (in_array('pe_ro_gr-orden_sust', $columns, true)) {
                $ord = trim((string) ($payload['pe_ro_gr-orden_sust'] ?? ''));
                if ($ord !== '' && preg_match('/^\d+$/', $ord) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for pe_ro_gr-orden_sust. Must be a number.',
                    ], 422);
                }
            }

            $excluded = array_values(array_filter([
                $tenantColumn,
                'pe_ro_gr-fech_fin',
                'pe_ro_gr-observ',
                ...$pkColumns,
            ], static fn ($v) => is_string($v) && $v !== ''));

            $required = array_values(array_filter(
                $columns,
                static fn ($c) => ! in_array($c, $excluded, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'riesgo_nivel_accion_set_cfg' && in_array('ri_ni_ac_se-id', $columns, true)) {
            unset($payload['ri_ni_ac_se-id']);

            if (in_array('ri_ni_ac_se-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ri_ni_ac_se-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ri_ni_ac_se-activo'] = 'SI';
                } elseif (! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for ri_ni_ac_se-activo. Allowed: SI, NO.',
                    ], 422);
                } else {
                    $payload['ri_ni_ac_se-activo'] = $activo;
                }
            }

            if (in_array('ri_ni_ac_se-prioridad', $columns, true)) {
                $prioridad = trim((string) ($payload['ri_ni_ac_se-prioridad'] ?? ''));
                if ($prioridad === '') {
                    $payload['ri_ni_ac_se-prioridad'] = '1';
                } elseif (preg_match('/^\d+$/', $prioridad) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for ri_ni_ac_se-prioridad. Must be a number.',
                    ], 422);
                }
            }

            $required = array_values(array_filter(
                $columns,
                fn ($c) => ! in_array($c, $pkColumns, true) && $c !== $tenantColumn
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'tipo_riesgo_nivel_accion_set_cfg' && in_array('ti_ri_ni_ac_se-id', $columns, true)) {
            unset($payload['ti_ri_ni_ac_se-id']);

            if (in_array('ti_ri_ni_ac_se-activo', $columns, true)) {
                $activo = strtoupper(trim((string) ($payload['ti_ri_ni_ac_se-activo'] ?? '')));
                if ($activo === '') {
                    $payload['ti_ri_ni_ac_se-activo'] = 'SI';
                } elseif (! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for ti_ri_ni_ac_se-activo. Allowed: SI, NO.',
                    ], 422);
                } else {
                    $payload['ti_ri_ni_ac_se-activo'] = $activo;
                }
            }

            if (in_array('ti_ri_ni_ac_se-orden', $columns, true) && array_key_exists('ti_ri_ni_ac_se-orden', $payload)) {
                $ord = trim((string) ($payload['ti_ri_ni_ac_se-orden'] ?? ''));
                if ($ord !== '' && preg_match('/^\d+$/', $ord) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for ti_ri_ni_ac_se-orden. Must be a number.',
                    ], 422);
                }
            }

            $excluded = array_values(array_filter([
                $tenantColumn,
                'ti_ri_ni_ac_se-observ',
                'ti_ri_ni_ac_se-orden',
                ...$pkColumns,
            ], static fn ($v) => is_string($v) && $v !== ''));

            $required = array_values(array_filter(
                $columns,
                static fn ($c) => ! in_array($c, $excluded, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        $meta = $this->schema->columnMeta($table);
        $excluded = array_values(array_filter([
            $tenantColumn,
            ...$pkColumns,
        ], static fn ($v) => is_string($v) && $v !== ''));

        $required = [];
        foreach ($columns as $c) {
            $c = (string) $c;
            if ($c === '' || in_array($c, $excluded, true)) {
                continue;
            }

            $m = $meta[$c] ?? null;
            if (! is_array($m)) {
                continue;
            }

            $isNullable = strtoupper(trim((string) ($m['is_nullable'] ?? ''))) === 'YES';
            if ($isNullable) {
                continue;
            }

            $hasDefault = array_key_exists('column_default', $m) && $m['column_default'] !== null;
            if ($hasDefault) {
                continue;
            }

            $extra = strtolower(trim((string) ($m['extra'] ?? '')));
            if ($extra !== '' && str_contains($extra, 'auto_increment')) {
                continue;
            }

            $required[] = $c;
        }

        if (! empty($required)) {
            $missing = [];
            foreach ($required as $c) {
                if (! array_key_exists($c, $payload)) {
                    $missing[] = $c;

                    continue;
                }

                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        $needsPersonaId = $table === 'persona_mst'
            && in_array('per-id', $columns, true)
            && (! array_key_exists('per-id', $payload) || trim((string) $payload['per-id']) === '');

        $needsLugarContactoId = $table === 'ev_lugar_contacto_mst'
            && in_array('ev_lu_con-id', $columns, true)
            && (! array_key_exists('ev_lu_con-id', $payload) || trim((string) $payload['ev_lu_con-id']) === '');

        $needsLugarCoordenadaId = $table === 'ev_lugar_coordenada_mst'
            && in_array('ev_lu_coo-id', $columns, true)
            && (! array_key_exists('ev_lu_coo-id', $payload) || trim((string) $payload['ev_lu_coo-id']) === '');

        $needsLugarId = $table === 'ev_lugar_mst'
            && in_array('ev_lu-id', $columns, true)
            && (! array_key_exists('ev_lu-id', $payload) || trim((string) $payload['ev_lu-id']) === '');

        $needsCanalComunicacionId = $table === 'canal_comunicacion_cat'
            && in_array('ca_co-id', $columns, true)
            && (! array_key_exists('ca_co-id', $payload) || trim((string) $payload['ca_co-id']) === '');

        $needsGrupoOperativoId = $table === 'grupo_operativo_cat'
            && in_array('gr_op-id', $columns, true)
            && (! array_key_exists('gr_op-id', $payload) || trim((string) $payload['gr_op-id']) === '');

        $needsLugarTipoId = $table === 'lugar_tipo_cat'
            && in_array('lu_ti-id', $columns, true)
            && (! array_key_exists('lu_ti-id', $payload) || trim((string) $payload['lu_ti-id']) === '');

        $needsNivelAlertaId = $table === 'nivel_alerta_cat'
            && in_array('ni_al-id', $columns, true)
            && (! array_key_exists('ni_al-id', $payload) || trim((string) $payload['ni_al-id']) === '');

        $needsNivelEmergenciaId = $table === 'nivel_emergencia_cat'
            && in_array('ni_em-id', $columns, true)
            && (! array_key_exists('ni_em-id', $payload) || trim((string) $payload['ni_em-id']) === '');

        $needsRiesgoId = $table === 'riesgo_cat'
            && in_array('rie-id', $columns, true)
            && (! array_key_exists('rie-id', $payload) || trim((string) $payload['rie-id']) === '');

        $needsRolId = $table === 'rol_cat'
            && in_array('rol-id', $columns, true)
            && (! array_key_exists('rol-id', $payload) || trim((string) $payload['rol-id']) === '');

        $needsPersonaRolId = $table === 'persona_rol_cfg'
            && in_array('pe_ro-id', $columns, true)
            && (! array_key_exists('pe_ro-id', $payload) || trim((string) $payload['pe_ro-id']) === '');

        $needsPersonaRolGrupoId = $table === 'persona_rol_grupo_cfg'
            && in_array('pe_ro_gr-id', $columns, true)
            && (! array_key_exists('pe_ro_gr-id', $payload) || trim((string) $payload['pe_ro_gr-id']) === '');

        $needsRiesgoNivelAccionSetId = $table === 'riesgo_nivel_accion_set_cfg'
            && in_array('ri_ni_ac_se-id', $columns, true)
            && (! array_key_exists('ri_ni_ac_se-id', $payload) || trim((string) $payload['ri_ni_ac_se-id']) === '');

        $needsTipoRiesgoNivelAccionSetId = $table === 'tipo_riesgo_nivel_accion_set_cfg'
            && in_array('ti_ri_ni_ac_se-id', $columns, true)
            && (! array_key_exists('ti_ri_ni_ac_se-id', $payload) || trim((string) $payload['ti_ri_ni_ac_se-id']) === '');

        $needsAccionSetId = $table === 'accion_set_cfg'
            && in_array('ac_se-id', $columns, true)
            && (! array_key_exists('ac_se-id', $payload) || trim((string) $payload['ac_se-id']) === '');

        $needsAccionOperativaId = $table === 'accion_operativa_cfg'
            && in_array('ac_op-id', $columns, true)
            && (! array_key_exists('ac_op-id', $payload) || trim((string) $payload['ac_op-id']) === '');

        $needsAccionSetDetalleId = $table === 'accion_set_detalle_cfg'
            && in_array('ac_se_de-id', $columns, true)
            && (! array_key_exists('ac_se_de-id', $payload) || trim((string) $payload['ac_se_de-id']) === '');

        $needsAccionSetDetalleCanalId = $table === 'accion_set_detalle_canal_cfg'
            && in_array('ac_se_de_ca-id', $columns, true)
            && (! array_key_exists('ac_se_de_ca-id', $payload) || trim((string) $payload['ac_se_de_ca-id']) === '');

        $needsCriterioOrden = $table === 'criterios_nivel_alerta_cfg'
            && in_array('criterio_orden', $columns, true)
            && (
                ! array_key_exists('criterio_orden', $payload)
                || trim((string) $payload['criterio_orden']) === ''
                || (preg_match('/^\d+$/', trim((string) $payload['criterio_orden'])) === 1 && (int) trim((string) $payload['criterio_orden']) <= 0)
            );

        if ($needsPersonaId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('persona_mst')->select(['per-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('per-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^PER(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $next = $max + 1;
                $payload['per-id'] = 'PER'.str_pad((string) $next, 2, '0', STR_PAD_LEFT);

                DB::table('persona_mst')->insert($payload);
            });
        } elseif ($needsLugarContactoId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('ev_lugar_contacto_mst')->select(['ev_lu_con-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ev_lu_con-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^LC(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $next = $max + 1;
                $payload['ev_lu_con-id'] = 'LC'.str_pad((string) $next, 2, '0', STR_PAD_LEFT);

                DB::table('ev_lugar_contacto_mst')->insert($payload);
            });
        } elseif ($needsLugarCoordenadaId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('ev_lugar_coordenada_mst')->select(['ev_lu_coo-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ev_lu_coo-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = trim((string) $id);
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['ev_lu_coo-id'] = (string) ($max + 1);

                DB::table('ev_lugar_coordenada_mst')->insert($payload);
            });
        } elseif ($needsLugarId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('ev_lugar_mst')->select(['ev_lu-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ev_lu-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^EVL(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $next = $max + 1;
                $payload['ev_lu-id'] = 'EVL'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);

                DB::table('ev_lugar_mst')->insert($payload);
            });
        } elseif ($needsCanalComunicacionId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('canal_comunicacion_cat')->select(['ca_co-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ca_co-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^CC(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['ca_co-id'] = 'CC'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);

                DB::table('canal_comunicacion_cat')->insert($payload);
            });
        } elseif ($needsGrupoOperativoId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();
            $hasCorrel = in_array('gr_op-correl', $columns, true);

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver, $hasCorrel) {
                $select = ['gr_op-id'];
                if ($hasCorrel) {
                    $select[] = 'gr_op-correl';
                }

                $q = DB::table('grupo_operativo_cat')->select($select);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $rows = $q->get();

                $maxId = 0;
                $maxCorrel = 0;
                foreach ($rows as $row) {
                    $id = strtoupper(trim((string) ($row?->{'gr_op-id'} ?? '')));
                    if ($id !== '' && preg_match('/^GR(\d{2,})/', $id, $m)) {
                        $n = (int) ($m[1] ?? 0);
                        if ($n > $maxId) {
                            $maxId = $n;
                        }
                    }

                    if ($hasCorrel) {
                        $corr = trim((string) ($row?->{'gr_op-correl'} ?? ''));
                        if ($corr !== '' && preg_match('/^(\d+)$/', $corr, $m2)) {
                            $n2 = (int) ($m2[1] ?? 0);
                            if ($n2 > $maxCorrel) {
                                $maxCorrel = $n2;
                            }
                        }
                    }
                }

                $next = $maxId + 1;
                $payload['gr_op-id'] = 'GR'.str_pad((string) $next, 2, '0', STR_PAD_LEFT);

                if ($hasCorrel) {
                    $current = trim((string) ($payload['gr_op-correl'] ?? ''));
                    if ($current === '') {
                        $payload['gr_op-correl'] = (string) ($maxCorrel + 1);
                    }
                }

                DB::table('grupo_operativo_cat')->insert($payload);
            });
        } elseif ($needsLugarTipoId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('lugar_tipo_cat')->select(['lu_ti-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('lu_ti-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^LT(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['lu_ti-id'] = 'LT'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);

                DB::table('lugar_tipo_cat')->insert($payload);
            });
        } elseif ($needsNivelAlertaId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('nivel_alerta_cat')->select(['ni_al-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ni_al-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^NA(\d{2,})/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['ni_al-id'] = 'NA'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                DB::table('nivel_alerta_cat')->insert($payload);
            });
        } elseif ($needsNivelEmergenciaId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('nivel_emergencia_cat')->select(['ni_em-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ni_em-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^NE(\d{2,})/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['ni_em-id'] = 'NE'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                DB::table('nivel_emergencia_cat')->insert($payload);
            });
        } elseif ($needsRiesgoId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();
            $hasOrden = in_array('rie-orden', $columns, true);

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver, $hasOrden) {
                $select = ['rie-id'];
                if ($hasOrden) {
                    $select[] = 'rie-orden';
                }

                $q = DB::table('riesgo_cat')->select($select);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $rows = $q->get();

                $maxId = 0;
                $maxOrden = 0;
                foreach ($rows as $row) {
                    $id = strtoupper(trim((string) ($row?->{'rie-id'} ?? '')));
                    if ($id !== '' && preg_match('/^R(\d{2})/', $id, $m)) {
                        $n = (int) ($m[1] ?? 0);
                        if ($n > $maxId) {
                            $maxId = $n;
                        }
                    }

                    if (! $hasOrden) {
                        continue;
                    }

                    $ord = trim((string) ($row?->{'rie-orden'} ?? ''));
                    if ($ord !== '' && preg_match('/^(\d+)$/', $ord, $m2)) {
                        $n2 = (int) ($m2[1] ?? 0);
                        if ($n2 > $maxOrden) {
                            $maxOrden = $n2;
                        }
                    }
                }

                $payload['rie-id'] = 'R'.str_pad((string) ($maxId + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                if ($hasOrden) {
                    $currentOrden = trim((string) ($payload['rie-orden'] ?? ''));
                    if ($currentOrden === '') {
                        $payload['rie-orden'] = (string) ($maxOrden + 1);
                    }
                }

                DB::table('riesgo_cat')->insert($payload);
            });
        } elseif ($needsRolId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();
            $hasCorrel = in_array('rol-correl', $columns, true);

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver, $hasCorrel) {
                $select = ['rol-id'];
                if ($hasCorrel) {
                    $select[] = 'rol-correl';
                }

                $q = DB::table('rol_cat')->select($select);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $rows = $q->get();

                $maxId = 0;
                $maxCorrel = 0;
                foreach ($rows as $row) {
                    $id = strtoupper(trim((string) ($row?->{'rol-id'} ?? '')));
                    if ($id !== '' && preg_match('/^ROL(\d{2})/', $id, $m)) {
                        $n = (int) ($m[1] ?? 0);
                        if ($n > $maxId) {
                            $maxId = $n;
                        }
                    }

                    if (! $hasCorrel) {
                        continue;
                    }

                    $correl = trim((string) ($row?->{'rol-correl'} ?? ''));
                    if ($correl !== '' && preg_match('/^(\d+)$/', $correl, $m2)) {
                        $n2 = (int) ($m2[1] ?? 0);
                        if ($n2 > $maxCorrel) {
                            $maxCorrel = $n2;
                        }
                    }
                }

                $payload['rol-id'] = 'ROL'.str_pad((string) ($maxId + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                if ($hasCorrel) {
                    $current = trim((string) ($payload['rol-correl'] ?? ''));
                    if ($current === '') {
                        $payload['rol-correl'] = (string) ($maxCorrel + 1);
                    }
                }

                DB::table('rol_cat')->insert($payload);
            });
        } elseif ($needsPersonaRolId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('persona_rol_cfg')->select(['pe_ro-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('pe_ro-id')->all();

                $max = 0;
                $tenantSuffix = strtoupper($effectiveTenantId);
                foreach ($existing as $id) {
                    $idRaw = trim((string) $id);
                    if ($idRaw === '') {
                        continue;
                    }
                    $idUpper = strtoupper($idRaw);
                    if (! str_ends_with($idUpper, $tenantSuffix)) {
                        continue;
                    }
                    if (! preg_match('/^PER_ROL(\d{2})/', $idUpper, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['pe_ro-id'] = 'PER_ROL'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                DB::table('persona_rol_cfg')->insert($payload);
            });
        } elseif ($needsPersonaRolGrupoId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('persona_rol_grupo_cfg')->select(['pe_ro_gr-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('pe_ro_gr-id')->all();

                $max = 0;
                $tenantSuffix = strtoupper($effectiveTenantId);
                foreach ($existing as $id) {
                    $idRaw = trim((string) $id);
                    if ($idRaw === '') {
                        continue;
                    }
                    $idUpper = strtoupper($idRaw);
                    if (! str_ends_with($idUpper, $tenantSuffix)) {
                        continue;
                    }
                    if (! preg_match('/^PER_ROL_GRU(\d{2})/', $idUpper, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['pe_ro_gr-id'] = 'PER_ROL_GRU'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                DB::table('persona_rol_grupo_cfg')->insert($payload);
            });
        } elseif ($needsRiesgoNivelAccionSetId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('riesgo_nivel_accion_set_cfg')->select(['ri_ni_ac_se-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ri_ni_ac_se-id')->all();

                $max = 0;
                $tenantSuffix = strtoupper($effectiveTenantId);
                foreach ($existing as $id) {
                    $idRaw = trim((string) $id);
                    if ($idRaw === '') {
                        continue;
                    }
                    $idUpper = strtoupper($idRaw);
                    if (! str_ends_with($idUpper, $tenantSuffix)) {
                        continue;
                    }
                    if (! preg_match('/^RNAS(\d{2})/', $idUpper, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['ri_ni_ac_se-id'] = 'RNAS'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                DB::table('riesgo_nivel_accion_set_cfg')->insert($payload);
            });
        } elseif ($needsTipoRiesgoNivelAccionSetId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();
            $hasOrden = in_array('ti_ri_ni_ac_se-orden', $columns, true);

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver, $hasOrden) {
                $select = ['ti_ri_ni_ac_se-id'];
                if ($hasOrden) {
                    $select[] = 'ti_ri_ni_ac_se-orden';
                }

                $q = DB::table('tipo_riesgo_nivel_accion_set_cfg')->select($select);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $rows = $q->get();

                $maxId = 0;
                $maxOrden = 0;
                $tenantSuffix = strtoupper($effectiveTenantId);
                foreach ($rows as $row) {
                    $idRaw = trim((string) ($row?->{'ti_ri_ni_ac_se-id'} ?? ''));
                    if ($idRaw !== '') {
                        $idUpper = strtoupper($idRaw);
                        if (str_ends_with($idUpper, $tenantSuffix) && preg_match('/^TRNAS(\d{2})/', $idUpper, $m) === 1) {
                            $n = (int) ($m[1] ?? 0);
                            if ($n > $maxId) {
                                $maxId = $n;
                            }
                        }
                    }

                    if (! $hasOrden) {
                        continue;
                    }

                    $ord = trim((string) ($row?->{'ti_ri_ni_ac_se-orden'} ?? ''));
                    if ($ord !== '' && preg_match('/^(\d+)$/', $ord, $m2) === 1) {
                        $n2 = (int) ($m2[1] ?? 0);
                        if ($n2 > $maxOrden) {
                            $maxOrden = $n2;
                        }
                    }
                }

                $payload['ti_ri_ni_ac_se-id'] = 'TRNAS'.str_pad((string) ($maxId + 1), 2, '0', STR_PAD_LEFT).$effectiveTenantId;

                if ($hasOrden) {
                    $currentOrden = trim((string) ($payload['ti_ri_ni_ac_se-orden'] ?? ''));
                    if ($currentOrden === '') {
                        $payload['ti_ri_ni_ac_se-orden'] = (string) ($maxOrden + 1);
                    }
                }

                DB::table('tipo_riesgo_nivel_accion_set_cfg')->insert($payload);
            });
        } elseif ($needsAccionSetId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();
            $hasOrden = in_array('ac_se-orden', $columns, true);

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver, $hasOrden) {
                $q = DB::table('accion_set_cfg')->select(['ac_se-id', 'ac_se-orden']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $rows = $q->get();

                $maxId = 0;
                $maxOrden = 0;
                foreach ($rows as $row) {
                    $id = strtoupper(trim((string) ($row?->{'ac_se-id'} ?? '')));
                    if ($id !== '' && preg_match('/^AS(\d+)$/', $id, $m)) {
                        $n = (int) ($m[1] ?? 0);
                        if ($n > $maxId) {
                            $maxId = $n;
                        }
                    }

                    $ord = trim((string) ($row?->{'ac_se-orden'} ?? ''));
                    if ($ord !== '' && preg_match('/^(\d+)$/', $ord, $m2)) {
                        $n2 = (int) ($m2[1] ?? 0);
                        if ($n2 > $maxOrden) {
                            $maxOrden = $n2;
                        }
                    }
                }

                $payload['ac_se-id'] = 'AS'.str_pad((string) ($maxId + 1), 2, '0', STR_PAD_LEFT);

                if ($hasOrden) {
                    $currentOrden = trim((string) ($payload['ac_se-orden'] ?? ''));
                    if ($currentOrden === '') {
                        $payload['ac_se-orden'] = (string) ($maxOrden + 1);
                    }
                }

                DB::table('accion_set_cfg')->insert($payload);
            });
        } elseif ($needsAccionOperativaId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('accion_operativa_cfg')->select(['ac_op-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ac_op-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^AC(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $next = $max + 1;
                $payload['ac_op-id'] = 'AC'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);

                DB::table('accion_operativa_cfg')->insert($payload);
            });
        } elseif ($needsAccionSetDetalleId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $hasOrdEjec = in_array('ac_se_de-ord_ejec', $columns, true);
            $hasSetFk = in_array('ac_se_de-ac_se_id-fk', $columns, true);
            $hasFaseFk = in_array('ac_se_de-fa_ac_id-fk', $columns, true);

            $currentOrdEjec = trim((string) ($payload['ac_se_de-ord_ejec'] ?? ''));
            $targetSetId = $hasSetFk ? trim((string) ($payload['ac_se_de-ac_se_id-fk'] ?? '')) : '';
            $targetFaseId = $hasFaseFk ? trim((string) ($payload['ac_se_de-fa_ac_id-fk'] ?? '')) : '';

            if ($hasOrdEjec && $currentOrdEjec === '' && $hasSetFk && $targetSetId === '') {
                return response()->json([
                    'message' => 'Missing required fields: ac_se_de-ac_se_id-fk',
                ], 422);
            }
            if ($hasOrdEjec && $currentOrdEjec === '' && $hasFaseFk && $targetFaseId === '') {
                return response()->json([
                    'message' => 'Missing required fields: ac_se_de-fa_ac_id-fk',
                ], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver, $hasOrdEjec, $hasSetFk, $hasFaseFk, $targetSetId, $targetFaseId) {
                $select = ['ac_se_de-id'];
                if ($hasOrdEjec) {
                    $select[] = 'ac_se_de-ord_ejec';
                }
                if ($hasSetFk) {
                    $select[] = 'ac_se_de-ac_se_id-fk';
                }
                if ($hasFaseFk) {
                    $select[] = 'ac_se_de-fa_ac_id-fk';
                }

                $q = DB::table('accion_set_detalle_cfg')->select($select);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $rows = $q->get();

                $maxId = 0;
                $maxOrdEjec = 0;
                foreach ($rows as $row) {
                    $id = strtoupper(trim((string) ($row?->{'ac_se_de-id'} ?? '')));
                    if ($id !== '' && preg_match('/^ASD(\d+)$/', $id, $m)) {
                        $n = (int) ($m[1] ?? 0);
                        if ($n > $maxId) {
                            $maxId = $n;
                        }
                    }

                    if (! $hasOrdEjec) {
                        continue;
                    }

                    if ($hasSetFk) {
                        $rowSetId = trim((string) ($row?->{'ac_se_de-ac_se_id-fk'} ?? ''));
                        if ($rowSetId !== $targetSetId) {
                            continue;
                        }
                    }

                    if ($hasFaseFk) {
                        $rowFaseId = trim((string) ($row?->{'ac_se_de-fa_ac_id-fk'} ?? ''));
                        if ($rowFaseId !== $targetFaseId) {
                            continue;
                        }
                    }

                    $ord = trim((string) ($row?->{'ac_se_de-ord_ejec'} ?? ''));
                    if ($ord !== '' && preg_match('/^(\d+)$/', $ord, $m2)) {
                        $n2 = (int) ($m2[1] ?? 0);
                        if ($n2 > $maxOrdEjec) {
                            $maxOrdEjec = $n2;
                        }
                    }
                }

                $payload['ac_se_de-id'] = 'ASD'.str_pad((string) ($maxId + 1), 3, '0', STR_PAD_LEFT);

                if ($hasOrdEjec) {
                    $currentOrd = trim((string) ($payload['ac_se_de-ord_ejec'] ?? ''));
                    if ($currentOrd === '') {
                        $payload['ac_se_de-ord_ejec'] = (string) ($maxOrdEjec + 1);
                    }
                }

                DB::table('accion_set_detalle_cfg')->insert($payload);
            });
        } elseif ($needsAccionSetDetalleCanalId) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('accion_set_detalle_canal_cfg')->select(['ac_se_de_ca-id']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('ac_se_de_ca-id')->all();

                $max = 0;
                foreach ($existing as $id) {
                    $id = strtoupper(trim((string) $id));
                    if ($id === '') {
                        continue;
                    }
                    if (! preg_match('/^ASDC(\d+)$/', $id, $m)) {
                        continue;
                    }
                    $n = (int) ($m[1] ?? 0);
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['ac_se_de_ca-id'] = 'ASDC'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);

                DB::table('accion_set_detalle_canal_cfg')->insert($payload);
            });
        } elseif ($needsCriterioOrden) {
            $effectiveTenantId = $tenantColumn !== null ? trim((string) ($payload[$tenantColumn] ?? '')) : trim((string) ($this->tenantContext->tenantId() ?? ''));
            if ($tenantColumn !== null && $effectiveTenantId === '') {
                return response()->json(['message' => __('messages.tenant.missing')], 422);
            }

            $driver = DB::connection()->getDriverName();

            DB::transaction(function () use (&$payload, $tenantColumn, $effectiveTenantId, $driver) {
                $q = DB::table('criterios_nivel_alerta_cfg')->select(['criterio_orden']);
                if ($tenantColumn !== null) {
                    $q->where($tenantColumn, $effectiveTenantId);
                }
                if (in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                    $q->lockForUpdate();
                }

                $existing = $q->pluck('criterio_orden')->all();
                $max = 0;
                foreach ($existing as $ord) {
                    $ord = trim((string) $ord);
                    if ($ord === '' || preg_match('/^\d+$/', $ord) !== 1) {
                        continue;
                    }
                    $n = (int) $ord;
                    if ($n > $max) {
                        $max = $n;
                    }
                }

                $payload['criterio_orden'] = (string) ($max + 1);

                DB::table('criterios_nivel_alerta_cfg')->insert($payload);
            });
        } else {
            DB::table($table)->insert($payload);
        }

        $pk = [];
        foreach ($pkColumns as $col) {
            if (array_key_exists($col, $payload)) {
                $pk[$col] = $payload[$col];
            }
        }
        if (empty($pk) && count($pkColumns) === 1) {
            $pkCol = (string) ($pkColumns[0] ?? '');
            if ($pkCol !== '') {
                $meta = $this->schema->columnMeta($table);
                $extra = strtolower((string) (($meta[$pkCol]['extra'] ?? '') ?? ''));
                if (str_contains($extra, 'auto_increment')) {
                    $lastId = DB::getPdo()->lastInsertId();
                    if (is_string($lastId)) {
                        $lastId = trim($lastId);
                        if ($lastId !== '') {
                            $pk[$pkCol] = ctype_digit($lastId) ? (int) $lastId : $lastId;
                        }
                    }
                }
            }
        }

        $after = array_merge($payload, $pk);
        $auditPayload = $this->auditPayloadForTable($table, $after, null);
        if ($auditPayload !== null) {
            $this->auditLogger->logFromRequest($request, $auditPayload);
        }

        return response()->json([
            'table' => $table,
            'message' => 'Created.',
            'pk' => ! empty($pk) ? $pk : null,
        ], 201);
    }

    public function update(Request $request, string $table): JsonResponse
    {
        $this->schema->assertAllowed($table);

        $columns = $this->schema->columns($table);
        $pkColumns = $this->schema->primaryKeyColumns($table);
        $tenantColumn = $this->schema->tenantColumn($columns);

        if (empty($pkColumns)) {
            return response()->json(['message' => 'Table has no primary key.'], 422);
        }

        $payload = $this->payload($request);

        $unknown = array_diff(array_keys($payload), $columns);
        if (! empty($unknown)) {
            return response()->json([
                'message' => 'Unknown columns: '.implode(', ', array_values($unknown)),
            ], 422);
        }

        foreach ($pkColumns as $pk) {
            unset($payload[$pk]);
        }

        if ($tenantColumn !== null) {
            unset($payload[$tenantColumn]);
        }

        if ($table === 'persona_rol_grupo_cfg') {
            if (array_key_exists('pe_ro_gr-fech_fin', $payload) && trim((string) ($payload['pe_ro_gr-fech_fin'] ?? '')) !== '') {
                $payload['pe_ro_gr-activo'] = 'NO';
            }

            if (array_key_exists('pe_ro_gr-activo', $payload)) {
                $activo = strtoupper(trim((string) ($payload['pe_ro_gr-activo'] ?? '')));
                if ($activo !== '' && ! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for pe_ro_gr-activo. Allowed: SI, NO.',
                    ], 422);
                }
                if ($activo !== '') {
                    $payload['pe_ro_gr-activo'] = $activo;
                }
            }

            if (array_key_exists('pe_ro_gr-orden_sust', $payload)) {
                $ord = trim((string) ($payload['pe_ro_gr-orden_sust'] ?? ''));
                if ($ord !== '' && preg_match('/^\d+$/', $ord) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for pe_ro_gr-orden_sust. Must be a number.',
                    ], 422);
                }
            }
        }

        if ($table === 'riesgo_nivel_accion_set_cfg') {
            if (array_key_exists('ri_ni_ac_se-activo', $payload)) {
                $activo = strtoupper(trim((string) ($payload['ri_ni_ac_se-activo'] ?? '')));
                if ($activo !== '' && ! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for ri_ni_ac_se-activo. Allowed: SI, NO.',
                    ], 422);
                }
                if ($activo !== '') {
                    $payload['ri_ni_ac_se-activo'] = $activo;
                }
            }

            if (array_key_exists('ri_ni_ac_se-prioridad', $payload)) {
                $prioridad = trim((string) ($payload['ri_ni_ac_se-prioridad'] ?? ''));
                if ($prioridad !== '' && preg_match('/^\d+$/', $prioridad) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for ri_ni_ac_se-prioridad. Must be a number.',
                    ], 422);
                }
            }
        }

        if ($table === 'accion_set_cfg') {
            if (array_key_exists('ac_se-activo', $payload)) {
                $activo = strtoupper(trim((string) ($payload['ac_se-activo'] ?? '')));
                if ($activo === '' || ! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for ac_se-activo. Allowed: SI, NO.',
                    ], 422);
                }
                $payload['ac_se-activo'] = $activo;
            }

            if (array_key_exists('ac_se-orden', $payload)) {
                $ord = trim((string) ($payload['ac_se-orden'] ?? ''));
                if ($ord !== '' && preg_match('/^\d+$/', $ord) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for ac_se-orden. Must be a number.',
                    ], 422);
                }
            }

            $required = array_values(array_filter(
                $columns,
                fn ($c) => ! in_array($c, $pkColumns, true) && $c !== $tenantColumn && $c !== 'ac_se-orden'
            ));

            $missing = [];
            foreach ($required as $c) {
                if (! array_key_exists($c, $payload)) {
                    continue;
                }
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if ($table === 'tipo_riesgo_nivel_accion_set_cfg') {
            if (array_key_exists('ti_ri_ni_ac_se-activo', $payload)) {
                $activo = strtoupper(trim((string) ($payload['ti_ri_ni_ac_se-activo'] ?? '')));
                if ($activo === '' || ! in_array($activo, ['SI', 'NO'], true)) {
                    return response()->json([
                        'message' => 'Invalid value for ti_ri_ni_ac_se-activo. Allowed: SI, NO.',
                    ], 422);
                }
                $payload['ti_ri_ni_ac_se-activo'] = $activo;
            }

            if (array_key_exists('ti_ri_ni_ac_se-orden', $payload)) {
                $ord = trim((string) ($payload['ti_ri_ni_ac_se-orden'] ?? ''));
                if ($ord !== '' && preg_match('/^\d+$/', $ord) !== 1) {
                    return response()->json([
                        'message' => 'Invalid value for ti_ri_ni_ac_se-orden. Must be a number.',
                    ], 422);
                }
            }

            $excluded = array_values(array_filter([
                $tenantColumn,
                'ti_ri_ni_ac_se-observ',
                ...$pkColumns,
            ], static fn ($v) => is_string($v) && $v !== ''));

            $required = array_values(array_filter(
                $columns,
                static fn ($c) => ! in_array($c, $excluded, true)
            ));

            $missing = [];
            foreach ($required as $c) {
                if (! array_key_exists($c, $payload)) {
                    continue;
                }
                $v = $payload[$c] ?? null;
                $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : '');
                if ($s === '') {
                    $missing[] = $c;
                }
            }

            if (! empty($missing)) {
                return response()->json([
                    'message' => 'Missing required fields: '.implode(', ', $missing),
                ], 422);
            }
        }

        if (empty($payload)) {
            return response()->json(['message' => 'No fields to update.'], 422);
        }

        $beforeQuery = DB::table($table);
        $this->applyTenantScope($beforeQuery, $tenantColumn);
        $this->applyPkWhere($beforeQuery, $request, $pkColumns);
        $beforeRow = $beforeQuery->first();

        $query = DB::table($table);
        $this->applyTenantScope($query, $tenantColumn);
        $this->applyPkWhere($query, $request, $pkColumns);

        $updated = $query->update($payload);

        if ($updated === 0) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($beforeRow) {
            $before = (array) $beforeRow;
            $after = array_merge($before, $payload);
            $auditPayload = $this->auditPayloadForTable($table, $after, $before);
            if ($auditPayload !== null) {
                $this->auditLogger->logFromRequest($request, $auditPayload);
            }
        }

        return response()->json([
            'table' => $table,
            'message' => 'Updated.',
        ]);
    }

    public function destroy(Request $request, string $table): JsonResponse
    {
        $this->schema->assertAllowed($table);

        $columns = $this->schema->columns($table);
        $pkColumns = $this->schema->primaryKeyColumns($table);
        $tenantColumn = $this->schema->tenantColumn($columns);

        if (empty($pkColumns)) {
            return response()->json(['message' => 'Table has no primary key.'], 422);
        }

        $beforeQuery = DB::table($table);
        $this->applyTenantScope($beforeQuery, $tenantColumn);
        $this->applyPkWhere($beforeQuery, $request, $pkColumns);
        $beforeRow = $beforeQuery->first();

        $query = DB::table($table);
        $this->applyTenantScope($query, $tenantColumn);
        $this->applyPkWhere($query, $request, $pkColumns);

        $deleted = $query->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($beforeRow) {
            $before = (array) $beforeRow;
            $auditPayload = $this->auditPayloadForTable($table, $before, $before);
            if ($auditPayload !== null) {
                $this->auditLogger->logFromRequest($request, $auditPayload);
            }
        }

        return response()->json([
            'table' => $table,
            'message' => 'Deleted.',
        ]);
    }

    private function auditPayloadForTable(string $table, array $after, ?array $before): ?array
    {
        if ($table === 'activacion_del_plan_trs') {
            $planId = (string) ($after['ac_de_pl-id'] ?? $before['ac_de_pl-id'] ?? '');
            if ($planId === '') {
                return null;
            }
            if ($before === null) {
                return [
                    'event_type' => 'plan_created',
                    'module' => 'activation',
                    'plan_id' => $planId,
                    'entity_id' => $planId,
                    'entity_type' => $table,
                    'new_value' => $after,
                ];
            }
            $prevState = (string) ($before['ac_de_pl-estado'] ?? '');
            $nextState = (string) ($after['ac_de_pl-estado'] ?? $prevState);
            if ($prevState !== $nextState) {
                return [
                    'event_type' => 'plan_status_changed',
                    'module' => 'activation',
                    'plan_id' => $planId,
                    'entity_id' => $planId,
                    'entity_type' => $table,
                    'previous_value' => ['estado' => $prevState],
                    'new_value' => ['estado' => $nextState],
                ];
            }
            return null;
        }

        if ($table === 'ejecucion_accion_trs') {
            $planId = (string) ($after['ej_ac-ac_de_pl_id-fk'] ?? $before['ej_ac-ac_de_pl_id-fk'] ?? '');
            $entityId = (string) ($after['ej_ac-id'] ?? $before['ej_ac-id'] ?? '');
            if ($before === null) {
                return [
                    'event_type' => 'action_created',
                    'module' => 'actions',
                    'plan_id' => $planId !== '' ? $planId : null,
                    'entity_id' => $entityId !== '' ? $entityId : null,
                    'entity_type' => $table,
                    'new_value' => $after,
                ];
            }
            $prevState = (string) ($before['ej_ac-estado'] ?? '');
            $nextState = (string) ($after['ej_ac-estado'] ?? $prevState);
            if ($prevState !== $nextState) {
                return [
                    'event_type' => 'action_status_changed',
                    'module' => 'actions',
                    'plan_id' => $planId !== '' ? $planId : null,
                    'entity_id' => $entityId !== '' ? $entityId : null,
                    'entity_type' => $table,
                    'previous_value' => ['estado' => $prevState],
                    'new_value' => ['estado' => $nextState],
                ];
            }
            return null;
        }

        if ($table === 'asignacion_en_funciones_trs') {
            $planId = (string) ($after['as_en_fu-ac_de_pl_id-fk'] ?? $before['as_en_fu-ac_de_pl_id-fk'] ?? '');
            $entityId = (string) ($after['as_en_fu-id'] ?? $before['as_en_fu-id'] ?? '');
            if ($before === null) {
                return [
                    'event_type' => 'delegation_created',
                    'module' => 'delegations',
                    'plan_id' => $planId !== '' ? $planId : null,
                    'entity_id' => $entityId !== '' ? $entityId : null,
                    'entity_type' => $table,
                    'new_value' => $after,
                ];
            }
            $prevPer = (string) ($before['as_en_fu-per_id-fk'] ?? '');
            $nextPer = (string) ($after['as_en_fu-per_id-fk'] ?? $prevPer);
            $prevState = (string) ($before['as_en_fu-estado'] ?? '');
            $nextState = (string) ($after['as_en_fu-estado'] ?? $prevState);
            if ($prevPer !== $nextPer || $prevState !== $nextState) {
                return [
                    'event_type' => 'delegation_updated',
                    'module' => 'delegations',
                    'plan_id' => $planId !== '' ? $planId : null,
                    'entity_id' => $entityId !== '' ? $entityId : null,
                    'entity_type' => $table,
                    'previous_value' => ['per_id' => $prevPer, 'estado' => $prevState],
                    'new_value' => ['per_id' => $nextPer, 'estado' => $nextState],
                ];
            }
            return null;
        }

        return null;
    }

    private function applyTenantScope(Builder $query, ?string $tenantColumn): void
    {
        if ($tenantColumn === null) {
            return;
        }

        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            abort(422, __('messages.tenant.missing'));
        }

        $query->where($tenantColumn, $tenantId);
    }

    private function applyPkWhere(Builder $query, Request $request, array $pkColumns): void
    {
        foreach ($pkColumns as $pk) {
            $value = $request->query($pk);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                abort(422, 'Missing primary key query param: '.$pk);
            }
            $query->where($pk, $value);
        }
    }

    private function payload(Request $request): array
    {
        $contentType = (string) $request->header('Content-Type', '');
        $looksJson = str_contains(strtolower($contentType), 'application/json') || $request->isJson();

        if (! $looksJson) {
            $payload = $request->all();
            if (! is_array($payload)) {
                abort(422, 'Invalid request body.');
            }

            return $payload;
        }

        $payload = $request->json()->all();
        if (is_array($payload) && ! empty($payload)) {
            return $payload;
        }

        $raw = $request->getContent();
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                abort(422, 'Invalid JSON body.');
            }

            return $decoded;
        } catch (JsonException) {
        }

        if (! str_contains($raw, "\x00") && ! str_starts_with($raw, "\xFF\xFE") && ! str_starts_with($raw, "\xFE\xFF")) {
            abort(422, 'Invalid JSON body.');
        }

        if (! function_exists('iconv')) {
            abort(422, 'Invalid JSON body.');
        }

        $candidates = [];

        if (str_starts_with($raw, "\xFF\xFE")) {
            $candidates[] = 'UTF-16LE';
        } elseif (str_starts_with($raw, "\xFE\xFF")) {
            $candidates[] = 'UTF-16BE';
        } else {
            $candidates[] = 'UTF-16LE';
            $candidates[] = 'UTF-16BE';
        }

        foreach ($candidates as $enc) {
            $utf8 = iconv($enc, 'UTF-8//IGNORE', $raw);
            if (! is_string($utf8) || trim($utf8) === '') {
                continue;
            }

            try {
                $decoded = json_decode($utf8, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    continue;
                }

                return $decoded;
            } catch (JsonException) {
                continue;
            }
        }

        abort(422, 'Invalid JSON body.');
    }
}
