<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTenantUserRequest;
use App\Http\Requests\Api\V1\UpdateTenantUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LanguageService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TenantUsersController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LanguageService $languageService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));
        $page = max(1, (int) $request->query('page', 1));
        $q = trim((string) $request->query('q', ''));
        $perfilFilter = strtolower(trim((string) $request->query('perfil', '')));
        $estadoFilter = strtolower(trim((string) $request->query('estado', '')));
        $hasIsActive = Schema::hasColumn('users', 'is_active');
        $hasPersonaId = Schema::hasColumn('users', 'persona_id');

        $query = User::query()->where('tenant_id', $tenantId);
        $currentUser = $request->user();
        if ($this->isTenantAdmin($currentUser?->perfil)) {
            $query->where('perfil', '<>', 'admin');
        }

        if ($q !== '') {
            $query->where(static function ($inner) use ($q) {
                $inner->where('email', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%');
            });
        }
        if ($perfilFilter !== '') {
            $query->where('perfil', $perfilFilter);
        }
        if ($hasIsActive && $estadoFilter !== '') {
            if (in_array($estadoFilter, ['activo', 'si', '1', 'true'], true)) {
                $query->where('is_active', true);
            } elseif (in_array($estadoFilter, ['inactivo', 'no', '0', 'false'], true)) {
                $query->where('is_active', false);
            }
        }

        $total = (clone $query)->count();

        $columns = ['id', 'name', 'email', 'tenant_id', 'language', 'perfil', 'created_at', 'updated_at'];
        if ($hasIsActive) {
            $columns[] = 'is_active';
        }
        if ($hasPersonaId) {
            $columns[] = 'persona_id';
        }

        $users = $query
            ->orderBy('email')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get($columns);

        $personaMap = $this->loadPersonaMap($tenantId, $users);
        $cargoMap = $this->loadCargoMap($tenantId, $users);
        $data = $users->map(function (User $u) use ($hasIsActive, $hasPersonaId, $personaMap, $cargoMap) {
            $row = $u->only(['id', 'name', 'email', 'tenant_id', 'language', 'perfil', 'created_at', 'updated_at']);
            $personaId = $hasPersonaId ? trim((string) ($u->persona_id ?? '')) : '';
            $persona = $personaId !== '' ? ($personaMap[$personaId] ?? null) : null;
            $row['persona_id'] = $personaId !== '' ? $personaId : null;
            $row['persona_nombre'] = $persona['nombre_completo'] ?? null;
            $row['cargo'] = $personaId !== '' ? ($cargoMap[$personaId] ?? null) : null;
            $row['is_active'] = $hasIsActive ? (bool) ($u->is_active ?? true) : true;

            return $row;
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function store(StoreTenantUserRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $data = $request->validated();
        $personaId = trim((string) ($data['persona_id'] ?? ''));
        if ($personaId === '') {
            return response()->json(['message' => 'persona_id is required.'], 422);
        }
        $persona = $this->findPersona($tenantId, $personaId);
        if ($persona === null) {
            return response()->json(['message' => 'Persona not found for tenant.'], 422);
        }
        $personaEmail = strtolower(trim((string) ($persona->{'per-email'} ?? '')));
        if ($personaEmail === '') {
            return response()->json(['message' => 'Selected person has no email.'], 422);
        }
        if (User::query()->where('email', $personaEmail)->exists()) {
            return response()->json(['message' => 'Email already exists.'], 422);
        }
        $personaName = $this->composePersonaName($persona);
        $language = array_key_exists('language', $data) && is_string($data['language'])
            ? strtolower(trim((string) $data['language']))
            : null;
        $currentUser = $request->user();
        $perfil = strtolower(trim((string) ($data['perfil'] ?? '')));
        if ($this->isTenantAdmin($currentUser?->perfil) && $perfil === 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($perfil === 'auditor' && ! $this->isSuperAdmin($currentUser?->perfil)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (is_string($language) && $language !== '' && ! $this->languageService->isEnabledForTenant($tenantId, $language)) {
            return response()->json(['message' => __('messages.user.language_not_enabled')], 422);
        }

        $payload = [
            'tenant_id' => $tenantId,
            'name' => $personaName !== '' ? $personaName : $personaEmail,
            'email' => $personaEmail,
            'password' => Hash::make((string) $data['password']),
            'language' => $language ?: null,
            'perfil' => $perfil,
        ];
        if (Schema::hasColumn('users', 'persona_id')) {
            $payload['persona_id'] = $personaId;
        }
        if (Schema::hasColumn('users', 'is_active')) {
            $payload['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        }

        $user = User::query()->create($payload);

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'user_created',
            'module' => 'users',
            'entity_id' => (string) $user->id,
            'entity_type' => 'User',
            'new_value' => $user->only(['id', 'name', 'email', 'tenant_id', 'language', 'perfil']),
        ]);

        return response()->json([
            'message' => 'Created.',
            'data' => $user->only(['id', 'name', 'email', 'tenant_id', 'language', 'perfil', 'persona_id', 'is_active']),
        ], 201);
    }

    public function update(UpdateTenantUserRequest $request, int $userId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $user = User::query()->where('tenant_id', $tenantId)->where('id', $userId)->first();
        if ($user === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $data = $request->validated();
        $currentUser = $request->user();
        $nextPerfil = array_key_exists('perfil', $data) ? strtolower(trim((string) ($data['perfil'] ?? ''))) : null;
        if ($this->isTenantAdmin($currentUser?->perfil) && $user->perfil === 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($this->isTenantAdmin($currentUser?->perfil) && $nextPerfil === 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($nextPerfil === 'auditor' && ! $this->isSuperAdmin($currentUser?->perfil)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $before = $user->only(['id', 'name', 'email', 'tenant_id', 'language', 'perfil', 'persona_id', 'is_active']);

        if (array_key_exists('language', $data)) {
            $language = is_string($data['language'])
                ? strtolower(trim((string) $data['language']))
                : $data['language'];
            if (is_string($language) && $language !== '' && ! $this->languageService->isEnabledForTenant($tenantId, $language)) {
                return response()->json(['message' => __('messages.user.language_not_enabled')], 422);
            }
            $user->language = $language ?: null;
        }

        if (array_key_exists('name', $data)) {
            $user->name = (string) $data['name'];
        }
        if (array_key_exists('persona_id', $data)) {
            $personaId = trim((string) ($data['persona_id'] ?? ''));
            if ($personaId === '') {
                return response()->json(['message' => 'persona_id cannot be empty.'], 422);
            }
            $persona = $this->findPersona($tenantId, $personaId);
            if ($persona === null) {
                return response()->json(['message' => 'Persona not found for tenant.'], 422);
            }
            $personaEmail = strtolower(trim((string) ($persona->{'per-email'} ?? '')));
            if ($personaEmail === '') {
                return response()->json(['message' => 'Selected person has no email.'], 422);
            }
            $emailUsedByAnother = User::query()
                ->where('email', $personaEmail)
                ->where('id', '<>', $user->id)
                ->exists();
            if ($emailUsedByAnother) {
                return response()->json(['message' => 'Email already exists.'], 422);
            }
            $user->email = $personaEmail;
            $user->name = $this->composePersonaName($persona) ?: $personaEmail;
            if (Schema::hasColumn('users', 'persona_id')) {
                $user->persona_id = $personaId;
            }
        }

        if (array_key_exists('password', $data) && is_string($data['password']) && $data['password'] !== '') {
            $user->password = Hash::make($data['password']);
        }

        if (array_key_exists('perfil', $data)) {
            $user->perfil = $nextPerfil ?: null;
        }
        if (array_key_exists('is_active', $data) && Schema::hasColumn('users', 'is_active')) {
            $user->is_active = (bool) $data['is_active'];
        }

        $user->save();

        $this->auditLogger->logFromRequest($request, [
            'event_type' => $before['perfil'] !== $user->perfil ? 'user_profile_changed' : 'user_updated',
            'module' => 'users',
            'entity_id' => (string) $user->id,
            'entity_type' => 'User',
            'previous_value' => $before,
            'new_value' => $user->only(['id', 'name', 'email', 'tenant_id', 'language', 'perfil', 'persona_id', 'is_active']),
        ]);

        return response()->json([
            'message' => 'OK',
            'data' => $user->only(['id', 'name', 'email', 'tenant_id', 'language', 'perfil', 'persona_id', 'is_active']),
        ]);
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $target = User::query()->where('tenant_id', $tenantId)->where('id', $userId)->first();
        if ($target === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $currentUser = $request->user();
        if ($this->isTenantAdmin($currentUser?->perfil) && $target->perfil === 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $deleted = User::query()->where('tenant_id', $tenantId)->where('id', $userId)->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'user_deleted',
            'module' => 'users',
            'entity_id' => (string) $userId,
            'entity_type' => 'User',
            'previous_value' => $target->only(['id', 'name', 'email', 'tenant_id', 'language', 'perfil']),
        ]);

        return response()->json(['message' => 'Deleted.']);
    }

    private function isSuperAdmin(null|string $perfil): bool
    {
        return strtolower(trim((string) $perfil)) === 'admin';
    }

    private function isTenantAdmin(null|string $perfil): bool
    {
        return strtolower(trim((string) $perfil)) === 'tenant_admin';
    }

    private function findPersona(string $tenantId, string $personaId): ?object
    {
        if (! Schema::hasTable('persona_mst')) {
            return null;
        }

        $query = DB::table('persona_mst')->where('per-id', $personaId);
        if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
            $query->where('per-tenant_id', $tenantId);
        }

        return $query->first();
    }

    private function composePersonaName(object $persona): string
    {
        return trim(implode(' ', array_filter([
            trim((string) ($persona->{'per-nombre'} ?? '')),
            trim((string) ($persona->{'per-apellido_1'} ?? '')),
            trim((string) ($persona->{'per-apellido_2'} ?? '')),
        ])));
    }

    private function loadPersonaMap(string $tenantId, Collection $users): array
    {
        if (! Schema::hasColumn('users', 'persona_id') || ! Schema::hasTable('persona_mst')) {
            return [];
        }
        $personaIds = $users->pluck('persona_id')->filter(static fn ($id) => trim((string) $id) !== '')->map(static fn ($id) => trim((string) $id))->unique()->values();
        if ($personaIds->isEmpty()) {
            return [];
        }

        $query = DB::table('persona_mst')->whereIn('per-id', $personaIds->all());
        if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
            $query->where('per-tenant_id', $tenantId);
        }
        $rows = $query->get(['per-id', 'per-nombre', 'per-apellido_1', 'per-apellido_2', 'per-email']);

        $out = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row->{'per-id'} ?? ''));
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'email' => trim((string) ($row->{'per-email'} ?? '')),
                'nombre_completo' => $this->composePersonaName($row),
            ];
        }

        return $out;
    }

    private function loadCargoMap(string $tenantId, Collection $users): array
    {
        if (! Schema::hasColumn('users', 'persona_id') || ! Schema::hasTable('persona_rol_cfg') || ! Schema::hasTable('rol_cat')) {
            return [];
        }
        $personaIds = $users->pluck('persona_id')->filter(static fn ($id) => trim((string) $id) !== '')->map(static fn ($id) => trim((string) $id))->unique()->values();
        if ($personaIds->isEmpty()) {
            return [];
        }

        $roleNameById = DB::table('rol_cat')->pluck('rol-nombre', 'rol-id')->all();
        $rows = DB::table('persona_rol_cfg')
            ->whereIn('pe_ro-per_id-fk', $personaIds->all())
            ->where('pe_ro-tenant_id', $tenantId)
            ->orderBy('pe_ro-per_id-fk')
            ->get(['pe_ro-per_id-fk', 'pe_ro-rol_id-fk', 'pe_ro-activo']);

        $out = [];
        foreach ($rows as $row) {
            $pid = trim((string) ($row->{'pe_ro-per_id-fk'} ?? ''));
            if ($pid === '' || isset($out[$pid])) {
                continue;
            }
            $activeRaw = strtolower(trim((string) ($row->{'pe_ro-activo'} ?? 'si')));
            if (in_array($activeRaw, ['no', 'n', '0', 'false'], true)) {
                continue;
            }
            $rid = trim((string) ($row->{'pe_ro-rol_id-fk'} ?? ''));
            if ($rid === '') {
                continue;
            }
            $out[$pid] = trim((string) ($roleNameById[$rid] ?? $rid));
        }

        return $out;
    }
}
