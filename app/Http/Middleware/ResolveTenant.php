<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-ID')
            ?? $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id;

        if ($tenantId === null) {
            if (Schema::hasTable('tenants')) {
                $tenantId = Tenant::query()->value('tenant_id');
            }
        }

        $this->tenantContext->setTenantId($tenantId);

        return $next($request);
    }
}
