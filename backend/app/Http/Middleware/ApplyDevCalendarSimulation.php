<?php

namespace App\Http\Middleware;

use App\Support\DevCalendarContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyDevCalendarSimulation
{
    public function handle(Request $request, Closure $next): Response
    {
        DevCalendarContext::applyFromRequest($request);

        try {
            return $next($request);
        } finally {
            DevCalendarContext::reset();
        }
    }
}
