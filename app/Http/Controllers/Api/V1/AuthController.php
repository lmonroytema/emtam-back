<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $tenant = null;
        if ($tenantId !== null && Schema::hasTable('tenants')) {
            $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
        }
        $twoFactorEnabled = (bool) ($tenant?->two_factor_enabled ?? false);
        $perfil = strtolower(trim((string) ($user->perfil ?? '')));
        if ($perfil === 'admin' || ! $twoFactorEnabled) {
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
        if (! Schema::hasTable('login_two_factor_tokens')) {
            return response()->json(['message' => 'Missing login_two_factor_tokens table.'], 422);
        }

        $challengeId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        DB::table('login_two_factor_tokens')
            ->where('user_id', $user->id)
            ->delete();

        DB::table('login_two_factor_tokens')->insert([
            'id' => $challengeId,
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'token_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = $this->sendTwoFactorCode($user, $tenantId, $code, $challengeId);

        if (! $delivery['sent']) {
            DB::table('login_two_factor_tokens')->where('id', $challengeId)->delete();
            return response()->json(['message' => $delivery['message']], 422);
        }

        return response()->json([
            'two_factor_required' => true,
            'challenge_id' => $challengeId,
            'expires_in' => 600,
            'delivery' => [
                'channel' => 'email',
                'mode' => $delivery['mode'],
                'destination' => $delivery['destination'],
            ],
        ]);
    }

    public function activationCodeLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'activation_code' => ['required', 'string'],
        ]);

        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $activationId = trim((string) $data['activation_code']);
        if ($activationId === '') {
            return response()->json(['message' => 'Invalid activation code.'], 422);
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

        if (! Schema::hasTable('control_panel_access_trs')) {
            return response()->json(['message' => 'Missing control_panel_access_trs table.'], 422);
        }

        $safeTenant = preg_replace('/[^a-z0-9]+/', '-', strtolower($tenantId));
        $safeTenant = trim((string) $safeTenant, '-') ?: 'tenant';
        $email = 'activation-code@'.$safeTenant.'.emta.local';

        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => 'Código de activación',
                'email' => $email,
                'tenant_id' => $tenantId,
                'password' => Hash::make(Str::uuid()->toString()),
                'perfil' => 'recurso-visor',
            ]);
        }

        $expiresAt = now()->addDay()->toDateTimeString();
        DB::table('control_panel_access_trs')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'activation_id' => $activationId,
                'user_id' => $user->id,
            ],
            [
                'created_by_user_id' => null,
                'expires_at' => $expiresAt,
                'updated_at' => now()->toDateTimeString(),
                'created_at' => now()->toDateTimeString(),
            ],
        );

        $this->auditLogger->logForUser($user, $tenantId, $request->ip(), [
            'event_type' => 'activation_code_login',
            'module' => 'auth',
            'entity_id' => (string) $user->id,
            'entity_type' => 'User',
        ]);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'token_type' => 'Bearer',
            'activation_id' => $activationId,
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

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        if (! Schema::hasTable('password_reset_tokens')) {
            return response()->json(['message' => 'Missing password_reset_tokens table.'], 422);
        }

        $tenantId = $this->tenantContext->tenantId();
        $email = strtolower(trim((string) $data['email']));

        $userQuery = User::query()->where('email', $email);
        if ($tenantId !== null) {
            $userQuery->where('tenant_id', $tenantId);
        }
        $user = $userQuery->first();

        if ($user === null) {
            return response()->json(['message' => __('messages.auth.password_reset_sent')]);
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->when(
                $tenantId !== null,
                static fn ($q) => $q->where('tenant_id', $tenantId),
                static fn ($q) => $q->whereNull('tenant_id'),
            )
            ->delete();

        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($token),
            'tenant_id' => $tenantId,
            'created_at' => now(),
        ]);

        $frontendUrl = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $query = http_build_query(array_filter([
            'token' => $token,
            'email' => $email,
            'tenantId' => $tenantId,
        ], static fn ($value) => $value !== null && $value !== ''));
        $resetUrl = $frontendUrl.'/reset-password?'.$query;

        try {
            Mail::raw(__('messages.auth.password_reset_email', ['url' => $resetUrl]), function ($message) use ($email) {
                $message->to($email)->subject(__('messages.auth.password_reset_subject'));
            });
        } catch (\Throwable) {
        }

        return response()->json(['message' => __('messages.auth.password_reset_sent')]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Schema::hasTable('password_reset_tokens')) {
            return response()->json(['message' => 'Missing password_reset_tokens table.'], 422);
        }

        $tenantId = $this->tenantContext->tenantId();
        $email = strtolower(trim((string) $data['email']));
        $token = (string) $data['token'];

        $resetQuery = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->when(
                $tenantId !== null,
                static fn ($q) => $q->where('tenant_id', $tenantId),
                static fn ($q) => $q->whereNull('tenant_id'),
            )
            ->orderByDesc('created_at');

        $record = $resetQuery->first();
        if (! $record || ! Hash::check($token, (string) $record->token)) {
            return response()->json(['message' => __('messages.auth.password_reset_invalid')], 422);
        }

        $expiresMinutes = (int) (config('auth.passwords.users.expire') ?? 60);
        $createdAt = $record->created_at ? Carbon::parse($record->created_at) : null;
        if ($createdAt === null || $createdAt->addMinutes($expiresMinutes)->isPast()) {
            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->when(
                    $tenantId !== null,
                    static fn ($q) => $q->where('tenant_id', $tenantId),
                    static fn ($q) => $q->whereNull('tenant_id'),
                )
                ->delete();
            return response()->json(['message' => __('messages.auth.password_reset_expired')], 422);
        }

        $userQuery = User::query()->where('email', $email);
        if ($tenantId !== null) {
            $userQuery->where('tenant_id', $tenantId);
        }
        $user = $userQuery->first();

        if (! $user) {
            return response()->json(['message' => __('messages.auth.password_reset_invalid')], 422);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->when(
                $tenantId !== null,
                static fn ($q) => $q->where('tenant_id', $tenantId),
                static fn ($q) => $q->whereNull('tenant_id'),
            )
            ->delete();

        $this->auditLogger->logForUser($user, $tenantId, $request->ip(), [
            'event_type' => 'password_reset',
            'module' => 'auth',
            'entity_id' => (string) $user->id,
            'entity_type' => 'User',
        ]);

        return response()->json(['message' => __('messages.auth.password_reset_success')]);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        if (! Schema::hasTable('login_two_factor_tokens')) {
            return response()->json(['message' => 'Missing login_two_factor_tokens table.'], 422);
        }

        $challengeId = trim((string) $data['challenge_id']);
        $code = trim((string) $data['code']);

        $record = DB::table('login_two_factor_tokens')->where('id', $challengeId)->first();
        if (! $record) {
            return response()->json(['message' => __('messages.auth.two_factor_invalid')], 422);
        }

        $expiresAt = $record->expires_at ? Carbon::parse($record->expires_at) : null;
        if ($expiresAt === null || $expiresAt->isPast()) {
            DB::table('login_two_factor_tokens')->where('id', $challengeId)->delete();
            return response()->json(['message' => __('messages.auth.two_factor_expired')], 422);
        }

        $attempts = (int) ($record->attempts ?? 0);
        if ($attempts >= 5) {
            return response()->json(['message' => __('messages.auth.two_factor_locked')], 422);
        }

        if (! Hash::check($code, (string) $record->token_hash)) {
            DB::table('login_two_factor_tokens')
                ->where('id', $challengeId)
                ->update(['attempts' => $attempts + 1, 'updated_at' => now()]);
            return response()->json(['message' => __('messages.auth.two_factor_invalid')], 422);
        }

        $user = User::query()->where('id', (int) $record->user_id)->first();
        if (! $user) {
            DB::table('login_two_factor_tokens')->where('id', $challengeId)->delete();
            return response()->json(['message' => __('messages.auth.two_factor_invalid')], 422);
        }

        DB::table('login_two_factor_tokens')->where('id', $challengeId)->delete();

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

    public function resendTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
        ]);

        if (! Schema::hasTable('login_two_factor_tokens')) {
            return response()->json(['message' => 'Missing login_two_factor_tokens table.'], 422);
        }

        $challengeId = trim((string) $data['challenge_id']);
        $record = DB::table('login_two_factor_tokens')->where('id', $challengeId)->first();
        if (! $record) {
            return response()->json(['message' => __('messages.auth.two_factor_invalid')], 422);
        }

        $user = User::query()->where('id', (int) $record->user_id)->first();
        if (! $user) {
            DB::table('login_two_factor_tokens')->where('id', $challengeId)->delete();
            return response()->json(['message' => __('messages.auth.two_factor_invalid')], 422);
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        DB::table('login_two_factor_tokens')
            ->where('id', $challengeId)
            ->update([
                'token_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'updated_at' => now(),
            ]);

        $tenantId = $record->tenant_id ?? $this->tenantContext->tenantId();
        $delivery = $this->sendTwoFactorCode($user, $tenantId, $code, $challengeId);

        if (! $delivery['sent']) {
            return response()->json(['message' => $delivery['message']], 422);
        }

        return response()->json([
            'message' => __('messages.auth.two_factor_resent'),
            'expires_in' => 600,
            'delivery' => [
                'channel' => 'email',
                'mode' => $delivery['mode'],
                'destination' => $delivery['destination'],
            ],
        ]);
    }

    private function sendTwoFactorCode(User $user, ?string $tenantId, string $code, string $challengeId): array
    {
        $tenant = $tenantId ? Tenant::query()->where('tenant_id', $tenantId)->first() : null;
        $productionMode = (bool) ($tenant?->notifications_production_mode ?? false);
        $recipients = [];
        if ($productionMode) {
            $email = strtolower(trim((string) ($user->email ?? '')));
            if ($email !== '') {
                $recipients = [$email];
            }
        } else {
            $raw = $tenant?->test_notification_emails;
            $rawArr = is_array($raw) ? $raw : [];
            $emails = [];
            foreach ($rawArr as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '') {
                    $emails[] = $e;
                }
            }
            $recipients = array_values(array_unique($emails));
        }

        if (count($recipients) === 0) {
            return [
                'sent' => false,
                'mode' => $productionMode ? 'production' : 'test',
                'destination' => null,
                'message' => __('messages.auth.two_factor_no_test_emails'),
            ];
        }

        $subjectPrefix = $productionMode ? '' : '[PRUEBA] ';
        $subject = $subjectPrefix.__('messages.auth.two_factor_subject');
        $body = __('messages.auth.two_factor_email', ['code' => $code]);

        if (app()->environment('local')) {
            $dir = 'two_factor_outbox/'.($tenantId ?? 'default');
            $path = $dir.'/'.$challengeId.'.txt';
            Storage::disk('local')->put($path, $body);
        } else {
            foreach ($recipients as $to) {
                Mail::raw($body, static function ($m) use ($to, $subject) {
                    $m->to($to)->subject($subject);
                });
            }
        }

        $destination = $productionMode
            ? $this->maskEmail($recipients[0] ?? '')
            : implode(', ', $recipients);

        return [
            'sent' => true,
            'mode' => $productionMode ? 'production' : 'test',
            'destination' => $destination,
            'message' => 'OK',
        ];
    }

    private function maskEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '') {
            return null;
        }
        $first = mb_substr($local, 0, 1);
        $last = mb_strlen($local) > 1 ? mb_substr($local, -1) : '';
        $masked = $first.str_repeat('*', max(1, mb_strlen($local) - 2)).$last;
        return $masked.'@'.$domain;
    }
}
