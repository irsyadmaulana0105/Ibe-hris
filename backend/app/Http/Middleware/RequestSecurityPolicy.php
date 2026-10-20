<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestSecurityPolicy
{
    private const BLOCKED_METHODS = ['TRACE', 'TRACK', 'CONNECT'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array(strtoupper($request->method()), self::BLOCKED_METHODS, true)) {
            return $this->reject($request, 'HTTP method is not allowed.', 405);
        }

        $declaredLength = $request->headers->get('Content-Length');
        $maximum = max(1, (int) config('request_security.max_body_bytes', 52_428_800));
        if (is_string($declaredLength) && ctype_digit($declaredLength) && (int) $declaredLength > $maximum) {
            return $this->reject($request, 'Request body exceeds the configured limit.', 413);
        }

        if ($this->hasBody($request) && ! $this->hasAllowedContentType($request)) {
            return $this->reject($request, 'Unsupported Content-Type for API request.', 415);
        }

        $response = $next($request);
        $response->headers->set('X-API-Version', '1');
        $response->headers->set('X-Download-Options', 'noopen');

        foreach (config('request_security.sensitive_paths', []) as $pattern) {
            if ($request->is($pattern)) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');
                break;
            }
        }

        return $response;
    }

    private function hasBody(Request $request): bool
    {
        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH'], true)) {
            return false;
        }

        $length = $request->headers->get('Content-Length');

        return (is_string($length) && ctype_digit($length) && (int) $length > 0)
            || $request->getContent() !== ''
            || $request->files->count() > 0;
    }

    private function hasAllowedContentType(Request $request): bool
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type'));

        foreach (config('request_security.allowed_content_types', []) as $allowed) {
            if ($contentType === $allowed || str_starts_with($contentType, $allowed.';')) {
                return true;
            }
        }

        return false;
    }

    private function reject(Request $request, string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'correlation_id' => $request->attributes->get('correlation_id'),
        ], $status);
    }
}
