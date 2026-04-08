<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantPersonnelController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }
        if (! Schema::hasTable('persona_mst')) {
            return response()->json(['message' => 'persona_mst table not found.'], 422);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(200, (int) $request->query('per_page', 25)));
        $q = trim((string) $request->query('q', ''));
        $cargo = trim((string) $request->query('cargo', ''));
        $estado = strtolower(trim((string) $request->query('estado', '')));
        $sortBy = strtolower(trim((string) $request->query('sort_by', 'nombre')));
        $sortDir = strtolower(trim((string) $request->query('sort_dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        $query = DB::table('persona_mst');
        if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
            $query->where('per-tenant_id', $tenantId);
        }
        if ($q !== '') {
            $query->where(static function ($inner) use ($q) {
                $inner->where('per-id', 'like', '%'.$q.'%')
                    ->orWhere('per-nombre', 'like', '%'.$q.'%')
                    ->orWhere('per-apellido_1', 'like', '%'.$q.'%')
                    ->orWhere('per-apellido_2', 'like', '%'.$q.'%')
                    ->orWhere('per-email', 'like', '%'.$q.'%')
                    ->orWhere('per-tel_mov', 'like', '%'.$q.'%');
            });
        }

        $rows = $query->get([
            'per-id',
            'per-nombre',
            'per-apellido_1',
            'per-apellido_2',
            'per-email',
            'per-tel_mov',
            'per-activo',
        ]);

        $personIds = $rows->pluck('per-id')->filter()->map(static fn ($v) => trim((string) $v))->values()->all();
        $rolesByPerson = $this->rolesByPerson($tenantId, $personIds);
        $linkedUsersByPerson = $this->linkedUsersByPerson($tenantId, $personIds);

        $mapped = $rows->map(function ($row) use ($rolesByPerson, $linkedUsersByPerson) {
            $personId = trim((string) ($row->{'per-id'} ?? ''));
            $firstName = trim((string) ($row->{'per-nombre'} ?? ''));
            $lastName1 = trim((string) ($row->{'per-apellido_1'} ?? ''));
            $lastName2 = trim((string) ($row->{'per-apellido_2'} ?? ''));
            $fullName = trim(implode(' ', array_filter([$firstName, $lastName1, $lastName2])));
            $statusRaw = strtolower(trim((string) ($row->{'per-activo'} ?? 'si')));
            $active = ! in_array($statusRaw, ['no', 'n', '0', 'false'], true);
            $linked = $linkedUsersByPerson[$personId] ?? null;

            return [
                'id' => $personId,
                'nombre' => $firstName,
                'apellido_1' => $lastName1,
                'apellido_2' => $lastName2,
                'nombre_completo' => $fullName,
                'email' => trim((string) ($row->{'per-email'} ?? '')),
                'telefono' => trim((string) ($row->{'per-tel_mov'} ?? '')),
                'estado' => $active ? 'ACTIVO' : 'INACTIVO',
                'cargo' => $rolesByPerson[$personId] ?? null,
                'has_user' => $linked !== null,
                'has_active_user' => (bool) ($linked['is_active'] ?? false),
                'linked_user' => $linked,
            ];
        })->values();

        $filtered = $mapped->filter(function (array $row) use ($cargo, $estado) {
            if ($cargo !== '' && strtolower(trim((string) ($row['cargo'] ?? ''))) !== strtolower($cargo)) {
                return false;
            }
            if ($estado === 'activo' && $row['estado'] !== 'ACTIVO') {
                return false;
            }
            if ($estado === 'inactivo' && $row['estado'] !== 'INACTIVO') {
                return false;
            }

            return true;
        })->values();

        $sortMap = [
            'nombre' => 'nombre_completo',
            'cargo' => 'cargo',
            'estado' => 'estado',
            'email' => 'email',
        ];
        $sortKey = $sortMap[$sortBy] ?? 'nombre_completo';
        $sorted = $filtered->sortBy(static fn (array $row) => strtolower((string) ($row[$sortKey] ?? '')), SORT_NATURAL, $sortDir === 'desc')->values();

        $total = $sorted->count();
        $pageItems = $sorted->slice(($page - 1) * $perPage, $perPage)->values();
        $cargoOptions = $mapped->pluck('cargo')->filter(static fn ($v) => trim((string) $v) !== '')->unique()->values();
        $roleOptions = $this->roleOptions();

        return response()->json([
            'data' => $pageItems,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'filters' => [
                'cargo_options' => $cargoOptions,
                'role_options' => $roleOptions,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }
        $data = $request->validate([
            'nombre' => ['required', 'string', 'min:1'],
            'apellido_1' => ['required', 'string', 'min:1'],
            'apellido_2' => ['nullable', 'string'],
            'correo' => ['required', 'email'],
            'telefono' => ['nullable', 'string'],
            'estado' => ['nullable', 'in:ACTIVO,INACTIVO'],
            'cargo_id' => ['nullable', 'string', 'min:1'],
        ]);

        $exists = DB::table('persona_mst')
            ->where('per-email', strtolower(trim((string) $data['correo'])))
            ->where('per-tenant_id', $tenantId)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Email already exists in personnel.'], 422);
        }

        $newId = $this->nextPersonaId($tenantId);
        $payload = [
            'per-id' => $newId,
            'per-tenant_id' => $tenantId,
            'per-nombre' => trim((string) $data['nombre']),
            'per-apellido_1' => trim((string) $data['apellido_1']),
            'per-apellido_2' => trim((string) ($data['apellido_2'] ?? '')),
            'per-email' => strtolower(trim((string) $data['correo'])),
            'per-tel_mov' => trim((string) ($data['telefono'] ?? '')),
            'per-activo' => ($data['estado'] ?? 'ACTIVO') === 'INACTIVO' ? 'NO' : 'SI',
        ];
        if (Schema::hasColumn('persona_mst', 'per-nom_data_orig')) {
            $payload['per-nom_data_orig'] = $this->composeFullName(
                (string) $payload['per-nombre'],
                (string) $payload['per-apellido_1'],
                (string) $payload['per-apellido_2'],
            );
        }
        $correlColumn = $this->personaCorrelColumn();
        if ($correlColumn !== null) {
            $payload[$correlColumn] = $this->nextPersonaCorrel($tenantId, $correlColumn);
        }
        DB::table('persona_mst')->insert($payload);
        if (array_key_exists('cargo_id', $data) && trim((string) ($data['cargo_id'] ?? '')) !== '') {
            $this->upsertPersonaRole($tenantId, $newId, trim((string) $data['cargo_id']));
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'personnel_created',
            'module' => 'admin.personnel',
            'entity_id' => $newId,
            'entity_type' => 'Persona',
            'new_value' => $payload,
        ]);

        return response()->json(['message' => 'Created.', 'id' => $newId], 201);
    }

    public function update(Request $request, string $personId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'min:1'],
            'apellido_1' => ['sometimes', 'string', 'min:1'],
            'apellido_2' => ['sometimes', 'nullable', 'string'],
            'correo' => ['sometimes', 'email'],
            'telefono' => ['sometimes', 'nullable', 'string'],
            'estado' => ['sometimes', 'in:ACTIVO,INACTIVO'],
            'cargo_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $query = DB::table('persona_mst')->where('per-id', $personId);
        if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
            $query->where('per-tenant_id', $tenantId);
        }
        $before = $query->first();
        if ($before === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $payload = [];
        if (array_key_exists('nombre', $data)) {
            $payload['per-nombre'] = trim((string) $data['nombre']);
        }
        if (array_key_exists('apellido_1', $data)) {
            $payload['per-apellido_1'] = trim((string) $data['apellido_1']);
        }
        if (array_key_exists('apellido_2', $data)) {
            $payload['per-apellido_2'] = trim((string) ($data['apellido_2'] ?? ''));
        }
        if (array_key_exists('correo', $data)) {
            $newEmail = strtolower(trim((string) $data['correo']));
            $emailTaken = DB::table('persona_mst')
                ->where('per-email', $newEmail)
                ->where('per-id', '<>', $personId)
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->where('per-tenant_id', $tenantId),
                )
                ->exists();
            if ($emailTaken) {
                return response()->json(['message' => 'Email already exists in personnel.'], 422);
            }
            $payload['per-email'] = $newEmail;
        }
        if (array_key_exists('telefono', $data)) {
            $payload['per-tel_mov'] = trim((string) ($data['telefono'] ?? ''));
        }
        if (array_key_exists('estado', $data)) {
            $payload['per-activo'] = $data['estado'] === 'INACTIVO' ? 'NO' : 'SI';
        }
        if (
            Schema::hasColumn('persona_mst', 'per-nom_data_orig')
            && (
                array_key_exists('per-nombre', $payload)
                || array_key_exists('per-apellido_1', $payload)
                || array_key_exists('per-apellido_2', $payload)
            )
        ) {
            $payload['per-nom_data_orig'] = $this->composeFullName(
                array_key_exists('per-nombre', $payload) ? (string) $payload['per-nombre'] : (string) ($before->{'per-nombre'} ?? ''),
                array_key_exists('per-apellido_1', $payload) ? (string) $payload['per-apellido_1'] : (string) ($before->{'per-apellido_1'} ?? ''),
                array_key_exists('per-apellido_2', $payload) ? (string) $payload['per-apellido_2'] : (string) ($before->{'per-apellido_2'} ?? ''),
            );
        }

        if ($payload !== []) {
            DB::table('persona_mst')
                ->where('per-id', $personId)
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->where('per-tenant_id', $tenantId),
                )
                ->update($payload);
        }

        if (array_key_exists('per-email', $payload) && Schema::hasColumn('users', 'persona_id')) {
            DB::table('users')
                ->where('tenant_id', $tenantId)
                ->where('persona_id', $personId)
                ->update(['email' => $payload['per-email']]);
        }
        if (array_key_exists('cargo_id', $data)) {
            $cargoId = trim((string) ($data['cargo_id'] ?? ''));
            if ($cargoId === '') {
                DB::table('persona_rol_cfg')
                    ->where('pe_ro-tenant_id', $tenantId)
                    ->where('pe_ro-per_id-fk', $personId)
                    ->update(['pe_ro-activo' => 'NO']);
            } else {
                $this->upsertPersonaRole($tenantId, $personId, $cargoId);
            }
        }

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'personnel_updated',
            'module' => 'admin.personnel',
            'entity_id' => $personId,
            'entity_type' => 'Persona',
            'previous_value' => (array) $before,
            'new_value' => $payload,
        ]);

        return response()->json(['message' => 'OK']);
    }

    public function destroy(Request $request, string $personId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOL);
        $query = DB::table('persona_mst')->where('per-id', $personId);
        if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
            $query->where('per-tenant_id', $tenantId);
        }
        $before = $query->first();
        if ($before === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $linkedActive = $this->linkedUsersByPerson($tenantId, [$personId])[$personId] ?? null;
        if ($linkedActive !== null && (bool) ($linkedActive['is_active'] ?? false) && ! $force) {
            return response()->json(['message' => 'Person has an active linked user.', 'requires_confirmation' => true], 409);
        }

        if (Schema::hasColumn('users', 'persona_id')) {
            $updates = ['persona_id' => null];
            if (Schema::hasColumn('users', 'is_active')) {
                $updates['is_active'] = false;
            }
            DB::table('users')
                ->where('tenant_id', $tenantId)
                ->where('persona_id', $personId)
                ->update($updates);
        }

        DB::table('persona_mst')
            ->where('per-id', $personId)
            ->when(
                Schema::hasColumn('persona_mst', 'per-tenant_id'),
                static fn ($q) => $q->where('per-tenant_id', $tenantId),
            )
            ->delete();

        $this->auditLogger->logFromRequest($request, [
            'event_type' => 'personnel_deleted',
            'module' => 'admin.personnel',
            'entity_id' => $personId,
            'entity_type' => 'Persona',
            'previous_value' => (array) $before,
        ]);

        return response()->json(['message' => 'Deleted.']);
    }

    private function rolesByPerson(string $tenantId, array $personIds): array
    {
        if ($personIds === [] || ! Schema::hasTable('persona_rol_cfg') || ! Schema::hasTable('rol_cat')) {
            return [];
        }
        $roleNames = DB::table('rol_cat')->pluck('rol-nombre', 'rol-id')->all();
        $rows = DB::table('persona_rol_cfg')
            ->whereIn('pe_ro-per_id-fk', $personIds)
            ->where('pe_ro-tenant_id', $tenantId)
            ->orderBy('pe_ro-per_id-fk')
            ->get(['pe_ro-per_id-fk', 'pe_ro-rol_id-fk', 'pe_ro-activo']);
        $out = [];
        foreach ($rows as $row) {
            $personId = trim((string) ($row->{'pe_ro-per_id-fk'} ?? ''));
            if ($personId === '' || isset($out[$personId])) {
                continue;
            }
            $activeRaw = strtolower(trim((string) ($row->{'pe_ro-activo'} ?? 'si')));
            if (in_array($activeRaw, ['no', 'n', '0', 'false'], true)) {
                continue;
            }
            $roleId = trim((string) ($row->{'pe_ro-rol_id-fk'} ?? ''));
            if ($roleId === '') {
                continue;
            }
            $out[$personId] = trim((string) ($roleNames[$roleId] ?? $roleId));
        }

        return $out;
    }

    private function linkedUsersByPerson(string $tenantId, array $personIds): array
    {
        if ($personIds === [] || ! Schema::hasColumn('users', 'persona_id')) {
            return [];
        }
        $columns = ['id', 'name', 'email', 'persona_id'];
        $hasIsActive = Schema::hasColumn('users', 'is_active');
        if ($hasIsActive) {
            $columns[] = 'is_active';
        }
        $rows = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereIn('persona_id', $personIds)
            ->get($columns);
        $out = [];
        foreach ($rows as $row) {
            $personId = trim((string) ($row->persona_id ?? ''));
            if ($personId === '' || isset($out[$personId])) {
                continue;
            }
            $out[$personId] = [
                'id' => (int) ($row->id ?? 0),
                'name' => trim((string) ($row->name ?? '')),
                'email' => trim((string) ($row->email ?? '')),
                'is_active' => $hasIsActive ? (bool) ($row->is_active ?? false) : true,
            ];
        }

        return $out;
    }

    private function nextPersonaId(string $tenantId): string
    {
        $query = DB::table('persona_mst')->select(['per-id']);
        if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
            $query->where('per-tenant_id', $tenantId);
        }
        $existing = $query->pluck('per-id')->all();
        $max = 0;
        foreach ($existing as $value) {
            $id = strtoupper(trim((string) $value));
            if ($id === '') {
                continue;
            }
            if (preg_match('/^PER(\d+)/', $id, $m) === 1) {
                $n = (int) ($m[1] ?? 0);
                if ($n > $max) {
                    $max = $n;
                }
            }
        }

        return 'PER'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }

    private function nextPersonaCorrel(string $tenantId, string $column): int
    {
        $query = DB::table('persona_mst')->select([$column]);
        if (Schema::hasColumn('persona_mst', 'per-tenant_id')) {
            $query->where('per-tenant_id', $tenantId);
        }
        $existing = $query->pluck($column)->all();
        $max = 0;
        foreach ($existing as $value) {
            $num = (int) trim((string) $value);
            if ($num > $max) {
                $max = $num;
            }
        }

        return $max + 1;
    }

    private function personaCorrelColumn(): ?string
    {
        if (Schema::hasColumn('persona_mst', 'per-correl')) {
            return 'per-correl';
        }
        if (Schema::hasColumn('persona_mst', 'per-rel')) {
            return 'per-rel';
        }

        return null;
    }

    private function composeFullName(string $nombre, string $apellido1, string $apellido2): string
    {
        return trim(implode(' ', array_filter([
            trim($nombre),
            trim($apellido1),
            trim($apellido2),
        ])));
    }

    private function roleOptions(): array
    {
        if (! Schema::hasTable('rol_cat')) {
            return [];
        }
        $select = ['rol-id', 'rol-nombre'];
        $hasActiveColumn = Schema::hasColumn('rol_cat', 'rol-activo');
        if ($hasActiveColumn) {
            $select[] = 'rol-activo';
        }
        $rows = DB::table('rol_cat')
            ->orderBy('rol-nombre')
            ->get($select);

        $out = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row->{'rol-id'} ?? ''));
            $name = trim((string) ($row->{'rol-nombre'} ?? ''));
            if ($hasActiveColumn) {
                $activeRaw = strtoupper(trim((string) ($row->{'rol-activo'} ?? 'SI')));
                if (in_array($activeRaw, ['NO', 'N', '0', 'FALSE'], true)) {
                    continue;
                }
            }
            if ($id === '' || $name === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name];
        }

        return $out;
    }

    private function upsertPersonaRole(string $tenantId, string $personId, string $cargoId): void
    {
        if (! Schema::hasTable('persona_rol_cfg')) {
            return;
        }
        $existing = DB::table('persona_rol_cfg')
            ->where('pe_ro-tenant_id', $tenantId)
            ->where('pe_ro-per_id-fk', $personId)
            ->orderBy('pe_ro-id')
            ->first(['pe_ro-id']);
        if ($existing !== null) {
            DB::table('persona_rol_cfg')
                ->where('pe_ro-id', $existing->{'pe_ro-id'})
                ->update([
                    'pe_ro-rol_id-fk' => $cargoId,
                    'pe_ro-activo' => 'SI',
                ]);

            return;
        }
        $newId = $this->nextPersonaRoleId($tenantId);
        DB::table('persona_rol_cfg')->insert([
            'pe_ro-id' => $newId,
            'pe_ro-tenant_id' => $tenantId,
            'pe_ro-per_id-fk' => $personId,
            'pe_ro-rol_id-fk' => $cargoId,
            'pe_ro-activo' => 'SI',
            'pe_ro-fech_ini' => null,
            'pe_ro-fech_fin' => null,
            'pe_ro-observ' => null,
        ]);
    }

    private function nextPersonaRoleId(string $tenantId): string
    {
        if (! Schema::hasTable('persona_rol_cfg')) {
            return 'PER_ROL01'.strtoupper($tenantId);
        }
        $existing = DB::table('persona_rol_cfg')
            ->where('pe_ro-tenant_id', $tenantId)
            ->pluck('pe_ro-id')
            ->all();
        $max = 0;
        foreach ($existing as $id) {
            $idUpper = strtoupper(trim((string) $id));
            if (preg_match('/^PER_ROL(\d{2})/', $idUpper, $m) !== 1) {
                continue;
            }
            $n = (int) ($m[1] ?? 0);
            if ($n > $max) {
                $max = $n;
            }
        }

        return 'PER_ROL'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT).strtoupper($tenantId);
    }
}
