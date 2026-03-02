<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function log(array $payload): void
    {
        if (! Schema::hasTable('audit_log_trs')) {
            return;
        }

        $tenantId = $payload['tenant_id'] ?? $this->tenantContext->tenantId();
        if (! is_string($tenantId) || trim($tenantId) === '') {
            return;
        }

        DB::table('audit_log_trs')->insert([
            'id' => (string) ($payload['id'] ?? Str::uuid()),
            'tenant_id' => $tenantId,
            'plan_id' => $payload['plan_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'event_type' => (string) ($payload['event_type'] ?? 'unknown'),
            'module' => $payload['module'] ?? null,
            'entity_id' => $payload['entity_id'] ?? null,
            'entity_type' => $payload['entity_type'] ?? null,
            'previous_value' => isset($payload['previous_value']) ? json_encode($payload['previous_value']) : null,
            'new_value' => isset($payload['new_value']) ? json_encode($payload['new_value']) : null,
            'justification' => $payload['justification'] ?? null,
            'ip_origin' => $payload['ip_origin'] ?? null,
            'created_at' => $payload['created_at'] ?? now()->toDateTimeString(),
        ]);
    }

    public function logFromRequest(Request $request, array $payload): void
    {
        $user = $request->user();
        $this->log(array_merge([
            'tenant_id' => $this->tenantContext->tenantId(),
            'user_id' => $user?->id,
            'ip_origin' => $request->ip(),
        ], $payload));
    }

    public function logForUser(?User $user, ?string $tenantId, ?string $ip, array $payload): void
    {
        $this->log(array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'ip_origin' => $ip,
        ], $payload));
    }
}
