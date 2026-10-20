<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->company_id) {
            app()->instance('active_company_id', auth()->user()->company_id);
        }

        return $next($request);
    }
}
