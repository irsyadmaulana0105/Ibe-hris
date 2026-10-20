<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EnterpriseAuthorizationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;

/**
 * Base controller for all API v1 controllers.
 * Provides consistent response envelope helpers.
 */
abstract class ApiController extends Controller
{
    protected function requireCapability(
        User $user,
        string $capability,
        string $message = 'Forbidden.',
        array $context = [],
    ): void {
        abort_unless(
            method_exists($user, 'hasCapability')
                && app(EnterpriseAuthorizationService::class)
                    ->allows($user, $capability, $context),
            403,
            $message
        );
    }

    /**
     * Permit the request if the user holds ANY of the listed capabilities.
     * Useful for endpoints accessible by multiple roles (e.g. payroll list
     * visible to HR, Finance, and Superadmin with different sub-caps).
     */
    protected function requireAnyCapability(
        User $user,
        array $capabilities,
        string $message = 'Forbidden.',
        array $context = [],
    ): void {
        if (! method_exists($user, 'hasCapability')) {
            abort(403, $message);
        }
        foreach ($capabilities as $cap) {
            if (app(EnterpriseAuthorizationService::class)->allows($user, $cap, $context)) {
                return;
            }
        }
        abort(403, $message);
    }

    /**
     * Require a least-privilege capability for a mutation. Shadow and legacy
     * deployments may explicitly fall back to the old umbrella capability;
     * enforce mode never does so.
     */
    protected function requireGranularCapability(
        User $user,
        string $capability,
        string $legacyCapability,
        string $message = 'Forbidden.',
        array $context = [],
    ): void {
        $authorization = app(EnterpriseAuthorizationService::class);

        if ($authorization->allows($user, $capability, $context)) {
            return;
        }

        if ((string) config('enterprise_rbac.mode', 'shadow') !== 'enforce'
            && $authorization->allows($user, $legacyCapability, $context)) {
            return;
        }

        abort(403, $message);
    }

    protected function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        $payload = [];
        if ($message) {
            $payload['message'] = $message;
        }
        if (! is_null($data)) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    protected function created(mixed $data, string $message = ''): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function paginated(
        LengthAwarePaginator $paginator,
        mixed $collection,
        array $extra = []
    ): JsonResponse {
        return response()->json(array_merge([
            'data' => $collection,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], $extra));
    }

    protected function requireAdmin(User $user): void
    {
        abort_unless($user->isAdmin, 403, 'Admin access required.');
    }
}
