<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\IdentityValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validate NIK + NPWP. Algorithmic (no external API call), safe untuk
 * dipakai inline saat form input → real-time feedback.
 */
class IdentityController extends ApiController
{
    public function __construct(private readonly IdentityValidator $validator) {}

    /** GET /api/v1/identity/nik?value=3578035107020001 */
    public function nik(Request $request): JsonResponse
    {
        $request->validate(['value' => ['required', 'string', 'max:30']]);
        return $this->success($this->validator->validateNik($request->string('value')->toString()));
    }

    /** GET /api/v1/identity/npwp?value=999999999999999 */
    public function npwp(Request $request): JsonResponse
    {
        $request->validate(['value' => ['required', 'string', 'max:30']]);
        return $this->success($this->validator->validateNpwp($request->string('value')->toString()));
    }
}
