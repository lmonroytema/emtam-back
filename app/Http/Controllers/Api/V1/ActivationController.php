<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActivationController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $data = $request->validate([
            'ti_em_id' => ['required', 'string'],
            'rie_id' => ['required', 'string'],
            'ni_al_id' => ['required', 'string'],
            'plan_espec' => ['nullable', 'string'],
            'per_id' => ['required', 'string'],
            'rol_id' => ['required', 'string'],
            'cargo_declarado' => ['nullable', 'string'],
            'fecha_activac' => ['required', 'string'],
            'hora_activac' => ['required', 'string'],
            'estado' => ['required', 'string'],
            'mensaje_inic' => ['nullable', 'string'],
            'mensaje_simul' => ['nullable', 'string'],
            'observ' => ['nullable', 'string'],
            'info_adicional' => ['nullable', 'string'],
            'justificacion' => ['nullable', 'string'],
            'asignaciones_manuales' => ['nullable', 'array'],
            'asignaciones_manuales.*.accion_detalle_id' => ['required', 'string'],
            'asignaciones_manuales.*.gr_op_id' => ['nullable', 'string'],
            'asignaciones_manuales.*.titular_per_id' => ['nullable', 'string'],
            'asignaciones_manuales.*.suplente_per_ids' => ['nullable', 'array'],
            'asignaciones_manuales.*.suplente_per_ids.*' => ['string'],
        ]);

        $activationId = 'ACPL-'.Str::uuid()->toString();

        return DB::transaction(function () use ($data, $tenantId, $activationId) {
            if (! Schema::hasTable('activacion_del_plan_trs')) {
                return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
            }

            $niAl = Schema::hasTable('nivel_alerta_cat')
                ? DB::table('nivel_alerta_cat')->where('ni_al-id', $data['ni_al_id'])->first()
                : null;

            $niEm = null;
            if ($niAl !== null && Schema::hasTable('nivel_emergencia_cat')) {
                $niEmId = (string) ($niAl->{'ni_al-ni_em_id-fk'} ?? '');
                if ($niEmId !== '') {
                    $niEm = DB::table('nivel_emergencia_cat')->where('ni_em-id', $niEmId)->first();
                }
            }

            $niAlCod = strtoupper(trim((string) ($niAl?->{'ni_al-cod'} ?? '')));
            $niAlNombre = strtoupper(trim((string) ($niAl?->{'ni_al-nombre'} ?? '')));
            $niEmActivaPlan = strtoupper(trim((string) ($niEm?->{'ni_em-activa_plan'} ?? 'NO')));

            $isPrealerta = str_starts_with($niAlCod, 'P') || str_contains($niAlNombre, 'PREALERTA');

            $scenario = 'NORMALIDAD';
            if ($isPrealerta) {
                $scenario = 'PREALERTA';
            } elseif ($niEmActivaPlan === 'SI') {
                $scenario = 'ACTIVACION';
            }

            if ($scenario === 'ACTIVACION') {
                $requiredInfo = trim((string) ($data['info_adicional'] ?? ''));
                if ($requiredInfo === '') {
                    throw ValidationException::withMessages([
                        'info_adicional' => 'Debes completar Información Obligatoria antes de activar el plan.',
                    ]);
                }
            }

            DB::table('activacion_del_plan_trs')->insert([
                'ac_de_pl-id' => $activationId,
                'ac_de_pl-tenant_id' => $tenantId,
                'ac_de_pl-ti_em_id-fk' => $data['ti_em_id'],
                'ac_de_pl-rie_id-fk' => $data['rie_id'],
                'ac_de_pl-plan_espec' => $data['plan_espec'] ?? null,
                'ac_de_pl-ni_al_id-fk-inicial' => $data['ni_al_id'],
                'ac_de_pl-per_id-fk-activador' => $data['per_id'],
                'ac_de_pl-rol_id-fk-activador' => $data['rol_id'],
                'ac_de_pl-cargo_declarado' => $data['cargo_declarado'] ?? null,
                'ac_de_pl-fecha_activac' => $data['fecha_activac'],
                'ac_de_pl-hora_activac' => $data['hora_activac'],
                'ac_de_pl-estado' => $data['estado'],
                'ac_de_pl-mensaje_inic' => $data['mensaje_inic'] ?? null,
                'ac_de_pl-mensaje_simul' => $data['mensaje_simul'] ?? null,
                'ac_de_pl-observ' => $data['observ'] ?? null,
            ]);

            $now = now()->toDateTimeString();

            $actionSetIds = [];
            if (Schema::hasTable('riesgo_nivel_accion_set_cfg')) {
                $mapping = DB::table('riesgo_nivel_accion_set_cfg')
                    ->when(
                        Schema::hasColumn('riesgo_nivel_accion_set_cfg', 'ri_ni_ac_se-tenant_id'),
                        static fn ($q) => $q->where('ri_ni_ac_se-tenant_id', $tenantId),
                    )
                    ->where('ri_ni_ac_se-rie_id-fk', $data['rie_id'])
                    ->where('ri_ni_ac_se-ni_al_id-fk', $data['ni_al_id'])
                    ->whereRaw("UPPER(COALESCE(`ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                    ->orderByRaw("CAST(COALESCE(`ri_ni_ac_se-prioridad`, '999') AS UNSIGNED) ASC")
                    ->orderBy('ri_ni_ac_se-id')
                    ->get();

                foreach ($mapping as $row) {
                    $id = trim((string) ($row->{'ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                    if ($id !== '') {
                        $actionSetIds[] = $id;
                    }
                }
            }

            if (empty($actionSetIds) && Schema::hasTable('riesgo_cat') && Schema::hasTable('tipo_riesgo_nivel_accion_set_cfg')) {
                $riesgo = DB::table('riesgo_cat')
                    ->when(
                        Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                        static fn ($q) => $q->where('rie-tenant_id', $tenantId),
                    )
                    ->where('rie-id', $data['rie_id'])
                    ->first();
                $tipoRiesgoId = trim((string) ($riesgo?->{'rie-ti_ri_id-fk'} ?? ''));

                if ($tipoRiesgoId !== '') {
                    $mappingTipo = DB::table('tipo_riesgo_nivel_accion_set_cfg')
                        ->when(
                            Schema::hasColumn('tipo_riesgo_nivel_accion_set_cfg', 'ti_ri_ni_ac_se-tenant_id'),
                            static fn ($q) => $q->where('ti_ri_ni_ac_se-tenant_id', $tenantId),
                        )
                        ->where('ti_ri_ni_ac_se-ti_ri_id-fk', $tipoRiesgoId)
                        ->where('ti_ri_ni_ac_se-ni_al_id-fk', $data['ni_al_id'])
                        ->whereRaw("UPPER(COALESCE(`ti_ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                        ->orderByRaw("CAST(COALESCE(`ti_ri_ni_ac_se-orden`, '999') AS UNSIGNED) ASC")
                        ->orderBy('ti_ri_ni_ac_se-id')
                        ->get();

                    foreach ($mappingTipo as $row) {
                        $id = trim((string) ($row->{'ti_ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                        if ($id !== '') {
                            $actionSetIds[] = $id;
                        }
                    }
                }
            }

            if ($scenario === 'PREALERTA' && Schema::hasTable('accion_set_cfg')) {
                $pre = DB::table('accion_set_cfg')
                    ->whereIn('ac_se-cod', ['AS01', 'AS04'])
                    ->whereRaw("UPPER(COALESCE(`ac_se-activo`, 'SI')) <> 'NO'")
                    ->when(
                        Schema::hasColumn('accion_set_cfg', 'ac_se-tenant_id'),
                        static fn ($q) => $q->where('ac_se-tenant_id', $tenantId),
                    )
                    ->pluck('ac_se-id')
                    ->all();

                if (! empty($pre)) {
                    $actionSetIds = array_values(array_unique(array_map('strval', $pre)));
                }
            } elseif ($scenario !== 'PREALERTA' && ! empty($actionSetIds)) {
                $actionSetIds = [array_values($actionSetIds)[0]];
            }

            $actionSetIds = array_values(array_unique(array_filter($actionSetIds, static fn ($v) => is_string($v) && trim($v) !== '')));

            if (Schema::hasTable('activacion_nivel_hist_trs')) {
                DB::table('activacion_nivel_hist_trs')->insert([
                    'ac_ni_hi-id' => 'ACNI-'.Str::uuid()->toString(),
                    'ac_ni_hi-tenant_id' => $tenantId,
                    'ac_ni_hi-ac_de_pl_id-fk' => $activationId,
                    'ac_ni_hi-ni_al_id-fk' => $data['ni_al_id'],
                    'ac_ni_hi-ac_se_id-fk' => $actionSetIds[0] ?? null,
                    'ac_ni_hi-fech_ini' => $data['fecha_activac'],
                    'ac_ni_hi-hora_ini' => $data['hora_activac'],
                    'ac_ni_hi-fech_fin' => null,
                    'ac_ni_hi-hora_fin' => null,
                    'ac_ni_hi-nivel_inicial' => 'SI',
                    'ac_ni_hi-motivo_cambio' => null,
                    'ac_ni_hi-per_id-fk-registrador' => $data['per_id'],
                    'ac_ni_hi-rol_id-fk-registrador' => $data['rol_id'],
                    'ac_ni_hi-fuente_cambio' => 'activacion',
                    'ac_ni_hi-observ' => null,
                    'ac_ni_hi-activo' => 'SI',
                    'ac_ni_hi-orden' => '1',
                    'ac_ni_hi-cr_ri_id-fk' => null,
                    'ac_ni_hi-valores_parametros' => null,
                    'ac_ni_hi-ni_al_id-fk-declarado' => $data['ni_al_id'],
                    'ac_ni_hi-fuente_decision' => 'manual',
                    'ac_ni_hi-justificacion' => $data['justificacion'] ?? null,
                    'ac_ni_hi-info_adicional' => $data['info_adicional'] ?? null,
                ]);
            }

            $unassignedActions = [];
            $ejecucionCount = 0;
            $notificationCount = 0;

            $manualAssignmentsByDetalleId = [];
            foreach (($data['asignaciones_manuales'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $detalleId = trim((string) ($row['accion_detalle_id'] ?? ''));
                if ($detalleId === '') {
                    continue;
                }
                $grOpId = trim((string) ($row['gr_op_id'] ?? ''));
                $titular = trim((string) ($row['titular_per_id'] ?? ''));
                $suplentes = [];
                foreach (($row['suplente_per_ids'] ?? []) as $sid) {
                    $sidStr = trim((string) $sid);
                    if ($sidStr !== '') {
                        $suplentes[] = $sidStr;
                    }
                }
                $suplentes = array_values(array_unique($suplentes));

                if ($titular !== '' || ! empty($suplentes)) {
                    $manualAssignmentsByDetalleId[$detalleId] = [
                        'gr_op_id' => $grOpId !== '' ? $grOpId : null,
                        'titular_per_id' => $titular !== '' ? $titular : null,
                        'suplente_per_ids' => $suplentes,
                    ];
                }
            }

            $shouldHydrate = $scenario !== 'NORMALIDAD' && ! empty($actionSetIds);
            if (! $shouldHydrate) {
                return response()->json([
                    'activation_id' => $activationId,
                    'scenario' => $scenario,
                    'action_set_ids' => $actionSetIds,
                    'unassigned_actions' => [],
                    'ejecucion_count' => 0,
                    'notification_count' => 0,
                ], 201);
            }

            if (! Schema::hasTable('accion_set_detalle_cfg')) {
                return response()->json([
                    'activation_id' => $activationId,
                    'scenario' => $scenario,
                    'action_set_ids' => $actionSetIds,
                    'unassigned_actions' => [],
                    'ejecucion_count' => 0,
                    'notification_count' => 0,
                    'message' => 'Missing accion_set_detalle_cfg table.',
                ], 201);
            }

            $detalles = DB::table('accion_set_detalle_cfg')
                ->whereIn('ac_se_de-ac_se_id-fk', $actionSetIds)
                ->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'")
                ->orderByRaw("CAST(COALESCE(`ac_se_de-ord_ejec`, '999') AS UNSIGNED) ASC")
                ->orderBy('ac_se_de-id')
                ->get();

            $isSimulacro = false;
            if (Schema::hasTable('tipo_emergencia_cat')) {
                $tiEm = DB::table('tipo_emergencia_cat')->where('ti_em-id', $data['ti_em_id'])->first();
                $tiEmCod = strtoupper(trim((string) ($tiEm?->{'ti_em-cod'} ?? '')));
                $tiEmNombre = strtoupper(trim((string) ($tiEm?->{'ti_em-nombre'} ?? '')));
                $isSimulacro = str_contains($tiEmCod, 'SIM') || str_contains($tiEmNombre, 'SIMULACRO');
            }

            $prefix = $isSimulacro ? '[SIMULACRO] ' : '';
            $message = match ($scenario) {
                'PREALERTA' => $prefix.'Aviso preventivo: Situación de Prealerta activada',
                'ACTIVACION' => $prefix.'URGENTE: PLAN ACTIVADO. Confirme recepción',
                default => $prefix.'Aviso',
            };

            $personaRolGrupoByRol = [];
            $asignacionByKey = [];

            foreach ($detalles as $de) {
                $detalleId = (string) ($de->{'ac_se_de-id'} ?? '');
                $rolId = $de->{'ac_se_de-rol_id-fk'} ?? null;

                $rolIdStr = trim((string) ($rolId ?? ''));
                $manualAssignment = $detalleId !== '' ? ($manualAssignmentsByDetalleId[$detalleId] ?? null) : null;
                $recipients = [];

                if ($rolIdStr !== '' && Schema::hasTable('persona_rol_grupo_cfg')) {
                    if (! array_key_exists($rolIdStr, $personaRolGrupoByRol)) {
                        $personaRolGrupoByRol[$rolIdStr] = DB::table('persona_rol_grupo_cfg')
                            ->where('pe_ro_gr-rol_id-fk', $rolIdStr)
                            ->whereRaw("UPPER(COALESCE(`pe_ro_gr-activo`, 'SI')) <> 'NO'")
                            ->whereNull('pe_ro_gr-fech_fin')
                            ->get();
                    }

                    foreach ($personaRolGrupoByRol[$rolIdStr] as $dest) {
                        $perId = trim((string) ($dest->{'pe_ro_gr-per_id-fk'} ?? ''));
                        if ($perId === '') {
                            continue;
                        }

                        $tipoAsign = strtoupper(trim((string) ($dest->{'pe_ro_gr-tipo_asignacion'} ?? '')));
                        if ($tipoAsign !== '' && $tipoAsign !== 'TITULAR' && $tipoAsign !== 'SUPLENTE') {
                            continue;
                        }
                        if ($tipoAsign === '') {
                            $tipoAsign = 'SUPLENTE';
                        }

                        $grOpId = $dest->{'pe_ro_gr-gr_op_id-fk'} ?? null;
                        $grOpIdStr = trim((string) ($grOpId ?? ''));

                        $recipients[] = [
                            'per_id' => $perId,
                            'gr_op_id' => $grOpIdStr !== '' ? $grOpIdStr : null,
                            'tipo_asignacion' => $tipoAsign,
                        ];
                    }
                }

                if (empty($recipients) && $manualAssignment !== null) {
                    $titularPerId = trim((string) ($manualAssignment['titular_per_id'] ?? ''));
                    $manualGrOpId = trim((string) ($manualAssignment['gr_op_id'] ?? ''));
                    $manualGrOpId = $manualGrOpId !== '' ? $manualGrOpId : null;
                    if ($titularPerId !== '') {
                        $recipients[] = [
                            'per_id' => $titularPerId,
                            'gr_op_id' => $manualGrOpId,
                            'tipo_asignacion' => 'TITULAR',
                        ];
                    }

                    foreach (($manualAssignment['suplente_per_ids'] ?? []) as $sid) {
                        $sidStr = trim((string) $sid);
                        if ($sidStr === '') {
                            continue;
                        }
                        $recipients[] = [
                            'per_id' => $sidStr,
                            'gr_op_id' => $manualGrOpId,
                            'tipo_asignacion' => 'SUPLENTE',
                        ];
                    }
                }

                $recipients = array_values(array_filter($recipients, static function ($r) {
                    return is_array($r) && trim((string) ($r['per_id'] ?? '')) !== '';
                }));

                if ($detalleId !== '' && empty($recipients)) {
                    $unassignedActions[] = $detalleId;
                }

                if (Schema::hasTable('ejecucion_accion_trs') && $detalleId !== '') {

                    if (empty($recipients)) {
                        DB::table('ejecucion_accion_trs')->insert([
                            'ej_ac-id' => 'EJAC-'.Str::uuid()->toString(),
                            'ej_ac-tenant_id' => $tenantId,
                            'ej_ac-ac_de_pl_id-fk' => $activationId,
                            'ej_ac-gr_op_id-fk' => null,
                            'ej_ac-ac_se_de_id-fk' => $detalleId,
                            'ej_ac-as_en_fu_id-fk' => null,
                            'ej_ac-estado' => 'PENDIENTE',
                            'ej_ac-ts_ini' => $now,
                            'ej_ac-ts_fin' => null,
                            'ej_ac-observ' => null,
                        ]);
                        $ejecucionCount++;

                        continue;
                    }

                    foreach ($recipients as $r) {
                        if (! Schema::hasTable('asignacion_en_funciones_trs')) {
                            $asignacionId = null;
                        } else {
                            $key = trim((string) $r['per_id']).'|'.trim((string) ($r['gr_op_id'] ?? '')).'|'.trim((string) ($r['tipo_asignacion'] ?? ''));
                            $asignacionId = $asignacionByKey[$key] ?? null;

                            if ($asignacionId === null) {
                                $asignacionId = 'ASEF-'.Str::uuid()->toString();
                                DB::table('asignacion_en_funciones_trs')->insert([
                                    'as_en_fu-id' => $asignacionId,
                                    'as_en_fu-tenant_id' => $tenantId,
                                    'as_en_fu-ac_de_pl_id-fk' => $activationId,
                                    'as_en_fu-gr_op_id-fk' => $r['gr_op_id'],
                                    'as_en_fu-per_id-fk' => $r['per_id'],
                                    'as_en_fu-tipo_asignacion' => $r['tipo_asignacion'],
                                    'as_en_fu-per_id-fk-delegador' => $data['per_id'],
                                    'as_en_fu-motivo' => null,
                                    'as_en_fu-ts_ini' => $now,
                                    'as_en_fu-ts_fin' => null,
                                    'as_en_fu-estado' => 'ACTIVA',
                                ]);

                                $asignacionByKey[$key] = $asignacionId;
                            }
                        }

                        DB::table('ejecucion_accion_trs')->insert([
                            'ej_ac-id' => 'EJAC-'.Str::uuid()->toString(),
                            'ej_ac-tenant_id' => $tenantId,
                            'ej_ac-ac_de_pl_id-fk' => $activationId,
                            'ej_ac-gr_op_id-fk' => $r['gr_op_id'],
                            'ej_ac-ac_se_de_id-fk' => $detalleId,
                            'ej_ac-as_en_fu_id-fk' => $asignacionId,
                            'ej_ac-estado' => 'PENDIENTE',
                            'ej_ac-ts_ini' => $now,
                            'ej_ac-ts_fin' => null,
                            'ej_ac-observ' => null,
                        ]);
                        $ejecucionCount++;
                    }
                }
            }

            return response()->json([
                'activation_id' => $activationId,
                'scenario' => $scenario,
                'action_set_ids' => $actionSetIds,
                'unassigned_actions' => array_values(array_unique($unassignedActions)),
                'ejecucion_count' => $ejecucionCount,
                'notification_count' => $notificationCount,
            ], 201);
        });
    }

    public function sendNotifications(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        $validated = $request->validate([
            'accion_detalle_id' => ['nullable', 'string'],
        ]);
        $accionDetalleId = trim((string) ($validated['accion_detalle_id'] ?? ''));

        if (
            ! Schema::hasTable('ejecucion_accion_trs')
            || ! Schema::hasTable('asignacion_en_funciones_trs')
            || ! Schema::hasTable('accion_set_detalle_cfg')
        ) {
            return response()->json(['message' => 'Missing required tables.'], 422);
        }

        $rows = DB::table('ejecucion_accion_trs as ej')
            ->join('asignacion_en_funciones_trs as asg', 'asg.as_en_fu-id', '=', 'ej.ej_ac-as_en_fu_id-fk')
            ->join('accion_set_detalle_cfg as de', 'de.ac_se_de-id', '=', 'ej.ej_ac-ac_se_de_id-fk')
            ->leftJoin('accion_operativa_cfg as ac', 'ac.ac_op-id', '=', 'de.ac_se_de-ac_op_id-fk')
            ->leftJoin('persona_mst as p', 'p.per-id', '=', 'asg.as_en_fu-per_id-fk')
            ->where('ej.ej_ac-tenant_id', $tenantId)
            ->where('ej.ej_ac-ac_de_pl_id-fk', $activationId)
            ->when($accionDetalleId !== '', static fn ($q) => $q->where('de.ac_se_de-id', $accionDetalleId))
            ->orderByRaw("CAST(COALESCE(`de`.`ac_se_de-ord_ejec`, '999') AS UNSIGNED) ASC")
            ->orderBy('de.ac_se_de-id')
            ->get([
                'ej.ej_ac-id as ejecucion_id',
                'ej.ej_ac-estado as ejecucion_estado',
                'asg.as_en_fu-per_id-fk as per_id',
                'asg.as_en_fu-tipo_asignacion as tipo_asignacion',
                'p.per-email as email',
                'p.per-tel_mov as tel_mov',
                'p.per-nombre as nombre',
                'p.per-apellido_1 as apellido_1',
                'p.per-apellido_2 as apellido_2',
                'ac.ac_op-id as accion_operativa_id',
                'ac.ac_op-descrip as accion_descrip',
                'ac.ac_op-cod as accion_cod',
                'de.ac_se_de-id as accion_detalle_id',
            ]);

        $byPerson = [];
        foreach ($rows as $r) {
            $perId = trim((string) ($r->per_id ?? ''));
            if ($perId === '') {
                continue;
            }
            $email = strtolower(trim((string) ($r->email ?? '')));
            $telMov = trim((string) ($r->tel_mov ?? ''));
            $nombre = trim(implode(' ', array_filter([
                (string) ($r->nombre ?? ''),
                (string) ($r->apellido_1 ?? ''),
                (string) ($r->apellido_2 ?? ''),
            ])));
            $accion = trim((string) ($r->accion_descrip ?? '')) ?: trim((string) ($r->accion_cod ?? '')) ?: trim((string) ($r->accion_detalle_id ?? ''));
            $tipo = strtoupper(trim((string) ($r->tipo_asignacion ?? 'SUPLENTE')));
            if ($tipo !== 'TITULAR') {
                $tipo = 'SUPLENTE';
            }

            $byPerson[$perId] ??= [
                'per_id' => $perId,
                'email' => $email !== '' ? $email : null,
                'tel_mov' => $telMov !== '' ? $telMov : null,
                'nombre' => $nombre !== '' ? $nombre : $perId,
                'acciones' => [],
            ];
            $byPerson[$perId]['acciones'][] = [
                'ejecucion_id' => (string) ($r->ejecucion_id ?? ''),
                'accion_detalle_id' => (string) ($r->accion_detalle_id ?? ''),
                'accion_operativa_id' => (string) ($r->accion_operativa_id ?? ''),
                'accion_operativa_cod' => (string) ($r->accion_cod ?? ''),
                'accion_operativa_descrip' => (string) ($r->accion_descrip ?? ''),
                'accion' => $accion,
                'tipo_asignacion' => $tipo,
                'estado' => (string) ($r->ejecucion_estado ?? ''),
            ];
        }

        $people = array_values($byPerson);

        $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $testEmails = [];
        if (! $productionMode) {
            $raw = $tenant?->test_notification_emails;
            $rawArr = is_array($raw) ? $raw : [];
            $emails = [];
            foreach ($rawArr as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '') {
                    $emails[] = $e;
                }
            }
            $testEmails = array_values(array_unique($emails));
        }

        $testWhatsappNumbers = [];
        if (! $productionMode) {
            $raw = $tenant?->test_notification_whatsapp_numbers;
            $rawArr = is_array($raw) ? $raw : [];
            $numbers = [];
            foreach ($rawArr as $n) {
                $n = trim((string) $n);
                if ($n === '') {
                    continue;
                }
                $n = preg_replace('/[()\-\.\s]+/', '', $n) ?? '';
                if (str_starts_with($n, '+')) {
                    $digits = preg_replace('/\D+/', '', substr($n, 1)) ?? '';
                    $n = $digits !== '' ? '+'.$digits : '';
                } else {
                    $n = preg_replace('/\D+/', '', $n) ?? '';
                }
                if ($n !== '') {
                    $numbers[] = $n;
                }
            }
            $testWhatsappNumbers = array_values(array_unique($numbers));
        }

        $mode = app()->environment('local') ? 'file' : 'mail';
        $ts = now()->format('Ymd_His');
        $sent = 0;
        $filesWritten = 0;
        $whatsappSent = 0;
        $whatsappFilesWritten = 0;

        if ($mode === 'file') {
            $dir = 'notifications_outbox/'.$tenantId.'/'.$activationId;
            if (! Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->makeDirectory($dir);
            }
        }

        $index = [
            'activation_id' => $activationId,
            'tenant_id' => $tenantId,
            'mode' => $mode,
            'generated_at' => now()->toDateTimeString(),
            'recipients' => [],
        ];

        foreach ($people as $p) {
            $to = $productionMode ? (string) ($p['email'] ?? '') : implode(',', $testEmails);
            $subject = 'Acciones asignadas — '.$activationId;

            $accionesByTipo = [
                'TITULAR' => [],
                'SUPLENTE' => [],
            ];

            foreach (($p['acciones'] ?? []) as $a) {
                $tag = strtoupper((string) ($a['tipo_asignacion'] ?? 'SUPLENTE')) === 'TITULAR' ? 'TITULAR' : 'SUPLENTE';
                $estado = trim((string) ($a['estado'] ?? '')) ?: 'PENDIENTE';
                $key = trim((string) ($a['accion_operativa_id'] ?? '')) ?: trim((string) ($a['accion_detalle_id'] ?? '')) ?: trim((string) ($a['accion'] ?? ''));
                $label = trim((string) ($a['accion_operativa_descrip'] ?? '')) ?: trim((string) ($a['accion_operativa_cod'] ?? '')) ?: trim((string) ($a['accion'] ?? ''));

                if (! array_key_exists($key, $accionesByTipo[$tag])) {
                    $accionesByTipo[$tag][$key] = [
                        'accion_operativa_id' => trim((string) ($a['accion_operativa_id'] ?? '')) ?: null,
                        'accion_operativa_cod' => trim((string) ($a['accion_operativa_cod'] ?? '')) ?: null,
                        'accion_operativa_descrip' => trim((string) ($a['accion_operativa_descrip'] ?? '')) ?: null,
                        'accion' => $label,
                        'items' => [],
                    ];
                }

                $accionesByTipo[$tag][$key]['items'][] = [
                    'ejecucion_id' => (string) ($a['ejecucion_id'] ?? ''),
                    'accion_detalle_id' => (string) ($a['accion_detalle_id'] ?? ''),
                    'estado' => $estado,
                ];
            }

            $lines = [];
            $lines[] = 'ACTIVACION: '.$activationId;
            $lines[] = 'PERSONA: '.(string) ($p['nombre'] ?? $p['per_id']);
            $lines[] = 'EMAIL: '.($to !== '' ? $to : '—');
            $lines[] = '';
            $lines[] = 'ACCIONES (TITULAR):';
            foreach (($accionesByTipo['TITULAR'] ?? []) as $group) {
                $lines[] = '- '.$group['accion'];
                foreach (($group['items'] ?? []) as $it) {
                    $lines[] = '  * '.$it['estado'].' ('.$it['ejecucion_id'].')';
                }
            }
            $lines[] = '';
            $lines[] = 'ACCIONES (SUPLENTE):';
            foreach (($accionesByTipo['SUPLENTE'] ?? []) as $group) {
                $lines[] = '- '.$group['accion'];
                foreach (($group['items'] ?? []) as $it) {
                    $lines[] = '  * '.$it['estado'].' ('.$it['ejecucion_id'].')';
                }
            }
            $body = implode("\n", $lines)."\n";

            if ($mode === 'file') {
                $safeTarget = $productionMode ? ($to !== '' ? $to : (string) ($p['per_id'] ?? 'persona')) : ($testEmails[0] ?? 'test');
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $safeTarget) ?: 'persona';
                $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.txt';
                Storage::disk('local')->put($path, $body);
                $jsonPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.json';
                Storage::disk('local')->put($jsonPath, json_encode([
                    'activation_id' => $activationId,
                    'tenant_id' => $tenantId,
                    'mode' => $mode,
                    'generated_at' => now()->toDateTimeString(),
                    'subject' => $subject,
                    'persona' => [
                        'per_id' => (string) ($p['per_id'] ?? ''),
                        'nombre' => (string) ($p['nombre'] ?? ''),
                        'email' => $to !== '' ? $to : null,
                    ],
                    'acciones_por_tipo' => [
                        'TITULAR' => array_values($accionesByTipo['TITULAR'] ?? []),
                        'SUPLENTE' => array_values($accionesByTipo['SUPLENTE'] ?? []),
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $filesWritten++;
            } else {
                if ($productionMode) {
                    if ($to !== '') {
                        Mail::raw($body, static function ($m) use ($to, $subject) {
                            $m->to($to)->subject($subject);
                        });
                        $sent++;
                    }
                } elseif (! empty($testEmails)) {
                    Mail::raw($body, static function ($m) use ($testEmails, $subject) {
                        $m->to($testEmails)->subject($subject);
                    });
                    $sent++;
                }
            }

            if (Schema::hasTable('notificacion_envio_trs')) {
                DB::table('notificacion_envio_trs')->insert([
                    'no_en-id' => 'NOEN-'.Str::uuid()->toString(),
                    'no_en-tenant_id' => $tenantId,
                    'no_en-ac_de_pl_id-fk' => $activationId,
                    'no_en-per_id-fk' => $p['per_id'],
                    'no_en-gr_op_id-fk' => null,
                    'no_en-rol_id-fk' => null,
                    'no_en-ca_co_id-fk' => null,
                    'no_en-mensaje' => $subject,
                    'no_en-ts' => now()->toDateTimeString(),
                    'no_en-estado' => $mode === 'file' ? 'SIMULADO' : 'ENVIADO',
                    'no_en-num_de_intento' => '0',
                ]);
            }

            $index['recipients'][] = [
                'per_id' => (string) ($p['per_id'] ?? ''),
                'nombre' => (string) ($p['nombre'] ?? ''),
                'email' => $to !== '' ? $to : null,
            ];
        }

        $normalizePhone = static function (?string $raw): ?string {
            $s = trim((string) ($raw ?? ''));
            if ($s === '') {
                return null;
            }
            $s = preg_replace('/[()\-\.\s]+/', '', $s) ?? '';
            if ($s === '') {
                return null;
            }
            if (str_starts_with($s, '+')) {
                $digits = preg_replace('/\D+/', '', substr($s, 1)) ?? '';
                $s = $digits !== '' ? '+'.$digits : '';
            } else {
                $s = preg_replace('/\D+/', '', $s) ?? '';
            }
            if ($s === '' || preg_match('/^\+?\d{8,15}$/', $s) !== 1) {
                return null;
            }

            return $s;
        };

        $whatsappTargets = [];
        if ($productionMode) {
            foreach ($people as $p) {
                $n = $normalizePhone(is_string($p['tel_mov'] ?? null) ? $p['tel_mov'] : null);
                if ($n === null) {
                    continue;
                }
                $perId = trim((string) ($p['per_id'] ?? ''));
                if (! array_key_exists($n, $whatsappTargets)) {
                    $whatsappTargets[$n] = ['to' => $n, 'per_ids' => []];
                }
                if ($perId !== '' && ! in_array($perId, $whatsappTargets[$n]['per_ids'], true)) {
                    $whatsappTargets[$n]['per_ids'][] = $perId;
                }
            }
        } else {
            foreach ($testWhatsappNumbers as $n) {
                $n = $normalizePhone(is_string($n) ? $n : null);
                if ($n === null) {
                    continue;
                }
                if (! array_key_exists($n, $whatsappTargets)) {
                    $whatsappTargets[$n] = ['to' => $n, 'per_ids' => []];
                }
            }
        }
        $whatsappTargets = array_values($whatsappTargets);

        if (! empty($whatsappTargets)) {
            $waDir = 'whatsapp_outbox/'.$tenantId.'/'.$activationId;
            if (! Storage::disk('local')->exists($waDir)) {
                Storage::disk('local')->makeDirectory($waDir);
            }

            $appUrl = rtrim((string) config('app.url', ''), '/');
            $activationUrl = $appUrl !== '' ? $appUrl.'/activacion/'.rawurlencode($activationId) : null;
            $waMessageLines = [
                'Plan Activo',
                'Activación: '.$activationId,
            ];
            if ($activationUrl) {
                $waMessageLines[] = 'Enlace: '.$activationUrl;
            }
            $waMessage = implode("\n", $waMessageLines)."\n";
            $webhookUrl = trim((string) env('WHATSAPP_WEBHOOK_URL', ''));

            foreach ($whatsappTargets as $t) {
                $toNumber = (string) ($t['to'] ?? '');
                $perIds = is_array($t['per_ids'] ?? null) ? $t['per_ids'] : [];
                $sentOk = false;
                if ($webhookUrl !== '') {
                    try {
                        $res = $this->postWhatsappWebhook($webhookUrl, [
                            'tenant_id' => $tenantId,
                            'activation_id' => $activationId,
                            'to' => $toNumber,
                            'message' => $waMessage,
                        ]);
                        $sentOk = $res instanceof HttpClientResponse ? $res->successful() : false;
                    } catch (\Throwable) {
                        $sentOk = false;
                    }
                }

                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $toNumber) ?: 'destino';
                $jsonPath = $waDir.'/'.$ts.'-'.$safe.'.json';
                Storage::disk('local')->put($jsonPath, json_encode([
                    'tenant_id' => $tenantId,
                    'activation_id' => $activationId,
                    'to' => $toNumber,
                    'message' => $waMessage,
                    'sent_via_webhook' => $sentOk,
                    'generated_at' => now()->toDateTimeString(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $whatsappFilesWritten++;

                if ($sentOk) {
                    $whatsappSent++;
                }

                if (Schema::hasTable('notificacion_envio_trs')) {
                    $perIdForLog = null;
                    if (count($perIds) === 1) {
                        $perIdForLog = $perIds[0];
                    }
                    DB::table('notificacion_envio_trs')->insert([
                        'no_en-id' => 'NOEN-'.Str::uuid()->toString(),
                        'no_en-tenant_id' => $tenantId,
                        'no_en-ac_de_pl_id-fk' => $activationId,
                        'no_en-per_id-fk' => $perIdForLog,
                        'no_en-gr_op_id-fk' => null,
                        'no_en-rol_id-fk' => null,
                        'no_en-ca_co_id-fk' => 'WHATSAPP',
                        'no_en-mensaje' => $waMessage."\nDestino: ".$toNumber,
                        'no_en-ts' => now()->toDateTimeString(),
                        'no_en-estado' => ($mode === 'file' || ! $sentOk) ? 'SIMULADO' : 'ENVIADO',
                        'no_en-num_de_intento' => '0',
                    ]);
                }
            }
        }

        if ($mode === 'file') {
            $indexPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-index.json';
            Storage::disk('local')->put($indexPath, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $filesWritten++;
        }

        return response()->json([
            'message' => 'OK',
            'mode' => $mode,
            'sent' => $sent,
            'files_written' => $filesWritten,
            'recipients' => count($people),
            'whatsapp_sent' => $whatsappSent,
            'whatsapp_files_written' => $whatsappFilesWritten,
            'whatsapp_recipients' => count($whatsappTargets),
        ]);
    }

    private function postWhatsappWebhook(string $webhookUrl, array $payload): HttpClientResponse|\GuzzleHttp\Promise\PromiseInterface
    {
        return Http::timeout(8)->post($webhookUrl, $payload);
    }

    public function sendEndNotifications(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        $validated = $request->validate([
            'detalle' => ['nullable', 'string'],
        ]);

        if (
            ! Schema::hasTable('ejecucion_accion_trs')
            || ! Schema::hasTable('asignacion_en_funciones_trs')
            || ! Schema::hasTable('accion_set_detalle_cfg')
            || ! Schema::hasTable('persona_mst')
        ) {
            return response()->json(['message' => 'Missing required tables.'], 422);
        }

        $rows = DB::table('ejecucion_accion_trs as ej')
            ->join('asignacion_en_funciones_trs as asg', 'asg.as_en_fu-id', '=', 'ej.ej_ac-as_en_fu_id-fk')
            ->join('accion_set_detalle_cfg as de', 'de.ac_se_de-id', '=', 'ej.ej_ac-ac_se_de_id-fk')
            ->leftJoin('persona_mst as p', 'p.per-id', '=', 'asg.as_en_fu-per_id-fk')
            ->where('ej.ej_ac-tenant_id', $tenantId)
            ->where('ej.ej_ac-ac_de_pl_id-fk', $activationId)
            ->orderBy('asg.as_en_fu-per_id-fk')
            ->get([
                'asg.as_en_fu-per_id-fk as per_id',
                'p.per-email as email',
                'p.per-nombre as nombre',
                'p.per-apellido_1 as apellido_1',
                'p.per-apellido_2 as apellido_2',
            ]);

        $byPerson = [];
        foreach ($rows as $r) {
            $perId = trim((string) ($r->per_id ?? ''));
            if ($perId === '') {
                continue;
            }
            $email = strtolower(trim((string) ($r->email ?? '')));
            $nombre = trim(implode(' ', array_filter([
                (string) ($r->nombre ?? ''),
                (string) ($r->apellido_1 ?? ''),
                (string) ($r->apellido_2 ?? ''),
            ])));

            $byPerson[$perId] ??= [
                'per_id' => $perId,
                'email' => $email !== '' ? $email : null,
                'nombre' => $nombre !== '' ? $nombre : $perId,
            ];
        }

        $people = array_values($byPerson);
        if (empty($people)) {
            return response()->json([
                'message' => 'OK',
                'mode' => app()->environment('local') ? 'file' : 'mail',
                'sent' => 0,
                'files_written' => 0,
            ]);
        }

        $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $testEmails = [];
        if (! $productionMode) {
            $raw = $tenant?->test_notification_emails;
            $rawArr = is_array($raw) ? $raw : [];
            $emails = [];
            foreach ($rawArr as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '') {
                    $emails[] = $e;
                }
            }
            $testEmails = array_values(array_unique($emails));
        }

        $isSimulacro = false;
        if (Schema::hasTable('activacion_del_plan_trs') && Schema::hasTable('tipo_emergencia_cat')) {
            $activation = DB::table('activacion_del_plan_trs')
                ->where('ac_de_pl-tenant_id', $tenantId)
                ->where('ac_de_pl-id', $activationId)
                ->first();
            $tiEmId = trim((string) ($activation?->{'ac_de_pl-ti_em_id-fk'} ?? ''));
            if ($tiEmId !== '') {
                $tiEm = DB::table('tipo_emergencia_cat')
                    ->when(
                        Schema::hasColumn('tipo_emergencia_cat', 'ti_em-tenant_id'),
                        static fn ($q) => $q->where('ti_em-tenant_id', $tenantId),
                    )
                    ->where('ti_em-id', $tiEmId)
                    ->first();
                $tiEmCod = strtoupper(trim((string) ($tiEm?->{'ti_em-cod'} ?? '')));
                $tiEmNombre = strtoupper(trim((string) ($tiEm?->{'ti_em-nombre'} ?? '')));
                $isSimulacro = str_contains($tiEmCod, 'SIM') || str_contains($tiEmNombre, 'SIMULACRO');
            }
        }

        $mode = app()->environment('local') ? 'file' : 'mail';
        $ts = now()->format('Ymd_His');
        $sent = 0;
        $filesWritten = 0;

        if ($mode === 'file') {
            $dir = 'notifications_outbox/'.$tenantId.'/'.$activationId;
            if (! Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->makeDirectory($dir);
            }
        }

        $label = $isSimulacro ? 'simulacro' : 'emergencia';
        $prefix = $isSimulacro ? '[SIMULACRO] ' : '';
        $subject = $prefix.'Fin de '.$label.' — '.$activationId;
        $detalle = trim((string) ($validated['detalle'] ?? ''));

        $index = [
            'activation_id' => $activationId,
            'tenant_id' => $tenantId,
            'mode' => $mode,
            'generated_at' => now()->toDateTimeString(),
            'recipients' => [],
        ];

        foreach ($people as $p) {
            $to = $productionMode ? (string) ($p['email'] ?? '') : implode(',', $testEmails);
            $lines = [];
            $lines[] = 'ACTIVACION: '.$activationId;
            $lines[] = 'AVISO: Fin de '.$label;
            $lines[] = 'FECHA/HORA: '.now()->toDateTimeString();
            $lines[] = 'PERSONA: '.(string) ($p['nombre'] ?? $p['per_id']);
            $lines[] = 'EMAIL: '.($to !== '' ? $to : '—');
            if ($detalle !== '') {
                $lines[] = '';
                $lines[] = 'DETALLE:';
                $lines[] = $detalle;
            }
            $body = implode("\n", $lines)."\n";

            if ($mode === 'file') {
                $safeTarget = $productionMode ? ($to !== '' ? $to : (string) ($p['per_id'] ?? 'persona')) : ($testEmails[0] ?? 'test');
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $safeTarget) ?: 'persona';
                $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-end-'.$safe.'.txt';
                Storage::disk('local')->put($path, $body);
                $jsonPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-end-'.$safe.'.json';
                Storage::disk('local')->put($jsonPath, json_encode([
                    'activation_id' => $activationId,
                    'tenant_id' => $tenantId,
                    'mode' => $mode,
                    'generated_at' => now()->toDateTimeString(),
                    'subject' => $subject,
                    'detalle' => $detalle !== '' ? $detalle : null,
                    'persona' => [
                        'per_id' => (string) ($p['per_id'] ?? ''),
                        'nombre' => (string) ($p['nombre'] ?? ''),
                        'email' => $to !== '' ? $to : null,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $filesWritten++;
            } else {
                if ($productionMode) {
                    if ($to !== '') {
                        Mail::raw($body, static function ($m) use ($to, $subject) {
                            $m->to($to)->subject($subject);
                        });
                        $sent++;
                    }
                } elseif (! empty($testEmails)) {
                    Mail::raw($body, static function ($m) use ($testEmails, $subject) {
                        $m->to($testEmails)->subject($subject);
                    });
                    $sent++;
                }
            }

            if (Schema::hasTable('notificacion_envio_trs')) {
                DB::table('notificacion_envio_trs')->insert([
                    'no_en-id' => 'NOEN-'.Str::uuid()->toString(),
                    'no_en-tenant_id' => $tenantId,
                    'no_en-ac_de_pl_id-fk' => $activationId,
                    'no_en-per_id-fk' => $p['per_id'],
                    'no_en-gr_op_id-fk' => null,
                    'no_en-rol_id-fk' => null,
                    'no_en-ca_co_id-fk' => null,
                    'no_en-mensaje' => $subject,
                    'no_en-ts' => now()->toDateTimeString(),
                    'no_en-estado' => $mode === 'file' ? 'SIMULADO' : 'ENVIADO',
                    'no_en-num_de_intento' => '0',
                ]);
            }

            $index['recipients'][] = [
                'per_id' => (string) ($p['per_id'] ?? ''),
                'nombre' => (string) ($p['nombre'] ?? ''),
                'email' => $to !== '' ? $to : null,
            ];
        }

        if ($mode === 'file') {
            $indexPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-end-index.json';
            Storage::disk('local')->put($indexPath, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $filesWritten++;
        }

        if (Schema::hasTable('cronologia_emergencia_trs')) {
            DB::table('cronologia_emergencia_trs')->insert([
                'cr_em-id' => 'CREM-'.Str::uuid()->toString(),
                'cr_em-tenant_id' => $tenantId,
                'cr_em-ac_de_pl_id-fk' => $activationId,
                'cr_em-tipo_emergencia' => $label,
                'cr_em-ts_emergencia' => now()->toDateTimeString(),
                'cr_em-per_id-fk' => null,
                'cr_em-gr_op_id-fk' => null,
                'cr_em-detalle' => $subject.($detalle !== '' ? (': '.$detalle) : ''),
                'cr_em-ref_tabla' => 'notificacion_envio_trs',
                'cr_em-referencia' => null,
            ]);
        }

        return response()->json([
            'message' => 'OK',
            'mode' => $mode,
            'sent' => $sent,
            'files_written' => $filesWritten,
            'recipients' => count($people),
        ]);
    }

    public function myActions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $validated = $request->validate([
            'activation_id' => ['nullable', 'string'],
        ]);

        $activationId = trim((string) ($validated['activation_id'] ?? ''));

        if ($activationId === '' && Schema::hasTable('activacion_del_plan_trs')) {
            $candidate = DB::table('activacion_del_plan_trs')
                ->where('ac_de_pl-tenant_id', $tenantId)
                ->orderByRaw("COALESCE(`ac_de_pl-fecha_activac`, '') DESC")
                ->orderByRaw("COALESCE(`ac_de_pl-hora_activac`, '') DESC")
                ->orderBy('ac_de_pl-id', 'DESC')
                ->first();
            $activationId = trim((string) ($candidate?->{'ac_de_pl-id'} ?? ''));
        }

        if ($activationId === '') {
            return response()->json([
                'activation_id' => null,
                'acciones' => [],
            ]);
        }

        $email = strtolower(trim((string) ($request->user()?->email ?? '')));
        if ($email === '' || ! Schema::hasTable('persona_mst')) {
            return response()->json([
                'activation_id' => $activationId,
                'acciones' => [],
            ]);
        }

        $persona = DB::table('persona_mst')
            ->when(
                Schema::hasColumn('persona_mst', 'per-tenant_id'),
                static fn ($q) => $q->where('per-tenant_id', $tenantId),
            )
            ->whereRaw('LOWER(TRIM(`per-email`)) = ?', [$email])
            ->first();

        $perId = trim((string) ($persona?->{'per-id'} ?? ''));
        if ($perId === '') {
            return response()->json([
                'activation_id' => $activationId,
                'acciones' => [],
            ]);
        }

        if (
            ! Schema::hasTable('ejecucion_accion_trs')
            || ! Schema::hasTable('asignacion_en_funciones_trs')
            || ! Schema::hasTable('accion_set_detalle_cfg')
        ) {
            return response()->json(['message' => 'Missing required tables.'], 422);
        }

        $rows = DB::table('ejecucion_accion_trs as ej')
            ->join('asignacion_en_funciones_trs as asg', 'asg.as_en_fu-id', '=', 'ej.ej_ac-as_en_fu_id-fk')
            ->join('accion_set_detalle_cfg as de', 'de.ac_se_de-id', '=', 'ej.ej_ac-ac_se_de_id-fk')
            ->leftJoin('accion_operativa_cfg as ac', 'ac.ac_op-id', '=', 'de.ac_se_de-ac_op_id-fk')
            ->where('ej.ej_ac-tenant_id', $tenantId)
            ->where('ej.ej_ac-ac_de_pl_id-fk', $activationId)
            ->where('asg.as_en_fu-per_id-fk', $perId)
            ->orderByRaw("CAST(COALESCE(`de`.`ac_se_de-ord_ejec`, '999') AS UNSIGNED) ASC")
            ->orderBy('de.ac_se_de-id')
            ->get([
                'ej.ej_ac-id as ejecucion_id',
                'ej.ej_ac-estado as estado',
                'asg.as_en_fu-tipo_asignacion as tipo_asignacion',
                'ac.ac_op-descrip as accion_descrip',
                'ac.ac_op-cod as accion_cod',
                'de.ac_se_de-id as accion_detalle_id',
            ]);

        $acciones = [];
        foreach ($rows as $r) {
            $accion = trim((string) ($r->accion_descrip ?? '')) ?: trim((string) ($r->accion_cod ?? '')) ?: trim((string) ($r->accion_detalle_id ?? ''));
            $tipo = strtoupper(trim((string) ($r->tipo_asignacion ?? 'SUPLENTE')));
            if ($tipo !== 'TITULAR') {
                $tipo = 'SUPLENTE';
            }
            $acciones[] = [
                'ejecucion_id' => (string) ($r->ejecucion_id ?? ''),
                'accion_detalle_id' => (string) ($r->accion_detalle_id ?? ''),
                'accion' => $accion,
                'tipo_asignacion' => $tipo,
                'estado' => (string) ($r->estado ?? ''),
            ];
        }

        return response()->json([
            'activation_id' => $activationId,
            'persona' => [
                'per_id' => $perId,
                'nombre' => trim(implode(' ', array_filter([
                    (string) ($persona?->{'per-nombre'} ?? ''),
                    (string) ($persona?->{'per-apellido_1'} ?? ''),
                    (string) ($persona?->{'per-apellido_2'} ?? ''),
                ]))) ?: $perId,
                'email' => $persona?->{'per-email'} ?? null,
            ],
            'acciones' => $acciones,
        ]);
    }

    public function confirmMyActions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $validated = $request->validate([
            'activation_id' => ['required', 'string'],
            'respuesta' => ['nullable', 'string'],
        ]);

        $activationId = trim((string) ($validated['activation_id'] ?? ''));
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        $email = strtolower(trim((string) ($request->user()?->email ?? '')));
        if ($email === '' || ! Schema::hasTable('persona_mst')) {
            return response()->json(['message' => 'Persona not found.'], 404);
        }

        $persona = DB::table('persona_mst')
            ->when(
                Schema::hasColumn('persona_mst', 'per-tenant_id'),
                static fn ($q) => $q->where('per-tenant_id', $tenantId),
            )
            ->whereRaw('LOWER(TRIM(`per-email`)) = ?', [$email])
            ->first();

        $perId = trim((string) ($persona?->{'per-id'} ?? ''));
        if ($perId === '') {
            return response()->json(['message' => 'Persona not found.'], 404);
        }

        if (! Schema::hasTable('ejecucion_accion_trs') || ! Schema::hasTable('asignacion_en_funciones_trs')) {
            return response()->json(['message' => 'Missing required tables.'], 422);
        }

        $updated = DB::table('ejecucion_accion_trs as ej')
            ->join('asignacion_en_funciones_trs as asg', 'asg.as_en_fu-id', '=', 'ej.ej_ac-as_en_fu_id-fk')
            ->where('ej.ej_ac-tenant_id', $tenantId)
            ->where('ej.ej_ac-ac_de_pl_id-fk', $activationId)
            ->where('asg.as_en_fu-per_id-fk', $perId)
            ->whereRaw("UPPER(COALESCE(`ej`.`ej_ac-estado`, '')) <> 'CONFIRMADO'")
            ->update([
                'ej_ac-estado' => 'CONFIRMADO',
            ]);

        $noEnId = null;
        if (Schema::hasTable('notificacion_envio_trs')) {
            $last = DB::table('notificacion_envio_trs')
                ->where('no_en-tenant_id', $tenantId)
                ->where('no_en-ac_de_pl_id-fk', $activationId)
                ->where('no_en-per_id-fk', $perId)
                ->orderBy('no_en-ts', 'DESC')
                ->orderBy('no_en-id', 'DESC')
                ->first();
            $noEnId = trim((string) ($last?->{'no_en-id'} ?? '')) ?: null;
        }

        if (Schema::hasTable('notificacion_confirmacion_trs')) {
            DB::table('notificacion_confirmacion_trs')->insert([
                'no_co-id' => 'NOCO-'.Str::uuid()->toString(),
                'no_co-tenant_id' => $tenantId,
                'no_co-no_en_id-fk' => $noEnId,
                'no_co-confirmado' => 'SI',
                'no_co-ts' => now()->toDateTimeString(),
                'no_co-respuesta' => $validated['respuesta'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'OK',
            'updated' => $updated,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $warnings = [];

        $validated = $request->validate([
            'riesgo_id' => ['required', 'string'],
            'nivel_alerta_id' => ['nullable', 'string'],
            'criterio_id' => ['nullable', 'string'],
        ]);

        $riesgoId = trim((string) ($validated['riesgo_id'] ?? ''));
        $nivelAlertaId = trim((string) ($validated['nivel_alerta_id'] ?? ''));
        $criterioId = trim((string) ($validated['criterio_id'] ?? ''));

        if ($riesgoId === '') {
            return response()->json(['message' => 'Invalid riesgo id.'], 422);
        }

        $criterios = [];
        if (Schema::hasTable('criterio_riesgo_cfg')) {
            $criterios = DB::table('criterio_riesgo_cfg')
                ->when(
                    Schema::hasColumn('criterio_riesgo_cfg', 'cr_ri-tenant_id'),
                    static fn ($q) => $q->where('cr_ri-tenant_id', $tenantId),
                )
                ->where('cr_ri-rie_id-fk', $riesgoId)
                ->whereRaw("UPPER(COALESCE(`cr_ri-activo`, 'SI')) <> 'NO'")
                ->orderByRaw("CAST(COALESCE(`cr_ri-orden`, '999') AS UNSIGNED) ASC")
                ->orderBy('cr_ri-id')
                ->get()
                ->map(static function ($r) {
                    return (array) $r;
                })
                ->all();
        }

        $criterioSeleccionadoId = $criterioId !== '' ? $criterioId : null;
        $nivelAlertaSugeridoId = null;

        if ($criterioSeleccionadoId !== null) {
            foreach ($criterios as $c) {
                if (trim((string) ($c['cr_ri-id'] ?? '')) !== $criterioSeleccionadoId) {
                    continue;
                }
                $nivelAlertaSugeridoId = trim((string) ($c['cr_ri-ni_al_id-fk-sugerido'] ?? '')) ?: null;
                break;
            }
        }

        if ($nivelAlertaSugeridoId === null) {
            foreach ($criterios as $c) {
                $sug = trim((string) ($c['cr_ri-ni_al_id-fk-sugerido'] ?? ''));
                if ($sug !== '') {
                    $nivelAlertaSugeridoId = $sug;
                    $criterioSeleccionadoId = trim((string) ($c['cr_ri-id'] ?? '')) ?: null;
                    break;
                }
            }
        }

        $nivelAlertaIdResolved = $nivelAlertaId !== '' ? $nivelAlertaId : ($nivelAlertaSugeridoId ?? '');
        $nivelAlertaIdResolved = trim($nivelAlertaIdResolved);

        if ($nivelAlertaIdResolved === '') {
            $warnings[] = 'No hay nivel de alerta resuelto (seleccionado o sugerido).';
        }

        $niAl = null;
        if ($nivelAlertaIdResolved !== '' && Schema::hasTable('nivel_alerta_cat')) {
            $niAl = DB::table('nivel_alerta_cat')
                ->when(
                    Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                    static fn ($q) => $q->where('ni_al-tenant_id', $tenantId),
                )
                ->where('ni_al-id', $nivelAlertaIdResolved)
                ->first();
        }

        $niEm = null;
        if ($niAl !== null && Schema::hasTable('nivel_emergencia_cat')) {
            $niEmId = trim((string) ($niAl->{'ni_al-ni_em_id-fk'} ?? ''));
            if ($niEmId !== '') {
                $niEm = DB::table('nivel_emergencia_cat')
                    ->when(
                        Schema::hasColumn('nivel_emergencia_cat', 'ni_em-tenant_id'),
                        static fn ($q) => $q->where('ni_em-tenant_id', $tenantId),
                    )
                    ->where('ni_em-id', $niEmId)
                    ->first();
            }
        }

        $niAlCod = strtoupper(trim((string) ($niAl?->{'ni_al-cod'} ?? '')));
        $niAlNombre = strtoupper(trim((string) ($niAl?->{'ni_al-nombre'} ?? '')));
        $niEmActivaPlan = strtoupper(trim((string) ($niEm?->{'ni_em-activa_plan'} ?? 'NO')));

        $isPrealerta = $niAlCod !== '' && (str_starts_with($niAlCod, 'P') || str_contains($niAlNombre, 'PREALERTA'));
        $scenario = 'NORMALIDAD';
        if ($isPrealerta) {
            $scenario = 'PREALERTA';
        } elseif ($nivelAlertaIdResolved !== '' && $niEmActivaPlan === 'SI') {
            $scenario = 'ACTIVACION';
        }

        $actionSetIds = [];
        if ($nivelAlertaIdResolved !== '' && Schema::hasTable('riesgo_nivel_accion_set_cfg')) {
            $mapping = DB::table('riesgo_nivel_accion_set_cfg')
                ->when(
                    Schema::hasColumn('riesgo_nivel_accion_set_cfg', 'ri_ni_ac_se-tenant_id'),
                    static fn ($q) => $q->where('ri_ni_ac_se-tenant_id', $tenantId),
                )
                ->where('ri_ni_ac_se-rie_id-fk', $riesgoId)
                ->where('ri_ni_ac_se-ni_al_id-fk', $nivelAlertaIdResolved)
                ->whereRaw("UPPER(COALESCE(`ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                ->orderByRaw("CAST(COALESCE(`ri_ni_ac_se-prioridad`, '999') AS UNSIGNED) ASC")
                ->orderBy('ri_ni_ac_se-id')
                ->get();

            foreach ($mapping as $row) {
                $id = trim((string) ($row->{'ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                if ($id !== '') {
                    $actionSetIds[] = $id;
                }
            }
        }

        if (
            $nivelAlertaIdResolved !== ''
            && empty($actionSetIds)
            && Schema::hasTable('riesgo_cat')
            && Schema::hasTable('tipo_riesgo_nivel_accion_set_cfg')
            && Schema::hasTable('tipo_riesgo_cat')
        ) {
            $riesgo = DB::table('riesgo_cat')
                ->when(
                    Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                    static fn ($q) => $q->where('rie-tenant_id', $tenantId),
                )
                ->where('rie-id', $riesgoId)
                ->first();
            $tipoRiesgoId = trim((string) ($riesgo?->{'rie-ti_ri_id-fk'} ?? ''));

            if ($tipoRiesgoId === '') {
                $warnings[] = 'No hay tipo de riesgo asociado (rie-ti_ri_id-fk) para buscar action set por tipo.';
            } else {
                $mappingTipo = DB::table('tipo_riesgo_nivel_accion_set_cfg')
                    ->when(
                        Schema::hasColumn('tipo_riesgo_nivel_accion_set_cfg', 'ti_ri_ni_ac_se-tenant_id'),
                        static fn ($q) => $q->where('ti_ri_ni_ac_se-tenant_id', $tenantId),
                    )
                    ->where('ti_ri_ni_ac_se-ti_ri_id-fk', $tipoRiesgoId)
                    ->where('ti_ri_ni_ac_se-ni_al_id-fk', $nivelAlertaIdResolved)
                    ->whereRaw("UPPER(COALESCE(`ti_ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                    ->orderByRaw("CAST(COALESCE(`ti_ri_ni_ac_se-orden`, '999') AS UNSIGNED) ASC")
                    ->orderBy('ti_ri_ni_ac_se-id')
                    ->get();

                foreach ($mappingTipo as $row) {
                    $id = trim((string) ($row->{'ti_ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                    if ($id !== '') {
                        $actionSetIds[] = $id;
                    }
                }

                if (! empty($actionSetIds)) {
                    $warnings[] = 'No hay mapeo por riesgo; se usó configuración por tipo de riesgo.';
                } else {
                    $warnings[] = 'No hay mapeo por riesgo ni por tipo de riesgo para este nivel de alerta.';
                }
            }
        }

        if ($scenario === 'PREALERTA' && Schema::hasTable('accion_set_cfg')) {
            $pre = DB::table('accion_set_cfg')
                ->whereIn('ac_se-cod', ['AS01', 'AS04'])
                ->whereRaw("UPPER(COALESCE(`ac_se-activo`, 'SI')) <> 'NO'")
                ->when(
                    Schema::hasColumn('accion_set_cfg', 'ac_se-tenant_id'),
                    static fn ($q) => $q->where('ac_se-tenant_id', $tenantId),
                )
                ->pluck('ac_se-id')
                ->all();
            if (! empty($pre)) {
                $actionSetIds = array_values(array_unique(array_map('strval', $pre)));
            } else {
                $warnings[] = 'PREALERTA: no se encontraron action sets AS01/AS04 activos.';
            }
        } elseif ($scenario !== 'PREALERTA' && ! empty($actionSetIds)) {
            $actionSetIds = [array_values($actionSetIds)[0]];
        }

        $actionSetIds = array_values(array_unique(array_filter($actionSetIds, static fn ($v) => is_string($v) && trim($v) !== '')));
        $actionSetId = $actionSetIds[0] ?? null;

        if ($nivelAlertaIdResolved !== '' && empty($actionSetIds) && $scenario !== 'PREALERTA') {
            $warnings[] = 'No hay mapeo en riesgo_nivel_accion_set_cfg para este riesgo y nivel de alerta.';
        }

        $rolesById = [];
        if (Schema::hasTable('rol_cat')) {
            $rolesById = DB::table('rol_cat')
                ->when(
                    Schema::hasColumn('rol_cat', 'rol-tenant_id'),
                    static fn ($q) => $q->where('rol-tenant_id', $tenantId),
                )
                ->get()
                ->keyBy('rol-id')
                ->all();
        }

        $gruposById = [];
        if (Schema::hasTable('grupo_operativo_cat')) {
            $gruposById = DB::table('grupo_operativo_cat')
                ->when(
                    Schema::hasColumn('grupo_operativo_cat', 'gr_op-tenant_id'),
                    static fn ($q) => $q->where('gr_op-tenant_id', $tenantId),
                )
                ->get()
                ->keyBy('gr_op-id')
                ->all();
        }

        $personasById = [];
        if (Schema::hasTable('persona_mst')) {
            $personasById = DB::table('persona_mst')
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->where('per-tenant_id', $tenantId),
                )
                ->get()
                ->keyBy('per-id')
                ->all();
        }

        $canalesById = [];
        if (Schema::hasTable('canal_comunicacion_cat')) {
            $canalesById = DB::table('canal_comunicacion_cat')
                ->when(
                    Schema::hasColumn('canal_comunicacion_cat', 'ca_co-tenant_id'),
                    static fn ($q) => $q->where('ca_co-tenant_id', $tenantId),
                )
                ->get()
                ->keyBy('ca_co-id')
                ->all();
        }

        $actionSetsById = [];
        if (! empty($actionSetIds) && Schema::hasTable('accion_set_cfg')) {
            $actionSetsById = DB::table('accion_set_cfg')
                ->when(
                    Schema::hasColumn('accion_set_cfg', 'ac_se-tenant_id'),
                    static fn ($q) => $q->where('ac_se-tenant_id', $tenantId),
                )
                ->whereIn('ac_se-id', $actionSetIds)
                ->get()
                ->keyBy('ac_se-id')
                ->all();
        }

        $accionOperativaById = [];
        if (Schema::hasTable('accion_operativa_cfg')) {
            $accionOperativaById = DB::table('accion_operativa_cfg')
                ->when(
                    Schema::hasColumn('accion_operativa_cfg', 'ac_op-tenant_id'),
                    static fn ($q) => $q->where('ac_op-tenant_id', $tenantId),
                )
                ->whereRaw("UPPER(COALESCE(`ac_op-activo`, 'SI')) <> 'NO'")
                ->get()
                ->keyBy('ac_op-id')
                ->all();
        }

        $destByRol = [];
        if (Schema::hasTable('persona_rol_grupo_cfg')) {
            $rows = DB::table('persona_rol_grupo_cfg')
                ->when(
                    Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                    static fn ($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
                )
                ->whereRaw("UPPER(COALESCE(`pe_ro_gr-activo`, 'SI')) <> 'NO'")
                ->whereNull('pe_ro_gr-fech_fin')
                ->get();
            foreach ($rows as $r) {
                $rolId = trim((string) ($r->{'pe_ro_gr-rol_id-fk'} ?? ''));
                if ($rolId === '') {
                    continue;
                }
                $destByRol[$rolId] ??= [];
                $destByRol[$rolId][] = $r;
            }
        }

        $acciones = [];
        if (! empty($actionSetIds) && Schema::hasTable('accion_set_detalle_cfg')) {
            $detalles = DB::table('accion_set_detalle_cfg')
                ->when(
                    Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                    static fn ($q) => $q->where('ac_se_de-tenant_id', $tenantId),
                )
                ->whereIn('ac_se_de-ac_se_id-fk', $actionSetIds)
                ->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'")
                ->orderByRaw("CAST(COALESCE(`ac_se_de-ord_ejec`, '999') AS UNSIGNED) ASC")
                ->orderBy('ac_se_de-id')
                ->get();

            if ($detalles->count() === 0 && ! empty($actionSetIds)) {
                $warnings[] = 'No hay detalles activos en accion_set_detalle_cfg para el/los action set.';
            }

            $canalesPorDetalle = [];
            if (Schema::hasTable('accion_set_detalle_canal_cfg')) {
                $rows = DB::table('accion_set_detalle_canal_cfg')
                    ->when(
                        Schema::hasColumn('accion_set_detalle_canal_cfg', 'ac_se_de_ca-tenant_id'),
                        static fn ($q) => $q->where('ac_se_de_ca-tenant_id', $tenantId),
                    )
                    ->whereRaw("UPPER(COALESCE(`ac_se_de_ca-activo`, 'SI')) <> 'NO'")
                    ->get();
                foreach ($rows as $r) {
                    $detalleId = trim((string) ($r->{'ac_se_de_ca-ac_se_de_id-fk'} ?? ''));
                    if ($detalleId === '') {
                        continue;
                    }
                    $canalId = trim((string) ($r->{'ac_se_de_ca-ca_co_id-fk'} ?? ''));
                    if ($canalId === '') {
                        continue;
                    }
                    $canalesPorDetalle[$detalleId] ??= [];
                    $canalesPorDetalle[$detalleId][] = $canalId;
                }
            }

            foreach ($detalles as $de) {
                $detalleId = trim((string) ($de->{'ac_se_de-id'} ?? ''));
                if ($detalleId === '') {
                    continue;
                }

                $detalleActionSetId = trim((string) ($de->{'ac_se_de-ac_se_id-fk'} ?? '')) ?: null;
                $actionSet = $detalleActionSetId !== null ? ($actionSetsById[$detalleActionSetId] ?? null) : null;

                $rolId = trim((string) ($de->{'ac_se_de-rol_id-fk'} ?? '')) ?: null;
                $rol = $rolId !== null ? ($rolesById[$rolId] ?? null) : null;

                $acOpId = trim((string) ($de->{'ac_se_de-ac_op_id-fk'} ?? '')) ?: null;
                $acOp = $acOpId !== null ? ($accionOperativaById[$acOpId] ?? null) : null;

                $obligatoria = null;
                if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-obligatoria')) {
                    $raw = $de->{'ac_se_de-obligatoria'} ?? null;
                    if (is_bool($raw)) {
                        $obligatoria = $raw;
                    } elseif (is_numeric($raw)) {
                        $obligatoria = ((int) $raw) !== 0;
                    } else {
                        $s = strtoupper(trim((string) ($raw ?? '')));
                        if ($s !== '') {
                            $obligatoria = in_array($s, ['SI', 'S', '1', 'TRUE', 'VERDADERO'], true);
                        }
                    }
                }

                $grupos = [];
                $destinatarios = $rolId !== null ? ($destByRol[$rolId] ?? []) : [];
                $destinatariosByGrupo = [];
                foreach ($destinatarios as $d) {
                    $grId = trim((string) ($d->{'pe_ro_gr-gr_op_id-fk'} ?? '')) ?: '';
                    $destinatariosByGrupo[$grId] ??= [];
                    $destinatariosByGrupo[$grId][] = $d;
                }

                foreach ($destinatariosByGrupo as $grId => $items) {
                    $titular = null;
                    $suplentes = [];

                    usort($items, static function ($a, $b) {
                        $ao = (int) trim((string) ($a->{'pe_ro_gr-orden_sust'} ?? '999'));
                        $bo = (int) trim((string) ($b->{'pe_ro_gr-orden_sust'} ?? '999'));
                        if ($ao !== $bo) {
                            return $ao <=> $bo;
                        }

                        return strcmp((string) ($a->{'pe_ro_gr-id'} ?? ''), (string) ($b->{'pe_ro_gr-id'} ?? ''));
                    });

                    foreach ($items as $d) {
                        $tipo = strtoupper(trim((string) ($d->{'pe_ro_gr-tipo_asignacion'} ?? '')));
                        if ($tipo !== '' && $tipo !== 'TITULAR' && $tipo !== 'SUPLENTE') {
                            continue;
                        }

                        $perId = trim((string) ($d->{'pe_ro_gr-per_id-fk'} ?? ''));
                        if ($perId === '') {
                            continue;
                        }
                        $p = $personasById[$perId] ?? null;
                        if ($p === null) {
                            continue;
                        }
                        $nombre = trim(implode(' ', array_filter([
                            (string) ($p->{'per-nombre'} ?? ''),
                            (string) ($p->{'per-apellido_1'} ?? ''),
                            (string) ($p->{'per-apellido_2'} ?? ''),
                        ])));
                        $personaData = [
                            'per_id' => $perId,
                            'nombre' => $nombre !== '' ? $nombre : $perId,
                            'email' => $p->{'per-email'} ?? null,
                        ];

                        if ($tipo === 'TITULAR' && $titular === null) {
                            $titular = $personaData;

                            continue;
                        }
                        if ($tipo === 'SUPLENTE' || $tipo === '') {
                            $suplentes[] = $personaData;
                        }
                    }

                    $grupoNombre = null;
                    if ($grId !== '') {
                        $g = $gruposById[$grId] ?? null;
                        $grupoNombre = $g?->{'gr_op-nombre'} ?? null;
                    }

                    $grupos[] = [
                        'grupo_id' => $grId !== '' ? $grId : null,
                        'grupo_nombre' => $grupoNombre,
                        'titular' => $titular,
                        'suplentes' => $suplentes,
                    ];
                }

                $canalIds = array_values(array_unique($canalesPorDetalle[$detalleId] ?? []));
                $canales = [];
                foreach ($canalIds as $cid) {
                    $c = $canalesById[$cid] ?? null;
                    $canales[] = [
                        'canal_id' => $cid,
                        'canal_nombre' => $c?->{'ca_co-nombre'} ?? null,
                    ];
                }

                $acciones[] = [
                    'accion_detalle_id' => $detalleId,
                    'accion_detalle' => $acOp?->{'ac_op-descrip'} ?? ($acOp?->{'ac_op-cod'} ?? null),
                    'action_set_id' => $detalleActionSetId,
                    'action_set_cod' => $actionSet?->{'ac_se-cod'} ?? null,
                    'action_set_nombre' => $actionSet?->{'ac_se-nombre'} ?? null,
                    'rol_id' => $rolId,
                    'rol_cod' => $rol?->{'rol-cod'} ?? null,
                    'rol_nombre' => $rol?->{'rol-nombre'} ?? null,
                    'accion_operativa_id' => $acOpId,
                    'accion_operativa_cod' => $acOp?->{'ac_op-cod'} ?? null,
                    'accion_operativa_descrip' => $acOp?->{'ac_op-descrip'} ?? null,
                    'obligatoria' => $obligatoria,
                    'grupos' => $grupos,
                    'canales' => $canales,
                ];
            }
        }

        return response()->json([
            'criterio_riesgo' => $criterios,
            'criterio_seleccionado_id' => $criterioSeleccionadoId,
            'nivel_alerta_sugerido_id' => $nivelAlertaSugeridoId,
            'nivel_alerta_id' => $nivelAlertaIdResolved !== '' ? $nivelAlertaIdResolved : null,
            'nivel_alerta_cod' => $niAl?->{'ni_al-cod'} ?? null,
            'nivel_alerta_nombre' => $niAl?->{'ni_al-nombre'} ?? null,
            'nivel_emergencia_id' => $niEm?->{'ni_em-id'} ?? null,
            'nivel_emergencia_activa_plan' => $niEm?->{'ni_em-activa_plan'} ?? null,
            'scenario' => $scenario,
            'activa_plan' => $scenario === 'ACTIVACION',
            'action_set_ids' => $actionSetIds,
            'action_set_id' => $actionSetId,
            'acciones' => $acciones,
            'warnings' => $warnings,
        ]);
    }

    public function uploadDocuments(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:51200'],
        ]);

        if (! Schema::hasTable('activacion_del_plan_trs')) {
            return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
        }

        $exists = DB::table('activacion_del_plan_trs')
            ->where('ac_de_pl-id', $activationId)
            ->where('ac_de_pl-tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Activation not found.'], 404);
        }

        $files = $data['files'] ?? [];
        $documents = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $original = $file->getClientOriginalName() ?: 'documento';
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: 'documento';
            $id = 'DOC-'.Str::uuid()->toString();
            $dir = 'activaciones/'.$tenantId.'/'.$activationId;
            $filename = $id.'-'.$safe;
            $path = Storage::disk('local')->putFileAs($dir, $file, $filename);

            $documents[] = [
                'id' => $id,
                'nombre' => $original,
                'path' => $path,
            ];
        }

        return response()->json([
            'message' => 'OK',
            'documents' => $documents,
        ]);
    }

    public function listRiskRepository(Request $request, string $riesgoId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $riesgoId = trim($riesgoId);
        if ($riesgoId === '') {
            return response()->json(['message' => 'Invalid riesgo id.'], 422);
        }

        $tipoEmergenciaId = trim((string) $request->query('ti_em_id', ''));
        if ($tipoEmergenciaId !== '' && (str_contains($tipoEmergenciaId, '..') || str_contains($tipoEmergenciaId, '/') || str_contains($tipoEmergenciaId, '\\'))) {
            return response()->json(['message' => 'Invalid tipo emergencia id.'], 422);
        }

        $dir = $tipoEmergenciaId !== ''
            ? 'repositorio_riesgo_plan/'.$tenantId.'/'.$tipoEmergenciaId.'/'.$riesgoId
            : 'repositorio_riesgo/'.$tenantId.'/'.$riesgoId;

        if (! Storage::disk('local')->exists($dir)) {
            Storage::disk('local')->makeDirectory($dir);
        }

        $files = array_values(array_filter(
            Storage::disk('local')->files($dir),
            static fn ($p) => is_string($p) && trim($p) !== '' && ! str_ends_with($p, '/')
        ));

        if (empty($files)) {
            $seed = [
                [
                    'name' => '01-descripcion-del-riesgo.txt',
                    'content' => ($tipoEmergenciaId !== '' ? "Tipo emergencia: {$tipoEmergenciaId}\n" : '')."Riesgo: {$riesgoId}\n\nDocumento de prueba.\n",
                ],
                [
                    'name' => '02-protocolo-de-actuacion.txt',
                    'content' => ($tipoEmergenciaId !== '' ? "Tipo emergencia: {$tipoEmergenciaId}\n" : '')."Riesgo: {$riesgoId}\n\nProtocolo de actuación (prueba).\n",
                ],
                [
                    'name' => '03-checklist-operativo.txt',
                    'content' => ($tipoEmergenciaId !== '' ? "Tipo emergencia: {$tipoEmergenciaId}\n" : '')."Riesgo: {$riesgoId}\n\nChecklist operativo (prueba).\n",
                ],
            ];

            foreach ($seed as $doc) {
                Storage::disk('local')->put($dir.'/'.$doc['name'], $doc['content']);
            }

            $files = Storage::disk('local')->files($dir);
        }

        $docs = [];
        foreach ($files as $path) {
            $basename = basename((string) $path);
            if ($basename === '' || $basename === '.' || $basename === '..') {
                continue;
            }

            $url = null;
            try {
                $content = Storage::disk('local')->get($path);
                if (is_string($content) && preg_match('/https?:\/\/[^\s<>"\'\]]+/i', $content, $m) === 1) {
                    $url = $m[0] ?? null;
                }
            } catch (\Throwable) {
                $url = null;
            }

            $docs[] = [
                'name' => $basename,
                'size' => Storage::disk('local')->size($path),
                'last_modified' => Storage::disk('local')->lastModified($path),
                'url' => $url,
            ];
        }

        usort($docs, static fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return response()->json([
            'riesgo_id' => $riesgoId,
            'ti_em_id' => $tipoEmergenciaId !== '' ? $tipoEmergenciaId : null,
            'documents' => $docs,
        ]);
    }

    public function storeRiskRepositoryLink(Request $request, string $riesgoId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $riesgoId = trim($riesgoId);
        if ($riesgoId === '') {
            return response()->json(['message' => 'Invalid riesgo id.'], 422);
        }

        $tipoEmergenciaId = trim((string) $request->query('ti_em_id', ''));
        if ($tipoEmergenciaId !== '' && (str_contains($tipoEmergenciaId, '..') || str_contains($tipoEmergenciaId, '/') || str_contains($tipoEmergenciaId, '\\'))) {
            return response()->json(['message' => 'Invalid tipo emergencia id.'], 422);
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'url' => ['required', 'string', 'url', 'max:2048'],
        ]);

        $title = trim((string) ($validated['title'] ?? ''));
        $url = trim((string) ($validated['url'] ?? ''));

        $dir = $tipoEmergenciaId !== ''
            ? 'repositorio_riesgo_plan/'.$tenantId.'/'.$tipoEmergenciaId.'/'.$riesgoId
            : 'repositorio_riesgo/'.$tenantId.'/'.$riesgoId;
        if (! Storage::disk('local')->exists($dir)) {
            Storage::disk('local')->makeDirectory($dir);
        }

        $safeTitle = $title !== '' ? preg_replace('/[^A-Za-z0-9._-]+/', '_', $title) : 'enlace';
        $safeTitle = trim((string) $safeTitle);
        if ($safeTitle === '') {
            $safeTitle = 'enlace';
        }

        $ts = now()->format('YmdHis');
        $id = Str::uuid()->toString();
        $filename = 'link-'.$ts.'-'.$safeTitle.'-'.substr($id, 0, 8).'.txt';

        Storage::disk('local')->put($dir.'/'.$filename, $url."\n");

        return response()->json([
            'message' => 'OK',
            'name' => $filename,
            'url' => $url,
        ]);
    }

    public function deleteRiskRepositoryLink(Request $request, string $riesgoId, string $filename): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $riesgoId = trim($riesgoId);
        $filename = trim($filename);

        if ($riesgoId === '' || $filename === '') {
            return response()->json(['message' => 'Invalid request.'], 422);
        }

        $tipoEmergenciaId = trim((string) $request->query('ti_em_id', ''));
        if ($tipoEmergenciaId !== '' && (str_contains($tipoEmergenciaId, '..') || str_contains($tipoEmergenciaId, '/') || str_contains($tipoEmergenciaId, '\\'))) {
            return response()->json(['message' => 'Invalid tipo emergencia id.'], 422);
        }

        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return response()->json(['message' => 'Invalid filename.'], 422);
        }

        if (! str_starts_with($filename, 'link-')) {
            return response()->json(['message' => 'Only link files can be deleted.'], 422);
        }

        $path = $tipoEmergenciaId !== ''
            ? 'repositorio_riesgo_plan/'.$tenantId.'/'.$tipoEmergenciaId.'/'.$riesgoId.'/'.$filename
            : 'repositorio_riesgo/'.$tenantId.'/'.$riesgoId.'/'.$filename;
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        Storage::disk('local')->delete($path);

        return response()->json([
            'message' => 'OK',
            'deleted' => $filename,
        ]);
    }

    public function downloadRiskRepositoryFile(Request $request, string $riesgoId, string $filename)
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $riesgoId = trim($riesgoId);
        $filename = trim($filename);

        if ($riesgoId === '' || $filename === '') {
            return response()->json(['message' => 'Invalid request.'], 422);
        }

        $tipoEmergenciaId = trim((string) $request->query('ti_em_id', ''));
        if ($tipoEmergenciaId !== '' && (str_contains($tipoEmergenciaId, '..') || str_contains($tipoEmergenciaId, '/') || str_contains($tipoEmergenciaId, '\\'))) {
            return response()->json(['message' => 'Invalid tipo emergencia id.'], 422);
        }

        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return response()->json(['message' => 'Invalid filename.'], 422);
        }

        $path = $tipoEmergenciaId !== ''
            ? 'repositorio_riesgo_plan/'.$tenantId.'/'.$tipoEmergenciaId.'/'.$riesgoId.'/'.$filename
            : 'repositorio_riesgo/'.$tenantId.'/'.$riesgoId.'/'.$filename;

        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return response()->streamDownload(
            static function () use ($path): void {
                echo Storage::disk('local')->get($path);
            },
            $filename,
        );
    }
}
