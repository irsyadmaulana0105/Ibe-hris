<?php

namespace App\Http\Middleware;

use App\Services\ModuleEntitlementService;
use App\Services\ModuleRouteManifest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRouteModuleEnabled
{
    public function __construct(
        private readonly ModuleRouteManifest $manifest,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $module = $route ? $this->manifest->moduleForUri($route->uri()) : null;

        if ($module === null) {
            return $next($request);
        }

        $user = $request->user();
        if ($user && $this->entitlements->enabled($user->company_id, $module)) {
            return $next($request);
        }

        return response()->json([
            'message' => "Modul {$module} tidak aktif untuk perusahaan ini.",
            'code' => 'MODULE_NOT_ENABLED',
            'module' => $module,
        ], 403);
    }
}
