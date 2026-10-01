<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeApiChatbot implements AuthenticatesRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $customer */
        $customer = $request->user('sanctum');

        if ($request->headers->has('Authorization') && $customer === null) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        Gate::forUser($customer)->authorize('use-chatbot');
        $request->setUserResolver(fn (): ?User => $customer);

        return $next($request);
    }
}
