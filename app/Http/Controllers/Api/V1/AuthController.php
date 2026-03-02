<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->validated('email')));
        $password = (string) $request->validated('password');

        if (app()->environment('local') && Schema::hasTable('persona_mst')) {
            $personas = DB::table('persona_mst')
                ->when(
                    Schema::hasColumn('persona_mst', 'per-tenant_id'),
                    static fn ($q) => $q->whereNotNull('per-tenant_id'),
                )
                ->whereRaw("COALESCE(`per-email`, '') <> ''")
                ->get();

            foreach ($personas as $p) {
                $personaEmail = strtolower(trim((string) ($p->{'per-email'} ?? '')));
                if ($personaEmail === '') {
                    continue;
                }
                $exists = User::query()->where('email', $personaEmail)->exists();
                if ($exists) {
                    continue;
                }
                $nombre = trim(implode(' ', array_filter([
                    (string) ($p->{'per-nombre'} ?? ''),
                    (string) ($p->{'per-apellido_1'} ?? ''),
                    (string) ($p->{'per-apellido_2'} ?? ''),
                ])));
                $tenantId = (string) ($p->{'per-tenant_id'} ?? null);
                User::query()->create([
                    'name' => $nombre !== '' ? $nombre : $personaEmail,
                    'email' => $personaEmail,
                    'tenant_id' => $tenantId !== '' ? $tenantId : null,
                    'password' => Hash::make(env('TEST_USER_PASSWORD', 'password')),
                    'perfil' => 'recurso',
                ]);
            }
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return response()->json([
                'message' => __('messages.auth.invalid_credentials'),
            ], 422);
        }

        $tenantId = $user->tenant_id ?? $this->tenantContext->tenantId();
        $this->auditLogger->logForUser($user, $tenantId, $request->ip(), [
            'event_type' => 'user_login',
            'module' => 'auth',
            'entity_id' => (string) $user->id,
            'entity_type' => 'User',
        ]);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'language' => $user->language,
                'perfil' => $user->perfil,
            ],
        ]);
    }
}
