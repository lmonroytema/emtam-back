<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTenantUserRequest;
use App\Http\Requests\Api\V1\UpdateTenantUserRequest;
use App\Models\User;
use App\Services\LanguageService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TenantUsersController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LanguageService $languageService,
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

        $query = User::query()->where('tenant_id', $tenantId);

        if ($q !== '') {
            $query->where(static function ($inner) use ($q) {
                $inner->where('email', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%');
            });
        }

        $total = (clone $query)->count();

        $users = $query
            ->orderBy('email')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get(['id', 'name', 'email', 'tenant_id', 'language', 'created_at', 'updated_at']);

        return response()->json([
            'data' => $users,
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
        $language = $data['language'] ?? null;

        if (is_string($language) && $language !== '' && ! $this->languageService->isEnabledForTenant($tenantId, $language)) {
            return response()->json(['message' => __('messages.user.language_not_enabled')], 422);
        }

        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => strtolower(trim((string) $data['email'])),
            'password' => Hash::make((string) $data['password']),
            'language' => $language ?: null,
        ]);

        return response()->json([
            'message' => 'Created.',
            'data' => $user->only(['id', 'name', 'email', 'tenant_id', 'language']),
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

        if (array_key_exists('language', $data)) {
            $language = $data['language'];
            if (is_string($language) && $language !== '' && ! $this->languageService->isEnabledForTenant($tenantId, $language)) {
                return response()->json(['message' => __('messages.user.language_not_enabled')], 422);
            }
            $user->language = $language ?: null;
        }

        if (array_key_exists('name', $data)) {
            $user->name = (string) $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = strtolower(trim((string) $data['email']));
        }

        if (array_key_exists('password', $data) && is_string($data['password']) && $data['password'] !== '') {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return response()->json([
            'message' => 'OK',
            'data' => $user->only(['id', 'name', 'email', 'tenant_id', 'language']),
        ]);
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $deleted = User::query()->where('tenant_id', $tenantId)->where('id', $userId)->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['message' => 'Deleted.']);
    }
}
