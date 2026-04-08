<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictAuditor
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $perfil = strtolower(trim((string) ($user?->perfil ?? '')));
        $path = $request->path();
        if ($perfil === 'auditor') {
            $allowedPrefixes = [
                'api/v1/audit',
                'api/v1/user/password',
                'api/v1/user/language',
            ];

            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return $next($request);
                }
            }

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($perfil === 'tenant_admin') {
            $allowedPrefixes = [
                'api/v1/tenant/settings',
                'api/v1/tenant/logo',
                'api/v1/tenant/users',
                'api/v1/tenant/personnel',
                'api/v1/tenant/documents',
                'api/v1/user/password',
                'api/v1/user/language',
                'api/v1/tenant/languages',
            ];

            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return $next($request);
                }
            }

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
