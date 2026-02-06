<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\LanguageService;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LanguageService $languageService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_languages')) {
            app()->setLocale('es');

            return $next($request);
        }

        $tenantId = $this->tenantContext->tenantId();
        $tenant = $tenantId === null ? null : Tenant::query()->where('tenant_id', $tenantId)->first();

        $locale = $this->languageService->resolveLocale($request->user(), $tenant);

        app()->setLocale($locale);

        return $next($request);
    }
}
