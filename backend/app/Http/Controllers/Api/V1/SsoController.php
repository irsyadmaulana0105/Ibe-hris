<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SsoController extends ApiController
{
    public function __construct(
        private readonly SsoService $sso,
    ) {}

    public function platforms(Request $request): JsonResponse
    {
        return $this->success($this->sso->platformsFor($request->user()));
    }

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'target_app' => ['required', 'string', 'max:50'],
        ]);

        return $this->success(
            $this->sso->generate($request->user(), $request->string('target_app')->toString(), $request)
        );
    }

    public function exchange(Request $request): JsonResponse
    {
        $request->validate([
            'sso_token' => ['required', 'string'],
            'target_app' => ['required', 'string', 'max:50'],
        ]);

        $payload = $this->sso->exchange(
            $request->string('sso_token')->toString(),
            $request->string('target_app')->toString(),
            $request,
        );

        // Issue refresh cookie sekalian - supaya SPA bisa auto-refresh tanpa
        // round-trip ke portal lagi (avoid balik ke launcher tiap 1 jam).
        // User object di-extract dari payload.
        $refreshCookie = null;
        if (!empty($payload['user']) && method_exists($payload['user'], 'resource')) {
            /** @var \App\Models\User $userModel */
            $userModel = $payload['user']->resource;
            $refreshCookie = app(\App\Services\RefreshTokenService::class)
                ->issue($userModel, $request);
        }

        $response = $this->success($payload);
        return $refreshCookie ? $response->withCookie($refreshCookie) : $response;
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'sso_token' => ['required', 'string'],
            'target_app' => ['required', 'string', 'max:50'],
        ]);

        return $this->success(
            $this->sso->verify(
                $request->string('sso_token')->toString(),
                $request->string('target_app')->toString(),
            )
        );
    }
}
