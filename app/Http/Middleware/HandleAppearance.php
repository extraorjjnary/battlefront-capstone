<?php

namespace App\Http\Middleware;

use App\Enums\AppearancePreference;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = $request->user()->appearance ?? AppearancePreference::System;

        View::share('appearance', $appearance->value);

        return $next($request);
    }
}
