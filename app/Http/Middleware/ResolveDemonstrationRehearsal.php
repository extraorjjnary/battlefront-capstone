<?php

namespace App\Http\Middleware;

use App\Services\DemonstrationProvisioning;
use App\Services\DemonstrationRehearsal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ResolveDemonstrationRehearsal
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->session()->get('demonstration_rehearsal');
        if (! is_array($context) || $request->routeIs('demonstration.rehearsal.*') || $request->isMethodSafe() || $request->is('logout')) {
            return $next($request);
        }

        return Cache::lock('battlefront-demo-rehearsal', 120)->block(10, function () use ($request, $next, $context): Response {
            abort_unless(app(DemonstrationProvisioning::class)->enabled() && config('demonstration.rehearsal_enabled') === true
                && ($context['run'] ?? null) === config('demonstration.rehearsal_run_id') && ($context['expires'] ?? 0) > now()->timestamp
                && app(DemonstrationRehearsal::class)->evidence('demo.rehearsal.closed', 'run', $context['run_record']) === null, 409, 'Rehearsal expired or closed. Leave rehearsal mode.');
            $expected = $context['user_id'] ?? null;
            abort_unless($expected === $request->user()?->id || ($expected !== null && $request->user() === null && $request->is('email/*', 'login')), 409, 'Rehearsal account changed. Leave rehearsal mode.');
            $request->attributes->set('demonstration_rehearsal', $context);

            return $next($request);
        });
    }
}
