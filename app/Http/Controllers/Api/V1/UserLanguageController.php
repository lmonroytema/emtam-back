<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateUserLanguageRequest;
use App\Services\LanguageService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class UserLanguageController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LanguageService $languageService,
    ) {}

    public function update(UpdateUserLanguageRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => __('messages.auth.unauthenticated')], 401);
        }

        $tenantId = $user->tenant_id ?? $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $language = $request->validated('language');

        if (! $this->languageService->isEnabledForTenant($tenantId, $language)) {
            return response()->json(['message' => __('messages.user.language_not_enabled')], 422);
        }

        $user->forceFill(['language' => $language])->save();

        return response()->json([
            'message' => __('messages.user.language_updated'),
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'language' => $user->language,
            ],
        ]);
    }
}
