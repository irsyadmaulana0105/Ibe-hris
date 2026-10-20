<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PrivateAssetIntegrityException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'Private asset is unavailable.',
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'private_asset_'.$this->reason,
        ], $this->httpStatus, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
