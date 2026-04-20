<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\LanguageService;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $this->applyTimezone('Europe/Madrid');

            return $next($request);
        }

        $tenantId = $this->tenantContext->tenantId();
        $tenant = $tenantId === null ? null : Tenant::query()->where('tenant_id', $tenantId)->first();

        $locale = $this->languageService->resolveLocale($request->user(), $tenant);
        $timezone = $this->resolveTimezone($tenant?->timezone);

        app()->setLocale($locale);
        $this->applyTimezone($timezone);

        return $next($request);
    }

    private function resolveTimezone(?string $timezone): string
    {
        $candidate = trim((string) $timezone);
        if ($candidate !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
            return $candidate;
        }

        return 'Europe/Madrid';
    }

    private function applyTimezone(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        $offset = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('P');
        DB::statement("SET time_zone = '{$offset}'");
    }
}
