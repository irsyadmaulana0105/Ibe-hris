<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\BlockInactiveUser;
use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnsureEnterpriseCapability;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureRouteModuleEnabled;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\PermanentEmployeeOnly;
use App\Http\Middleware\PublicCors;
use App\Http\Middleware\RequestSecurityPolicy;
use App\Http\Middleware\RequireTenantContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionTimeout;
use App\Http\Middleware\SetActiveCompany;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UserMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Cross-origin Portal SSO uses a short-lived one-time credential in a
        // POST body. It is not cookie-authenticated and is replay protected.
        $middleware->validateCsrfTokens(except: ['sso/callback']);

        // Keep this outside the route middleware priority list so even an
        // authentication/authorization exception receives a traceable ID.
        $middleware->prepend(CorrelationId::class);

        // The application containers are not published directly. Requests enter
        // through the managed host gateway, which overwrites every forwarded
        // header before proxying to the private Docker listener.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'user' => UserMiddleware::class,
            'permanent' => PermanentEmployeeOnly::class,
            'public.cors' => PublicCors::class,
            'block.inactive' => BlockInactiveUser::class,
            'module' => EnsureModuleEnabled::class,
            'module.routes' => EnsureRouteModuleEnabled::class,
            'enterprise.capability' => EnsureEnterpriseCapability::class,
            'idempotency' => IdempotencyKey::class,
            'tenant.required' => RequireTenantContext::class,
            // Penghitung throttle per rute (bukan satu untuk semua rute per IP) -
            // lihat App\Http\Middleware\ThrottleRequestsPerRoute.
            'throttle' => \App\Http\Middleware\ThrottleRequestsPerRoute::class,
        ]);

        $middleware->appendToGroup('web', [
            SessionTimeout::class,
            SecurityHeaders::class,
            SetLocale::class,
            SetActiveCompany::class,
        ]);

        $middleware->appendToGroup('api', [
            RequestSecurityPolicy::class,
            SetActiveCompany::class,
            // Security headers (HSTS, CSP, X-Frame-Options, COOP, dll) juga
            // diterapkan ke response API - defense-in-depth kalau nginx
            // override-nya hilang/lupa di vhost baru
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Authentication and authorization exceptions are rendered outside the
        // route middleware pipeline. Add the correlation header after exception
        // rendering as well, so rejected API requests remain traceable.
        $exceptions->respond(function (Response $response) {
            $correlationId = request()->attributes->get('correlation_id');

            if (is_string($correlationId) && $correlationId !== '') {
                $response->headers->set('X-Correlation-Id', $correlationId);
            }

            if (request()->is('api/*')) {
                $response->headers->set('X-API-Version', '1');
                $response->headers->set('X-Download-Options', 'noopen');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
                $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
                $response->headers->set('Origin-Agent-Cluster', '?1');

                foreach (config('request_security.sensitive_paths', []) as $pattern) {
                    if (request()->is($pattern)) {
                        $response->headers->set('Cache-Control', 'no-store, private');
                        $response->headers->set('Pragma', 'no-cache');
                        break;
                    }
                }
            }

            return $response;
        });
    })->create();
