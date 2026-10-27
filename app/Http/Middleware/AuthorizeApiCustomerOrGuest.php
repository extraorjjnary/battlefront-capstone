<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeApiCustomerOrGuest
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
        if ($customer !== null) {
            abort_unless($customer->can('use-customer-cart'), 403);
        }

        return $next($request);
    }
}
