<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActivationController extends Controller
{
    private array $tenantTimezoneCache = [];
    private array $passwordResetUrlCache = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(Request $request): JsonResponse
    {
        file_put_contents(public_path('activation_hit.txt'), 'Hit at ' . date('Y-m-d H:i:s') . "\n" . print_r($request->all(), true));

        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $data = $request->validate([
            'ti_em_id' => ['required', 'string'],
            'rie_id' => ['required', 'string'],
            'ni_al_id' => ['required', 'string'],
            'plan_espec' => ['nullable', 'string'],
            'per_id' => ['nullable', 'string'],
            'rol_id' => ['nullable', 'string'],
            'cargo_declarado' => ['nullable', 'string'],
            'fecha_activac' => ['nullable', 'string'],
            'hora_activac' => ['nullable', 'string'],
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

        // Failsafe: If per_id or rol_id are missing, try to resolve from current user
        $resolvedPerId = $data['per_id'] ?? null;
        $resolvedRolId = $data['rol_id'] ?? null;

        if (empty($resolvedPerId)) {
            /** @var \App\Models\User|null $user */
            $user = $request->user();
            if ($user && $user->email) {
                $persona = DB::table('persona_mst')
                    ->where('per-tenant_id', $tenantId)
                    ->where('per-email', $user->email)
                    ->first();
                
                if ($persona) {
                    $resolvedPerId = $persona->{'per-id'};
                }
            }
        }

        if (empty($resolvedPerId)) {
            $fallbackPersona = DB::table('persona_mst')
                ->where('per-tenant_id', $tenantId)
                ->whereRaw("UPPER(COALESCE(`per-activo`, 'SI')) <> 'NO'")
                ->orderBy('per-id')
                ->first();
            if ($fallbackPersona) {
                $resolvedPerId = $fallbackPersona->{'per-id'};
            }
        }

        if (empty($resolvedRolId) && !empty($resolvedPerId)) {
            // Pick the first active role for this person if available
            $role = DB::table('persona_rol_cfg')
                ->where('pe_ro-tenant_id', $tenantId)
                ->where('pe_ro-per_id-fk', $resolvedPerId)
                ->whereRaw("UPPER(COALESCE(`pe_ro-activo`, 'SI')) <> 'NO'")
                ->orderBy('pe_ro-id')
                ->first();
                
            if ($role) {
                $resolvedRolId = $role->{'pe_ro-rol_id-fk'};
            }
        }

        if (empty($resolvedPerId) || empty($resolvedRolId)) {
            return response()->json(['message' => 'No se pudo identificar al activador (per_id/rol_id faltantes).'], 422);
        }

        return DB::transaction(function () use ($data, $tenantId, $activationId, $request, $resolvedPerId, $resolvedRolId) {
            try {
            if (! Schema::hasTable('activacion_del_plan_trs')) {
                return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
            }

            $activationNow = $this->tenantNow($tenantId);
            $activationDate = $activationNow->toDateString();
            $activationTime = $activationNow->toTimeString();
            $activationTs = $activationNow->toDateTimeString();

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
            $criterioSelector = strtoupper(trim((string) ($data['justificacion'] ?? '')));

            $isAviso = $niAlCod !== '' && (str_contains($niAlNombre, 'AVISO') || $niAlCod === 'AVISO' || $niAlCod === 'AV' || str_starts_with($niAlCod, 'AV'));
            $isPrealerta = str_starts_with($niAlCod, 'P') || str_contains($niAlNombre, 'PREALERTA');

            if ($isPrealerta && $isAviso) {
                $isAviso = false;
            }

            $scenario = 'NORMALIDAD';
            if ($criterioSelector === 'NORMALIDAD') {
                $scenario = 'NORMALIDAD';
            } elseif ($criterioSelector === 'AVISO_SYNTH') {
                $scenario = 'AVISO';
            } elseif ($niEmActivaPlan === 'SI') {
                $scenario = 'ACTIVACION';
            } elseif ($isPrealerta) {
                $scenario = 'PREALERTA';
            } elseif ($isAviso) {
                $scenario = 'AVISO';
            }
            $estadoGuardado = trim((string) ($data['estado'] ?? ''));
            if ($estadoGuardado === '') {
                $estadoGuardado = 'ACTIVA';
            }
            if (in_array($scenario, ['NORMALIDAD', 'AVISO'], true)) {
                $estadoGuardado = 'FINALIZADA';
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
                'ac_de_pl-per_id-fk-activador' => $resolvedPerId,
                'ac_de_pl-rol_id-fk-activador' => $resolvedRolId,
                'ac_de_pl-cargo_declarado' => $data['cargo_declarado'] ?? null,
                'ac_de_pl-fecha_activac' => $activationDate,
                'ac_de_pl-hora_activac' => $activationTime,
                'ac_de_pl-estado' => $estadoGuardado,
                'ac_de_pl-mensaje_inic' => $data['mensaje_inic'] ?? null,
                'ac_de_pl-mensaje_simul' => $data['mensaje_simul'] ?? null,
                'ac_de_pl-observ' => $data['observ'] ?? null,
            ]);

            $activatorGroupId = null;
            if (Schema::hasTable('persona_rol_grupo_cfg')) {
                $prg = DB::table('persona_rol_grupo_cfg')
                    ->where('pe_ro_gr-per_id-fk', $resolvedPerId)
                    ->where('pe_ro_gr-rol_id-fk', $resolvedRolId)
                    ->first();
                $activatorGroupId = $prg->{'pe_ro_gr-gr_op_id-fk'} ?? null;
            }

            $initialMessageNote = trim((string) ($data['mensaje_inic'] ?? ''));
            if ($initialMessageNote !== '' && Schema::hasTable('notas_operativas_trs')) {
                $notePayload = [
                    'no_op-id' => 'NOOP-'.Str::uuid()->toString(),
                    'no_op-ac_de_pl_id-fk' => $activationId,
                    'no_op-gr_op_id-fk' => $activatorGroupId,
                    'no_op-per_id-fk' => $resolvedPerId,
                    'no_op-ts_nota' => $activationTs,
                    'no_op-texto' => $initialMessageNote,
                    'no_op-visibilidad' => 'INTERNA',
                ];
                if (Schema::hasColumn('notas_operativas_trs', 'no_op-tenant_id')) {
                    $notePayload['no_op-tenant_id'] = $tenantId;
                }
                DB::table('notas_operativas_trs')->insert($notePayload);
            }

            if (Schema::hasTable('cronologia_emergencia_trs')) {
                DB::table('cronologia_emergencia_trs')->insert([
                    'cr_em-id' => 'CREM-'.Str::uuid()->toString(),
                    'cr_em-tenant_id' => $tenantId,
                    'cr_em-ac_de_pl_id-fk' => $activationId,
                    'cr_em-tipo_emergencia' => $scenario,
                    'cr_em-ts_emergencia' => $activationTs,
                    'cr_em-per_id-fk' => $resolvedPerId,
                    'cr_em-gr_op_id-fk' => $activatorGroupId,
                    'cr_em-detalle' => 'Activación del plan: '.($data['mensaje_inic'] ?? 'Sin mensaje inicial'),
                    'cr_em-ref_tabla' => 'activacion_del_plan_trs',
                    'cr_em-referencia' => $activationId,
                ]);
            }

            $this->auditLogger->logFromRequest($request, [
                'event_type' => 'plan_activated',
                'module' => 'activation',
                'plan_id' => $activationId,
                'entity_id' => $activationId,
                'entity_type' => 'activacion_del_plan_trs',
                'new_value' => [
                    'ti_em_id' => $data['ti_em_id'],
                    'rie_id' => $data['rie_id'],
                    'ni_al_id' => $data['ni_al_id'],
                    'estado' => $estadoGuardado,
                    'scenario' => $scenario,
                ],
            ]);

            $now = $activationTs;
            $warnings = [];

            $actionSetIds = $this->getActionSets($tenantId, $data['rie_id'], $data['ni_al_id']);

            $actionSetIds = array_values(array_unique(array_filter($actionSetIds, static fn ($v) => is_string($v) && trim($v) !== '')));
            if ($scenario === 'ACTIVACION' && empty($actionSetIds)) {
                $warnings[] = 'No se encontraron acciones operativas configuradas para este riesgo y nivel de alerta.';
            }

            if ($scenario === 'AVISO') {
                $actionSetIds = [];
            }

            if (Schema::hasTable('activacion_nivel_hist_trs')) {
                DB::table('activacion_nivel_hist_trs')->insert([
                    'ac_ni_hi-id' => 'ACNI-'.Str::uuid()->toString(),
                    'ac_ni_hi-tenant_id' => $tenantId,
                    'ac_ni_hi-ac_de_pl_id-fk' => $activationId,
                    'ac_ni_hi-ni_al_id-fk' => $data['ni_al_id'],
                    'ac_ni_hi-ac_se_id-fk' => $actionSetIds[0] ?? null,
                    'ac_ni_hi-fech_ini' => $activationDate,
                    'ac_ni_hi-hora_ini' => $activationTime,
                    'ac_ni_hi-fech_fin' => null,
                    'ac_ni_hi-hora_fin' => null,
                    'ac_ni_hi-nivel_inicial' => 'SI',
                    'ac_ni_hi-motivo_cambio' => null,
                    'ac_ni_hi-per_id-fk-registrador' => $resolvedPerId,
                    'ac_ni_hi-rol_id-fk-registrador' => $resolvedRolId,
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

            $shouldHydrate = in_array($scenario, ['ACTIVACION', 'PREALERTA'], true) && ! empty($actionSetIds);
            if (! $shouldHydrate) {
                return response()->json([
                    'activation_id' => $activationId,
                    'scenario' => $scenario,
                    'action_set_ids' => $actionSetIds,
                    'unassigned_actions' => [],
                    'ejecucion_count' => 0,
                    'notification_count' => 0,
                    'warnings' => $warnings,
                ], 201);
            }

            if (! Schema::hasTable('accion_set_detalle_cfg')) {
                return response()->json([
                    'message' => 'Missing accion_set_detalle_cfg table.',
                ], 422);
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
                            ->when(
                                Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                                static fn ($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
                            )
                            ->where('pe_ro_gr-rol_id-fk', $rolIdStr)
                            ->whereRaw("UPPER(COALESCE(`pe_ro_gr-activo`, 'SI')) <> 'NO'")
                            ->whereNull('pe_ro_gr-fech_fin')
                            ->get();
                    }
                    $recipients = $this->resolveRoleRecipientsForActivation($personaRolGrupoByRol[$rolIdStr]);
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
                    } else {
                        $fallbackSuplente = trim((string) (($manualAssignment['suplente_per_ids'][0] ?? '')));
                        if ($fallbackSuplente !== '') {
                            $recipients[] = [
                                'per_id' => $fallbackSuplente,
                                'gr_op_id' => $manualGrOpId,
                                'tipo_asignacion' => 'SUPLENTE',
                            ];
                        }
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
                                    'as_en_fu-per_id-fk-delegador' => $resolvedPerId,
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
                'warnings' => $warnings,
            ], 201);
            } catch (\Throwable $e) {
                file_put_contents(public_path('last_activation_error.txt'), $e->getMessage() . "\n" . $e->getTraceAsString());
                throw $e;
            }
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
            'recipient_emails' => ['nullable', 'array'],
            'recipient_emails.*' => ['nullable', 'string'],
            'resolve_only' => ['nullable', 'boolean'],
        ]);
        $accionDetalleId = trim((string) ($validated['accion_detalle_id'] ?? ''));
        $resolveOnly = (bool) ($validated['resolve_only'] ?? false);
        $targetEmails = array_values(array_unique(array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim((string) $e)),
            is_array($validated['recipient_emails'] ?? null) ? $validated['recipient_emails'] : [],
        ), static fn ($email) => $email !== ''))));
        try {

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
                'de.ac_se_de-rol_id-fk as rol_id',
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

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es'],
        );
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $channels = $this->resolveNotificationChannels($tenant);
        $emailNotificationsEnabled = (bool) ($channels['email_enabled'] ?? false);
        $whatsappNotificationsEnabled = (bool) ($channels['whatsapp_enabled'] ?? false);

        $byPerson = [];
        $actionsByRole = [];
        $appendAction = static function (array &$person, array $action): void {
            $idx = trim((string) ($action['accion_detalle_id'] ?? '')).'|'.trim((string) ($action['ejecucion_id'] ?? ''));
            if ($idx === '|') {
                return;
            }
            $person['acciones_index'][$idx] = true;
            $person['acciones'][] = $action;
        };
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
            $rolId = trim((string) ($r->rol_id ?? ''));

            $byPerson[$perId] ??= [
                'per_id' => $perId,
                'email' => $email !== '' ? $email : null,
                'tel_mov' => $telMov !== '' ? $telMov : null,
                'nombre' => $nombre !== '' ? $nombre : $perId,
                'acciones' => [],
                'acciones_index' => [],
            ];
            if (($byPerson[$perId]['email'] ?? null) === null && $email !== '') {
                $byPerson[$perId]['email'] = $email;
            }
            if (($byPerson[$perId]['tel_mov'] ?? null) === null && $telMov !== '') {
                $byPerson[$perId]['tel_mov'] = $telMov;
            }
            if (($byPerson[$perId]['nombre'] ?? '') === $perId && $nombre !== '') {
                $byPerson[$perId]['nombre'] = $nombre;
            }
            $actionPayload = [
                'ejecucion_id' => (string) ($r->ejecucion_id ?? ''),
                'accion_detalle_id' => (string) ($r->accion_detalle_id ?? ''),
                'accion_operativa_id' => (string) ($r->accion_operativa_id ?? ''),
                'accion_operativa_cod' => (string) ($r->accion_cod ?? ''),
                'accion_operativa_descrip' => (string) ($r->accion_descrip ?? ''),
                'accion' => $accion,
                'tipo_asignacion' => $tipo,
                'estado' => (string) ($r->ejecucion_estado ?? ''),
            ];
            $appendAction($byPerson[$perId], $actionPayload);
            if ($rolId !== '') {
                $actionsByRole[$rolId] ??= [];
                $actionsByRole[$rolId][] = $actionPayload;
            }
        }

        if ($productionMode && ! empty($actionsByRole) && Schema::hasTable('persona_rol_grupo_cfg') && Schema::hasTable('persona_mst')) {
            $roleIds = array_keys($actionsByRole);
            $roleRows = DB::table('persona_rol_grupo_cfg as prg')
                ->join('persona_mst as p', 'p.per-id', '=', 'prg.pe_ro_gr-per_id-fk')
                ->whereIn('prg.pe_ro_gr-rol_id-fk', $roleIds)
                ->whereRaw("UPPER(COALESCE(`prg`.`pe_ro_gr-activo`, 'SI')) <> 'NO'")
                ->whereNull('prg.pe_ro_gr-fech_fin')
                ->get([
                    'prg.pe_ro_gr-rol_id-fk as rol_id',
                    'prg.pe_ro_gr-per_id-fk as per_id',
                    'prg.pe_ro_gr-tipo_asignacion as tipo_asignacion',
                    'p.per-email as email',
                    'p.per-tel_mov as tel_mov',
                    'p.per-nombre as nombre',
                    'p.per-apellido_1 as apellido_1',
                    'p.per-apellido_2 as apellido_2',
                ]);
            foreach ($roleRows as $rr) {
                $rolId = trim((string) ($rr->rol_id ?? ''));
                $perId = trim((string) ($rr->per_id ?? ''));
                if ($rolId === '' || $perId === '') {
                    continue;
                }
                $tipo = strtoupper(trim((string) ($rr->tipo_asignacion ?? 'SUPLENTE')));
                if ($tipo === '') {
                    $tipo = 'SUPLENTE';
                }
                if ($tipo === 'LIDER') {
                    $tipo = 'TITULAR';
                }
                if ($tipo !== 'TITULAR' && $tipo !== 'SUPLENTE') {
                    continue;
                }
                $email = strtolower(trim((string) ($rr->email ?? '')));
                $telMov = trim((string) ($rr->tel_mov ?? ''));
                $nombre = trim(implode(' ', array_filter([
                    (string) ($rr->nombre ?? ''),
                    (string) ($rr->apellido_1 ?? ''),
                    (string) ($rr->apellido_2 ?? ''),
                ])));
                $byPerson[$perId] ??= [
                    'per_id' => $perId,
                    'email' => $email !== '' ? $email : null,
                    'tel_mov' => $telMov !== '' ? $telMov : null,
                    'nombre' => $nombre !== '' ? $nombre : $perId,
                    'acciones' => [],
                    'acciones_index' => [],
                ];
                if (($byPerson[$perId]['email'] ?? null) === null && $email !== '') {
                    $byPerson[$perId]['email'] = $email;
                }
                if (($byPerson[$perId]['tel_mov'] ?? null) === null && $telMov !== '') {
                    $byPerson[$perId]['tel_mov'] = $telMov;
                }
                if (($byPerson[$perId]['nombre'] ?? '') === $perId && $nombre !== '') {
                    $byPerson[$perId]['nombre'] = $nombre;
                }
                foreach (($actionsByRole[$rolId] ?? []) as $actionPayload) {
                    $appendAction($byPerson[$perId], $actionPayload);
                }
            }
        }

        if (Schema::hasTable('users')) {
            $usersQuery = DB::table('users as u')
                ->where('u.tenant_id', $tenantId)
                ->whereIn(DB::raw('LOWER(TRIM(u.perfil))'), ['recurso', 'director']);
            if (Schema::hasColumn('users', 'is_active')) {
                $usersQuery->where('u.is_active', true);
            }
            $select = ['u.id as user_id', 'u.email as user_email', 'u.name as user_name', 'u.perfil as user_perfil'];
            $hasPersonaId = Schema::hasColumn('users', 'persona_id');
            if ($hasPersonaId) {
                $select[] = 'u.persona_id';
            }
            if ($hasPersonaId && Schema::hasTable('persona_mst')) {
                $usersQuery->leftJoin('persona_mst as p', 'p.per-id', '=', 'u.persona_id');
                if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
                    $usersQuery->where(function ($q) use ($tenantId) {
                        $q->where('p.per-tenant_id', $tenantId)->orWhereNull('p.per-id');
                    });
                }
                if (Schema::hasColumn('persona_mst', 'per-activo')) {
                    $usersQuery->where(function ($q) {
                        $q->whereRaw("UPPER(COALESCE(`p`.`per-activo`, 'SI')) <> 'NO'")->orWhereNull('p.per-id');
                    });
                }
                $select[] = 'p.per-id as per_id';
                $select[] = 'p.per-email as per_email';
                $select[] = 'p.per-tel_mov as tel_mov';
                $select[] = 'p.per-nombre as per_nombre';
                $select[] = 'p.per-apellido_1 as per_apellido_1';
                $select[] = 'p.per-apellido_2 as per_apellido_2';
            }
            $allUsersRows = $usersQuery->get($select);
            foreach ($allUsersRows as $ur) {
                $perId = trim((string) ($ur->per_id ?? ''));
                if ($perId === '') {
                    $userId = trim((string) ($ur->user_id ?? ''));
                    if ($userId === '') {
                        continue;
                    }
                    $perId = 'USR:'.$userId;
                }
                $email = strtolower(trim((string) (($ur->per_email ?? null) ?: ($ur->user_email ?? ''))));
                $telMov = trim((string) ($ur->tel_mov ?? ''));
                $nombre = trim(implode(' ', array_filter([
                    (string) ($ur->per_nombre ?? ''),
                    (string) ($ur->per_apellido_1 ?? ''),
                    (string) ($ur->per_apellido_2 ?? ''),
                ])));
                if ($nombre === '') {
                    $nombre = trim((string) ($ur->user_name ?? ''));
                }
                $byPerson[$perId] ??= [
                    'per_id' => $perId,
                    'email' => $email !== '' ? $email : null,
                    'tel_mov' => $telMov !== '' ? $telMov : null,
                    'nombre' => $nombre !== '' ? $nombre : $perId,
                    'acciones' => [],
                    'acciones_index' => [],
                ];
                if (($byPerson[$perId]['email'] ?? null) === null && $email !== '') {
                    $byPerson[$perId]['email'] = $email;
                }
                if (($byPerson[$perId]['tel_mov'] ?? null) === null && $telMov !== '') {
                    $byPerson[$perId]['tel_mov'] = $telMov;
                }
                if (($byPerson[$perId]['nombre'] ?? '') === $perId && $nombre !== '') {
                    $byPerson[$perId]['nombre'] = $nombre;
                }
            }
        }

        foreach ($byPerson as $perId => $person) {
            if (isset($person['acciones_index'])) {
                unset($person['acciones_index']);
            }
            $byPerson[$perId] = $person;
        }

        $people = array_values($byPerson);
        if (! empty($targetEmails)) {
            $targetSet = array_flip($targetEmails);
            $people = array_values(array_filter($people, static function ($p) use ($targetSet) {
                $email = strtolower(trim((string) ($p['email'] ?? '')));
                if ($email === '') {
                    return false;
                }
                return isset($targetSet[$email]);
            }));
        }
        if ($resolveOnly) {
            return response()->json([
                'message' => 'RESOLVED',
                'mode' => 'mail',
                'sent' => 0,
                'files_written' => 0,
                'recipients' => count($people),
                'recipient_emails' => array_values(array_unique(array_values(array_filter(array_map(
                    static fn ($p) => strtolower(trim((string) ($p['email'] ?? ''))),
                    $people,
                ), static fn ($email) => $email !== '')))),
                'sent_recipient_emails' => [],
                'failed_recipients' => [],
                'warnings' => [],
                'debug' => [
                    'resolve_only' => true,
                    'email_notifications_enabled' => $emailNotificationsEnabled,
                    'target_filter_count' => count($targetEmails),
                    'resolved_people_count' => count($people),
                ],
            ]);
        }

        $modoLabel = $productionMode ? 'PRODUCCION' : 'PRUEBA';
        $subjectPrefix = $productionMode ? '' : '[PRUEBA] ';
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
            $testWhatsappNumbers = $this->parseWhatsappNumbers($tenant?->test_notification_whatsapp_numbers);
        }
        $testSmsNumbers = [];
        if (! $productionMode) {
            $testSmsNumbers = $this->parseSmsNumbers($tenant?->test_notification_sms_numbers);
        }

        $mode = $this->resolveNotificationMode();
        $warnings = [];
        $mailerName = (string) config('mail.default', 'log');
        $smtpConfigured = $mailerName === 'smtp'
            && trim((string) config('mail.mailers.smtp.host', '')) !== ''
            && trim((string) config('mail.mailers.smtp.username', '')) !== '';
        if ($productionMode && ! $smtpConfigured) {
            $warnings[] = 'Configuración recomendada: usar SMTP autenticado (MAIL_MAILER=smtp + credenciales) para mejorar entrega y evitar rate limit.';
            Log::warning('Activation email notifications are not using authenticated SMTP', [
                'tenant_id' => $tenantId,
                'activation_id' => $activationId,
                'mailer' => $mailerName,
                'smtp_host' => (string) config('mail.mailers.smtp.host', ''),
                'smtp_username_set' => trim((string) config('mail.mailers.smtp.username', '')) !== '',
            ]);
        }
        $fastMailModeEnv = filter_var((string) env('MAIL_FAST_MODE', 'false'), FILTER_VALIDATE_BOOLEAN);
        $fastMailMode = $fastMailModeEnv || ! $productionMode;
        $emailsPerMinute = $fastMailMode
            ? max(1, (int) env('MAIL_FAST_NOTIFICATIONS_PER_MINUTE', 1000))
            : max(1, (int) env('MAIL_NOTIFICATIONS_PER_MINUTE', 12));
        $mailThrottleKey = 'mail_notify_rate:'.$tenantId.':'.$mailerName;
        $debugEvents = [];
        $appendDebugEvent = static function (array $event) use (&$debugEvents): void {
            if (count($debugEvents) >= 300) {
                return;
            }
            $debugEvents[] = $event;
        };
        $appendDebugEvent([
            'stage' => 'notification_start',
            'ts' => $this->tenantNowDateTime($tenantId),
            'tenant_id' => $tenantId,
            'activation_id' => $activationId,
            'production_mode' => $productionMode,
            'mailer' => $mailerName,
            'smtp_configured' => $smtpConfigured,
            'email_notifications_enabled' => $emailNotificationsEnabled,
            'mode' => $mode,
            'fast_mail_mode' => $fastMailMode,
            'emails_per_minute' => $emailsPerMinute,
            'target_filter_count' => count($targetEmails),
            'resolved_people_count' => count($people),
        ]);
        $throttleBeforeSend = static function () use ($mailThrottleKey, $emailsPerMinute): array {
            $safeCounter = 0;
            $waitedSeconds = 0;
            try {
                while (RateLimiter::tooManyAttempts($mailThrottleKey, $emailsPerMinute) && $safeCounter < 20) {
                    $waitSeconds = max(1, RateLimiter::availableIn($mailThrottleKey));
                    usleep($waitSeconds * 1000 * 1000);
                    $waitedSeconds += $waitSeconds;
                    $safeCounter++;
                }
                RateLimiter::hit($mailThrottleKey, 60);
            } catch (\Throwable $rateLimiterError) {
                return [
                    'waited_seconds' => $waitedSeconds,
                    'wait_cycles' => $safeCounter,
                    'key' => $mailThrottleKey,
                    'error' => trim((string) $rateLimiterError->getMessage()),
                ];
            }

            return [
                'waited_seconds' => $waitedSeconds,
                'wait_cycles' => $safeCounter,
                'key' => $mailThrottleKey,
                'error' => '',
            ];
        };
        $ts = now()->format('Ymd_His');
        $sent = 0;
        $filesWritten = 0;
        $whatsappSent = 0;
        $whatsappFilesWritten = 0;
        $smsSent = 0;
        $smsFilesWritten = 0;
        $sentRecipients = [];
        $failedRecipients = [];
        if (empty($people)) {
            $warnings[] = 'No se resolvieron destinatarios para esta activación.';
            $appendDebugEvent([
                'stage' => 'no_recipients_resolved',
                'ts' => $this->tenantNowDateTime($tenantId),
                'target_filter_count' => count($targetEmails),
                'production_mode' => $productionMode,
            ]);
        }
        if ($mode !== 'file' && ! $emailNotificationsEnabled) {
            $warnings[] = 'Envío de correos desactivado en configuración del tenant.';
            $appendDebugEvent([
                'stage' => 'email_notifications_disabled',
                'ts' => $this->tenantNowDateTime($tenantId),
                'tenant_id' => $tenantId,
                'activation_id' => $activationId,
            ]);
        }
        if ($mode !== 'file' && ! $whatsappNotificationsEnabled) {
            $warnings[] = 'Envío de WhatsApp desactivado en configuración del tenant.';
        }
        if ($mode !== 'file' && ! ((bool) ($channels['sms_enabled'] ?? false))) {
            $warnings[] = 'Envío de SMS desactivado en configuración del tenant.';
        }

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
            'generated_at' => $this->tenantNowDateTime($tenantId),
            'recipients' => [],
        ];

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

        $riesgoLabel = '';
        $nivelLabel = '';
        $activationMessageReal = '';
        $activationMessageSimul = '';
        if (Schema::hasTable('activacion_del_plan_trs')) {
            $activation = DB::table('activacion_del_plan_trs')
                ->where('ac_de_pl-tenant_id', $tenantId)
                ->where('ac_de_pl-id', $activationId)
                ->first();
            $activationMessageReal = trim((string) ($activation?->{'ac_de_pl-mensaje_inic'} ?? ''));
            $activationMessageSimul = trim((string) ($activation?->{'ac_de_pl-mensaje_simul'} ?? ''));
            $riesgoId = trim((string) ($activation?->{'ac_de_pl-rie_id-fk'} ?? ''));
            $nivelId = trim((string) ($activation?->{'ac_de_pl-ni_al_id-fk-inicial'} ?? ''));
            if ($riesgoId !== '' && Schema::hasTable('riesgo_cat')) {
                $riesgo = DB::table('riesgo_cat')
                    ->when(
                        Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                        static fn ($q) => $q->where(function ($qq) use ($tenantId) {
                            $qq->whereNull('rie-tenant_id')->orWhere('rie-tenant_id', $tenantId);
                        }),
                    )
                    ->where('rie-id', $riesgoId)
                    ->first();
                $riesgoLabel = trim((string) ($riesgo?->{'rie-nombre'} ?? '')) ?: $riesgoId;
            }
            if ($nivelId !== '' && Schema::hasTable('nivel_alerta_cat')) {
                $nivel = DB::table('nivel_alerta_cat')
                    ->when(
                        Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                        static fn ($q) => $q->where(function ($qq) use ($tenantId) {
                            $qq->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                        }),
                    )
                    ->where('ni_al-id', $nivelId)
                    ->first();
                $nivelLabel = trim((string) ($nivel?->{'ni_al-nombre'} ?? '')) ?: $nivelId;
            }
        }
        $planName = implode(' · ', array_filter([$riesgoLabel, $nivelLabel], static fn ($v) => trim((string) $v) !== '')) ?: $activationId;
        $normalizeMessage = static function ($value): string {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
            }
            if (is_array($value)) {
                $parts = [];
                foreach ($value as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $parts[] = trim($v);
                    }
                }
                return trim(implode("\n", $parts));
            }
            return trim((string) ($value ?? ''));
        };
        $notificationMessage = $normalizeMessage(
            $isSimulacro ? ($tenant?->notifications_message_simulacrum ?? '') : ($tenant?->notifications_message_real ?? ''),
        );
        if ($notificationMessage === '' || preg_match('/^\d+$/', $notificationMessage) === 1) {
            $phase2Message = $isSimulacro ? '' : $normalizeMessage($tenant?->notifications_message_phase2 ?? '');
            if ($phase2Message !== '') {
                $notificationMessage = $phase2Message;
            } else {
                $fallbackMessage = $isSimulacro ? $activationMessageSimul : $activationMessageReal;
                if ($fallbackMessage !== '') {
                    $notificationMessage = $fallbackMessage;
                }
            }
        }
        if ($notificationMessage !== '') {
            $tmp = $notificationMessage;
            if (str_contains($tmp, 'XXXX')) {
                $tmp = preg_replace('/XXXX/', $nivelLabel !== '' ? $nivelLabel : 'NIVEL', $tmp, 1) ?? $tmp;
                $tmp = preg_replace('/XXXX/', $riesgoLabel !== '' ? $riesgoLabel : 'RIESGO', $tmp, 1) ?? $tmp;
            }
            $notificationMessage = $tmp;
        }
        $emailSubject = $subjectPrefix.$planName;
        $emailBody = ($notificationMessage !== '' ? $notificationMessage : '')."\n";
        $includeCredentials = (bool) ($tenant?->notifications_include_credentials ?? false);
        $escapeHtml = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $buildActionsByTipo = static function (array $acciones): array {
            $accionesByTipo = [
                'TITULAR' => [],
                'SUPLENTE' => [],
            ];
            foreach ($acciones as $a) {
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

            return $accionesByTipo;
        };
        $maxEmailAttempts = $fastMailMode ? 1 : 4;
        $emailDelayMs = $fastMailMode ? 0 : 250;
        $batchSize = $fastMailMode ? 999999 : 3;
        $batchDelayMs = $fastMailMode ? 0 : 1500;
        $rateLimitBaseDelayMs = $fastMailMode ? 0 : 1200;
        $transientRetryDelayMs = $fastMailMode ? 0 : max(1000, (int) env('MAIL_TRANSIENT_RETRY_DELAY_MS', 15000));
        $rateLimitRetryDelayMs = $fastMailMode ? 0 : max(1000, (int) env('MAIL_RATELIMIT_RETRY_DELAY_MS', 65000));
        $cooldownAfterTransientFailureMs = $fastMailMode ? 0 : max(0, (int) env('MAIL_COOLDOWN_AFTER_TRANSIENT_FAILURE_MS', 45000));
        $isTransientSmtpError = static function (string $error): bool {
            $msg = strtolower(trim($error));
            if ($msg === '') {
                return false;
            }

            return str_contains($msg, '451')
                || str_contains($msg, '421')
                || str_contains($msg, '4.7.1')
                || str_contains($msg, '4.4.2')
                || str_contains($msg, 'ratelimit')
                || str_contains($msg, 'rate limit')
                || str_contains($msg, 'timeout')
                || str_contains($msg, 'timed out')
                || str_contains($msg, 'temporarily')
                || str_contains($msg, 'try again later')
                || str_contains($msg, 'too many');
        };
        $isRateLimitSmtpError = static function (string $error): bool {
            $msg = strtolower(trim($error));
            if ($msg === '') {
                return false;
            }

            return str_contains($msg, '451')
                || str_contains($msg, '4.7.1')
                || str_contains($msg, 'ratelimit')
                || str_contains($msg, 'rate limit');
        };
        $sendEmailWithRetry = static function (callable $sender) use ($isTransientSmtpError, $isRateLimitSmtpError, $maxEmailAttempts, $rateLimitBaseDelayMs, $transientRetryDelayMs, $rateLimitRetryDelayMs): array {
            $attempt = 0;
            $lastError = '';
            $transientError = false;
            $waitedMs = 0;
            while ($attempt < $maxEmailAttempts) {
                $attempt++;
                try {
                    $sender();

                    return ['sent' => true, 'error' => '', 'attempts' => $attempt, 'transient_error' => false, 'waited_ms' => $waitedMs];
                } catch (\Throwable $mailErrorEx) {
                    $lastError = trim((string) $mailErrorEx->getMessage());
                    $isTransient = $isTransientSmtpError($lastError);
                    $transientError = $transientError || $isTransient;
                    if (! $isTransient || $attempt >= $maxEmailAttempts) {
                        break;
                    }
                    $delayMs = $isRateLimitSmtpError($lastError)
                        ? $rateLimitRetryDelayMs
                        : max($transientRetryDelayMs, $rateLimitBaseDelayMs * (2 ** ($attempt - 1)));
                    usleep($delayMs * 1000);
                    $waitedMs += $delayMs;
                }
            }

            return ['sent' => false, 'error' => $lastError, 'attempts' => $attempt, 'transient_error' => $transientError, 'waited_ms' => $waitedMs];
        };
        $mailAttemptCounter = 0;

        if ($productionMode) {
            foreach ($people as $p) {
                $rawTo = trim((string) ($p['email'] ?? ''));
                $to = filter_var($rawTo, FILTER_VALIDATE_EMAIL) ? strtolower($rawTo) : '';
                $subject = $emailSubject;
                $accionesByTipo = $buildActionsByTipo(is_array($p['acciones'] ?? null) ? $p['acciones'] : []);
                $hasTitular = ! empty($accionesByTipo['TITULAR'] ?? []);
                $hasSuplente = ! empty($accionesByTipo['SUPLENTE'] ?? []);
                $rolesLabel = $hasTitular && $hasSuplente
                    ? 'TITULAR / SUPLENTE'
                    : ($hasTitular ? 'TITULAR' : ($hasSuplente ? 'SUPLENTE' : '—'));
                $emailSent = false;
                $emailError = '';

                $body = $emailBody;
                if ($includeCredentials) {
                    $credentialsLines = $this->buildCredentialsLines($tenant, $to !== '' ? $to : null);
                    if (! empty($credentialsLines)) {
                        $body = rtrim($body)."\n\n".implode("\n", $credentialsLines)."\n";
                    }
                }
                $bodyHtml = '<div style="font-family: Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.6;">'
                    .'<div style="font-size: 16px; font-weight: 700; margin-bottom: 12px;">'.$escapeHtml($subject).'</div>'
                    .($notificationMessage !== ''
                        ? '<div style="margin-bottom: 12px;">'.$this->renderNotificationHtml($notificationMessage).'</div>'
                        : '')
                    .'<div style="margin: 12px 0; padding: 10px 12px; background: #f6f6f6; border: 1px solid #e5e5e5; border-radius: 8px;">'
                    .'<div><strong>Plan:</strong> '.$escapeHtml($planName).'</div>'
                    .'<div><strong>Activación:</strong> '.$escapeHtml($activationId).'</div>'
                    .'<div><strong>Rol:</strong> '.$escapeHtml($rolesLabel).'</div>'
                    .'</div>'
                    .'<div style="font-size: 12px; color: #666;">'.$escapeHtml($modoLabel).'</div>'
                    .'</div>';
                if ($includeCredentials) {
                    $credentialsLines = $this->buildCredentialsLines($tenant, $to !== '' ? $to : null);
                    if (! empty($credentialsLines)) {
                        $bodyHtml .= '<div style="margin-top: 12px; padding: 10px 12px; border: 1px solid #e5e5e5; border-radius: 8px; background: #fafafa;">'
                            .'<div style="font-weight: 600; margin-bottom: 6px;">Credenciales de acceso</div>'
                            .$this->renderNotificationHtml(implode("\n", $credentialsLines))
                            .'</div>';
                    }
                }

                if ($mode === 'file') {
                    $safeTarget = $to !== '' ? $to : (string) ($p['per_id'] ?? 'persona');
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $safeTarget) ?: 'persona';
                    $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.txt';
                    Storage::disk('local')->put($path, $body);
                    $htmlPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.html';
                    Storage::disk('local')->put($htmlPath, $bodyHtml);
                    $jsonPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.json';
                    Storage::disk('local')->put($jsonPath, json_encode([
                        'activation_id' => $activationId,
                        'tenant_id' => $tenantId,
                        'mode' => $mode,
                        'generated_at' => $this->tenantNowDateTime($tenantId),
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
                    if ($to !== '') {
                        if (! $emailNotificationsEnabled) {
                            $appendDebugEvent([
                                'stage' => 'skip_person_email_disabled',
                                'ts' => $this->tenantNowDateTime($tenantId),
                                'per_id' => (string) ($p['per_id'] ?? ''),
                                'email' => $to,
                            ]);
                        } else {
                        $throttleInfo = $throttleBeforeSend();
                        $throttleError = trim((string) ($throttleInfo['error'] ?? ''));
                        if ($throttleError !== '') {
                            $warnings[] = 'Rate limiter no disponible, se continúa sin control de tasa: '.$throttleError;
                        }
                        $mailResult = $sendEmailWithRetry(static function () use ($bodyHtml, $to, $subject): void {
                            Mail::html($bodyHtml, static function ($m) use ($to, $subject) {
                                $m->to($to)->subject($subject);
                            });
                        });
                        if (($mailResult['sent'] ?? false) === true) {
                            $sent++;
                            $emailSent = true;
                            $sentRecipients[] = $to;
                        } else {
                            $emailSent = false;
                            $emailError = trim((string) ($mailResult['error'] ?? ''));
                            $attempts = (int) ($mailResult['attempts'] ?? 1);
                            $retryWaitedMs = (int) ($mailResult['waited_ms'] ?? 0);
                            if (($mailResult['transient_error'] ?? false) === true) {
                                $warnings[] = 'Se agotaron reintentos por error SMTP transitorio para '.$to.' (intentos: '.$attempts.', espera acumulada: '.round($retryWaitedMs / 1000, 1).'s).';
                            }
                        }
                        $appendDebugEvent([
                            'stage' => 'send_person_email',
                            'ts' => $this->tenantNowDateTime($tenantId),
                            'per_id' => (string) ($p['per_id'] ?? ''),
                            'email' => $to,
                            'sent' => $emailSent,
                            'attempts' => (int) ($mailResult['attempts'] ?? 0),
                            'transient_error' => (bool) ($mailResult['transient_error'] ?? false),
                            'retry_waited_ms' => (int) ($mailResult['waited_ms'] ?? 0),
                            'error' => $emailError,
                            'throttle_waited_seconds' => (int) ($throttleInfo['waited_seconds'] ?? 0),
                            'throttle_wait_cycles' => (int) ($throttleInfo['wait_cycles'] ?? 0),
                        ]);
                        if (($mailResult['sent'] ?? false) !== true && ($mailResult['transient_error'] ?? false) === true && $cooldownAfterTransientFailureMs > 0) {
                            usleep($cooldownAfterTransientFailureMs * 1000);
                        }
                        }
                    } else {
                        $emailError = 'email destinatario no válido o ausente';
                        $appendDebugEvent([
                            'stage' => 'skip_person_email_invalid',
                            'ts' => $this->tenantNowDateTime($tenantId),
                            'per_id' => (string) ($p['per_id'] ?? ''),
                            'email' => $rawTo !== '' ? strtolower($rawTo) : null,
                            'reason' => $emailError,
                        ]);
                    }
                    $mailAttemptCounter++;
                    if ($mailAttemptCounter % $batchSize === 0) {
                        usleep($batchDelayMs * 1000);
                    } else {
                        usleep($emailDelayMs * 1000);
                    }
                }
                if ($mode !== 'file' && $emailNotificationsEnabled && ! $emailSent) {
                    $failedRecipients[] = [
                        'per_id' => (string) ($p['per_id'] ?? ''),
                        'email' => $to !== '' ? $to : null,
                        'reason' => $emailError !== '' ? $emailError : 'email no enviado',
                    ];
                }

                if (Schema::hasTable('notificacion_envio_trs')) {
                    $insert = [
                        'no_en-id' => 'NOEN-'.Str::uuid()->toString(),
                        'no_en-tenant_id' => $tenantId,
                        'no_en-ac_de_pl_id-fk' => $activationId,
                        'no_en-per_id-fk' => $p['per_id'],
                        'no_en-gr_op_id-fk' => null,
                        'no_en-rol_id-fk' => null,
                        'no_en-ca_co_id-fk' => null,
                        'no_en-mensaje' => $notificationMessage !== '' ? $notificationMessage : $subject,
                        'no_en-ts' => $this->tenantNowDateTime($tenantId),
                        'no_en-estado' => $mode === 'file' ? 'SIMULADO' : ($emailSent ? 'ENVIADO' : 'SIMULADO'),
                        'no_en-num_de_intento' => '0',
                    ];
                    if ($mode !== 'file' && ! $emailSent) {
                        $extra = $emailError !== ''
                            ? '[email no enviado: '.$emailError.']'
                            : (! $emailNotificationsEnabled ? '[envío de correos desactivado en tenant]' : '[email destinatario no válido o ausente]');
                        $insert['no_en-mensaje'] = trim(($insert['no_en-mensaje'] ?? '').' '.$extra);
                    }
                    if (Schema::hasColumn('notificacion_envio_trs', 'no_en-modo')) {
                        $insert['no_en-modo'] = $modoLabel;
                    }
                    try {
                        DB::table('notificacion_envio_trs')->insert($insert);
                    } catch (\Throwable $logError) {
                        $warnings[] = 'No se pudo registrar notificación email para persona '.$p['per_id'].': '.$logError->getMessage();
                    }
                }

                $index['recipients'][] = [
                    'per_id' => (string) ($p['per_id'] ?? ''),
                    'nombre' => (string) ($p['nombre'] ?? ''),
                    'email' => $to !== '' ? $to : null,
                ];
            }
        } elseif (! empty($testEmails)) {
            $subject = $emailSubject;
            foreach ($testEmails as $testEmail) {
                $body = $emailBody;
                if ($includeCredentials) {
                    $credentialsLines = $this->buildCredentialsLines($tenant, $testEmail);
                    if (! empty($credentialsLines)) {
                        $body = rtrim($body)."\n\n".implode("\n", $credentialsLines)."\n";
                    }
                }
                $bodyHtml = '<div style="font-family: Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.6;">'
                    .'<div style="font-size: 16px; font-weight: 700; margin-bottom: 12px;">'.$escapeHtml($subject).'</div>'
                    .($notificationMessage !== ''
                        ? '<div style="margin-bottom: 12px;">'.$this->renderNotificationHtml($notificationMessage).'</div>'
                        : '')
                    .'<div style="margin: 12px 0; padding: 10px 12px; background: #f6f6f6; border: 1px solid #e5e5e5; border-radius: 8px;">'
                    .'<div><strong>Plan:</strong> '.$escapeHtml($planName).'</div>'
                    .'<div><strong>Activación:</strong> '.$escapeHtml($activationId).'</div>'
                    .'</div>'
                    .'<div style="font-size: 12px; color: #666;">'.$escapeHtml($modoLabel).'</div>'
                    .'</div>';
                if ($includeCredentials) {
                    $credentialsLines = $this->buildCredentialsLines($tenant, $testEmail);
                    if (! empty($credentialsLines)) {
                        $bodyHtml .= '<div style="margin-top: 12px; padding: 10px 12px; border: 1px solid #e5e5e5; border-radius: 8px; background: #fafafa;">'
                            .'<div style="font-weight: 600; margin-bottom: 6px;">Credenciales de acceso</div>'
                            .$this->renderNotificationHtml(implode("\n", $credentialsLines))
                            .'</div>';
                    }
                }

                if ($mode === 'file') {
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $testEmail) ?: 'test';
                    $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.txt';
                    Storage::disk('local')->put($path, $body);
                    $htmlPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.html';
                    Storage::disk('local')->put($htmlPath, $bodyHtml);
                    $jsonPath = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-'.$safe.'.json';
                    Storage::disk('local')->put($jsonPath, json_encode([
                        'activation_id' => $activationId,
                        'tenant_id' => $tenantId,
                        'mode' => $mode,
                        'generated_at' => $this->tenantNowDateTime($tenantId),
                        'subject' => $subject,
                        'destino_prueba' => $testEmail,
                        'personas' => array_map(static fn ($p) => [
                            'per_id' => (string) ($p['per_id'] ?? ''),
                            'nombre' => (string) ($p['nombre'] ?? ''),
                            'email' => (string) ($p['email'] ?? ''),
                        ], $people),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $filesWritten++;
                } else {
                    if (! $emailNotificationsEnabled) {
                        $appendDebugEvent([
                            'stage' => 'skip_test_email_disabled',
                            'ts' => $this->tenantNowDateTime($tenantId),
                            'email' => $testEmail,
                        ]);
                    } else {
                    $throttleInfo = $throttleBeforeSend();
                    $throttleError = trim((string) ($throttleInfo['error'] ?? ''));
                    if ($throttleError !== '') {
                        $warnings[] = 'Rate limiter no disponible, se continúa sin control de tasa: '.$throttleError;
                    }
                    $mailResult = $sendEmailWithRetry(static function () use ($bodyHtml, $testEmail, $subject): void {
                        Mail::html($bodyHtml, static function ($m) use ($testEmail, $subject) {
                            $m->to($testEmail)->subject($subject);
                        });
                    });
                    if (($mailResult['sent'] ?? false) === true) {
                        $sent++;
                        $sentRecipients[] = $testEmail;
                    } else {
                        $error = trim((string) ($mailResult['error'] ?? ''));
                        $attempts = (int) ($mailResult['attempts'] ?? 1);
                        $retryWaitedMs = (int) ($mailResult['waited_ms'] ?? 0);
                        $warnings[] = 'No se pudo enviar email de prueba a '.$testEmail.': '.$error;
                        if (($mailResult['transient_error'] ?? false) === true) {
                            $warnings[] = 'Se agotaron reintentos por error SMTP transitorio para email de prueba '.$testEmail.' (intentos: '.$attempts.', espera acumulada: '.round($retryWaitedMs / 1000, 1).'s).';
                        }
                        $failedRecipients[] = [
                            'per_id' => null,
                            'email' => $testEmail,
                            'reason' => $error !== '' ? $error : 'email de prueba no enviado',
                        ];
                    }
                    $appendDebugEvent([
                        'stage' => 'send_test_email',
                        'ts' => $this->tenantNowDateTime($tenantId),
                        'email' => $testEmail,
                        'sent' => (bool) ($mailResult['sent'] ?? false),
                        'attempts' => (int) ($mailResult['attempts'] ?? 0),
                        'transient_error' => (bool) ($mailResult['transient_error'] ?? false),
                        'retry_waited_ms' => (int) ($mailResult['waited_ms'] ?? 0),
                        'error' => trim((string) ($mailResult['error'] ?? '')),
                        'throttle_waited_seconds' => (int) ($throttleInfo['waited_seconds'] ?? 0),
                        'throttle_wait_cycles' => (int) ($throttleInfo['wait_cycles'] ?? 0),
                    ]);
                    if (($mailResult['sent'] ?? false) !== true && ($mailResult['transient_error'] ?? false) === true && $cooldownAfterTransientFailureMs > 0) {
                        usleep($cooldownAfterTransientFailureMs * 1000);
                    }
                    $mailAttemptCounter++;
                    if ($mailAttemptCounter % $batchSize === 0) {
                        usleep($batchDelayMs * 1000);
                    } else {
                        usleep($emailDelayMs * 1000);
                    }
                    }
                }

                if (Schema::hasTable('notificacion_envio_trs')) {
                    $insert = [
                        'no_en-id' => 'NOEN-'.Str::uuid()->toString(),
                        'no_en-tenant_id' => $tenantId,
                        'no_en-ac_de_pl_id-fk' => $activationId,
                        'no_en-per_id-fk' => null,
                        'no_en-gr_op_id-fk' => null,
                        'no_en-rol_id-fk' => null,
                        'no_en-ca_co_id-fk' => null,
                        'no_en-mensaje' => $notificationMessage !== '' ? $notificationMessage : $subject.' -> '.$testEmail,
                        'no_en-ts' => $this->tenantNowDateTime($tenantId),
                        'no_en-estado' => $mode === 'file' ? 'SIMULADO' : 'ENVIADO',
                        'no_en-num_de_intento' => '0',
                    ];
                    if (Schema::hasColumn('notificacion_envio_trs', 'no_en-modo')) {
                        $insert['no_en-modo'] = $modoLabel;
                    }
                    try {
                        DB::table('notificacion_envio_trs')->insert($insert);
                    } catch (\Throwable $logError) {
                        $warnings[] = 'No se pudo registrar notificación email de prueba a '.$testEmail.': '.$logError->getMessage();
                    }
                }

                $index['recipients'][] = [
                    'per_id' => null,
                    'nombre' => null,
                    'email' => $testEmail,
                ];
            }
        }

        $whatsappTargets = [];
        if ($productionMode) {
            foreach ($people as $p) {
                $phone = $this->normalizeWhatsappNumber((string) ($p['tel_mov'] ?? ''));
                if ($phone === '') {
                    continue;
                }
                $whatsappTargets[$phone] = [
                    'per_id' => (string) ($p['per_id'] ?? ''),
                    'nombre' => (string) ($p['nombre'] ?? ''),
                    'email' => strtolower(trim((string) ($p['email'] ?? ''))),
                    'phone' => $phone,
                ];
            }
        } else {
            foreach ($testWhatsappNumbers as $phone) {
                $normalized = $this->normalizeWhatsappNumber((string) $phone);
                if ($normalized === '') {
                    continue;
                }
                $whatsappTargets[$normalized] = [
                    'per_id' => null,
                    'nombre' => 'TEST',
                    'phone' => $normalized,
                ];
            }
        }
        $whatsappMessage = trim($emailBody);
        if ($whatsappMessage !== '' && ! empty($whatsappTargets)) {
            foreach ($whatsappTargets as $target) {
                $phone = (string) ($target['phone'] ?? '');
                if ($phone === '') {
                    continue;
                }
                $messageForTarget = $whatsappMessage;
                if ($includeCredentials) {
                    $credentialsLines = $this->buildCredentialsLines($tenant, (string) ($target['email'] ?? ''));
                    if (! empty($credentialsLines)) {
                        $messageForTarget = trim($messageForTarget."\n\n".implode("\n", $credentialsLines));
                    }
                }
                if ($mode === 'file') {
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'whatsapp';
                    $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-whatsapp-'.$safe.'.txt';
                    Storage::disk('local')->put($path, $messageForTarget."\n");
                    $whatsappFilesWritten++;
                    continue;
                }
                if (! $whatsappNotificationsEnabled) {
                    continue;
                }
                $wa = $this->sendWhatsappText($tenantId, $phone, $messageForTarget, [
                    'activation_id' => $activationId,
                    'per_id' => $target['per_id'] ?? null,
                    'nombre' => $target['nombre'] ?? null,
                    'type' => 'activation',
                ]);
                if (($wa['sent'] ?? false) === true) {
                    $whatsappSent++;
                } else {
                    $failedRecipients[] = [
                        'per_id' => (string) ($target['per_id'] ?? ''),
                        'email' => null,
                        'phone' => $phone,
                        'reason' => trim((string) ($wa['error'] ?? 'WhatsApp no enviado')),
                    ];
                }
            }
        }
        $smsTargets = [];
        if ($productionMode) {
            foreach ($people as $p) {
                $phone = $this->normalizeWhatsappNumber((string) ($p['tel_mov'] ?? ''));
                if ($phone === '') {
                    continue;
                }
                $smsTargets[$phone] = [
                    'per_id' => (string) ($p['per_id'] ?? ''),
                    'nombre' => (string) ($p['nombre'] ?? ''),
                    'email' => strtolower(trim((string) ($p['email'] ?? ''))),
                    'phone' => $phone,
                ];
            }
        } else {
            foreach ($testSmsNumbers as $phone) {
                $normalized = $this->normalizeWhatsappNumber((string) $phone);
                if ($normalized === '') {
                    continue;
                }
                $smsTargets[$normalized] = [
                    'per_id' => null,
                    'nombre' => 'TEST',
                    'phone' => $normalized,
                ];
            }
        }
        $smsMessage = trim($emailBody);
        if ($smsMessage !== '' && ! empty($smsTargets)) {
            foreach ($smsTargets as $target) {
                $phone = (string) ($target['phone'] ?? '');
                if ($phone === '') {
                    continue;
                }
                $messageForTarget = $smsMessage;
                if ($includeCredentials) {
                    $credentialsLines = $this->buildCredentialsLines($tenant, (string) ($target['email'] ?? ''));
                    if (! empty($credentialsLines)) {
                        $messageForTarget = trim($messageForTarget."\n\n".implode("\n", $credentialsLines));
                    }
                }
                if ($mode === 'file') {
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'sms';
                    $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-sms-'.$safe.'.txt';
                    Storage::disk('local')->put($path, $messageForTarget."\n");
                    $smsFilesWritten++;
                    continue;
                }
                if (! ((bool) ($channels['sms_enabled'] ?? false))) {
                    continue;
                }
                $sms = $this->sendSmsText($tenantId, $phone, $messageForTarget, [
                    'activation_id' => $activationId,
                    'per_id' => $target['per_id'] ?? null,
                    'nombre' => $target['nombre'] ?? null,
                    'type' => 'activation',
                ]);
                if (($sms['sent'] ?? false) === true) {
                    $smsSent++;
                } else {
                    $failedRecipients[] = [
                        'per_id' => (string) ($target['per_id'] ?? ''),
                        'email' => null,
                        'phone' => $phone,
                        'reason' => trim((string) ($sms['error'] ?? 'SMS no enviado')),
                    ];
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
            'recipient_emails' => array_values(array_unique(array_values(array_filter(array_map(
                static fn ($r) => strtolower(trim((string) ($r['email'] ?? ''))),
                is_array($index['recipients'] ?? null) ? $index['recipients'] : [],
            ), static fn ($email) => $email !== '')))),
            'sent_recipient_emails' => array_values(array_unique(array_values(array_filter(array_map(
                static fn ($e) => strtolower(trim((string) $e)),
                $sentRecipients,
            ), static fn ($email) => $email !== '')))),
            'failed_recipients' => $failedRecipients,
            'whatsapp_sent' => $whatsappSent,
            'whatsapp_files_written' => $whatsappFilesWritten,
            'whatsapp_recipients' => count($whatsappTargets),
            'sms_sent' => $smsSent,
            'sms_files_written' => $smsFilesWritten,
            'sms_recipients' => count($smsTargets),
            'email_subject' => $emailSubject,
            'email_body' => $emailBody,
            'warnings' => $warnings,
            'debug' => [
                'production_mode' => $productionMode,
                'mailer' => $mailerName,
                'smtp_configured' => $smtpConfigured,
                'email_notifications_enabled' => $emailNotificationsEnabled,
                'whatsapp_notifications_enabled' => $whatsappNotificationsEnabled,
                'sms_notifications_enabled' => (bool) ($channels['sms_enabled'] ?? false),
                'notifications_channel' => (string) ($channels['channel'] ?? 'email'),
                'fast_mail_mode' => $fastMailMode,
                'emails_per_minute' => $emailsPerMinute,
                'transient_retry_delay_ms' => $transientRetryDelayMs,
                'ratelimit_retry_delay_ms' => $rateLimitRetryDelayMs,
                'cooldown_after_transient_failure_ms' => $cooldownAfterTransientFailureMs,
                'target_filter_count' => count($targetEmails),
                'resolved_people_count' => count($people),
                'mail_attempt_counter' => $mailAttemptCounter,
                'events' => $debugEvents,
            ],
        ]);
        } catch (\Throwable $e) {
            Log::error('sendNotifications failed', [
                'tenant_id' => $tenantId,
                'activation_id' => $activationId,
                'accion_detalle_id' => $accionDetalleId,
                'recipient_emails' => $targetEmails,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'ERROR',
                'mode' => 'mail',
                'sent' => 0,
                'files_written' => 0,
                'recipients' => 0,
                'failed_recipients' => [],
                'warnings' => [],
                'debug' => [
                    'exception_class' => get_class($e),
                    'exception_message' => trim((string) $e->getMessage()),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                ],
            ], 500);
        }
    }

    public function sendNormalidadNotifications(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $validated = $request->validate([
            'ti_em_id' => ['required', 'string'],
            'rie_id' => ['required', 'string'],
        ]);

        $tiEmId = trim((string) ($validated['ti_em_id'] ?? ''));
        $rieId = trim((string) ($validated['rie_id'] ?? ''));

        if ($tiEmId === '' || $rieId === '') {
            return response()->json(['message' => 'Invalid request.'], 422);
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es'],
        );
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $channels = $this->resolveNotificationChannels($tenant);
        $emailNotificationsEnabled = (bool) ($channels['email_enabled'] ?? false);
        $whatsappNotificationsEnabled = (bool) ($channels['whatsapp_enabled'] ?? false);
        $includeCredentials = (bool) ($tenant?->notifications_include_credentials ?? false);
        $modeLabel = $productionMode ? 'PRODUCCION' : 'PRUEBA';
        $subjectPrefix = $productionMode ? '' : '[PRUEBA] ';

        $tipoLabel = $tiEmId;
        if (Schema::hasTable('tipo_emergencia_cat')) {
            $tipo = DB::table('tipo_emergencia_cat')
                ->when(
                    Schema::hasColumn('tipo_emergencia_cat', 'ti_em-tenant_id'),
                    static fn ($q) => $q->where('ti_em-tenant_id', $tenantId),
                )
                ->where('ti_em-id', $tiEmId)
                ->first();
            if ($tipo) {
                $cod = trim((string) ($tipo->{'ti_em-cod'} ?? ''));
                $nombre = trim((string) ($tipo->{'ti_em-nombre'} ?? ''));
                $tipoLabel = trim(implode(' — ', array_filter([$cod, $nombre]))) ?: $tiEmId;
            }
        }

        $riesgoLabel = $rieId;
        if (Schema::hasTable('riesgo_cat')) {
            $riesgo = DB::table('riesgo_cat')
                ->when(
                    Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                    static fn ($q) => $q->where('rie-tenant_id', $tenantId),
                )
                ->where('rie-id', $rieId)
                ->first();
            if ($riesgo) {
                $cod = trim((string) ($riesgo->{'rie-cod'} ?? ''));
                $nombre = trim((string) ($riesgo->{'rie-nombre'} ?? ''));
                $riesgoLabel = trim(implode(' — ', array_filter([$cod, $nombre]))) ?: $rieId;
            }
        }

        $nivelLabel = 'Aviso';
        if (Schema::hasTable('nivel_alerta_cat')) {
            $nivel = DB::table('nivel_alerta_cat')
                ->when(
                    Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                    static fn ($q) => $q->where('ni_al-tenant_id', $tenantId),
                )
                ->whereRaw("UPPER(COALESCE(`ni_al-nombre`, '')) LIKE '%AVISO%' OR UPPER(COALESCE(`ni_al-cod`, '')) IN ('AVISO','AV')")
                ->orderByRaw("CASE WHEN UPPER(COALESCE(`ni_al-nombre`, '')) LIKE '%AVISO%' THEN 0 ELSE 1 END")
                ->first();
            if ($nivel) {
                $cod = trim((string) ($nivel->{'ni_al-cod'} ?? ''));
                $nombre = trim((string) ($nivel->{'ni_al-nombre'} ?? ''));
                $nivelLabel = trim(implode(' — ', array_filter([$cod, $nombre]))) ?: 'Aviso';
            }
        }

        $recipients = [];
        $whatsappRecipients = [];
        $smsRecipients = [];
        $smsRecipients = [];
        if ($productionMode) {
            if (Schema::hasTable('persona_rol_grupo_cfg') && Schema::hasTable('persona_mst')) {
                $rows = DB::table('persona_rol_grupo_cfg as prg')
                    ->join('persona_mst as p', 'p.per-id', '=', 'prg.pe_ro_gr-per_id-fk')
                    ->when(
                        Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                        static fn ($q) => $q->where('prg.pe_ro_gr-tenant_id', $tenantId),
                    )
                    ->when(
                        Schema::hasColumn('persona_mst', 'per-tenant_id'),
                        static fn ($q) => $q->where('p.per-tenant_id', $tenantId),
                    )
                    ->whereRaw("UPPER(COALESCE(`prg`.`pe_ro_gr-activo`, 'SI')) <> 'NO'")
                    ->whereRaw("UPPER(COALESCE(`prg`.`pe_ro_gr-tipo_asignacion`, '')) IN ('TITULAR','LIDER')")
                    ->when(
                        Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-fech_fin'),
                        static fn ($q) => $q->whereNull('prg.pe_ro_gr-fech_fin'),
                    )
                    ->get([
                        'p.per-email as email',
                        'p.per-tel_mov as tel_mov',
                        'p.per-nombre as nombre',
                        'p.per-apellido_1 as apellido_1',
                        'p.per-apellido_2 as apellido_2',
                    ]);

                foreach ($rows as $row) {
                    $email = strtolower(trim((string) ($row->email ?? '')));
                    if ($email === '') {
                        continue;
                    }
                    $recipients[$email] = trim(implode(' ', array_filter([
                        (string) ($row->nombre ?? ''),
                        (string) ($row->apellido_1 ?? ''),
                        (string) ($row->apellido_2 ?? ''),
                    ])));
                    $phone = $this->normalizeWhatsappNumber((string) ($row->tel_mov ?? ''));
                    if ($phone !== '') {
                        $whatsappRecipients[$phone] = $recipients[$email];
                        $smsRecipients[$phone] = $recipients[$email];
                    }
                }
            }
        } else {
            $raw = $tenant?->test_notification_emails;
            if (! empty($raw)) {
                if (is_string($raw)) {
                    $parts = preg_split('/[;,]+/', $raw) ?: [];
                    foreach ($parts as $p) {
                        $email = strtolower(trim($p));
                        if ($email !== '') {
                            $recipients[$email] = $email;
                        }
                    }
                } elseif (is_array($raw)) {
                    foreach ($raw as $p) {
                        $email = strtolower(trim((string) $p));
                        if ($email !== '') {
                            $recipients[$email] = $email;
                        }
                    }
                }
            }
            foreach ($this->parseWhatsappNumbers($tenant?->test_notification_whatsapp_numbers) as $phone) {
                $whatsappRecipients[$phone] = $phone;
            }
            foreach ($this->parseSmsNumbers($tenant?->test_notification_sms_numbers) as $phone) {
                $smsRecipients[$phone] = $phone;
            }
        }

        $subject = $subjectPrefix.'Aviso — '.$tipoLabel;
        $escapeHtml = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $bodyHtml = '<div style="font-family: Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.6;">'
            .'<div style="font-size: 16px; font-weight: 700; margin-bottom: 12px;">'.$escapeHtml($subject).'</div>'
            .'<div style="margin-bottom: 12px;">Se informa que se mantiene la situación en <strong>Normalidad</strong>.</div>'
            .'<div style="margin: 12px 0; padding: 10px 12px; background: #f6f6f6; border: 1px solid #e5e5e5; border-radius: 8px;">'
            .'<div><strong>Tipo de emergencia:</strong> '.$escapeHtml($tipoLabel).'</div>'
            .'<div><strong>Riesgo identificado:</strong> '.$escapeHtml($riesgoLabel).'</div>'
            .'<div><strong>Criterio:</strong> Normalidad</div>'
            .'<div><strong>Nivel de alerta:</strong> '.$escapeHtml($nivelLabel).'</div>'
            .'<div><strong>Fecha/hora:</strong> '.$escapeHtml($this->tenantNowDateTime($tenantId)).'</div>'
            .'</div>'
            .'<div style="font-size: 12px; color: #666;">'.$escapeHtml($modeLabel).'</div>'
            .'</div>';
        if ($includeCredentials) {
            $credentialsLines = $this->buildCredentialsLines($tenant, null);
            if (! empty($credentialsLines)) {
                $bodyHtml .= '<div style="margin-top: 12px; padding: 10px 12px; border: 1px solid #e5e5e5; border-radius: 8px; background: #fafafa;">'
                    .'<div style="font-weight: 600; margin-bottom: 6px;">Credenciales de acceso</div>'
                    .$this->renderNotificationHtml(implode("\n", $credentialsLines))
                    .'</div>';
            }
        }

        $mode = $this->resolveNotificationMode();
        $sent = 0;
        $filesWritten = 0;
        $whatsappSent = 0;
        $whatsappFilesWritten = 0;
        $smsSent = 0;
        $smsFilesWritten = 0;
        $ts = now()->format('YmdHis');

        if (empty($recipients) && empty($whatsappRecipients) && empty($smsRecipients)) {
            return response()->json([
                'message' => 'No recipients.',
                'mode' => $mode,
                'sent' => 0,
                'files_written' => 0,
                'recipients' => 0,
                'whatsapp_recipients' => 0,
                'sms_recipients' => 0,
                'email_subject' => $subject,
                'email_body' => strip_tags($bodyHtml),
            ]);
        }

        foreach ($recipients as $email => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $email) ?: 'persona';
                $path = 'notifications_outbox/'.$tenantId.'/normalidad/'.$ts.'-'.$safe.'.html';
                Storage::disk('local')->put($path, $bodyHtml);
                $filesWritten++;
                continue;
            }
            if (! $emailNotificationsEnabled) {
                continue;
            }

            Mail::html($bodyHtml, static function ($m) use ($email, $subject) {
                $m->to($email)->subject($subject);
            });
            $sent++;
        }
        $whatsappMessage = trim(strip_tags($bodyHtml));
        foreach ($whatsappRecipients as $phone => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'whatsapp';
                $path = 'notifications_outbox/'.$tenantId.'/normalidad/'.$ts.'-wa-'.$safe.'.txt';
                Storage::disk('local')->put($path, $whatsappMessage."\n");
                $whatsappFilesWritten++;
                continue;
            }
            if (! $whatsappNotificationsEnabled) {
                continue;
            }
            $res = $this->sendWhatsappText($tenantId, $phone, $whatsappMessage, ['type' => 'normalidad']);
            if (($res['sent'] ?? false) === true) {
                $whatsappSent++;
            }
        }
        $smsMessage = $whatsappMessage;
        foreach ($smsRecipients as $phone => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'sms';
                $path = 'notifications_outbox/'.$tenantId.'/normalidad/'.$tiEmId.'/'.$rieId.'/'.$ts.'-sms-'.$safe.'.txt';
                Storage::disk('local')->put($path, $smsMessage."\n");
                $smsFilesWritten++;
                continue;
            }
            if (! ((bool) ($channels['sms_enabled'] ?? false))) {
                continue;
            }
            $res = $this->sendSmsText($tenantId, (string) $phone, $smsMessage, ['type' => 'normalidad']);
            if (($res['sent'] ?? false) === true) {
                $smsSent++;
            }
        }

        return response()->json([
            'message' => 'OK',
            'mode' => $mode,
            'sent' => $sent,
            'files_written' => $filesWritten,
            'whatsapp_sent' => $whatsappSent,
            'whatsapp_files_written' => $whatsappFilesWritten,
            'recipients' => count($recipients),
            'whatsapp_recipients' => count($whatsappRecipients),
            'sms_sent' => $smsSent,
            'sms_files_written' => $smsFilesWritten,
            'sms_recipients' => count($smsRecipients),
            'email_subject' => $subject,
            'email_body' => strip_tags($bodyHtml),
            'warnings' => array_values(array_filter([
                ! $emailNotificationsEnabled ? 'Envío de correos desactivado en configuración del tenant.' : null,
                ! $whatsappNotificationsEnabled ? 'Envío de WhatsApp desactivado en configuración del tenant.' : null,
                ! ((bool) ($channels['sms_enabled'] ?? false)) ? 'Envío de SMS desactivado en configuración del tenant.' : null,
            ])),
        ]);
    }

    public function sendSummaryNotifications(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        $activationTable = null;
        if (Schema::hasTable('activacion_control')) {
            $activationTable = 'activacion_control';
        } elseif (Schema::hasTable('activacion_del_plan_trs')) {
            $activationTable = 'activacion_del_plan_trs';
        }
        if ($activationTable === null) {
            return response()->json(['message' => 'Missing activation table.'], 422);
        }

        $activation = DB::table($activationTable)
            ->when(
                Schema::hasColumn($activationTable, 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->where('ac_de_pl-id', $activationId)
            ->first();

        if (! $activation) {
            return response()->json(['message' => 'Activation not found.'], 404);
        }

        $tiEmId = trim((string) ($activation->{'ac_de_pl-ti_em_id-fk'} ?? ''));
        $rieId = trim((string) ($activation->{'ac_de_pl-rie_id-fk'} ?? ''));
        $nivelId = trim((string) ($activation->{'ac_de_pl-ni_al_id-fk'} ?? $activation->{'ac_de_pl-ni_al_id-fk-inicial'} ?? ''));
        $isSimulacro = strtoupper(trim((string) ($activation->{'ac_de_pl-es_simul'} ?? 'NO'))) === 'SI';

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es'],
        );
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $channels = $this->resolveNotificationChannels($tenant);
        $emailNotificationsEnabled = (bool) ($channels['email_enabled'] ?? false);
        $whatsappNotificationsEnabled = (bool) ($channels['whatsapp_enabled'] ?? false);
        $includeCredentials = (bool) ($tenant?->notifications_include_credentials ?? false);
        $modeLabel = $productionMode ? 'PRODUCCION' : 'PRUEBA';
        $subjectPrefix = $productionMode ? '' : '[PRUEBA] ';
        $simPrefix = $isSimulacro ? '[SIMULACRO] ' : '';

        $tipoLabel = $tiEmId;
        if ($tiEmId !== '' && Schema::hasTable('tipo_emergencia_cat')) {
            $tipo = DB::table('tipo_emergencia_cat')
                ->when(
                    Schema::hasColumn('tipo_emergencia_cat', 'ti_em-tenant_id'),
                    static fn ($q) => $q->where('ti_em-tenant_id', $tenantId),
                )
                ->where('ti_em-id', $tiEmId)
                ->first();
            if ($tipo) {
                $cod = trim((string) ($tipo->{'ti_em-cod'} ?? ''));
                $nombre = trim((string) ($tipo->{'ti_em-nombre'} ?? ''));
                $tipoLabel = $nombre !== '' ? $nombre : ($cod !== '' ? $cod : $tiEmId);
            }
        }

        $riesgoLabel = $rieId;
        if ($rieId !== '' && Schema::hasTable('riesgo_cat')) {
            $riesgo = DB::table('riesgo_cat')
                ->when(
                    Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                    static fn ($q) => $q->where('rie-tenant_id', $tenantId),
                )
                ->where('rie-id', $rieId)
                ->first();
            if ($riesgo) {
                $cod = trim((string) ($riesgo->{'rie-cod'} ?? ''));
                $nombre = trim((string) ($riesgo->{'rie-nombre'} ?? ''));
                $riesgoLabel = $nombre !== '' ? $nombre : ($cod !== '' ? $cod : $rieId);
            }
        }

        $nivelLabel = $nivelId;
        $nivelCod = '';
        $nivelNombre = '';
        if ($nivelId !== '' && Schema::hasTable('nivel_alerta_cat')) {
            $nivel = DB::table('nivel_alerta_cat')
                ->when(
                    Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                    static fn ($q) => $q->where('ni_al-tenant_id', $tenantId),
                )
                ->where('ni_al-id', $nivelId)
                ->first();
            if ($nivel) {
                $nivelCod = strtoupper(trim((string) ($nivel->{'ni_al-cod'} ?? '')));
                $nivelNombre = trim((string) ($nivel->{'ni_al-nombre'} ?? ''));
                $nivelLabel = $nivelNombre !== '' ? $nivelNombre : ($nivelCod !== '' ? $nivelCod : $nivelId);
            }
        }

        $nivelUpper = strtoupper($nivelNombre !== '' ? $nivelNombre : (string) $nivelLabel);
        $isAviso = $nivelUpper !== '' && (str_contains($nivelUpper, 'AVISO') || $nivelCod === 'AVISO' || $nivelCod === 'AV' || str_starts_with($nivelCod, 'AV'));
        $isPrealerta = $nivelUpper !== '' && (str_contains($nivelUpper, 'PREALERTA') || str_starts_with($nivelCod, 'P'));
        $scenarioLabel = $isPrealerta ? 'Prealerta' : ($isAviso ? 'Aviso' : 'Resumen');
        $motivoDetalle = trim((string) ($activation->{'ac_ni_hi-info_adicional'} ?? ''));
        if ($motivoDetalle === '' && Schema::hasTable('activacion_nivel_hist_trs')) {
            $row = DB::table('activacion_nivel_hist_trs')
                ->when(
                    Schema::hasColumn('activacion_nivel_hist_trs', 'ac_ni_hi-tenant_id'),
                    static fn ($q) => $q->where('ac_ni_hi-tenant_id', $tenantId),
                )
                ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId)
                ->orderByRaw('COALESCE(CAST(`ac_ni_hi-orden` AS SIGNED),0) DESC')
                ->orderByDesc('ac_ni_hi-id')
                ->first(['ac_ni_hi-info_adicional']);
            $motivoDetalle = trim((string) ($row->{'ac_ni_hi-info_adicional'} ?? ''));
        }
        if ($motivoDetalle === '') {
            $motivoDetalle = trim((string) ($activation->{'ac_de_pl-observ'} ?? ''));
        }

        $recipients = [];
        $whatsappRecipients = [];
        $recipientSource = 'none';
        if ($productionMode) {
            if ($isAviso) {
                $recipientSource = 'users_roles';
                $userPhoneColumn = Schema::hasColumn('users', 'phone');
                $userColumns = ['name', 'email', 'perfil'];
                if ($userPhoneColumn) {
                    $userColumns[] = 'phone';
                }
                $rows = User::query()
                    ->when(
                        Schema::hasColumn('users', 'tenant_id'),
                        static fn ($q) => $q->where('tenant_id', $tenantId),
                    )
                    ->get($userColumns);

                foreach ($rows as $row) {
                    $perfil = strtolower(trim((string) ($row->perfil ?? '')));
                    if (! in_array($perfil, ['director', 'recurso'], true)) {
                        continue;
                    }
                    $email = strtolower(trim((string) ($row->email ?? '')));
                    if ($email === '') {
                        $phone = $this->normalizeWhatsappNumber((string) ($row->phone ?? ''));
                        if ($phone !== '') {
                            $whatsappRecipients[$phone] = trim((string) ($row->name ?? '')) ?: $phone;
                        }
                        continue;
                    }
                    $recipients[$email] = trim((string) ($row->name ?? '')) ?: $email;
                    $phone = $this->normalizeWhatsappNumber((string) ($row->phone ?? ''));
                    if ($phone !== '') {
                        $whatsappRecipients[$phone] = $recipients[$email];
                    }
                }
            } elseif (Schema::hasTable('persona_rol_grupo_cfg') && Schema::hasTable('persona_mst')) {
                $recipientSource = 'roles_groups';
                $rows = DB::table('persona_rol_grupo_cfg as prg')
                    ->join('persona_mst as p', 'p.per-id', '=', 'prg.pe_ro_gr-per_id-fk')
                    ->when(
                        Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                        static fn ($q) => $q->where('prg.pe_ro_gr-tenant_id', $tenantId),
                    )
                    ->when(
                        Schema::hasColumn('persona_mst', 'per-tenant_id'),
                        static fn ($q) => $q->where('p.per-tenant_id', $tenantId),
                    )
                    ->whereRaw("UPPER(COALESCE(`prg`.`pe_ro_gr-activo`, 'SI')) <> 'NO'")
                    ->whereRaw("UPPER(COALESCE(`prg`.`pe_ro_gr-tipo_asignacion`, 'SUPLENTE')) IN ('TITULAR','SUPLENTE','LIDER')")
                    ->when(
                        Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-fech_fin'),
                        static fn ($q) => $q->whereNull('prg.pe_ro_gr-fech_fin'),
                    )
                    ->get([
                        'p.per-email as email',
                        'p.per-tel_mov as tel_mov',
                        'p.per-nombre as nombre',
                        'p.per-apellido_1 as apellido_1',
                        'p.per-apellido_2 as apellido_2',
                    ]);

                foreach ($rows as $row) {
                    $email = strtolower(trim((string) ($row->email ?? '')));
                    if ($email === '') {
                        continue;
                    }
                    $recipients[$email] = trim(implode(' ', array_filter([
                        (string) ($row->nombre ?? ''),
                        (string) ($row->apellido_1 ?? ''),
                        (string) ($row->apellido_2 ?? ''),
                    ])));
                    $phone = $this->normalizeWhatsappNumber((string) ($row->tel_mov ?? ''));
                    if ($phone !== '') {
                        $whatsappRecipients[$phone] = $recipients[$email];
                        $smsRecipients[$phone] = $recipients[$email];
                    }
                }
            }
        } else {
            $recipientSource = 'test_emails';
            $raw = $tenant?->test_notification_emails;
            if (! empty($raw)) {
                if (is_string($raw)) {
                    $parts = preg_split('/[;,]+/', $raw) ?: [];
                    foreach ($parts as $p) {
                        $email = strtolower(trim($p));
                        if ($email !== '') {
                            $recipients[$email] = $email;
                        }
                    }
                } elseif (is_array($raw)) {
                    foreach ($raw as $p) {
                        $email = strtolower(trim((string) $p));
                        if ($email !== '') {
                            $recipients[$email] = $email;
                        }
                    }
                }
            }
            foreach ($this->parseWhatsappNumbers($tenant?->test_notification_whatsapp_numbers) as $phone) {
                $whatsappRecipients[$phone] = $phone;
            }
            foreach ($this->parseSmsNumbers($tenant?->test_notification_sms_numbers) as $phone) {
                $smsRecipients[$phone] = $phone;
            }
        }

        $subject = $subjectPrefix.$simPrefix.'Resumen '.$scenarioLabel.($tipoLabel ? ' — '.$tipoLabel : '');
        $escapeHtml = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $bodyHtml = '<div style="font-family: Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.6;">'
            .'<div style="font-size: 16px; font-weight: 700; margin-bottom: 12px;">'.$escapeHtml($subject).'</div>'
            .'<div style="margin-bottom: 12px;">Se informa el nivel de <strong>'.$escapeHtml($scenarioLabel).'</strong>.</div>'
            .'<div style="margin: 12px 0; padding: 10px 12px; background: #f6f6f6; border: 1px solid #e5e5e5; border-radius: 8px;">'
            .($tipoLabel ? '<div><strong>Tipo de emergencia:</strong> '.$escapeHtml($tipoLabel).'</div>' : '')
            .($riesgoLabel ? '<div><strong>Riesgo identificado:</strong> '.$escapeHtml($riesgoLabel).'</div>' : '')
            .($nivelLabel ? '<div><strong>Nivel de alerta:</strong> '.$escapeHtml($nivelLabel).'</div>' : '')
            .($motivoDetalle !== '' ? '<div><strong>Motivo de activación:</strong> '.$escapeHtml($motivoDetalle).'</div>' : '')
            .'<div><strong>Fecha/hora:</strong> '.$escapeHtml($this->tenantNowDateTime($tenantId)).'</div>'
            .'</div>'
            .'<div>En este nivel no se generan acciones operativas.</div>'
            .'<div style="font-size: 12px; color: #666; margin-top: 10px;">'.$escapeHtml($modeLabel).'</div>'
            .'</div>';
        if ($includeCredentials) {
            $credentialsLines = $this->buildCredentialsLines($tenant, null);
            if (! empty($credentialsLines)) {
                $bodyHtml .= '<div style="margin-top: 12px; padding: 10px 12px; border: 1px solid #e5e5e5; border-radius: 8px; background: #fafafa;">'
                    .'<div style="font-weight: 600; margin-bottom: 6px;">Credenciales de acceso</div>'
                    .$this->renderNotificationHtml(implode("\n", $credentialsLines))
                    .'</div>';
            }
        }

        $mode = $this->resolveNotificationMode();
        $sent = 0;
        $filesWritten = 0;
        $whatsappSent = 0;
        $whatsappFilesWritten = 0;
        $smsSent = 0;
        $smsFilesWritten = 0;
        $ts = now()->format('YmdHis');

        if (empty($recipients) && empty($whatsappRecipients) && empty($smsRecipients)) {
            return response()->json([
                'message' => 'No recipients.',
                'mode' => $mode,
                'sent' => 0,
                'files_written' => 0,
                'recipients' => 0,
                'whatsapp_recipients' => 0,
                'sms_recipients' => 0,
                'recipient_emails' => [],
                'sent_recipient_emails' => [],
                'email_subject' => $subject,
                'email_body' => strip_tags($bodyHtml),
                'debug' => [
                    'production_mode' => $productionMode,
                    'email_notifications_enabled' => $emailNotificationsEnabled,
                    'whatsapp_notifications_enabled' => $whatsappNotificationsEnabled,
                    'sms_notifications_enabled' => (bool) ($channels['sms_enabled'] ?? false),
                    'recipient_source' => $recipientSource,
                    'is_aviso' => $isAviso,
                ],
            ]);
        }

        foreach ($recipients as $email => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $email) ?: 'persona';
                $path = 'notifications_outbox/'.$tenantId.'/summary/'.$activationId.'/'.$ts.'-'.$safe.'.html';
                Storage::disk('local')->put($path, $bodyHtml);
                $filesWritten++;
                continue;
            }
            if (! $emailNotificationsEnabled) {
                continue;
            }

            Mail::html($bodyHtml, static function ($m) use ($email, $subject) {
                $m->to($email)->subject($subject);
            });
            $sent++;
        }
        $whatsappMessage = trim(strip_tags($bodyHtml));
        foreach ($whatsappRecipients as $phone => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'whatsapp';
                $path = 'notifications_outbox/'.$tenantId.'/summary/'.$activationId.'/'.$ts.'-wa-'.$safe.'.txt';
                Storage::disk('local')->put($path, $whatsappMessage."\n");
                $whatsappFilesWritten++;
                continue;
            }
            if (! $whatsappNotificationsEnabled) {
                continue;
            }
            $res = $this->sendWhatsappText($tenantId, $phone, $whatsappMessage, ['type' => 'summary', 'activation_id' => $activationId]);
            if (($res['sent'] ?? false) === true) {
                $whatsappSent++;
            }
        }
        $smsMessage = $whatsappMessage;
        foreach ($smsRecipients as $phone => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'sms';
                $path = 'notifications_outbox/'.$tenantId.'/summary/'.$activationId.'/'.$ts.'-sms-'.$safe.'.txt';
                Storage::disk('local')->put($path, $smsMessage."\n");
                $smsFilesWritten++;
                continue;
            }
            if (! ((bool) ($channels['sms_enabled'] ?? false))) {
                continue;
            }
            $res = $this->sendSmsText($tenantId, (string) $phone, $smsMessage, ['type' => 'summary', 'activation_id' => $activationId]);
            if (($res['sent'] ?? false) === true) {
                $smsSent++;
            }
        }

        return response()->json([
            'message' => 'OK',
            'mode' => $mode,
            'sent' => $sent,
            'files_written' => $filesWritten,
            'whatsapp_sent' => $whatsappSent,
            'whatsapp_files_written' => $whatsappFilesWritten,
            'recipients' => count($recipients),
            'whatsapp_recipients' => count($whatsappRecipients),
            'sms_sent' => $smsSent,
            'sms_files_written' => $smsFilesWritten,
            'sms_recipients' => count($smsRecipients),
            'recipient_emails' => array_values(array_keys($recipients)),
            'sent_recipient_emails' => $sent > 0 ? array_values(array_keys($recipients)) : [],
            'email_subject' => $subject,
            'email_body' => strip_tags($bodyHtml),
            'warnings' => array_values(array_filter([
                ! $emailNotificationsEnabled ? 'Envío de correos desactivado en configuración del tenant.' : null,
                ! $whatsappNotificationsEnabled ? 'Envío de WhatsApp desactivado en configuración del tenant.' : null,
                ! ((bool) ($channels['sms_enabled'] ?? false)) ? 'Envío de SMS desactivado en configuración del tenant.' : null,
            ])),
            'debug' => [
                'production_mode' => $productionMode,
                'email_notifications_enabled' => $emailNotificationsEnabled,
                'whatsapp_notifications_enabled' => $whatsappNotificationsEnabled,
                'sms_notifications_enabled' => (bool) ($channels['sms_enabled'] ?? false),
                'recipient_source' => $recipientSource,
                'is_aviso' => $isAviso,
            ],
        ]);
    }

    public function sendTitularChangeNotification(Request $request, string $activationId): JsonResponse
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
            'grupo_id' => ['required', 'string'],
            'per_id' => ['required', 'string'],
        ]);
        $grupoId = trim((string) ($validated['grupo_id'] ?? ''));
        $perId = trim((string) ($validated['per_id'] ?? ''));
        if ($grupoId === '' || $perId === '') {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es'],
        );
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $channels = $this->resolveNotificationChannels($tenant);
        $emailNotificationsEnabled = (bool) ($channels['email_enabled'] ?? false);
        $whatsappNotificationsEnabled = (bool) ($channels['whatsapp_enabled'] ?? false);
        $includeCredentials = (bool) ($tenant?->notifications_include_credentials ?? false);
        $mode = $this->resolveNotificationMode();

        $groupName = '';
        if (Schema::hasTable('grupo_operativo_cat')) {
            $groupName = trim((string) DB::table('grupo_operativo_cat')
                ->when(
                    Schema::hasColumn('grupo_operativo_cat', 'gr_op-tenant_id'),
                    static fn ($q) => $q->where('gr_op-tenant_id', $tenantId),
                )
                ->where('gr_op-id', $grupoId)
                ->value('gr_op-nombre'));
        }

        $personEmail = '';
        $personWhatsapp = '';
        $personLabel = '';
        if (Schema::hasTable('persona_mst')) {
            $person = DB::table('persona_mst as p')
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->where('p.per-tenant_id', $tenantId),
                )
                ->where('p.per-id', $perId)
                ->first([
                    'p.per-email as email',
                    'p.per-tel_mov as tel_mov',
                    'p.per-nombre as nombre',
                    'p.per-apellido_1 as apellido_1',
                    'p.per-apellido_2 as apellido_2',
                ]);
            $personEmail = strtolower(trim((string) ($person->email ?? '')));
            $personWhatsapp = $this->normalizeWhatsappNumber((string) ($person->tel_mov ?? ''));
            $personLabel = trim(implode(' ', array_filter([
                (string) ($person->nombre ?? ''),
                (string) ($person->apellido_1 ?? ''),
                (string) ($person->apellido_2 ?? ''),
            ])));
        }

        $recipients = [];
        $whatsappRecipients = [];
        $smsRecipients = [];
        if ($productionMode) {
            if ($personEmail !== '') {
                $recipients[$personEmail] = $personLabel !== '' ? $personLabel : $personEmail;
            }
            if ($personWhatsapp !== '') {
                $whatsappRecipients[$personWhatsapp] = $personLabel !== '' ? $personLabel : $personWhatsapp;
                $smsRecipients[$personWhatsapp] = $personLabel !== '' ? $personLabel : $personWhatsapp;
            }
        } else {
            $raw = $tenant?->test_notification_emails;
            $values = [];
            if (is_string($raw)) {
                $values = preg_split('/[;,]+/', $raw) ?: [];
            } elseif (is_array($raw)) {
                $values = $raw;
            }
            foreach ($values as $value) {
                $email = strtolower(trim((string) $value));
                if ($email !== '') {
                    $recipients[$email] = $email;
                }
            }
            foreach ($this->parseWhatsappNumbers($tenant?->test_notification_whatsapp_numbers) as $phone) {
                $whatsappRecipients[$phone] = $phone;
            }
            foreach ($this->parseSmsNumbers($tenant?->test_notification_sms_numbers) as $phone) {
                $smsRecipients[$phone] = $phone;
            }
        }

        $subject = 'Cambio de titularidad'.($groupName !== '' ? ' — '.$groupName : '');
        $text = "Se confirma que ahora eres titular del grupo operativo.\n"
            .($personLabel !== '' ? "Nuevo titular: {$personLabel}\n" : '')
            .($groupName !== '' ? "Grupo operativo: {$groupName}\n" : '')
            ."Plan activado: {$activationId}\n"
            .'Accede a la aplicación: https://emta.grupo-tema.com/';
        if ($includeCredentials) {
            $credentialsLines = $this->buildCredentialsLines($tenant, $personEmail !== '' ? $personEmail : null);
            if (! empty($credentialsLines)) {
                $text = rtrim($text)."\n\n".implode("\n", $credentialsLines);
            }
        }
        $bodyHtml = $this->renderNotificationHtml($text);

        $sent = 0;
        $filesWritten = 0;
        $whatsappSent = 0;
        $whatsappFilesWritten = 0;
        $smsSent = 0;
        $smsFilesWritten = 0;
        $ts = now()->format('YmdHis');
        foreach ($recipients as $email => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $email) ?: 'persona';
                $path = 'notifications_outbox/'.$tenantId.'/titular/'.$activationId.'/'.$ts.'-'.$safe.'.html';
                Storage::disk('local')->put($path, $bodyHtml);
                $filesWritten++;
                continue;
            }
            if (! $emailNotificationsEnabled) {
                continue;
            }
            Mail::html($bodyHtml, static function ($m) use ($email, $subject): void {
                $m->to($email)->subject($subject);
            });
            $sent++;
        }
        foreach ($whatsappRecipients as $phone => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'whatsapp';
                $path = 'notifications_outbox/'.$tenantId.'/titular/'.$activationId.'/'.$ts.'-wa-'.$safe.'.txt';
                Storage::disk('local')->put($path, $text."\n");
                $whatsappFilesWritten++;
                continue;
            }
            if (! $whatsappNotificationsEnabled) {
                continue;
            }
            $res = $this->sendWhatsappText($tenantId, $phone, $text, ['type' => 'titular', 'activation_id' => $activationId]);
            if (($res['sent'] ?? false) === true) {
                $whatsappSent++;
            }
        }
        foreach ($smsRecipients as $phone => $displayName) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'sms';
                $path = 'notifications_outbox/'.$tenantId.'/titular/'.$activationId.'/'.$ts.'-sms-'.$safe.'.txt';
                Storage::disk('local')->put($path, $text."\n");
                $smsFilesWritten++;
                continue;
            }
            if (! ((bool) ($channels['sms_enabled'] ?? false))) {
                continue;
            }
            $res = $this->sendSmsText($tenantId, (string) $phone, $text, ['type' => 'titular', 'activation_id' => $activationId]);
            if (($res['sent'] ?? false) === true) {
                $smsSent++;
            }
        }

        return response()->json([
            'message' => 'OK',
            'mode' => $mode,
            'sent' => $sent,
            'files_written' => $filesWritten,
            'whatsapp_sent' => $whatsappSent,
            'whatsapp_files_written' => $whatsappFilesWritten,
            'sms_sent' => $smsSent,
            'sms_files_written' => $smsFilesWritten,
            'recipients' => count($recipients),
            'whatsapp_recipients' => count($whatsappRecipients),
            'sms_recipients' => count($smsRecipients),
            'recipient_emails' => array_keys($recipients),
            'email_subject' => $subject,
            'email_body' => strip_tags($bodyHtml),
            'warnings' => array_values(array_filter([
                ! $emailNotificationsEnabled ? 'Envío de correos desactivado en configuración del tenant.' : null,
                ! $whatsappNotificationsEnabled ? 'Envío de WhatsApp desactivado en configuración del tenant.' : null,
                ! ((bool) ($channels['sms_enabled'] ?? false)) ? 'Envío de SMS desactivado en configuración del tenant.' : null,
            ])),
            'debug' => [
                'production_mode' => $productionMode,
                'email_notifications_enabled' => $emailNotificationsEnabled,
                'whatsapp_notifications_enabled' => $whatsappNotificationsEnabled,
                'sms_notifications_enabled' => (bool) ($channels['sms_enabled'] ?? false),
            ],
        ]);
    }

    private function resolveNotificationMode(): string
    {
        $forceFile = filter_var((string) env('NOTIFICATIONS_FORCE_FILE_MODE', 'false'), FILTER_VALIDATE_BOOL);
        return $forceFile ? 'file' : 'mail';
    }

    private function renderNotificationHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $withLinks = preg_replace_callback(
            '/(https?:\/\/[^\s<]+)/iu',
            static function (array $matches): string {
                $url = $matches[1] ?? '';
                if ($url === '') {
                    return '';
                }
                return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>';
            },
            $escaped,
        ) ?? $escaped;
        return nl2br($withLinks);
    }

    private function buildCredentialsLines(?Tenant $tenant, ?string $email = null): array
    {
        $enabled = (bool) ($tenant?->notifications_include_credentials ?? false);
        if (! $enabled) {
            return [];
        }

        $frontendUrl = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $loginUrl = $frontendUrl !== '' ? $frontendUrl.'/' : '';
        $normalizedEmail = strtolower(trim((string) ($email ?? '')));
        $lines = [];
        $resetUrl = '';
        $tenantId = trim((string) ($tenant?->tenant_id ?? ''));

        if ($normalizedEmail !== '' && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) && $tenantId !== '' && Schema::hasTable('password_reset_tokens')) {
            $cacheKey = $tenantId.'|'.$normalizedEmail;
            $resetUrl = (string) ($this->passwordResetUrlCache[$cacheKey] ?? '');
            if ($resetUrl === '') {
                $userExists = User::query()
                    ->where('tenant_id', $tenantId)
                    ->where('email', $normalizedEmail)
                    ->exists();
                if ($userExists) {
                    $token = Str::random(64);
                    $resetQuery = DB::table('password_reset_tokens')->where('email', $normalizedEmail);
                    if (Schema::hasColumn('password_reset_tokens', 'tenant_id')) {
                        $resetQuery->where('tenant_id', $tenantId);
                    }
                    $resetQuery->delete();

                    $insert = [
                        'email' => $normalizedEmail,
                        'token' => Hash::make($token),
                        'created_at' => now(),
                    ];
                    if (Schema::hasColumn('password_reset_tokens', 'tenant_id')) {
                        $insert['tenant_id'] = $tenantId;
                    }
                    DB::table('password_reset_tokens')->insert($insert);

                    $query = [
                        'token' => $token,
                        'email' => $normalizedEmail,
                    ];
                    if ($tenantId !== '') {
                        $query['tenantId'] = $tenantId;
                    }
                    $resetUrl = rtrim($frontendUrl, '/').'/reset-password?'.http_build_query($query);
                    $this->passwordResetUrlCache[$cacheKey] = $resetUrl;
                }
            }
        }

        if ($resetUrl !== '') {
            $lines[] = 'Acceso inicial / restablecer contraseña: '.$resetUrl;
        } elseif ($loginUrl !== '') {
            $lines[] = 'Accede a la aplicación: '.$loginUrl;
        }
        if ($normalizedEmail !== '' && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            $lines[] = 'Usuario: '.$normalizedEmail;
        }
        if ($resetUrl !== '') {
            $lines[] = 'Define tu clave desde el enlace anterior.';
        } else {
            $lines[] = 'Contraseña: la habitual de acceso.';
        }

        return $lines;
    }

    private function postWhatsappWebhook(string $webhookUrl, array $payload): HttpClientResponse
    {
        $result = Http::timeout(8)->post($webhookUrl, $payload);
        if ($result instanceof \GuzzleHttp\Promise\PromiseInterface) {
            $resolved = $result->wait();
            if ($resolved instanceof HttpClientResponse) {
                return $resolved;
            }
            throw new \RuntimeException('Respuesta inválida del webhook de WhatsApp.');
        }

        return $result;
    }

    private function resolveNotificationChannels(?Tenant $tenant): array
    {
        $channel = strtolower(trim((string) ($tenant?->notifications_channel ?? 'email')));
        if (! in_array($channel, ['email', 'whatsapp', 'both', 'email_sms'], true)) {
            $channel = 'email';
        }
        $emailEnabledByChannel = $channel === 'email' || $channel === 'both' || $channel === 'email_sms';
        $whatsappEnabledByChannel = $channel === 'whatsapp' || $channel === 'both';

        return [
            'channel' => $channel,
            'email_enabled' => (bool) ($tenant?->notifications_email_enabled ?? false) && $emailEnabledByChannel,
            'whatsapp_enabled' => $whatsappEnabledByChannel,
            'sms_enabled' => (bool) ($tenant?->notifications_sms_enabled ?? false),
        ];
    }

    private function normalizeWhatsappNumber(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[()\-\.\s]+/', '', $value) ?? '';
        if (str_starts_with($value, '+')) {
            $digits = preg_replace('/\D+/', '', substr($value, 1)) ?? '';
            return $digits !== '' ? '+'.$digits : '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function parseWhatsappNumbers(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [];
        $numbers = [];
        foreach ($values as $number) {
            $normalized = $this->normalizeWhatsappNumber((string) $number);
            if ($normalized !== '') {
                $numbers[] = $normalized;
            }
        }

        return array_values(array_unique($numbers));
    }

    private function parseSmsNumbers(mixed $raw): array
    {
        return $this->parseWhatsappNumbers($raw);
    }

    private function sendWhatsappText(string $tenantId, string $phone, string $message, array $context = []): array
    {
        $normalizedPhone = $this->normalizeWhatsappNumber($phone);
        if ($normalizedPhone === '') {
            return ['sent' => false, 'error' => 'número de WhatsApp inválido'];
        }
        $provider = strtolower(trim((string) env('NOTIFICATIONS_WHATSAPP_PROVIDER', 'brevo')));
        if ($provider === '') {
            $provider = 'brevo';
        }
        if ($provider === 'none') {
            return ['sent' => false, 'error' => 'proveedor WhatsApp deshabilitado'];
        }
        try {
            if ($provider === 'webhook') {
                $webhookUrl = trim((string) env('WHATSAPP_WEBHOOK_URL', ''));
                if ($webhookUrl === '') {
                    return ['sent' => false, 'error' => 'WHATSAPP_WEBHOOK_URL no configurado'];
                }
                $payload = array_merge($context, [
                    'tenant_id' => $tenantId,
                    'phone' => $normalizedPhone,
                    'message' => $message,
                ]);
                $res = $this->postWhatsappWebhook($webhookUrl, $payload);
                if ($res->failed()) {
                    return ['sent' => false, 'error' => 'webhook '.((string) $res->status()).' '.$res->body()];
                }

                return ['sent' => true, 'error' => ''];
            }

            $apiKey = trim((string) config('services.brevo.api_key', ''));
            if ($apiKey === '') {
                return ['sent' => false, 'error' => 'BREVO_API_KEY no configurado'];
            }
            $sender = trim((string) env('BREVO_WHATSAPP_SENDER', ''));
            if ($sender === '') {
                return ['sent' => false, 'error' => 'BREVO_WHATSAPP_SENDER no configurado'];
            }
            $url = trim((string) env('BREVO_WHATSAPP_API_URL', 'https://api.brevo.com/v3/whatsapp/sendMessage'));
            $response = Http::timeout(12)
                ->withHeaders([
                    'api-key' => $apiKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ])
                ->post($url, [
                    'senderNumber' => $sender,
                    'contactNumbers' => [$normalizedPhone],
                    'content' => [
                        'type' => 'text',
                        'text' => $message,
                    ],
                ]);
            if ($response instanceof \GuzzleHttp\Promise\PromiseInterface) {
                $resolved = $response->wait();
                if (! $resolved instanceof HttpClientResponse) {
                    return ['sent' => false, 'error' => 'Respuesta inválida de Brevo WhatsApp'];
                }
                $response = $resolved;
            }
            if ($response->failed()) {
                return ['sent' => false, 'error' => 'brevo '.((string) $response->status()).' '.$response->body()];
            }

            return ['sent' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['sent' => false, 'error' => trim((string) $e->getMessage())];
        }
    }

    private function sendSmsText(string $tenantId, string $phone, string $message, array $context = []): array
    {
        $normalizedPhone = $this->normalizeWhatsappNumber($phone);
        if ($normalizedPhone === '') {
            return ['sent' => false, 'error' => 'número SMS inválido'];
        }
        $provider = strtolower(trim((string) env('NOTIFICATIONS_SMS_PROVIDER', 'brevo')));
        if ($provider === '') {
            $provider = 'brevo';
        }
        if ($provider === 'none') {
            return ['sent' => false, 'error' => 'proveedor SMS deshabilitado'];
        }

        try {
            if ($provider === 'webhook') {
                $webhookUrl = trim((string) env('SMS_WEBHOOK_URL', ''));
                if ($webhookUrl === '') {
                    return ['sent' => false, 'error' => 'SMS_WEBHOOK_URL no configurado'];
                }
                $payload = array_merge($context, [
                    'tenant_id' => $tenantId,
                    'phone' => $normalizedPhone,
                    'message' => $message,
                ]);
                $res = Http::timeout(8)->post($webhookUrl, $payload);
                if ($res instanceof \GuzzleHttp\Promise\PromiseInterface) {
                    $resolved = $res->wait();
                    if (! $resolved instanceof HttpClientResponse) {
                        return ['sent' => false, 'error' => 'Respuesta inválida del webhook SMS'];
                    }
                    $res = $resolved;
                }
                if ($res->failed()) {
                    return ['sent' => false, 'error' => 'webhook '.((string) $res->status()).' '.$res->body()];
                }

                return ['sent' => true, 'error' => ''];
            }

            $apiKey = trim((string) config('services.brevo.api_key', ''));
            if ($apiKey === '') {
                return ['sent' => false, 'error' => 'BREVO_API_KEY no configurado'];
            }
            $sender = trim((string) env('BREVO_SMS_SENDER', ''));
            if ($sender === '') {
                return ['sent' => false, 'error' => 'BREVO_SMS_SENDER no configurado'];
            }
            $url = trim((string) env('BREVO_SMS_API_URL', 'https://api.brevo.com/v3/transactionalSMS/sms'));
            $response = Http::timeout(12)
                ->withHeaders([
                    'api-key' => $apiKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ])
                ->post($url, [
                    'sender' => $sender,
                    'recipient' => $normalizedPhone,
                    'content' => mb_substr($message, 0, 1200),
                    'type' => 'transactional',
                ]);
            if ($response instanceof \GuzzleHttp\Promise\PromiseInterface) {
                $resolved = $response->wait();
                if (! $resolved instanceof HttpClientResponse) {
                    return ['sent' => false, 'error' => 'Respuesta inválida de Brevo SMS'];
                }
                $response = $resolved;
            }
            if ($response->failed()) {
                return ['sent' => false, 'error' => 'brevo '.((string) $response->status()).' '.$response->body()];
            }

            return ['sent' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['sent' => false, 'error' => trim((string) $e->getMessage())];
        }
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
        $tenantNow = $this->tenantNow($tenantId);
        $activationRow = Schema::hasTable('activacion_del_plan_trs')
            ? DB::table('activacion_del_plan_trs')
                ->where('ac_de_pl-tenant_id', $tenantId)
                ->where('ac_de_pl-id', $activationId)
                ->first()
            : null;
        $activationPerId = trim((string) ($activationRow?->{'ac_de_pl-per_id-fk-activador'} ?? ''));
        $activationGroupId = null;
        if ($activationPerId !== '' && Schema::hasTable('persona_rol_grupo_cfg')) {
            $activationGroupId = DB::table('persona_rol_grupo_cfg')
                ->when(
                    Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                    static fn ($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
                )
                ->where('pe_ro_gr-per_id-fk', $activationPerId)
                ->orderByDesc('pe_ro_gr-id')
                ->value('pe_ro_gr-gr_op_id-fk');
        }

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
                'p.per-tel_mov as tel_mov',
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
                'tel_mov' => $this->normalizeWhatsappNumber((string) ($r->tel_mov ?? '')),
                'nombre' => $nombre !== '' ? $nombre : $perId,
            ];
            if (($byPerson[$perId]['email'] ?? null) === null && $email !== '') {
                $byPerson[$perId]['email'] = $email;
            }
            if (($byPerson[$perId]['tel_mov'] ?? null) === null) {
                $byPerson[$perId]['tel_mov'] = $this->normalizeWhatsappNumber((string) ($r->tel_mov ?? ''));
            }
            if (($byPerson[$perId]['nombre'] ?? '') === $perId && $nombre !== '') {
                $byPerson[$perId]['nombre'] = $nombre;
            }
        }

        $people = array_values($byPerson);
        if (empty($people)) {
            if (Schema::hasTable('cronologia_emergencia_trs')) {
                DB::table('cronologia_emergencia_trs')->insert([
                    'cr_em-id' => 'CREM-'.Str::uuid()->toString(),
                    'cr_em-tenant_id' => $tenantId,
                    'cr_em-ac_de_pl_id-fk' => $activationId,
                    'cr_em-tipo_emergencia' => 'FIN',
                    'cr_em-ts_emergencia' => $tenantNow->toDateTimeString(),
                    'cr_em-per_id-fk' => $activationPerId !== '' ? $activationPerId : null,
                    'cr_em-gr_op_id-fk' => $activationGroupId,
                    'cr_em-detalle' => 'Finalización del plan',
                    'cr_em-ref_tabla' => 'notificacion_envio_trs',
                    'cr_em-referencia' => null,
                ]);
            }
            return response()->json([
                'message' => 'OK',
                'mode' => $this->resolveNotificationMode(),
                'sent' => 0,
                'files_written' => 0,
            ]);
        }

        $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $channels = $this->resolveNotificationChannels($tenant);
        $emailNotificationsEnabled = (bool) ($channels['email_enabled'] ?? false);
        $whatsappNotificationsEnabled = (bool) ($channels['whatsapp_enabled'] ?? false);
        $includeCredentials = (bool) ($tenant?->notifications_include_credentials ?? false);
        $modoLabel = $productionMode ? 'PRODUCCION' : 'PRUEBA';
        $subjectPrefix = $productionMode ? '' : '[PRUEBA] ';
        $testEmails = [];
        $testWhatsappNumbers = [];
        $testSmsNumbers = [];
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
            $testWhatsappNumbers = $this->parseWhatsappNumbers($tenant?->test_notification_whatsapp_numbers);
            $testSmsNumbers = $this->parseSmsNumbers($tenant?->test_notification_sms_numbers);
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

        $mode = $this->resolveNotificationMode();
        $ts = now()->format('Ymd_His');
        $sent = 0;
        $filesWritten = 0;
        $whatsappSent = 0;
        $whatsappFilesWritten = 0;
        $smsSent = 0;
        $smsFilesWritten = 0;

        if ($mode === 'file') {
            $dir = 'notifications_outbox/'.$tenantId.'/'.$activationId;
            if (! Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->makeDirectory($dir);
            }
        }

        $label = $isSimulacro ? 'simulacro' : 'emergencia';
        $prefix = $isSimulacro ? '[SIMULACRO] ' : '';
        $subject = $subjectPrefix.$prefix.'Fin de '.$label.' — '.$activationId;
        $detalle = trim((string) ($validated['detalle'] ?? ''));

        $index = [
            'activation_id' => $activationId,
            'tenant_id' => $tenantId,
            'mode' => $mode,
            'generated_at' => $this->tenantNowDateTime($tenantId),
            'recipients' => [],
        ];

        foreach ($people as $p) {
            $rawTo = trim((string) ($p['email'] ?? ''));
            $to = $productionMode
                ? (filter_var($rawTo, FILTER_VALIDATE_EMAIL) ? strtolower($rawTo) : '')
                : implode(',', $testEmails);
            $emailSent = false;
            $emailError = '';
            $lines = [];
            $lines[] = 'ACTIVACION: '.$activationId;
            if (! $productionMode) {
                $lines[] = 'MODO: PRUEBA';
            }
            $lines[] = 'AVISO: Fin de '.$label;
            $lines[] = 'FECHA/HORA: '.$this->tenantNowDateTime($tenantId);
            $lines[] = 'PERSONA: '.(string) ($p['nombre'] ?? $p['per_id']);
            $lines[] = 'EMAIL: '.($to !== '' ? $to : '—');
            if ($detalle !== '') {
                $lines[] = '';
                $lines[] = 'DETALLE:';
                $lines[] = $detalle;
            }
            if ($includeCredentials) {
                $credentialsLines = $this->buildCredentialsLines($tenant, $to !== '' ? $to : null);
                if (! empty($credentialsLines)) {
                    $lines[] = '';
                    $lines[] = 'CREDENCIALES DE ACCESO:';
                    foreach ($credentialsLines as $line) {
                        $lines[] = $line;
                    }
                }
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
                    'generated_at' => $this->tenantNowDateTime($tenantId),
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
                if (! $emailNotificationsEnabled) {
                    $emailSent = false;
                } elseif ($productionMode) {
                    if ($to !== '') {
                        try {
                            Mail::raw($body, static function ($m) use ($to, $subject) {
                                $m->to($to)->subject($subject);
                            });
                            $sent++;
                            $emailSent = true;
                        } catch (\Throwable $mailErrorEx) {
                            $emailSent = false;
                            $emailError = trim((string) $mailErrorEx->getMessage());
                        }
                    }
                } elseif (! empty($testEmails)) {
                    Mail::raw($body, static function ($m) use ($testEmails, $subject) {
                        $m->to($testEmails)->subject($subject);
                    });
                    $sent++;
                }
            }

            if (Schema::hasTable('notificacion_envio_trs')) {
                $insert = [
                    'no_en-id' => 'NOEN-'.Str::uuid()->toString(),
                    'no_en-tenant_id' => $tenantId,
                    'no_en-ac_de_pl_id-fk' => $activationId,
                    'no_en-per_id-fk' => $p['per_id'],
                    'no_en-gr_op_id-fk' => null,
                    'no_en-rol_id-fk' => null,
                    'no_en-ca_co_id-fk' => null,
                    'no_en-mensaje' => $subject,
                    'no_en-ts' => $this->tenantNowDateTime($tenantId),
                    'no_en-estado' => $mode === 'file' ? 'SIMULADO' : ($emailSent ? 'ENVIADO' : 'SIMULADO'),
                    'no_en-num_de_intento' => '0',
                ];
                if ($mode !== 'file' && ! $emailSent) {
                    $extra = $emailError !== ''
                        ? '[email no enviado: '.$emailError.']'
                        : (! $emailNotificationsEnabled ? '[envío de correos desactivado en tenant]' : '[email destinatario no válido o ausente]');
                    $insert['no_en-mensaje'] = trim(($insert['no_en-mensaje'] ?? '').' '.$extra);
                }
                if (Schema::hasColumn('notificacion_envio_trs', 'no_en-modo')) {
                    $insert['no_en-modo'] = $modoLabel;
                }
                DB::table('notificacion_envio_trs')->insert($insert);
            }

            $index['recipients'][] = [
                'per_id' => (string) ($p['per_id'] ?? ''),
                'nombre' => (string) ($p['nombre'] ?? ''),
                'email' => $to !== '' ? $to : null,
            ];
        }

        $whatsappTargets = [];
        if ($productionMode) {
            foreach ($people as $p) {
                $phone = $this->normalizeWhatsappNumber((string) ($p['tel_mov'] ?? ''));
                if ($phone === '') {
                    continue;
                }
                $whatsappTargets[$phone] = $phone;
            }
        } else {
            foreach ($testWhatsappNumbers as $phone) {
                $normalized = $this->normalizeWhatsappNumber((string) $phone);
                if ($normalized === '') {
                    continue;
                }
                $whatsappTargets[$normalized] = $normalized;
            }
        }
        $whatsappMessage = $subject.($detalle !== '' ? "\n\n".$detalle : '');
        if ($includeCredentials) {
            $credentialsLines = $this->buildCredentialsLines($tenant, null);
            if (! empty($credentialsLines)) {
                $whatsappMessage = trim($whatsappMessage."\n\n".implode("\n", $credentialsLines));
            }
        }
        foreach (array_keys($whatsappTargets) as $phone) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'whatsapp';
                $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-end-wa-'.$safe.'.txt';
                Storage::disk('local')->put($path, $whatsappMessage."\n");
                $whatsappFilesWritten++;
                continue;
            }
            if (! $whatsappNotificationsEnabled) {
                continue;
            }
            $wa = $this->sendWhatsappText($tenantId, $phone, $whatsappMessage, [
                'activation_id' => $activationId,
                'type' => 'end',
            ]);
            if (($wa['sent'] ?? false) === true) {
                $whatsappSent++;
            }
        }
        $smsTargets = [];
        if ($productionMode) {
            foreach ($people as $p) {
                $phone = $this->normalizeWhatsappNumber((string) ($p['tel_mov'] ?? ''));
                if ($phone === '') {
                    continue;
                }
                $smsTargets[$phone] = $phone;
            }
        } else {
            foreach ($testSmsNumbers as $phone) {
                $normalized = $this->normalizeWhatsappNumber((string) $phone);
                if ($normalized === '') {
                    continue;
                }
                $smsTargets[$normalized] = $normalized;
            }
        }
        $smsMessage = $whatsappMessage;
        foreach (array_keys($smsTargets) as $phone) {
            if ($mode === 'file') {
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $phone) ?: 'sms';
                $path = 'notifications_outbox/'.$tenantId.'/'.$activationId.'/'.$ts.'-end-sms-'.$safe.'.txt';
                Storage::disk('local')->put($path, $smsMessage."\n");
                $smsFilesWritten++;
                continue;
            }
            if (! ((bool) ($channels['sms_enabled'] ?? false))) {
                continue;
            }
            $sms = $this->sendSmsText($tenantId, $phone, $smsMessage, [
                'activation_id' => $activationId,
                'type' => 'end',
            ]);
            if (($sms['sent'] ?? false) === true) {
                $smsSent++;
            }
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
                'cr_em-ts_emergencia' => $tenantNow->toDateTimeString(),
                'cr_em-per_id-fk' => $activationPerId !== '' ? $activationPerId : null,
                'cr_em-gr_op_id-fk' => $activationGroupId,
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
            'whatsapp_sent' => $whatsappSent,
            'whatsapp_files_written' => $whatsappFilesWritten,
            'sms_sent' => $smsSent,
            'sms_files_written' => $smsFilesWritten,
            'recipients' => count($people),
            'whatsapp_recipients' => count($whatsappTargets),
            'sms_recipients' => count($smsTargets),
        ]);
    }

    public function resetActivations(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $user = $request->user();
        $perfil = strtolower(trim((string) ($user?->perfil ?? '')));
        if ($perfil !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! Schema::hasTable('activacion_del_plan_trs')) {
            return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
        }

        $states = ['ACTIVA', 'ACTIVADA', 'FINALIZADA', 'FINALIZADO', 'CERRADA', 'CERRADO'];
        $activationIds = DB::table('activacion_del_plan_trs')
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->whereIn(DB::raw("UPPER(COALESCE(`ac_de_pl-estado`, ''))"), $states)
            ->pluck('ac_de_pl-id')
            ->map(static fn ($id) => trim((string) $id))
            ->filter(static fn ($id) => $id !== '')
            ->values();

        if ($activationIds->isEmpty()) {
            return response()->json([
                'message' => 'OK',
                'deleted' => [
                    'activaciones' => 0,
                ],
            ]);
        }

        $deleted = DB::transaction(function () use ($activationIds, $tenantId) {
            $counts = [
                'control_panel_access' => 0,
                'notificacion_confirmacion' => 0,
                'notificacion_envio' => 0,
                'notas_operativas' => 0,
                'ejecucion_accion' => 0,
                'asignacion_en_funciones' => 0,
                'activacion_nivel_hist' => 0,
                'cronologia_emergencia' => 0,
                'activaciones' => 0,
            ];

            if (Schema::hasTable('control_panel_access_trs')) {
                $counts['control_panel_access'] = DB::table('control_panel_access_trs')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('activation_id', $activationIds)
                    ->delete();
            }

            $notificacionEnvioIds = collect();
            if (Schema::hasTable('notificacion_envio_trs')) {
                $notificacionEnvioIds = DB::table('notificacion_envio_trs')
                    ->when(
                        Schema::hasColumn('notificacion_envio_trs', 'no_en-tenant_id'),
                        static fn ($q) => $q->where('no_en-tenant_id', $tenantId),
                    )
                    ->whereIn('no_en-ac_de_pl_id-fk', $activationIds)
                    ->pluck('no_en-id')
                    ->map(static fn ($id) => trim((string) $id))
                    ->filter(static fn ($id) => $id !== '')
                    ->values();

                $counts['notificacion_envio'] = DB::table('notificacion_envio_trs')
                    ->when(
                        Schema::hasColumn('notificacion_envio_trs', 'no_en-tenant_id'),
                        static fn ($q) => $q->where('no_en-tenant_id', $tenantId),
                    )
                    ->whereIn('no_en-ac_de_pl_id-fk', $activationIds)
                    ->delete();
            }

            if (Schema::hasTable('notificacion_confirmacion_trs')) {
                $counts['notificacion_confirmacion'] = DB::table('notificacion_confirmacion_trs')
                    ->when(
                        Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-tenant_id'),
                        static fn ($q) => $q->where('no_co-tenant_id', $tenantId),
                    )
                    ->when(
                        $notificacionEnvioIds->isNotEmpty(),
                        static fn ($q) => $q->whereIn('no_co-no_en_id-fk', $notificacionEnvioIds),
                        static fn ($q) => $q->whereRaw('1 = 0'),
                    )
                    ->delete();

                $counts['notificacion_confirmacion'] += DB::table('notificacion_confirmacion_trs')
                    ->when(
                        Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-tenant_id'),
                        static fn ($q) => $q->where('no_co-tenant_id', $tenantId),
                    )
                    ->where(function ($q): void {
                        $q->whereNull('no_co-no_en_id-fk')->orWhere('no_co-no_en_id-fk', '');
                    })
                    ->delete();
            }

            if (Schema::hasTable('notas_operativas_trs')) {
                $counts['notas_operativas'] = DB::table('notas_operativas_trs')
                    ->when(
                        Schema::hasColumn('notas_operativas_trs', 'no_op-tenant_id'),
                        static fn ($q) => $q->where('no_op-tenant_id', $tenantId),
                    )
                    ->whereIn('no_op-ac_de_pl_id-fk', $activationIds)
                    ->delete();
            }

            if (Schema::hasTable('ejecucion_accion_trs')) {
                $counts['ejecucion_accion'] = DB::table('ejecucion_accion_trs')
                    ->when(
                        Schema::hasColumn('ejecucion_accion_trs', 'ej_ac-tenant_id'),
                        static fn ($q) => $q->where('ej_ac-tenant_id', $tenantId),
                    )
                    ->whereIn('ej_ac-ac_de_pl_id-fk', $activationIds)
                    ->delete();
            }

            if (Schema::hasTable('asignacion_en_funciones_trs')) {
                $counts['asignacion_en_funciones'] = DB::table('asignacion_en_funciones_trs')
                    ->when(
                        Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-tenant_id'),
                        static fn ($q) => $q->where('as_en_fu-tenant_id', $tenantId),
                    )
                    ->whereIn('as_en_fu-ac_de_pl_id-fk', $activationIds)
                    ->delete();
            }

            if (Schema::hasTable('activacion_nivel_hist_trs')) {
                $counts['activacion_nivel_hist'] = DB::table('activacion_nivel_hist_trs')
                    ->when(
                        Schema::hasColumn('activacion_nivel_hist_trs', 'ac_ni_hi-tenant_id'),
                        static fn ($q) => $q->where('ac_ni_hi-tenant_id', $tenantId),
                    )
                    ->whereIn('ac_ni_hi-ac_de_pl_id-fk', $activationIds)
                    ->delete();
            }

            if (Schema::hasTable('cronologia_emergencia_trs')) {
                $counts['cronologia_emergencia'] = DB::table('cronologia_emergencia_trs')
                    ->when(
                        Schema::hasColumn('cronologia_emergencia_trs', 'cr_em-tenant_id'),
                        static fn ($q) => $q->where('cr_em-tenant_id', $tenantId),
                    )
                    ->whereIn('cr_em-ac_de_pl_id-fk', $activationIds)
                    ->delete();
            }

            $counts['activaciones'] = DB::table('activacion_del_plan_trs')
                ->when(
                    Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                    static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
                )
                ->whereIn('ac_de_pl-id', $activationIds)
                ->delete();

            return $counts;
        });

        foreach ($activationIds as $activationId) {
            $dir = 'activaciones/'.$tenantId.'/'.$activationId;
            if (Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->deleteDirectory($dir);
            }
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'activations_reset',
            'module' => 'activation',
            'new_value' => [
                'states' => $states,
                'activation_ids' => $activationIds->values()->all(),
                'deleted' => $deleted,
            ],
        ]);

        return response()->json([
            'message' => 'OK',
            'deleted' => $deleted,
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
                'de.ac_se_de-dependencia_id-fk as dependencia_id',
            ]);

        $dependencyIds = $rows->pluck('dependencia_id')->filter()->unique()->values()->all();
        $dependencyStatus = [];
        if (! empty($dependencyIds)) {
            $dependencyStatus = DB::table('ejecucion_accion_trs')
                ->where('ej_ac-tenant_id', $tenantId)
                ->where('ej_ac-ac_de_pl_id-fk', $activationId)
                ->whereIn('ej_ac-ac_se_de_id-fk', $dependencyIds)
                ->get(['ej_ac-ac_se_de_id-fk', 'ej_ac-estado', 'ej_ac-ts_fin'])
                ->mapWithKeys(function ($item) {
                    $st = strtoupper(trim((string) ($item->{'ej_ac-estado'} ?? '')));
                    $done = in_array($st, ['REALIZADA', 'REALIZADO', 'EJECUTADA', 'EJECUTADO'], true) || (string) ($item->{'ej_ac-ts_fin'} ?? '') !== '';
                    return [(string) ($item->{'ej_ac-ac_se_de_id-fk'} ?? '') => $done];
                })
                ->all();
        }

        $acciones = [];
        foreach ($rows as $r) {
            $accion = trim((string) ($r->accion_descrip ?? '')) ?: trim((string) ($r->accion_cod ?? '')) ?: trim((string) ($r->accion_detalle_id ?? ''));
            $tipo = strtoupper(trim((string) ($r->tipo_asignacion ?? 'SUPLENTE')));
            if ($tipo !== 'TITULAR') {
                $tipo = 'SUPLENTE';
            }
            $depId = trim((string) ($r->dependencia_id ?? ''));
            $depMet = $depId === '' || ($dependencyStatus[$depId] ?? false);

            $acciones[] = [
                'ejecucion_id' => (string) ($r->ejecucion_id ?? ''),
                'accion_detalle_id' => (string) ($r->accion_detalle_id ?? ''),
                'accion' => $accion,
                'tipo_asignacion' => $tipo,
                'estado' => (string) ($r->estado ?? ''),
                'dependencia_id' => $depId,
                'dependency_met' => $depMet,
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
            $buildNotifQuery = static function () use ($activationId, $perId) {
                return DB::table('notificacion_envio_trs')
                    ->where('no_en-ac_de_pl_id-fk', $activationId)
                    ->where('no_en-per_id-fk', $perId)
                    ->orderBy('no_en-ts', 'DESC')
                    ->orderBy('no_en-id', 'DESC');
            };

            $query = $buildNotifQuery();
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-tenant_id')) {
                $query->where('no_en-tenant_id', $tenantId);
            }
            $last = $query->first();

            // Fallback: en algunos históricos no_en-tenant_id no está poblado.
            if ($last === null) {
                $last = $buildNotifQuery()->first();
            }
            $noEnId = trim((string) ($last?->{'no_en-id'} ?? '')) ?: null;
        }

        $confirmationRequiresNotifId = Schema::hasTable('notificacion_confirmacion_trs')
            && Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-no_en_id-fk');
        if ($confirmationRequiresNotifId && $noEnId === null) {
            if (! Schema::hasTable('notificacion_envio_trs') || ! Schema::hasColumn('notificacion_envio_trs', 'no_en-id')) {
                return response()->json([
                    'message' => 'No se pudo vincular la confirmación con su notificación de envío.',
                    'code' => 'CONFIRMATION_NOTIF_LINK_MISSING',
                ], 422);
            }

            $syntheticNoEnId = 'NOEN-'.Str::uuid()->toString();
            $syntheticInsert = [];
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-id')) {
                $syntheticInsert['no_en-id'] = $syntheticNoEnId;
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-tenant_id')) {
                $syntheticInsert['no_en-tenant_id'] = $tenantId;
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-ac_de_pl_id-fk')) {
                $syntheticInsert['no_en-ac_de_pl_id-fk'] = $activationId;
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-per_id-fk')) {
                $syntheticInsert['no_en-per_id-fk'] = $perId;
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-gr_op_id-fk')) {
                $syntheticInsert['no_en-gr_op_id-fk'] = null;
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-rol_id-fk')) {
                $syntheticInsert['no_en-rol_id-fk'] = null;
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-ca_co_id-fk')) {
                $syntheticInsert['no_en-ca_co_id-fk'] = null;
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-mensaje')) {
                $syntheticInsert['no_en-mensaje'] = 'Registro técnico para vincular confirmación de disponibilidad';
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-ts')) {
                $syntheticInsert['no_en-ts'] = $this->tenantNowDateTime($tenantId);
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-estado')) {
                $syntheticInsert['no_en-estado'] = 'ENVIADO';
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-num_de_intento')) {
                $syntheticInsert['no_en-num_de_intento'] = '0';
            }
            if (Schema::hasColumn('notificacion_envio_trs', 'no_en-modo')) {
                $syntheticInsert['no_en-modo'] = 'EMAIL';
            }

            DB::table('notificacion_envio_trs')->insert($syntheticInsert);
            $noEnId = $syntheticNoEnId;
        }

        $insertedConfirmation = false;
        if (Schema::hasTable('notificacion_confirmacion_trs')) {
            $payload = [];
            if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-id')) {
                $payload['no_co-id'] = 'NOCO-'.Str::uuid()->toString();
            }
            if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-tenant_id')) {
                $payload['no_co-tenant_id'] = $tenantId;
            }
            if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-no_en_id-fk')) {
                $payload['no_co-no_en_id-fk'] = $noEnId;
            }
            if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-confirmado')) {
                $payload['no_co-confirmado'] = 'SI';
            }
            if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-ts')) {
                $payload['no_co-ts'] = $this->tenantNowDateTime($tenantId);
            }
            if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-respuesta')) {
                $payload['no_co-respuesta'] = $validated['respuesta'] ?? null;
            }

            if (! empty($payload)) {
                DB::table('notificacion_confirmacion_trs')->insert($payload);
                $insertedConfirmation = true;
            }
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'action_status_changed',
            'module' => 'actions',
            'plan_id' => $activationId,
            'entity_type' => 'notificacion_confirmacion_trs',
            'entity_id' => $noEnId,
            'previous_value' => [
                'confirmado' => 'NO',
            ],
            'new_value' => [
                'confirmado' => 'SI',
                'respuesta' => $validated['respuesta'] ?? null,
                'acciones_actualizadas' => (int) $updated,
                'persona_id' => $perId,
            ],
            'justification' => 'Confirmación de disponibilidad desde Pantalla 2',
        ]);

        return response()->json([
            'message' => 'OK',
            'updated' => $updated,
            'confirmation_inserted' => $insertedConfirmation,
            'confirmation_notif_id' => $noEnId,
        ]);
    }

    public function logAutoDelegation(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $validated = $request->validate([
            'accion_detalle_id' => ['required', 'string'],
            'grupo_id' => ['required', 'string'],
            'titular_prev_per_id' => ['nullable', 'string'],
            'suplente_new_per_id' => ['required', 'string'],
            'asignacion_id' => ['nullable', 'string'],
            'asignacion_prev_id' => ['nullable', 'string'],
            'motivo' => ['nullable', 'string'],
        ]);

        $accionDetalleId = trim((string) ($validated['accion_detalle_id'] ?? ''));
        $grupoId = trim((string) ($validated['grupo_id'] ?? ''));
        $titularPrev = trim((string) ($validated['titular_prev_per_id'] ?? ''));
        $suplenteNew = trim((string) ($validated['suplente_new_per_id'] ?? ''));
        $asignacionId = trim((string) ($validated['asignacion_id'] ?? ''));
        $asignacionPrevId = trim((string) ($validated['asignacion_prev_id'] ?? ''));
        $motivo = trim((string) ($validated['motivo'] ?? ''));

        if ($accionDetalleId === '' || $grupoId === '' || $suplenteNew === '') {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        $accionDetalleIds = array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            explode(',', $accionDetalleId),
        ), static fn ($v) => $v !== ''));

        $accionDetalles = collect();
        if (! empty($accionDetalleIds) && Schema::hasTable('accion_set_detalle_cfg')) {
            $detalleQuery = DB::table('accion_set_detalle_cfg')
                ->whereIn('ac_se_de-id', $accionDetalleIds);
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id')) {
                $detalleQuery->where('ac_se_de-tenant_id', $tenantId);
            }
            $accionDetalles = $detalleQuery->get(['ac_se_de-id', 'ac_se_de-detalle', 'ac_se_de-rol_id-fk']);
        }

        $roleIds = [];
        $actionNames = [];
        foreach ($accionDetalles as $detalleRow) {
            $detailRoleId = trim((string) ($detalleRow->{'ac_se_de-rol_id-fk'} ?? ''));
            if ($detailRoleId !== '') {
                $roleIds[] = $detailRoleId;
            }
            $detailName = trim((string) ($detalleRow->{'ac_se_de-detalle'} ?? ''));
            if ($detailName !== '') {
                $actionNames[] = $detailName;
            }
        }
        $roleIds = array_values(array_unique($roleIds));
        $actionNames = array_values(array_unique($actionNames));

        $roleNamesById = [];
        if (! empty($roleIds) && Schema::hasTable('rol_cat')) {
            $roleQuery = DB::table('rol_cat')->whereIn('rol-id', $roleIds);
            if (Schema::hasColumn('rol_cat', 'rol-tenant_id')) {
                $roleQuery->where('rol-tenant_id', $tenantId);
            }
            foreach ($roleQuery->get(['rol-id', 'rol-nombre']) as $roleRow) {
                $rid = trim((string) ($roleRow->{'rol-id'} ?? ''));
                if ($rid !== '') {
                    $roleNamesById[$rid] = trim((string) ($roleRow->{'rol-nombre'} ?? ''));
                }
            }
        }

        $suplenteNewNombre = null;
        if (Schema::hasTable('persona_mst')) {
            $personaNew = DB::table('persona_mst')
                ->where('per-id', $suplenteNew)
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->where('per-tenant_id', $tenantId),
                )
                ->first(['per-nombre', 'per-apellido_1', 'per-apellido_2']);
            if ($personaNew) {
                $suplenteNewNombre = trim(implode(' ', array_filter([
                    (string) ($personaNew->{'per-nombre'} ?? ''),
                    (string) ($personaNew->{'per-apellido_1'} ?? ''),
                    (string) ($personaNew->{'per-apellido_2'} ?? ''),
                ])));
            }
        }

        $this->auditLogger->log([
            'tenant_id' => $tenantId,
            'user_id' => null,
            'ip_origin' => $request->ip(),
            'event_type' => 'delegation_auto',
            'module' => 'delegations',
            'plan_id' => $activationId,
            'entity_id' => $asignacionId !== '' ? $asignacionId : null,
            'entity_type' => 'asignacion_en_funciones_trs',
            'previous_value' => [
                'accion_detalle_id' => $accionDetalleId,
                'grupo_id' => $grupoId,
                'titular_prev_per_id' => $titularPrev !== '' ? $titularPrev : null,
                'asignacion_prev_id' => $asignacionPrevId !== '' ? $asignacionPrevId : null,
            ],
            'new_value' => [
                'suplente_new_per_id' => $suplenteNew,
                'suplente_new_nombre' => $suplenteNewNombre,
                'asignacion_id' => $asignacionId !== '' ? $asignacionId : null,
                'accion_detalle_ids' => ! empty($accionDetalleIds) ? $accionDetalleIds : null,
                'accion_detalle_nombre' => ! empty($actionNames) ? implode(', ', $actionNames) : null,
                'rol_id' => $roleIds[0] ?? null,
                'rol_nombre' => isset($roleIds[0]) ? ($roleNamesById[$roleIds[0]] ?? $roleIds[0]) : null,
                'actor' => 'APP',
                'tipo_delegacion' => 'AUTO',
                'motivo' => $motivo !== '' ? $motivo : 'Vencimiento tiempo conformación',
            ],
            'justification' => 'Autodelegación por vencimiento de tiempo de conformación',
        ]);

        return response()->json(['message' => 'OK']);
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

        $isAviso = $niAlCod !== '' && (str_contains($niAlNombre, 'AVISO') || $niAlCod === 'AVISO' || $niAlCod === 'AV' || str_starts_with($niAlCod, 'AV'));
        $isPrealerta = str_starts_with($niAlCod, 'P') || str_contains($niAlNombre, 'PREALERTA');

        if ($isPrealerta && $isAviso) {
            $isAviso = false;
        }

        $scenario = 'NORMALIDAD';
        if ($niEmActivaPlan === 'SI') {
            $scenario = 'ACTIVACION';
        } elseif ($isPrealerta) {
            $scenario = 'PREALERTA';
        } elseif ($isAviso) {
            $scenario = 'AVISO';
        }

        $actionSetIds = [];
        if ($nivelAlertaIdResolved !== '') {
            $actionSetIds = $this->getActionSets($tenantId, $riesgoId, $nivelAlertaIdResolved);
        }

        $actionSetIds = array_values(array_unique(array_filter($actionSetIds, static fn ($v) => is_string($v) && trim($v) !== '')));
        if ($scenario === 'AVISO') {
            $actionSetIds = [];
        }
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
                $groupIds = array_keys($destinatariosByGrupo);
                $hasMultipleGroups = count($groupIds) > 1;
                $leaderCandidates = [];
                foreach ($destinatariosByGrupo as $grId => $items) {
                    foreach ($items as $d) {
                        $tipo = strtoupper(trim((string) ($d->{'pe_ro_gr-tipo_asignacion'} ?? '')));
                        if ($tipo !== 'LIDER') {
                            continue;
                        }
                        $leaderCandidates[] = [
                            'group_id' => $grId,
                            'order' => (int) trim((string) ($d->{'pe_ro_gr-orden_sust'} ?? '999')),
                            'row_id' => (string) ($d->{'pe_ro_gr-id'} ?? ''),
                        ];
                    }
                }
                $selectedLeaderGroupId = null;
                if ($hasMultipleGroups && ! empty($leaderCandidates)) {
                    usort($leaderCandidates, static function ($a, $b) {
                        $ao = (int) ($a['order'] ?? 999);
                        $bo = (int) ($b['order'] ?? 999);
                        if ($ao !== $bo) {
                            return $ao <=> $bo;
                        }
                        return strcmp((string) ($a['row_id'] ?? ''), (string) ($b['row_id'] ?? ''));
                    });
                    $selectedLeaderGroupId = (string) ($leaderCandidates[0]['group_id'] ?? '');
                }
                $groupsToProcess = $destinatariosByGrupo;
                if ($hasMultipleGroups && $selectedLeaderGroupId !== null && array_key_exists($selectedLeaderGroupId, $destinatariosByGrupo)) {
                    $groupsToProcess = [$selectedLeaderGroupId => $destinatariosByGrupo[$selectedLeaderGroupId]];
                }

                foreach ($groupsToProcess as $grId => $items) {
                    $titular = null;
                    $suplentes = [];
                    $allowLeaderAsTitular = $hasMultipleGroups && $selectedLeaderGroupId !== null && $grId === $selectedLeaderGroupId;

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
                        if ($tipo !== '' && $tipo !== 'TITULAR' && $tipo !== 'SUPLENTE' && $tipo !== 'LIDER') {
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

                        if (($tipo === 'TITULAR' || ($allowLeaderAsTitular && $tipo === 'LIDER')) && $titular === null) {
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

    public function changeLevel(Request $request, string $activationId): JsonResponse
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
            'ni_al_id' => ['required', 'string'],
            'per_id' => ['nullable', 'string'],
            'rol_id' => ['nullable', 'string'],
            'justificacion' => ['nullable', 'string'],
        ]);

        $newLevelId = trim($validated['ni_al_id']);
        $perId = trim((string) ($validated['per_id'] ?? '')) ?: null;
        $rolId = trim((string) ($validated['rol_id'] ?? '')) ?: null;
        $justification = trim((string) ($validated['justificacion'] ?? '')) ?: null;

        if (! Schema::hasTable('activacion_del_plan_trs')) {
            return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
        }

        return DB::transaction(function () use ($tenantId, $activationId, $newLevelId, $perId, $rolId, $justification, $request) {
            $tenantNow = $this->tenantNow($tenantId);
            $currentDate = $tenantNow->toDateString();
            $currentTime = $tenantNow->toTimeString();
            $currentTs = $tenantNow->toDateTimeString();
            $activation = DB::table('activacion_del_plan_trs')
                ->where('ac_de_pl-tenant_id', $tenantId)
                ->where('ac_de_pl-id', $activationId)
                ->first();

            if (! $activation) {
                return response()->json(['message' => 'Activation not found.'], 404);
            }

            $riesgoId = trim((string) ($activation->{'ac_de_pl-rie_id-fk'} ?? ''));
            $previousLevel = null;
            if (Schema::hasTable('activacion_nivel_hist_trs')) {
                $previousLevel = DB::table('activacion_nivel_hist_trs')
                    ->when(
                        Schema::hasColumn('activacion_nivel_hist_trs', 'ac_ni_hi-tenant_id'),
                        static fn ($q) => $q->where('ac_ni_hi-tenant_id', $tenantId),
                    )
                    ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId)
                    ->whereRaw("UPPER(COALESCE(`ac_ni_hi-activo`, 'SI')) <> 'NO'")
                    ->orderByDesc('ac_ni_hi-fech_ini')
                    ->orderByDesc('ac_ni_hi-hora_ini')
                    ->first();
            }

            $niAl = null;
            if (Schema::hasTable('nivel_alerta_cat')) {
                $niAl = DB::table('nivel_alerta_cat')
                    ->where('ni_al-id', $newLevelId)
                    ->when(
                        Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                        static fn ($q) => $q->where('ni_al-tenant_id', $tenantId),
                    )
                    ->first();
            }

            if (! $niAl) {
                return response()->json(['message' => 'Level not found.'], 422);
            }

            $actionSetIds = $this->getActionSets($tenantId, $riesgoId, $newLevelId);

            $actionSetIds = array_values(array_unique(array_filter($actionSetIds, static fn ($v) => is_string($v) && trim($v) !== '')));
            $actionSetId = $actionSetIds[0] ?? null;

            if (Schema::hasTable('activacion_nivel_hist_trs')) {
                DB::table('activacion_nivel_hist_trs')
                    ->where('ac_ni_hi-tenant_id', $tenantId)
                    ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId)
                    ->update(['ac_ni_hi-activo' => 'NO']);

                DB::table('activacion_nivel_hist_trs')->insert([
                    'ac_ni_hi-id' => 'ACNI-'.Str::uuid()->toString(),
                    'ac_ni_hi-tenant_id' => $tenantId,
                    'ac_ni_hi-ac_de_pl_id-fk' => $activationId,
                    'ac_ni_hi-ni_al_id-fk' => $newLevelId,
                    'ac_ni_hi-ac_se_id-fk' => $actionSetId,
                    'ac_ni_hi-fech_ini' => $currentDate,
                    'ac_ni_hi-hora_ini' => $currentTime,
                    'ac_ni_hi-fech_fin' => null,
                    'ac_ni_hi-hora_fin' => null,
                    'ac_ni_hi-nivel_inicial' => 'NO',
                    'ac_ni_hi-per_id-fk-registrador' => $perId,
                    'ac_ni_hi-rol_id-fk-registrador' => $rolId,
                    'ac_ni_hi-fuente_cambio' => 'manual',
                    'ac_ni_hi-activo' => 'SI',
                    'ac_ni_hi-justificacion' => $justification,
                ]);
            }

            $createdCount = 0;
            if ($actionSetId && Schema::hasTable('accion_set_detalle_cfg')) {
                $personaRolGrupoByRol = [];
                $asignacionByKey = [];
                $detalles = DB::table('accion_set_detalle_cfg')
                    ->when(
                        Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                        static fn ($q) => $q->where('ac_se_de-tenant_id', $tenantId),
                    )
                    ->where('ac_se_de-ac_se_id-fk', $actionSetId)
                    ->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'")
                    ->orderByRaw("CAST(COALESCE(`ac_se_de-ord_ejec`, '999') AS UNSIGNED) ASC")
                    ->orderBy('ac_se_de-id')
                    ->get();

                foreach ($detalles as $detalle) {
                    $detalleId = trim((string) ($detalle->{'ac_se_de-id'} ?? ''));
                    if ($detalleId === '') {
                        continue;
                    }
                    $rolIdStr = trim((string) ($detalle->{'ac_se_de-rol_id-fk'} ?? ''));
                    $grOpId = trim((string) ($detalle->{'ac_se_de-gr_op_id-fk'} ?? ''));
                    $resolvedGroupId = $grOpId !== '' ? $grOpId : null;
                    $defaultPerId = null;
                    $defaultTipoAsignacion = 'TITULAR';

                    if ($rolIdStr !== '' && Schema::hasTable('persona_rol_grupo_cfg')) {
                        if (! array_key_exists($rolIdStr, $personaRolGrupoByRol)) {
                            $query = DB::table('persona_rol_grupo_cfg')
                                ->when(
                                    Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                                    static fn ($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
                                )
                                ->where('pe_ro_gr-rol_id-fk', $rolIdStr);
                            if (Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-activo')) {
                                $query->whereRaw("UPPER(COALESCE(`pe_ro_gr-activo`, 'SI')) <> 'NO'");
                            }
                            if (Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-fech_fin')) {
                                $query->whereNull('pe_ro_gr-fech_fin');
                            }
                            $personaRolGrupoByRol[$rolIdStr] = $query->get();
                        }
                        $candidates = [];
                        foreach ($personaRolGrupoByRol[$rolIdStr] as $row) {
                            $perId = trim((string) ($row->{'pe_ro_gr-per_id-fk'} ?? ''));
                            if ($perId === '') {
                                continue;
                            }
                            $candidateGroupId = trim((string) ($row->{'pe_ro_gr-gr_op_id-fk'} ?? ''));
                            if ($resolvedGroupId !== null && $candidateGroupId !== '' && $candidateGroupId !== $resolvedGroupId) {
                                continue;
                            }
                            $tipo = strtoupper(trim((string) ($row->{'pe_ro_gr-tipo_asignacion'} ?? 'SUPLENTE')));
                            if ($tipo === 'LIDER') {
                                $tipo = 'TITULAR';
                            }
                            if ($tipo !== 'TITULAR' && $tipo !== 'SUPLENTE') {
                                continue;
                            }
                            $candidates[] = [
                                'per_id' => $perId,
                                'gr_op_id' => $candidateGroupId !== '' ? $candidateGroupId : null,
                                'tipo_asignacion' => $tipo,
                                'rank' => $tipo === 'TITULAR' ? 0 : 1,
                                'order' => (int) trim((string) ($row->{'pe_ro_gr-orden_sust'} ?? '999')),
                                'row_id' => trim((string) ($row->{'pe_ro_gr-id'} ?? '')),
                            ];
                        }
                        usort($candidates, static function (array $a, array $b): int {
                            $ar = (int) ($a['rank'] ?? 99);
                            $br = (int) ($b['rank'] ?? 99);
                            if ($ar !== $br) {
                                return $ar <=> $br;
                            }
                            $ao = (int) ($a['order'] ?? 999);
                            $bo = (int) ($b['order'] ?? 999);
                            if ($ao !== $bo) {
                                return $ao <=> $bo;
                            }

                            return strcmp((string) ($a['row_id'] ?? ''), (string) ($b['row_id'] ?? ''));
                        });
                        $selected = $candidates[0] ?? null;
                        if ($selected !== null) {
                            $defaultPerId = (string) ($selected['per_id'] ?? '');
                            $defaultTipoAsignacion = strtoupper(trim((string) ($selected['tipo_asignacion'] ?? 'TITULAR')));
                            $selectedGroupId = trim((string) ($selected['gr_op_id'] ?? ''));
                            if ($resolvedGroupId === null && $selectedGroupId !== '') {
                                $resolvedGroupId = $selectedGroupId;
                            }
                        }
                    }

                    $asignacionId = null;
                    if (Schema::hasTable('asignacion_en_funciones_trs')) {
                        $assignmentKey = trim((string) $defaultPerId).'|'.trim((string) ($resolvedGroupId ?? '')).'|'.trim((string) $defaultTipoAsignacion);
                        if ($defaultPerId !== null && $defaultPerId !== '' && isset($asignacionByKey[$assignmentKey])) {
                            $asignacionId = $asignacionByKey[$assignmentKey];
                        }
                        if ($asignacionId === null) {
                            $existingAsign = DB::table('asignacion_en_funciones_trs')
                                ->when(
                                    Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-tenant_id'),
                                    static fn ($q) => $q->where('as_en_fu-tenant_id', $tenantId),
                                )
                                ->where('as_en_fu-ac_de_pl_id-fk', $activationId)
                                ->when($resolvedGroupId !== null, static fn ($q) => $q->where('as_en_fu-gr_op_id-fk', $resolvedGroupId))
                                ->when($defaultPerId !== null && $defaultPerId !== '', static fn ($q) => $q->where('as_en_fu-per_id-fk', $defaultPerId))
                                ->when($defaultTipoAsignacion !== '', static fn ($q) => $q->where('as_en_fu-tipo_asignacion', $defaultTipoAsignacion))
                                ->orderBy('as_en_fu-ts_ini', 'desc')
                                ->first();

                            if ($existingAsign) {
                                $asignacionId = $existingAsign->{'as_en_fu-id'};
                            } else {
                                $asignacionId = 'ASEF-'.Str::uuid()->toString();
                                DB::table('asignacion_en_funciones_trs')->insert([
                                    'as_en_fu-id' => $asignacionId,
                                    'as_en_fu-tenant_id' => $tenantId,
                                    'as_en_fu-ac_de_pl_id-fk' => $activationId,
                                    'as_en_fu-gr_op_id-fk' => $resolvedGroupId,
                                    'as_en_fu-per_id-fk' => $defaultPerId,
                                    'as_en_fu-tipo_asignacion' => $defaultTipoAsignacion !== '' ? $defaultTipoAsignacion : 'TITULAR',
                                    'as_en_fu-motivo' => 'GENERACION_AUTOMATICA_CAMBIO_NIVEL',
                                    'as_en_fu-ts_ini' => $currentTs,
                                    'as_en_fu-estado' => 'ACTIVA',
                                ]);
                            }
                            if ($defaultPerId !== null && $defaultPerId !== '') {
                                $asignacionByKey[$assignmentKey] = $asignacionId;
                            }
                        }
                    }

                    if (Schema::hasTable('ejecucion_accion_trs')) {
                        DB::table('ejecucion_accion_trs')->insert([
                            'ej_ac-id' => 'EJAC-'.Str::uuid()->toString(),
                            'ej_ac-tenant_id' => $tenantId,
                            'ej_ac-ac_de_pl_id-fk' => $activationId,
                            'ej_ac-gr_op_id-fk' => $resolvedGroupId,
                            'ej_ac-ac_se_de_id-fk' => $detalleId,
                            'ej_ac-as_en_fu_id-fk' => $asignacionId,
                            'ej_ac-estado' => 'PENDIENTE',
                            'ej_ac-ts_ini' => $currentTs,
                            'ej_ac-observ' => 'Regenerado por cambio de nivel',
                        ]);
                        $createdCount++;
                    }
                }
            }

            $this->auditLogger->logFromRequest($request, [
                'event_type' => 'level_changed',
                'module' => 'activation',
                'plan_id' => $activationId,
                'entity_id' => $activationId,
                'entity_type' => 'activacion_nivel_hist_trs',
                'previous_value' => [
                    'rie_id' => $riesgoId !== '' ? $riesgoId : null,
                    'ni_al_id' => $previousLevel?->{'ac_ni_hi-ni_al_id-fk'} ?? null,
                    'ac_se_id' => $previousLevel?->{'ac_ni_hi-ac_se_id-fk'} ?? null,
                ],
                'new_value' => [
                    'rie_id' => $riesgoId !== '' ? $riesgoId : null,
                    'ni_al_id' => $newLevelId,
                    'ac_se_id' => $actionSetId,
                ],
                'justification' => $justification,
            ]);

            return response()->json([
                'message' => 'Level changed successfully.',
                'new_level_id' => $newLevelId,
                'actions_created' => $createdCount,
            ]);
        });
    }

    public function finalizeActivation(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        if (! Schema::hasTable('activacion_del_plan_trs')) {
            return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
        }

        return DB::transaction(function () use ($tenantId, $activationId, $request) {
            $activation = DB::table('activacion_del_plan_trs')
                ->where('ac_de_pl-tenant_id', $tenantId)
                ->where('ac_de_pl-id', $activationId)
                ->first();
            if (! $activation) {
                return response()->json(['message' => 'Activation not found.'], 404);
            }

            $tenantNow = $this->tenantNow($tenantId);
            $currentDate = $tenantNow->toDateString();
            $currentTime = $tenantNow->toTimeString();

            $closedLevels = 0;
            if (Schema::hasTable('activacion_nivel_hist_trs')) {
                $closedLevels = DB::table('activacion_nivel_hist_trs')
                    ->where('ac_ni_hi-tenant_id', $tenantId)
                    ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId)
                    ->where(function ($q): void {
                        $q->whereRaw("UPPER(COALESCE(`ac_ni_hi-activo`, 'SI')) <> 'NO'")
                            ->orWhereNull('ac_ni_hi-fech_fin')
                            ->orWhereNull('ac_ni_hi-hora_fin')
                            ->orWhere('ac_ni_hi-fech_fin', '')
                            ->orWhere('ac_ni_hi-hora_fin', '');
                    })
                    ->update([
                        'ac_ni_hi-fech_fin' => $currentDate,
                        'ac_ni_hi-hora_fin' => $currentTime,
                        'ac_ni_hi-activo' => 'NO',
                    ]);
            }

            DB::table('activacion_del_plan_trs')
                ->where('ac_de_pl-tenant_id', $tenantId)
                ->where('ac_de_pl-id', $activationId)
                ->update([
                    'ac_de_pl-estado' => 'FINALIZADA',
                ]);

            $this->auditLogger->logFromRequest($request, [
                'event_type' => 'plan_status_changed',
                'module' => 'activation',
                'plan_id' => $activationId,
                'entity_id' => $activationId,
                'entity_type' => 'activacion_del_plan_trs',
                'previous_value' => [
                    'estado' => $activation->{'ac_de_pl-estado'} ?? null,
                ],
                'new_value' => [
                    'estado' => 'FINALIZADA',
                ],
                'justification' => 'Finalización del plan',
            ]);

            return response()->json([
                'message' => 'Activation finalized.',
                'closed_levels' => (int) $closedLevels,
                'finalized_at' => $tenantNow->toDateTimeString(),
            ]);
        });
    }

    public function auditActions(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        if (! Schema::hasTable('activacion_del_plan_trs')) {
            return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
        }

        $activationExists = DB::table('activacion_del_plan_trs')
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->where('ac_de_pl-id', $activationId)
            ->exists();
        if (! $activationExists) {
            return response()->json(['message' => 'Activation not found.'], 404);
        }

        $activationRow = DB::table('activacion_del_plan_trs')
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->where('ac_de_pl-id', $activationId)
            ->first(['ac_de_pl-rie_id-fk', 'ac_de_pl-ni_al_id-fk-inicial']);
        $riesgoId = trim((string) ($activationRow?->{'ac_de_pl-rie_id-fk'} ?? ''));

        $normalizeActionStatus = static function (?object $ejec): string {
            $estado = strtoupper(trim((string) ($ejec?->{'ej_ac-estado'} ?? '')));
            $tsFin = trim((string) ($ejec?->{'ej_ac-ts_fin'} ?? ''));
            if (in_array($estado, ['REALIZADA', 'REALIZADO', 'EJECUTADA', 'EJECUTADO'], true) || $tsFin !== '') {
                return 'REALIZADA';
            }
            if (in_array($estado, ['INICIADA', 'INICIADO', 'EN_CURSO', 'EN CURSO', 'EN_PROGRESO', 'EN PROGRESO'], true)) {
                return 'EN_CURSO';
            }
            if (in_array($estado, ['CONFIRMADO', 'CONFIRMADA', 'NO_INICIADA', 'NO INICIADO'], true)) {
                return 'NO_INICIADA';
            }

            return 'PENDIENTE';
        };

        $asignacionById = [];
        if (Schema::hasTable('asignacion_en_funciones_trs')) {
            $asignacionColumns = [
                'as_en_fu-id',
                'as_en_fu-gr_op_id-fk',
                'as_en_fu-per_id-fk',
                'as_en_fu-tipo_asignacion',
                'as_en_fu-ts_ini',
                'as_en_fu-ts_fin',
                'as_en_fu-estado',
            ];
            if (Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-per_id-fk-delegador')) {
                $asignacionColumns[] = 'as_en_fu-per_id-fk-delegador';
            }
            $asignaciones = DB::table('asignacion_en_funciones_trs')
                ->when(
                    Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-tenant_id'),
                    static fn ($q) => $q->where('as_en_fu-tenant_id', $tenantId),
                )
                ->where('as_en_fu-ac_de_pl_id-fk', $activationId)
                ->get($asignacionColumns);
            foreach ($asignaciones as $a) {
                $id = trim((string) ($a->{'as_en_fu-id'} ?? ''));
                if ($id === '') {
                    continue;
                }
                $asignacionById[$id] = $a;
            }
        }

        $ejecRows = [];
        if (Schema::hasTable('ejecucion_accion_trs')) {
            $ejecRows = DB::table('ejecucion_accion_trs')
                ->when(
                    Schema::hasColumn('ejecucion_accion_trs', 'ej_ac-tenant_id'),
                    static fn ($q) => $q->where('ej_ac-tenant_id', $tenantId),
                )
                ->where('ej_ac-ac_de_pl_id-fk', $activationId)
                ->get([
                    'ej_ac-id',
                    'ej_ac-gr_op_id-fk',
                    'ej_ac-ac_se_de_id-fk',
                    'ej_ac-as_en_fu_id-fk',
                    'ej_ac-estado',
                    'ej_ac-ts_ini',
                    'ej_ac-ts_fin',
                ])
                ->all();
        }

        $nivelRows = [];
        if (Schema::hasTable('activacion_nivel_hist_trs')) {
            $hasNivelOrden = Schema::hasColumn('activacion_nivel_hist_trs', 'ac_ni_hi-orden');
            $nivelQuery = DB::table('activacion_nivel_hist_trs')
                ->when(
                    Schema::hasColumn('activacion_nivel_hist_trs', 'ac_ni_hi-tenant_id'),
                    static fn ($q) => $q->where('ac_ni_hi-tenant_id', $tenantId),
                )
                ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId);
            if ($hasNivelOrden) {
                $nivelQuery->orderByRaw("CAST(COALESCE(`ac_ni_hi-orden`, '0') AS UNSIGNED) ASC");
            } else {
                $nivelQuery->orderBy('ac_ni_hi-ni_al_id-fk', 'asc');
            }
            $nivelSelect = ['ac_ni_hi-ni_al_id-fk', 'ac_ni_hi-ac_se_id-fk'];
            if ($hasNivelOrden) {
                $nivelSelect[] = 'ac_ni_hi-orden';
            }
            $nivelRows = $nivelQuery->get($nivelSelect)->all();
        }
        $nivelIds = [];
        $nivelIdsOrdered = [];
        foreach ($nivelRows as $n) {
            $nid = trim((string) ($n->{'ac_ni_hi-ni_al_id-fk'} ?? ''));
            if ($nid !== '' && ! isset($nivelIds[$nid])) {
                $nivelIds[$nid] = true;
                $nivelIdsOrdered[] = $nid;
            }
        }
        $nivelInicialId = trim((string) ($activationRow?->{'ac_de_pl-ni_al_id-fk-inicial'} ?? ''));
        if ($nivelInicialId !== '' && ! isset($nivelIds[$nivelInicialId])) {
            $nivelIds[$nivelInicialId] = true;
            $nivelIdsOrdered[] = $nivelInicialId;
        }

        $actionSetIds = [];
        foreach ($nivelRows as $n) {
            $setId = trim((string) ($n->{'ac_ni_hi-ac_se_id-fk'} ?? ''));
            $nid = trim((string) ($n->{'ac_ni_hi-ni_al_id-fk'} ?? ''));
            if ($setId !== '') {
                $actionSetIds[$setId] = true;
                continue;
            }
            if ($riesgoId !== '' && $nid !== '') {
                foreach ($this->getActionSets($tenantId, $riesgoId, $nid) as $candidateSetId) {
                    $candidateSetId = trim((string) $candidateSetId);
                    if ($candidateSetId !== '') {
                        $actionSetIds[$candidateSetId] = true;
                    }
                }
            }
        }

        if ($riesgoId !== '') {
            foreach ($nivelIdsOrdered as $nivelIdCandidate) {
                foreach ($this->getActionSets($tenantId, $riesgoId, $nivelIdCandidate) as $candidateSetId) {
                    $candidateSetId = trim((string) $candidateSetId);
                    if ($candidateSetId !== '') {
                        $actionSetIds[$candidateSetId] = true;
                    }
                }
            }
        }

        $latestEjecByKey = [];
        $detalleIds = [];
        foreach ($ejecRows as $row) {
            $detalleId = trim((string) ($row->{'ej_ac-ac_se_de_id-fk'} ?? ''));
            if ($detalleId === '') {
                continue;
            }
            $detalleIds[$detalleId] = true;
            $gid = trim((string) ($row->{'ej_ac-gr_op_id-fk'} ?? ''));
            if ($gid === '') {
                $asignacionId = trim((string) ($row->{'ej_ac-as_en_fu_id-fk'} ?? ''));
                $gid = trim((string) ($asignacionById[$asignacionId]?->{'as_en_fu-gr_op_id-fk'} ?? ''));
            }
            $groupKey = $gid !== '' ? $gid : 'SIN_GRUPO';
            $key = $groupKey.'|'.$detalleId;
            $ts = trim((string) ($row->{'ej_ac-ts_fin'} ?? $row->{'ej_ac-ts_ini'} ?? ''));
            $prev = $latestEjecByKey[$key] ?? null;
            $prevTs = $prev ? trim((string) ($prev->{'ej_ac-ts_fin'} ?? $prev->{'ej_ac-ts_ini'} ?? '')) : '';
            if (! $prev || $ts > $prevTs) {
                $latestEjecByKey[$key] = $row;
            }
        }

        if (! empty($actionSetIds) && Schema::hasTable('accion_set_detalle_cfg')) {
            $detallesPorSetQuery = DB::table('accion_set_detalle_cfg')
                ->when(
                    Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                    static fn ($q) => $q->where('ac_se_de-tenant_id', $tenantId),
                )
                ->whereIn('ac_se_de-ac_se_id-fk', array_keys($actionSetIds));
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-activo')) {
                $detallesPorSetQuery->where(function ($q) {
                    $q->whereNull('ac_se_de-activo')
                        ->orWhereRaw("UPPER(TRIM(COALESCE(`ac_se_de-activo`, 'SI'))) <> 'NO'");
                });
            }
            $detallesPorSet = $detallesPorSetQuery->get(['ac_se_de-id']);
            foreach ($detallesPorSet as $d) {
                $did = trim((string) ($d->{'ac_se_de-id'} ?? ''));
                if ($did !== '') {
                    $detalleIds[$did] = true;
                }
            }
        }

        $detalleById = [];
        if (Schema::hasTable('accion_set_detalle_cfg') && ! empty($detalleIds)) {
            $detalleColumns = ['ac_se_de-id'];
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-rol_id-fk')) {
                $detalleColumns[] = 'ac_se_de-rol_id-fk';
            }
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-ac_op_id-fk')) {
                $detalleColumns[] = 'ac_se_de-ac_op_id-fk';
            }
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-detalle')) {
                $detalleColumns[] = 'ac_se_de-detalle';
            }
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-ord_ejec')) {
                $detalleColumns[] = 'ac_se_de-ord_ejec';
            }
            $detalles = DB::table('accion_set_detalle_cfg')
                ->when(
                    Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                    static fn ($q) => $q->where('ac_se_de-tenant_id', $tenantId),
                )
                ->whereIn('ac_se_de-id', array_keys($detalleIds))
                ->get($detalleColumns);
            foreach ($detalles as $d) {
                $id = trim((string) ($d->{'ac_se_de-id'} ?? ''));
                if ($id === '') {
                    continue;
                }
                $detalleById[$id] = $d;
            }
        }

        $opById = [];
        $opIds = array_values(array_filter(array_map(
            static fn ($d) => trim((string) ($d?->{'ac_se_de-ac_op_id-fk'} ?? '')),
            $detalleById
        )));
        if (Schema::hasTable('accion_operativa_cfg') && ! empty($opIds)) {
            $ops = DB::table('accion_operativa_cfg')
                ->when(
                    Schema::hasColumn('accion_operativa_cfg', 'ac_op-tenant_id'),
                    static fn ($q) => $q->where('ac_op-tenant_id', $tenantId),
                )
                ->whereIn('ac_op-id', $opIds)
                ->get(['ac_op-id', 'ac_op-cod', 'ac_op-descrip']);
            foreach ($ops as $op) {
                $id = trim((string) ($op->{'ac_op-id'} ?? ''));
                if ($id === '') {
                    continue;
                }
                $opById[$id] = $op;
            }
        }

        $personaIds = [];
        foreach ($asignacionById as $asg) {
            $perId = trim((string) ($asg->{'as_en_fu-per_id-fk'} ?? ''));
            $delegadorId = trim((string) ($asg->{'as_en_fu-per_id-fk-delegador'} ?? ''));
            if ($perId !== '') {
                $personaIds[$perId] = true;
            }
            if ($delegadorId !== '') {
                $personaIds[$delegadorId] = true;
            }
        }

        $personaById = [];
        if (Schema::hasTable('persona_mst') && ! empty($personaIds)) {
            $personaColumns = ['per-id'];
            foreach (['per-nombre', 'per-apellido_1', 'per-apellido_2', 'per-email'] as $col) {
                if (Schema::hasColumn('persona_mst', $col)) {
                    $personaColumns[] = $col;
                }
            }
            $personas = DB::table('persona_mst')
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->where('per-tenant_id', $tenantId),
                )
                ->whereIn('per-id', array_keys($personaIds))
                ->get($personaColumns);
            foreach ($personas as $p) {
                $id = trim((string) ($p->{'per-id'} ?? ''));
                if ($id === '') {
                    continue;
                }
                $personaById[$id] = $p;
            }
        }

        $titularOriginalByKey = [];
        foreach ($ejecRows as $row) {
            $detalleId = trim((string) ($row->{'ej_ac-ac_se_de_id-fk'} ?? ''));
            if ($detalleId === '') {
                continue;
            }
            $asignacionId = trim((string) ($row->{'ej_ac-as_en_fu_id-fk'} ?? ''));
            if ($asignacionId === '') {
                continue;
            }
            $asg = $asignacionById[$asignacionId] ?? null;
            if (! $asg) {
                continue;
            }
            $tipo = strtoupper(trim((string) ($asg->{'as_en_fu-tipo_asignacion'} ?? '')));
            if ($tipo !== 'TITULAR') {
                continue;
            }
            $perId = trim((string) ($asg->{'as_en_fu-per_id-fk'} ?? ''));
            if ($perId === '') {
                continue;
            }
            $gid = trim((string) ($row->{'ej_ac-gr_op_id-fk'} ?? ''));
            if ($gid === '') {
                $gid = trim((string) ($asg->{'as_en_fu-gr_op_id-fk'} ?? ''));
            }
            $groupKey = $gid !== '' ? $gid : 'SIN_GRUPO';
            $key = $groupKey.'|'.$detalleId;
            $ts = trim((string) ($asg->{'as_en_fu-ts_ini'} ?? $row->{'ej_ac-ts_ini'} ?? ''));
            $prev = $titularOriginalByKey[$key] ?? null;
            if (! $prev || strcmp((string) ($prev['ts'] ?? ''), $ts) > 0) {
                $titularOriginalByKey[$key] = ['per_id' => $perId, 'ts' => $ts];
            }
        }

        $notificationTsByPersonaId = [];
        $notificationIdsByPersonaId = [];
        if (Schema::hasTable('notificacion_envio_trs')) {
            $hasNotifPersona = Schema::hasColumn('notificacion_envio_trs', 'no_en-per_id-fk');
            $hasNotifTs = Schema::hasColumn('notificacion_envio_trs', 'no_en-ts');
            $hasNotifActivation = Schema::hasColumn('notificacion_envio_trs', 'no_en-ac_de_pl_id-fk');
            if ($hasNotifPersona && $hasNotifTs && $hasNotifActivation) {
                $sentQuery = DB::table('notificacion_envio_trs')
                    ->when(
                        Schema::hasColumn('notificacion_envio_trs', 'no_en-tenant_id'),
                        static fn ($q) => $q->where('no_en-tenant_id', $tenantId),
                    )
                    ->where('no_en-ac_de_pl_id-fk', $activationId)
                    ->whereNotNull('no_en-per_id-fk')
                    ->orderBy('no_en-ts', 'asc');
                if (Schema::hasColumn('notificacion_envio_trs', 'no_en-id')) {
                    $sentQuery->orderBy('no_en-id', 'asc');
                }
                $sentCols = ['no_en-per_id-fk', 'no_en-ts'];
                if (Schema::hasColumn('notificacion_envio_trs', 'no_en-id')) {
                    $sentCols[] = 'no_en-id';
                }
                $sent = $sentQuery->get($sentCols);
                foreach ($sent as $n) {
                    $perId = trim((string) ($n->{'no_en-per_id-fk'} ?? ''));
                    $ts = trim((string) ($n->{'no_en-ts'} ?? ''));
                    if ($perId === '' || $ts === '') {
                        continue;
                    }
                    if (! array_key_exists($perId, $notificationTsByPersonaId)) {
                        $notificationTsByPersonaId[$perId] = $ts;
                    }
                    $noEnId = trim((string) ($n->{'no_en-id'} ?? ''));
                    if ($noEnId !== '') {
                        $notificationIdsByPersonaId[$perId] ??= [];
                        $notificationIdsByPersonaId[$perId][] = $noEnId;
                    }
                }
            }
        }
        $confirmationTsByNotificationId = [];
        if (Schema::hasTable('notificacion_confirmacion_trs')) {
            $notifIds = [];
            foreach ($notificationIdsByPersonaId as $ids) {
                foreach ((array) $ids as $id) {
                    $id = trim((string) $id);
                    if ($id !== '') {
                        $notifIds[] = $id;
                    }
                }
            }
            $notifIds = array_values(array_unique($notifIds));
            if (! empty($notifIds)
                && Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-no_en_id-fk')
                && Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-confirmado')
            ) {
                $confirmCols = ['no_co-no_en_id-fk', 'no_co-confirmado'];
                if (Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-ts')) {
                    $confirmCols[] = 'no_co-ts';
                }
                $confirmRows = DB::table('notificacion_confirmacion_trs')
                    ->when(
                        Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-tenant_id'),
                        static fn ($q) => $q->where('no_co-tenant_id', $tenantId),
                    )
                    ->whereIn('no_co-no_en_id-fk', $notifIds)
                    ->orderByDesc('no_co-ts')
                    ->get($confirmCols);
                foreach ($confirmRows as $c) {
                    $noEnId = trim((string) ($c->{'no_co-no_en_id-fk'} ?? ''));
                    if ($noEnId === '' || array_key_exists($noEnId, $confirmationTsByNotificationId)) {
                        continue;
                    }
                    $confirmed = strtoupper(trim((string) ($c->{'no_co-confirmado'} ?? '')));
                    if ($confirmed === 'NO' || $confirmed === 'N' || $confirmed === '0' || $confirmed === 'FALSE') {
                        continue;
                    }
                    $confirmationTsByNotificationId[$noEnId] = trim((string) ($c->{'no_co-ts'} ?? ''));
                }
            }
        }
        $confirmationTsByPersonaId = [];
        foreach ($notificationIdsByPersonaId as $perId => $ids) {
            $minTs = null;
            foreach ((array) $ids as $id) {
                $id = trim((string) $id);
                if ($id === '') {
                    continue;
                }
                $ts = trim((string) ($confirmationTsByNotificationId[$id] ?? ''));
                if ($ts === '') {
                    continue;
                }
                if ($minTs === null || strcmp($ts, $minTs) < 0) {
                    $minTs = $ts;
                }
            }
            if ($minTs !== null) {
                $confirmationTsByPersonaId[$perId] = $minTs;
            }
        }

        $rolToGrupoId = [];
        if (Schema::hasTable('persona_rol_grupo_cfg')) {
            $rolGroupQuery = DB::table('persona_rol_grupo_cfg')
                ->when(
                    Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tenant_id'),
                    static fn ($q) => $q->where('pe_ro_gr-tenant_id', $tenantId),
                );
            if (Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-activo')) {
                $rolGroupQuery->where(function ($q) {
                    $q->whereNull('pe_ro_gr-activo')
                        ->orWhereRaw("UPPER(TRIM(COALESCE(`pe_ro_gr-activo`, 'SI'))) <> 'NO'");
                });
            }
            if (Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-fech_fin')) {
                $rolGroupQuery->whereNull('pe_ro_gr-fech_fin');
            }
            $rolGroupRows = $rolGroupQuery->get(['pe_ro_gr-rol_id-fk', 'pe_ro_gr-gr_op_id-fk']);
            foreach ($rolGroupRows as $row) {
                $rolId = trim((string) ($row->{'pe_ro_gr-rol_id-fk'} ?? ''));
                $gid = trim((string) ($row->{'pe_ro_gr-gr_op_id-fk'} ?? ''));
                if ($rolId === '' || $gid === '' || isset($rolToGrupoId[$rolId])) {
                    continue;
                }
                $rolToGrupoId[$rolId] = $gid;
            }
        }

        $latestAsignacionByGroupRole = [];
        $latestAsignacionByGroup = [];
        foreach ($asignacionById as $asgId => $asg) {
            $gid = trim((string) ($asg->{'as_en_fu-gr_op_id-fk'} ?? ''));
            $pid = trim((string) ($asg->{'as_en_fu-per_id-fk'} ?? ''));
            if ($gid === '' || $pid === '') {
                continue;
            }
            $ts = trim((string) ($asg->{'as_en_fu-ts_ini'} ?? ''));
            $existingGroup = $latestAsignacionByGroup[$gid] ?? null;
            if (! $existingGroup || strcmp((string) ($existingGroup['ts'] ?? ''), $ts) <= 0) {
                $latestAsignacionByGroup[$gid] = ['id' => $asgId, 'ts' => $ts];
            }
            $tipo = strtoupper(trim((string) ($asg->{'as_en_fu-tipo_asignacion'} ?? '')));
            if ($tipo !== 'TITULAR') {
                continue;
            }
            foreach ($detalleById as $did => $d) {
                $rolId = trim((string) ($d->{'ac_se_de-rol_id-fk'} ?? ''));
                if ($rolId === '') {
                    continue;
                }
                $key = $gid.'|'.$rolId;
                $existing = $latestAsignacionByGroupRole[$key] ?? null;
                if (! $existing || strcmp((string) ($existing['ts'] ?? ''), $ts) <= 0) {
                    $latestAsignacionByGroupRole[$key] = ['id' => $asgId, 'ts' => $ts];
                }
            }
        }

        $allRowKeys = array_keys($latestEjecByKey);
        foreach ($detalleById as $detalleId => $detalle) {
            $rolId = trim((string) ($detalle?->{'ac_se_de-rol_id-fk'} ?? ''));
            $gid = $rolId !== '' ? trim((string) ($rolToGrupoId[$rolId] ?? '')) : '';
            $groupKey = $gid !== '' ? $gid : 'SIN_GRUPO';
            $key = $groupKey.'|'.$detalleId;
            if (! isset($latestEjecByKey[$key])) {
                $allRowKeys[] = $key;
            }
        }
        $allRowKeys = array_values(array_unique($allRowKeys));

        $rows = [];
        $manualDelegationAssignmentIds = [];
        if (Schema::hasTable('audit_log_trs')) {
            $manualDelegationLogs = DB::table('audit_log_trs')
                ->when(
                    Schema::hasColumn('audit_log_trs', 'tenant_id'),
                    static fn ($q) => $q->where('tenant_id', $tenantId),
                )
                ->where('plan_id', $activationId)
                ->whereIn('event_type', ['delegation_created', 'delegation_updated'])
                ->whereNotNull('entity_id')
                ->get(['entity_id']);
            foreach ($manualDelegationLogs as $log) {
                $id = trim((string) ($log->entity_id ?? ''));
                if ($id !== '') {
                    $manualDelegationAssignmentIds[$id] = true;
                }
            }
        }
        foreach ($allRowKeys as $key) {
            $ejec = $latestEjecByKey[$key] ?? null;
            [$groupKey, $detalleId] = array_pad(explode('|', $key, 2), 2, '');
            $detalle = $detalleById[$detalleId] ?? null;
            $rolId = trim((string) ($detalle?->{'ac_se_de-rol_id-fk'} ?? ''));
            $opId = trim((string) ($detalle?->{'ac_se_de-ac_op_id-fk'} ?? ''));
            $op = $opById[$opId] ?? null;
            $asignacionId = trim((string) ($ejec?->{'ej_ac-as_en_fu_id-fk'} ?? ''));
            if ($asignacionId === '' && $groupKey !== 'SIN_GRUPO' && $rolId !== '') {
                $asignacionId = (string) ($latestAsignacionByGroupRole[$groupKey.'|'.$rolId]['id'] ?? '');
            }
            if ($asignacionId === '' && $groupKey !== 'SIN_GRUPO') {
                $asignacionId = (string) ($latestAsignacionByGroup[$groupKey]['id'] ?? '');
            }
            $asg = $asignacionById[$asignacionId] ?? null;
            $personaId = trim((string) ($asg?->{'as_en_fu-per_id-fk'} ?? ''));
            $delegadorId = trim((string) ($asg?->{'as_en_fu-per_id-fk-delegador'} ?? ''));
            $delegated = $personaId !== '' && $delegadorId !== '' && $personaId !== $delegadorId;
            $delegatedManual = $delegated && $asignacionId !== '' && isset($manualDelegationAssignmentIds[$asignacionId]);
            $titularOriginalId = trim((string) ($titularOriginalByKey[$key]['per_id'] ?? ''));
            $accion = trim((string) ($op?->{'ac_op-descrip'} ?? ''));
            if ($accion === '') {
                $accion = trim((string) ($op?->{'ac_op-cod'} ?? ''));
            }
            if ($accion === '') {
                $accion = trim((string) ($detalle?->{'ac_se_de-detalle'} ?? ''));
            }
            if ($accion === '') {
                $accion = $detalleId !== '' ? $detalleId : trim((string) ($ejec->{'ej_ac-id'} ?? ''));
            }
            $tsIni = trim((string) ($ejec?->{'ej_ac-ts_ini'} ?? ''));
            $tsFin = trim((string) ($ejec?->{'ej_ac-ts_fin'} ?? ''));
            $durationMinutes = null;
            if ($tsIni !== '' && $tsFin !== '') {
                try {
                    $durationMinutes = Carbon::parse($tsIni)->diffInMinutes(Carbon::parse($tsFin));
                } catch (\Throwable) {
                    $durationMinutes = null;
                }
            }
            $notificationTs = $personaId !== '' ? ($notificationTsByPersonaId[$personaId] ?? null) : null;
            $confirmationTs = $personaId !== '' ? ($confirmationTsByPersonaId[$personaId] ?? null) : null;
            $status = $normalizeActionStatus($ejec);
            if ($status === 'PENDIENTE' && $notificationTs !== null) {
                $status = 'ENVIADA';
            }
            $rows[] = [
                'id' => $detalleId !== '' ? $detalleId : trim((string) ($ejec?->{'ej_ac-id'} ?? '')),
                'group_id' => $groupKey,
                'role_id' => $rolId,
                'action' => $accion,
                'status' => $status,
                'notification_ts' => $notificationTs,
                'notification_confirmed' => $confirmationTs !== null,
                'confirmation_ts' => $confirmationTs,
                'start_ts' => $tsIni !== '' ? $tsIni : null,
                'end_ts' => $tsFin !== '' ? $tsFin : null,
                'duration_minutes' => $durationMinutes,
                'persona_id' => $personaId !== '' ? $personaId : null,
                'responsible_id' => $personaId !== '' ? $personaId : null,
                'delegated' => $delegated,
                'delegated_manual' => $delegatedManual,
                'delegator_id' => $delegadorId !== '' ? $delegadorId : null,
                'titular_original_id' => $titularOriginalId !== '' ? $titularOriginalId : null,
                'has_execution' => $ejec !== null,
                'order' => (int) ($detalle?->{'ac_se_de-ord_ejec'} ?? 9999),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $ao = (int) ($a['order'] ?? 9999);
            $bo = (int) ($b['order'] ?? 9999);
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        $total = count($rows);
        $done = 0;
        $inProgress = 0;
        $pending = 0;
        $delegatedCount = 0;
        $withDurationCount = 0;
        $durationTotal = 0;
        $responsablesSet = [];
        foreach ($rows as $row) {
            $status = strtoupper(trim((string) ($row['status'] ?? 'PENDIENTE')));
            if ($status === 'REALIZADA') {
                $done++;
            } elseif ($status === 'EN_CURSO') {
                $inProgress++;
            } else {
                $pending++;
            }
            if (! empty($row['delegated_manual'])) {
                $delegatedCount++;
            }
            $duration = $row['duration_minutes'];
            if (is_int($duration) || is_float($duration)) {
                $withDurationCount++;
                $durationTotal += (int) $duration;
            }
            $responsableId = trim((string) ($row['responsible_id'] ?? ''));
            if ($responsableId !== '') {
                $responsablesSet[$responsableId] = true;
            }
        }

        $warnings = [];
        if ($total === 0) {
            $warnings[] = 'No execution records found for this activation.';
        }

        return response()->json([
            'activation_id' => $activationId,
            'stats' => [
                'total' => $total,
                'done' => $done,
                'in_progress' => $inProgress,
                'pending' => $pending,
                'delegated' => $delegatedCount,
                'responsables' => count($responsablesSet),
                'with_duration' => $withDurationCount,
                'avg_duration_minutes' => $withDurationCount > 0 ? (int) round($durationTotal / $withDurationCount) : null,
            ],
            'rows' => $rows,
            'personas' => array_map(static function ($p) {
                $id = trim((string) ($p->{'per-id'} ?? ''));
                $nombre = trim(implode(' ', array_filter([
                    (string) ($p->{'per-nombre'} ?? ''),
                    (string) ($p->{'per-apellido_1'} ?? ''),
                    (string) ($p->{'per-apellido_2'} ?? ''),
                ])));

                return [
                    'id' => $id,
                    'nombre' => $nombre !== '' ? $nombre : $id,
                    'email' => $p->{'per-email'} ?? null,
                ];
            }, array_values($personaById)),
            'warnings' => $warnings,
        ]);
    }

    public function logDocumentViewed(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        $payload = $request->validate([
            'document_id' => ['nullable', 'integer'],
            'document_name' => ['required', 'string', 'max:255'],
            'document_url' => ['nullable', 'string', 'max:2000'],
            'folder_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:32'],
        ]);

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'document_viewed',
            'module' => 'documents',
            'plan_id' => $activationId,
            'entity_id' => isset($payload['document_id']) ? (string) $payload['document_id'] : null,
            'entity_type' => 'tenant_documents',
            'new_value' => [
                'document_name' => (string) ($payload['document_name'] ?? ''),
                'document_url' => isset($payload['document_url']) ? (string) $payload['document_url'] : null,
                'folder_id' => isset($payload['folder_id']) ? (string) $payload['folder_id'] : null,
                'source' => isset($payload['source']) ? (string) $payload['source'] : null,
            ],
        ]);

        return response()->json(['message' => 'Document view logged.'], 201);
    }

    public function controlPanel(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        if (! Schema::hasTable('activacion_del_plan_trs')) {
            return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
        }

        $activationExists = DB::table('activacion_del_plan_trs')
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->where('ac_de_pl-id', $activationId)
            ->exists();

        if (! $activationExists) {
            return response()->json(['message' => 'Activation not found.'], 404);
        }

        $user = $request->user();
        $perfil = strtolower(trim((string) ($user?->perfil ?? '')));
        if ($perfil !== 'director') {
            $allowed = false;
            if ($user && Schema::hasTable('control_panel_access_trs')) {
                $allowed = DB::table('control_panel_access_trs')
                    ->where('tenant_id', $tenantId)
                    ->where('activation_id', $activationId)
                    ->where('user_id', $user->id)
                    ->where(function ($q) use ($tenantId) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>=', $this->tenantNowDateTime($tenantId));
                    })
                    ->exists();
            }

            if (! $allowed) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        }

        $nivelRow = null;
        if (Schema::hasTable('activacion_nivel_hist_trs')) {
            $nivelRow = DB::table('activacion_nivel_hist_trs')
                ->when(
                    Schema::hasColumn('activacion_nivel_hist_trs', 'ac_ni_hi-tenant_id'),
                    static fn ($q) => $q->where('ac_ni_hi-tenant_id', $tenantId),
                )
                ->where('ac_ni_hi-ac_de_pl_id-fk', $activationId)
                ->orderByRaw("CASE WHEN UPPER(COALESCE(`ac_ni_hi-activo`, 'NO')) = 'SI' THEN 0 ELSE 1 END ASC")
                ->orderByRaw("CAST(COALESCE(`ac_ni_hi-orden`, '999') AS UNSIGNED) DESC")
                ->orderBy('ac_ni_hi-id', 'DESC')
                ->first();
        }

        $actionSetId = trim((string) ($nivelRow?->{'ac_ni_hi-ac_se_id-fk'} ?? ''));
        if ($actionSetId === '') {
            $actionSetId = null;
        }

        $totalAcciones = 0;
        if ($actionSetId !== null && Schema::hasTable('accion_set_detalle_cfg')) {
            $totalAcciones = DB::table('accion_set_detalle_cfg')
                ->when(
                    Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-tenant_id'),
                    static fn ($q) => $q->where('ac_se_de-tenant_id', $tenantId),
                )
                ->where('ac_se_de-ac_se_id-fk', $actionSetId)
                ->whereRaw("UPPER(COALESCE(`ac_se_de-activo`, 'SI')) <> 'NO'")
                ->count();
        }

        $doneCountByGrupo = [];
        $totalCountByGrupo = [];
        $involvedGroupIds = [];

        if (Schema::hasTable('ejecucion_accion_trs')) {
            $ejecuciones = DB::table('ejecucion_accion_trs')
                ->when(
                    Schema::hasColumn('ejecucion_accion_trs', 'ej_ac-tenant_id'),
                    static fn ($q) => $q->where('ej_ac-tenant_id', $tenantId),
                )
                ->where('ej_ac-ac_de_pl_id-fk', $activationId)
                ->get(['ej_ac-gr_op_id-fk', 'ej_ac-estado', 'ej_ac-ts_fin']);

            foreach ($ejecuciones as $e) {
                $gid = trim((string) ($e->{'ej_ac-gr_op_id-fk'} ?? ''));
                if ($gid === '') {
                    continue;
                }
                $safeKey = Str::slug($gid);
                $totalCountByGrupo[$safeKey] = ($totalCountByGrupo[$safeKey] ?? 0) + 1;
                $involvedGroupIds[$gid] = true;
                $estado = strtoupper(trim((string) ($e->{'ej_ac-estado'} ?? '')));
                $done = $estado === 'REALIZADA' || $estado === 'REALIZADO' || (string) ($e->{'ej_ac-ts_fin'} ?? '') !== '';
                if ($done) {
                    $doneCountByGrupo[$safeKey] = ($doneCountByGrupo[$safeKey] ?? 0) + 1;
                }
            }
        }

        $involvedGroupIds = array_keys($involvedGroupIds);

        $grupos = [];
        if (Schema::hasTable('grupo_operativo_cat') && ! empty($involvedGroupIds)) {
            $grupos = DB::table('grupo_operativo_cat')
                ->when(
                    Schema::hasColumn('grupo_operativo_cat', 'gr_op-tenant_id'),
                    static fn ($q) => $q->where('gr_op-tenant_id', $tenantId),
                )
                ->whereIn('gr_op-id', $involvedGroupIds)
                ->orderByRaw("CAST(COALESCE(`gr_op-ord_vis`, '0') AS UNSIGNED) ASC")
                ->orderBy('gr_op-id')
                ->get()
                ->all();
        }

        $lastNotificationByPerson = [];
        $confirmedNotificationIds = [];
        $confirmedByExecutionPerson = [];
        if (Schema::hasTable('notificacion_envio_trs')) {
            $sent = DB::table('notificacion_envio_trs')
                ->when(
                    Schema::hasColumn('notificacion_envio_trs', 'no_en-tenant_id'),
                    static fn ($q) => $q->where('no_en-tenant_id', $tenantId),
                )
                ->where('no_en-ac_de_pl_id-fk', $activationId)
                ->whereNotNull('no_en-per_id-fk')
                ->orderBy('no_en-ts', 'DESC')
                ->orderBy('no_en-id', 'DESC')
                ->get(['no_en-id', 'no_en-per_id-fk', 'no_en-ts']);

            foreach ($sent as $row) {
                $perId = trim((string) ($row->{'no_en-per_id-fk'} ?? ''));
                if ($perId === '') {
                    continue;
                }
                if (! array_key_exists($perId, $lastNotificationByPerson)) {
                    $lastNotificationByPerson[$perId] = [
                        'id' => trim((string) ($row->{'no_en-id'} ?? '')),
                        'ts' => trim((string) ($row->{'no_en-ts'} ?? '')),
                    ];
                }
            }
        }

        if (! empty($lastNotificationByPerson) && Schema::hasTable('notificacion_confirmacion_trs')) {
            $lastIds = array_values(array_filter(array_map(
                static fn ($row) => is_array($row) ? trim((string) ($row['id'] ?? '')) : '',
                $lastNotificationByPerson
            ), static fn ($v) => $v !== ''));
            if (! empty($lastIds)) {
                $rowsConfirm = DB::table('notificacion_confirmacion_trs')
                    ->when(
                        Schema::hasColumn('notificacion_confirmacion_trs', 'no_co-tenant_id'),
                        static fn ($q) => $q->where('no_co-tenant_id', $tenantId),
                    )
                    ->whereIn('no_co-no_en_id-fk', $lastIds)
                    ->orderBy('no_co-ts', 'DESC')
                    ->get(['no_co-no_en_id-fk', 'no_co-confirmado']);

                foreach ($rowsConfirm as $row) {
                    $noEnId = trim((string) ($row->{'no_co-no_en_id-fk'} ?? ''));
                    if ($noEnId === '') {
                        continue;
                    }
                    $confirmado = strtoupper(trim((string) ($row->{'no_co-confirmado'} ?? '')));
                    if ($confirmado === '' || $confirmado === 'SI' || $confirmado === 'S' || $confirmado === '1' || $confirmado === 'TRUE') {
                        $confirmedNotificationIds[$noEnId] = true;
                    }
                }
            }
        }

        if (Schema::hasTable('ejecucion_accion_trs') && Schema::hasTable('asignacion_en_funciones_trs')) {
            $confirmedRows = DB::table('ejecucion_accion_trs as ej')
                ->join('asignacion_en_funciones_trs as asg', 'asg.as_en_fu-id', '=', 'ej.ej_ac-as_en_fu_id-fk')
                ->when(
                    Schema::hasColumn('ejecucion_accion_trs', 'ej_ac-tenant_id'),
                    static fn ($q) => $q->where('ej.ej_ac-tenant_id', $tenantId),
                )
                ->when(
                    Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-tenant_id'),
                    static fn ($q) => $q->where('asg.as_en_fu-tenant_id', $tenantId),
                )
                ->where('ej.ej_ac-ac_de_pl_id-fk', $activationId)
                ->whereRaw("UPPER(COALESCE(`ej`.`ej_ac-estado`, '')) IN ('CONFIRMADO','CONFIRMADA')")
                ->get(['asg.as_en_fu-per_id-fk']);
            foreach ($confirmedRows as $row) {
                $pid = trim((string) ($row->{'as_en_fu-per_id-fk'} ?? ''));
                if ($pid !== '') {
                    $confirmedByExecutionPerson[$pid] = true;
                }
            }
        }

        $assignByGrupo = [];
        if (Schema::hasTable('asignacion_en_funciones_trs')) {
            $asignaciones = DB::table('asignacion_en_funciones_trs as a')
                ->leftJoin('persona_mst as p', 'p.per-id', '=', 'a.as_en_fu-per_id-fk')
                ->when(
                    Schema::hasColumn('asignacion_en_funciones_trs', 'as_en_fu-tenant_id'),
                    static fn ($q) => $q->where('a.as_en_fu-tenant_id', $tenantId),
                )
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->where('p.per-tenant_id', $tenantId),
                )
                ->where('a.as_en_fu-ac_de_pl_id-fk', $activationId)
                ->whereRaw("COALESCE(`a`.`as_en_fu-ts_fin`, '') = ''")
                ->whereRaw("UPPER(COALESCE(`a`.`as_en_fu-estado`, '')) <> 'CERRADA'")
                ->get([
                    'a.as_en_fu-gr_op_id-fk',
                    'a.as_en_fu-per_id-fk',
                    'a.as_en_fu-tipo_asignacion',
                    'a.as_en_fu-ts_ini',
                    'p.per-nombre',
                    'p.per-apellido_1',
                    'p.per-apellido_2',
                    'p.per-email',
                ]);

            foreach ($asignaciones as $a) {
                $gid = strtoupper(trim((string) ($a->{'as_en_fu-gr_op_id-fk'} ?? '')));
                $perId = trim((string) ($a->{'as_en_fu-per_id-fk'} ?? ''));
                if ($gid === '' || $perId === '') {
                    continue;
                }
                $tipo = strtoupper(trim((string) ($a->{'as_en_fu-tipo_asignacion'} ?? 'SUPLENTE')));
                if ($tipo !== 'TITULAR' && $tipo !== 'SUPLENTE') {
                    $tipo = 'SUPLENTE';
                }
                $nombre = trim(implode(' ', array_filter([
                    (string) ($a->{'per-nombre'} ?? ''),
                    (string) ($a->{'per-apellido_1'} ?? ''),
                    (string) ($a->{'per-apellido_2'} ?? ''),
                ])));
                $persona = [
                    'per_id' => $perId,
                    'nombre' => $nombre !== '' ? $nombre : $perId,
                    'email' => $a->{'per-email'} ?? null,
                    'estado_disponibilidad' => null,
                ];
                if (array_key_exists($perId, $lastNotificationByPerson)) {
                    $noEnId = (string) ($lastNotificationByPerson[$perId]['id'] ?? '');
                    $persona['estado_disponibilidad'] = $noEnId !== '' && array_key_exists($noEnId, $confirmedNotificationIds)
                        ? 'CONFIRMADO'
                        : 'PENDIENTE';
                } elseif (array_key_exists($perId, $confirmedByExecutionPerson)) {
                    $persona['estado_disponibilidad'] = 'CONFIRMADO';
                } else {
                    $persona['estado_disponibilidad'] = 'PENDIENTE';
                }
                $assignByGrupo[$gid] ??= ['TITULAR' => [], 'SUPLENTE' => []];
                $assignByGrupo[$gid][$tipo][] = [
                    'persona' => $persona,
                    'ts_ini' => (string) ($a->{'as_en_fu-ts_ini'} ?? ''),
                ];
            }
        }

        $rows = [];
        foreach ($grupos as $g) {
            $gid = trim((string) ($g->{'gr_op-id'} ?? ''));
            if ($gid === '') {
                continue;
            }
            $safeKey = Str::slug($gid);
            $entry = $assignByGrupo[strtoupper($gid)] ?? ['TITULAR' => [], 'SUPLENTE' => []];
            usort($entry['TITULAR'], static fn ($a, $b) => strcmp((string) ($b['ts_ini'] ?? ''), (string) ($a['ts_ini'] ?? '')));
            usort($entry['SUPLENTE'], static fn ($a, $b) => strcmp((string) ($b['ts_ini'] ?? ''), (string) ($a['ts_ini'] ?? '')));
            $titular = $entry['TITULAR'][0]['persona'] ?? null;
            $suplentes = array_values(array_map(
                static fn ($row) => $row['persona'],
                array_slice($entry['SUPLENTE'], 0, 2),
            ));

            $done = (int) ($doneCountByGrupo[$safeKey] ?? 0);
            $totalForGrupo = (int) ($totalCountByGrupo[$safeKey] ?? 0);
            if ($totalForGrupo <= 0) {
                continue;
            }
            $pendingForGrupo = max(0, $totalForGrupo - $done);
            $percent = $totalForGrupo > 0 ? (int) min(100, round(($done / $totalForGrupo) * 100)) : 0;
            $hasAsignacion = $titular !== null || count($suplentes) > 0;
            $color = ! $hasAsignacion ? 'ROJO' : ($totalAcciones > 0 && $done >= $totalAcciones ? 'VERDE' : 'AMARILLO');

            $rows[] = [
                'grupo_id' => $gid,
                'grupo_nombre' => $g?->{'gr_op-nombre'} ?? $gid,
                'titular' => $titular,
                'suplentes' => $suplentes,
                'done' => $done,
                'total' => $totalForGrupo,
                'pending' => $pendingForGrupo,
                'percent' => $percent,
                'color' => $color,
            ];
        }

        $overallDone = array_reduce($rows, static fn ($acc, $r) => $acc + (int) ($r['done'] ?? 0), 0);
        $overallTotal = array_reduce(
            $rows,
            static fn ($acc, $r) => $acc + (int) ($r['total'] ?? 0),
            0
        );
        if ($overallTotal <= 0) {
            $overallTotal = $totalAcciones * count($rows);
        }
        $overallPercent = $overallTotal > 0 ? (int) min(100, round(($overallDone / $overallTotal) * 100)) : 0;

        return response()->json([
            'activation_id' => $activationId,
            'action_set_id' => $actionSetId,
            'total_actions' => $totalAcciones,
            'overall_done' => $overallDone,
            'overall_total' => $overallTotal,
            'overall_percent' => $overallPercent,
            'groups' => $rows,
        ]);
    }

    public function grantControlPanelAccess(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $user = $request->user();
        $perfil = strtolower(trim((string) ($user?->perfil ?? '')));
        if ($perfil !== 'director') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        if (! Schema::hasTable('activacion_del_plan_trs')) {
            return response()->json(['message' => 'Missing activacion_del_plan_trs table.'], 422);
        }

        $activationExists = DB::table('activacion_del_plan_trs')
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->where('ac_de_pl-id', $activationId)
            ->exists();

        if (! $activationExists) {
            return response()->json(['message' => 'Activation not found.'], 404);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'expires_at' => ['nullable', 'date'],
        ]);

        if (! Schema::hasTable('control_panel_access_trs')) {
            return response()->json(['message' => 'Missing control_panel_access_trs table.'], 422);
        }

        $targetUser = User::query()
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $data['user_id'])
            ->first();

        if (! $targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt === null) {
            $expiresAt = now()->addDay()->toDateTimeString();
        } else {
            $expiresAt = Carbon::parse($expiresAt)->toDateTimeString();
        }

        DB::table('control_panel_access_trs')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'activation_id' => $activationId,
                'user_id' => $targetUser->id,
            ],
            [
                'created_by_user_id' => $user?->id,
                'expires_at' => $expiresAt,
                'updated_at' => $this->tenantNowDateTime($tenantId),
                'created_at' => $this->tenantNowDateTime($tenantId),
            ],
        );

        $tenantLang = strtolower(trim((string) (Tenant::query()->where('tenant_id', $tenantId)->value('default_language') ?? 'es')));
        if (! in_array($tenantLang, ['es', 'ca', 'en'], true)) {
            $tenantLang = 'es';
        }
        $subjectByLang = [
            'es' => 'Acceso al panel de control compartido',
            'ca' => 'Accés al panell de control compartit',
            'en' => 'Shared control panel access',
        ];
        $activationRow = DB::table('activacion_del_plan_trs')
            ->when(
                Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
            )
            ->where('ac_de_pl-id', $activationId)
            ->first(['ac_de_pl-rie_id-fk', 'ac_de_pl-ni_al_id-fk-inicial']);
        $riskId = trim((string) ($activationRow->{'ac_de_pl-rie_id-fk'} ?? ''));
        $levelId = trim((string) ($activationRow->{'ac_de_pl-ni_al_id-fk-inicial'} ?? ''));
        $riskLabel = $riskId;
        $levelLabel = $levelId;
        if ($riskId !== '' && Schema::hasTable('riesgo_cat')) {
            $risk = DB::table('riesgo_cat')
                ->when(
                    Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                    static fn ($q) => $q->where('rie-tenant_id', $tenantId),
                )
                ->where('rie-id', $riskId)
                ->first(['rie-nombre']);
            $riskLabel = trim((string) ($risk->{'rie-nombre'} ?? $riskId));
        }
        if ($levelId !== '' && Schema::hasTable('nivel_alerta_cat')) {
            $level = DB::table('nivel_alerta_cat')
                ->when(
                    Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'),
                    static fn ($q) => $q->where('ni_al-tenant_id', $tenantId),
                )
                ->where('ni_al-id', $levelId)
                ->first(['ni_al-nombre']);
            $levelLabel = trim((string) ($level->{'ni_al-nombre'} ?? $levelId));
        }
        $line1ByLang = [
            'es' => "Se te ha concedido acceso al panel de control. Riesgo: {$riskLabel}. Nivel: {$levelLabel}.",
            'ca' => "Se t'ha concedit accés al panell de control. Risc: {$riskLabel}. Nivell: {$levelLabel}.",
            'en' => "You have been granted control panel access. Risk: {$riskLabel}. Level: {$levelLabel}.",
        ];
        $line2ByLang = [
            'es' => "Vence: {$expiresAt}",
            'ca' => "Caduca: {$expiresAt}",
            'en' => "Expires: {$expiresAt}",
        ];
        $line3ByLang = [
            'es' => 'Inicia sesión para acceder al panel.',
            'ca' => 'Inicia sessió per accedir al panell.',
            'en' => 'Sign in to access the panel.',
        ];
        if (is_string($targetUser->email) && trim($targetUser->email) !== '') {
            try {
                Mail::raw(
                    implode("\n\n", [
                        $line1ByLang[$tenantLang],
                        $line2ByLang[$tenantLang],
                        $line3ByLang[$tenantLang],
                    ]),
                    static function ($message) use ($targetUser, $subjectByLang, $tenantLang): void {
                        $message->to($targetUser->email)->subject($subjectByLang[$tenantLang]);
                    }
                );
            } catch (\Throwable $e) {
                Log::warning('control_panel_share_email_failed', [
                    'tenant_id' => $tenantId,
                    'activation_id' => $activationId,
                    'user_id' => $targetUser->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Access granted.',
            'expires_at' => $expiresAt,
        ]);
    }

    public function checkControlPanelAccess(Request $request, string $activationId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $activationId = trim($activationId);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation id.'], 422);
        }

        $allowed = false;
        $expiresAt = null;
        if (Schema::hasTable('control_panel_access_trs')) {
            $row = DB::table('control_panel_access_trs')
                ->where('tenant_id', $tenantId)
                ->where('activation_id', $activationId)
                ->where('user_id', $user->id)
                ->orderBy('id', 'desc')
                ->first();

            if ($row) {
                $expiresAt = $row->expires_at;
                $allowed = $row->expires_at === null || $row->expires_at >= $this->tenantNowDateTime($tenantId);
            }
        }

        return response()->json([
            'allowed' => $allowed,
            'expires_at' => $expiresAt,
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

        if (! empty($documents)) {
            $this->auditLogger->logFromRequest($request, [
                'event_type' => 'document_uploaded',
                'module' => 'documents',
                'plan_id' => $activationId,
                'entity_id' => $activationId,
                'entity_type' => 'activacion_document',
                'new_value' => $documents,
            ]);
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

    private function resolveRoleRecipientsForActivation(iterable $rawRows): array
    {
        $destinatariosByGrupo = [];
        foreach ($rawRows as $row) {
            $grId = trim((string) ($row->{'pe_ro_gr-gr_op_id-fk'} ?? ''));
            $destinatariosByGrupo[$grId] ??= [];
            $destinatariosByGrupo[$grId][] = $row;
        }

        $groupIds = array_keys($destinatariosByGrupo);
        $hasMultipleGroups = count($groupIds) > 1;
        $leaderCandidates = [];
        foreach ($destinatariosByGrupo as $grId => $items) {
            foreach ($items as $d) {
                $tipo = strtoupper(trim((string) ($d->{'pe_ro_gr-tipo_asignacion'} ?? '')));
                if ($tipo !== 'LIDER') {
                    continue;
                }
                $leaderCandidates[] = [
                    'group_id' => $grId,
                    'order' => (int) trim((string) ($d->{'pe_ro_gr-orden_sust'} ?? '999')),
                    'row_id' => (string) ($d->{'pe_ro_gr-id'} ?? ''),
                ];
            }
        }
        $selectedLeaderGroupId = null;
        if ($hasMultipleGroups && ! empty($leaderCandidates)) {
            usort($leaderCandidates, static function ($a, $b) {
                $ao = (int) ($a['order'] ?? 999);
                $bo = (int) ($b['order'] ?? 999);
                if ($ao !== $bo) {
                    return $ao <=> $bo;
                }

                return strcmp((string) ($a['row_id'] ?? ''), (string) ($b['row_id'] ?? ''));
            });
            $selectedLeaderGroupId = (string) ($leaderCandidates[0]['group_id'] ?? '');
        }

        $groupsToProcess = $destinatariosByGrupo;
        if ($hasMultipleGroups && $selectedLeaderGroupId !== null && array_key_exists($selectedLeaderGroupId, $destinatariosByGrupo)) {
            $groupsToProcess = [$selectedLeaderGroupId => $destinatariosByGrupo[$selectedLeaderGroupId]];
        }

        $recipients = [];
        foreach ($groupsToProcess as $grId => $items) {
            usort($items, static function ($a, $b) {
                $ao = (int) trim((string) ($a->{'pe_ro_gr-orden_sust'} ?? '999'));
                $bo = (int) trim((string) ($b->{'pe_ro_gr-orden_sust'} ?? '999'));
                if ($ao !== $bo) {
                    return $ao <=> $bo;
                }

                return strcmp((string) ($a->{'pe_ro_gr-id'} ?? ''), (string) ($b->{'pe_ro_gr-id'} ?? ''));
            });

            $titular = null;
            $lider = null;
            $suplente = null;
            foreach ($items as $d) {
                $tipo = strtoupper(trim((string) ($d->{'pe_ro_gr-tipo_asignacion'} ?? '')));
                if ($tipo !== '' && $tipo !== 'TITULAR' && $tipo !== 'SUPLENTE' && $tipo !== 'LIDER') {
                    continue;
                }
                $perId = trim((string) ($d->{'pe_ro_gr-per_id-fk'} ?? ''));
                if ($perId === '') {
                    continue;
                }
                if ($tipo === 'TITULAR' && $titular === null) {
                    $titular = ['per_id' => $perId, 'gr_op_id' => $grId !== '' ? $grId : null, 'tipo_asignacion' => 'TITULAR'];
                    continue;
                }
                if ($tipo === 'LIDER' && $lider === null) {
                    $lider = ['per_id' => $perId, 'gr_op_id' => $grId !== '' ? $grId : null, 'tipo_asignacion' => 'LIDER'];
                    continue;
                }
                if (($tipo === 'SUPLENTE' || $tipo === '') && $suplente === null) {
                    $suplente = ['per_id' => $perId, 'gr_op_id' => $grId !== '' ? $grId : null, 'tipo_asignacion' => 'SUPLENTE'];
                }
            }

            $selected = $titular ?? $lider ?? $suplente;
            if ($selected !== null) {
                $recipients[] = $selected;
            }
        }

        $unique = [];
        foreach ($recipients as $recipient) {
            $perId = trim((string) ($recipient['per_id'] ?? ''));
            $grOp = trim((string) ($recipient['gr_op_id'] ?? ''));
            $tipo = strtoupper(trim((string) ($recipient['tipo_asignacion'] ?? 'SUPLENTE')));
            if ($perId === '') {
                continue;
            }
            if ($tipo !== 'TITULAR' && $tipo !== 'LIDER') {
                $tipo = 'SUPLENTE';
            }
            $key = $perId.'|'.$grOp.'|'.$tipo;
            $unique[$key] = [
                'per_id' => $perId,
                'gr_op_id' => $grOp !== '' ? $grOp : null,
                'tipo_asignacion' => $tipo,
            ];
        }

        return array_values($unique);
    }

    private function getActionSets(string $tenantId, string $riesgoId, string $nivelAlertaId): array
    {
        $targetLevels = [$nivelAlertaId];
        
        // Find siblings
        if (Schema::hasTable('nivel_alerta_cat')) {
            $currentLevel = DB::table('nivel_alerta_cat')
                 ->when(Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'), fn($q) => $q->where(function($qq) use ($tenantId) {
                     $qq->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                 }))
                ->where('ni_al-id', $nivelAlertaId)
                ->first();
                
            $emId = $currentLevel?->{'ni_al-ni_em_id-fk'} ?? null;
            if ($emId) {
                $siblings = DB::table('nivel_alerta_cat')
                    ->when(Schema::hasColumn('nivel_alerta_cat', 'ni_al-tenant_id'), fn($q) => $q->where(function($qq) use ($tenantId) {
                         $qq->whereNull('ni_al-tenant_id')->orWhere('ni_al-tenant_id', $tenantId);
                     }))
                    ->where('ni_al-ni_em_id-fk', $emId)
                    ->where('ni_al-id', '<>', $nivelAlertaId)
                    ->pluck('ni_al-id')
                    ->toArray();
                // Add siblings after current level
                $targetLevels = array_merge($targetLevels, $siblings);
            }
        }
        
        $actionSetIds = [];

        // 1. Riesgo Config
        if (Schema::hasTable('riesgo_nivel_accion_set_cfg')) {
            $mapping = DB::table('riesgo_nivel_accion_set_cfg')
                ->when(Schema::hasColumn('riesgo_nivel_accion_set_cfg', 'ri_ni_ac_se-tenant_id'), fn($q) => $q->where('ri_ni_ac_se-tenant_id', $tenantId))
                ->where('ri_ni_ac_se-rie_id-fk', $riesgoId)
                ->whereIn('ri_ni_ac_se-ni_al_id-fk', $targetLevels)
                ->whereRaw("UPPER(COALESCE(`ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                ->orderByRaw("CAST(COALESCE(`ri_ni_ac_se-prioridad`, '999') AS UNSIGNED) ASC")
                ->get();
            
            foreach ($targetLevels as $lvl) {
                $foundForLevel = false;
                foreach ($mapping as $row) {
                    if (($row->{'ri_ni_ac_se-ni_al_id-fk'} ?? '') == $lvl) {
                        $id = trim((string) ($row->{'ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                        if ($id !== '') {
                            $actionSetIds[] = $id;
                            $foundForLevel = true;
                        }
                    }
                }
                if ($foundForLevel) return $actionSetIds; // Found match for highest priority level
            }
        }

        // 2. Tipo Riesgo Config
        if (Schema::hasTable('riesgo_cat') && Schema::hasTable('tipo_riesgo_nivel_accion_set_cfg')) {
             $riesgo = DB::table('riesgo_cat')
                ->when(
                    Schema::hasColumn('riesgo_cat', 'rie-tenant_id'),
                    static fn ($q) => $q->where('rie-tenant_id', $tenantId),
                )
                ->where('rie-id', $riesgoId)
                ->first();
            $tipoRiesgoId = trim((string) ($riesgo?->{'rie-ti_ri_id-fk'} ?? ''));

            if ($tipoRiesgoId !== '') {
                $mappingTipo = DB::table('tipo_riesgo_nivel_accion_set_cfg')
                    ->when(
                        Schema::hasColumn('tipo_riesgo_nivel_accion_set_cfg', 'ti_ri_ni_ac_se-tenant_id'),
                        static fn ($q) => $q->where('ti_ri_ni_ac_se-tenant_id', $tenantId),
                    )
                    ->where('ti_ri_ni_ac_se-ti_ri_id-fk', $tipoRiesgoId)
                    ->whereIn('ti_ri_ni_ac_se-ni_al_id-fk', $targetLevels)
                    ->whereRaw("UPPER(COALESCE(`ti_ri_ni_ac_se-activo`, 'SI')) <> 'NO'")
                    ->orderByRaw("CAST(COALESCE(`ti_ri_ni_ac_se-orden`, '999') AS UNSIGNED) ASC")
                    ->get();

                foreach ($targetLevels as $lvl) {
                    $foundForLevel = false;
                    foreach ($mappingTipo as $row) {
                        if (($row->{'ti_ri_ni_ac_se-ni_al_id-fk'} ?? '') == $lvl) {
                            $id = trim((string) ($row->{'ti_ri_ni_ac_se-ac_se_id-fk'} ?? ''));
                            if ($id !== '') {
                                $actionSetIds[] = $id;
                                $foundForLevel = true;
                            }
                        }
                    }
                    if ($foundForLevel) return $actionSetIds;
                }
            }
        }
        
        return $actionSetIds;
    }

    private function tenantNowDateTime(string $tenantId): string
    {
        return $this->tenantNow($tenantId)->toDateTimeString();
    }

    private function tenantNowDate(string $tenantId): string
    {
        return $this->tenantNow($tenantId)->toDateString();
    }

    private function tenantNowTime(string $tenantId): string
    {
        return $this->tenantNow($tenantId)->toTimeString();
    }

    private function tenantNow(string $tenantId): Carbon
    {
        if (! isset($this->tenantTimezoneCache[$tenantId])) {
            $timezone = 'Europe/Madrid';
            if (Schema::hasTable('tenants')) {
                $tenant = DB::table('tenants')->where('tenant_id', $tenantId)->first();
                $candidate = trim((string) ($tenant?->timezone ?? ''));
                if ($candidate !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
                    $timezone = $candidate;
                }
            }
            $this->tenantTimezoneCache[$tenantId] = $timezone;
        }

        return Carbon::now($this->tenantTimezoneCache[$tenantId]);
    }
}
