<?php

namespace TriggerEngage\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TriggerEngage\Server\Contracts\WorkspaceResolver;

class ResolveEmbeddedWorkspace
{
    public function __construct(protected WorkspaceResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('workspace', $this->resolver->resolve());

        return $next($request);
    }
}
