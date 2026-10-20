<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->company_id) {
            return response()->json([
                'message' => 'Authenticated account has no active tenant context.',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ], 403);
        }

        $companyId = (int) $user->company_id;
        $request->attributes->set('tenant_id', $companyId);
        app()->instance('active_company_id', $companyId);

        return $next($request);
    }
}
