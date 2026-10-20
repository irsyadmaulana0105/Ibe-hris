<?php

namespace App\Http\Middleware;

use App\Services\ModuleEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleEntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        if ($user && $this->entitlements->enabled($user->company_id, $module)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "Modul {$module} tidak aktif untuk perusahaan ini.",
                'code' => 'MODULE_NOT_ENABLED',
                'module' => $module,
            ], 403);
        }

        abort(403, "Modul {$module} tidak aktif.");
    }
}
