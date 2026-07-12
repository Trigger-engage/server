<?php

namespace TriggerEngage\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        $ability = config('trigger-engage-server.authorization_gate', 'viewTriggerEngage');

        $authorized = $ability && Gate::has($ability)
            ? Gate::allows($ability)
            : Auth::check();

        abort_unless($authorized, 403);

        return $next($request);
    }
}
