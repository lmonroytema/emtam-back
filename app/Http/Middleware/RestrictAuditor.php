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
        if ($perfil !== 'auditor') {
            return $next($request);
        }

        $path = $request->path();
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
}
