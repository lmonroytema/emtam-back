<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantLanguage;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class LanguageService
{
    public const SUPPORTED = ['ca', 'es', 'en'];

    public function resolveLocale(?User $user, ?Tenant $tenant): string
    {
        $preferred = $user?->language;

        if ($preferred !== null && $tenant !== null && $this->isEnabledForTenant($tenant->tenant_id, $preferred)) {
            return $preferred;
        }

        $defaultTenantLocale = $tenant?->default_language;

        if ($defaultTenantLocale !== null && $tenant !== null && $this->isEnabledForTenant($tenant->tenant_id, $defaultTenantLocale)) {
            return $defaultTenantLocale;
        }

        return 'es';
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    public function isEnabledForTenant(string $tenantId, string $locale): bool
    {
        if (! Schema::hasTable('tenant_languages')) {
            return false;
        }

        return TenantLanguage::query()
            ->where('tenant_id', $tenantId)
            ->where('language_code', $locale)
            ->where('is_active', true)
            ->exists();
    }
}
