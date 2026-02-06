<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateTenantLanguagesRequest;
use App\Models\Tenant;
use App\Models\TenantLanguage;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class TenantLanguageController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function update(UpdateTenantLanguagesRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $defaultLanguage = $request->validated('default_language');
        $languages = array_values(array_unique($request->validated('languages')));

        if (! in_array($defaultLanguage, $languages, true)) {
            $languages[] = $defaultLanguage;
        }

        $tenant = Tenant::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'name' => $tenantId,
                'default_language' => $defaultLanguage,
            ],
        );

        $desired = array_fill_keys($languages, true);

        $existing = TenantLanguage::query()
            ->where('tenant_id', $tenant->tenant_id)
            ->get()
            ->keyBy('language_code');

        foreach ($desired as $code => $active) {
            TenantLanguage::query()->updateOrCreate(
                ['tenant_id' => $tenant->tenant_id, 'language_code' => $code],
                ['is_active' => true],
            );
        }

        $toDeactivate = array_diff($existing->keys()->all(), array_keys($desired));

        if (! empty($toDeactivate)) {
            TenantLanguage::query()
                ->where('tenant_id', $tenant->tenant_id)
                ->whereIn('language_code', $toDeactivate)
                ->update(['is_active' => false]);
        }

        $enabled = TenantLanguage::query()
            ->where('tenant_id', $tenant->tenant_id)
            ->where('is_active', true)
            ->pluck('language_code')
            ->values()
            ->all();

        return response()->json([
            'message' => __('messages.tenant.languages_updated'),
            'tenant' => [
                'tenant_id' => $tenant->tenant_id,
                'default_language' => $tenant->default_language,
                'languages' => $enabled,
            ],
        ]);
    }
}
