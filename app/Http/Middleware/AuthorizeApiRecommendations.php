<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeApiRecommendations
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('sanctum');

        if ($request->headers->has('Authorization') && $customer === null) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        Gate::forUser($customer)->authorize('use-recommendations');

        return $next($request);
    }
}
