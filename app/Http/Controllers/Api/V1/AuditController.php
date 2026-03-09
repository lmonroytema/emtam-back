<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $user = $request->user();
        $perfil = strtolower(trim((string) ($user?->perfil ?? '')));
        if ($perfil !== 'auditor' && $perfil !== 'admin' && $perfil !== 'director') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! Schema::hasTable('audit_log_trs')) {
            return response()->json(['message' => 'Missing audit_log_trs table.'], 422);
        }

        $query = DB::table('audit_log_trs')->where('tenant_id', $tenantId);

        $activationId = trim((string) ($request->query('activation_id') ?? ''));
        if ($activationId !== '') {
            $query->where('plan_id', $activationId);
        }

        $userId = trim((string) ($request->query('user_id') ?? ''));
        if ($userId !== '') {
            $query->where('user_id', $userId);
        }

        $eventType = trim((string) ($request->query('event_type') ?? ''));
        $eventTypes = trim((string) ($request->query('event_types') ?? ''));
        if ($eventTypes !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', $eventTypes))));
            if (count($list) > 0) {
                $query->whereIn('event_type', $list);
            }
        } elseif ($eventType !== '') {
            $query->where('event_type', $eventType);
        }

        $module = trim((string) ($request->query('module') ?? ''));
        if ($module !== '') {
            $query->where('module', $module);
        }

        $from = trim((string) ($request->query('date_from') ?? ''));
        if ($from !== '') {
            $query->where('created_at', '>=', $from);
        }

        $to = trim((string) ($request->query('date_to') ?? ''));
        if ($to !== '') {
            $query->where('created_at', '<=', $to);
        }

        $perPage = (int) ($request->query('per_page') ?? 50);
        if ($perPage <= 0) {
            $perPage = 50;
        }
        $page = (int) ($request->query('page') ?? 1);
        if ($page <= 0) {
            $page = 1;
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function responsibilities(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $user = $request->user();
        $perfil = strtolower(trim((string) ($user?->perfil ?? '')));
        if ($perfil !== 'auditor' && $perfil !== 'admin' && $perfil !== 'director') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! Schema::hasTable('audit_log_trs')) {
            return response()->json(['message' => 'Missing audit_log_trs table.'], 422);
        }

        $activationId = trim((string) ($request->query('activation_id') ?? ''));
        $query = DB::table('audit_log_trs')->where('tenant_id', $tenantId);
        if ($activationId !== '') {
            $query->where('plan_id', $activationId);
        }

        $rows = $query
            ->whereIn('event_type', ['action_status_changed', 'action_created', 'delegation_created', 'delegation_updated', 'delegation_auto'])
            ->select(['user_id', 'event_type'])
            ->get();

        $summary = [];
        foreach ($rows as $row) {
            $uid = (string) ($row->user_id ?? '');
            if ($uid === '') {
                continue;
            }
            $summary[$uid] ??= [
                'user_id' => $uid,
                'actions_created' => 0,
                'actions_updated' => 0,
                'delegations_created' => 0,
                'delegations_updated' => 0,
            ];
            if ($row->event_type === 'action_created') {
                $summary[$uid]['actions_created']++;
            } elseif ($row->event_type === 'action_status_changed') {
                $summary[$uid]['actions_updated']++;
            } elseif ($row->event_type === 'delegation_created') {
                $summary[$uid]['delegations_created']++;
            } elseif ($row->event_type === 'delegation_updated' || $row->event_type === 'delegation_auto') {
                $summary[$uid]['delegations_updated']++;
            }
        }

        return response()->json([
            'data' => array_values($summary),
        ]);
    }

    public function filters(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $user = $request->user();
        $perfil = strtolower(trim((string) ($user?->perfil ?? '')));
        if ($perfil !== 'auditor' && $perfil !== 'admin' && $perfil !== 'director') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $activations = [];
        if (Schema::hasTable('activacion_del_plan_trs')) {
            $activations = DB::table('activacion_del_plan_trs')
                ->when(
                    Schema::hasColumn('activacion_del_plan_trs', 'ac_de_pl-tenant_id'),
                    static fn ($q) => $q->where('ac_de_pl-tenant_id', $tenantId),
                )
                ->orderByDesc('ac_de_pl-fecha_activac')
                ->orderByDesc('ac_de_pl-hora_activac')
                ->get([
                    'ac_de_pl-id as id',
                    'ac_de_pl-fecha_activac as fecha',
                    'ac_de_pl-hora_activac as hora',
                    'ac_de_pl-estado as estado',
                    'ac_de_pl-rie_id-fk as riesgo_id',
                ]);
        }

        $users = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'perfil']);

        return response()->json([
            'activations' => $activations,
            'users' => $users,
        ]);
    }
}
