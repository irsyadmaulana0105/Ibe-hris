<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = trim((string) $request->header('X-Correlation-Id'));
        $correlationId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $provided)
            ? $provided
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        app()->instance('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
