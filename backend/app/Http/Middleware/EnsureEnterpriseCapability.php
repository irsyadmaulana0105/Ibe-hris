<?php

namespace App\Http\Middleware;

use App\Services\EnterpriseAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEnterpriseCapability
{
    public function __construct(
        private readonly EnterpriseAuthorizationService $authorization,
    ) {}

    /**
     * Require a domain capability at the route boundary. The optional legacy
     * capability keeps legacy/shadow deployments backward compatible, but it
     * is deliberately ignored when enterprise RBAC is in enforce mode.
     */
    public function handle(
        Request $request,
        Closure $next,
        string $capability,
        ?string $legacyCapability = null,
    ): Response {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $context = [
            'company_id' => $user->company_id,
            'resource_type' => 'route',
            'resource_id' => $request->route()?->getName() ?: $request->route()?->uri(),
            'action' => $request->method(),
        ];

        if ($this->authorization->allows($user, $capability, $context)) {
            return $next($request);
        }

        if ((string) config('enterprise_rbac.mode', 'shadow') !== 'enforce'
            && $legacyCapability
            && $this->authorization->allows($user, $legacyCapability, $context)) {
            return $next($request);
        }

        abort(403, 'Required enterprise capability not granted.');
    }
}
