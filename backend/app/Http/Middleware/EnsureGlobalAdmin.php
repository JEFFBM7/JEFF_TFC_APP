<?php

namespace App\Http\Middleware;

use App\Support\AdminScopeContext;
use Closure;
use Illuminate\Http\Request;

class EnsureGlobalAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        AdminScopeContext::assertGlobalAdmin($request->user());

        return $next($request);
    }
}
