<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define(
            'access-administration',
            fn (User $user): bool => $user->isAdministrator(),
        );

        Gate::define(
            'use-customer-cart',
            fn (User $user): bool => $user->role === UserRole::Customer,
        );

        Gate::define(
            'use-chatbot',
            fn (?User $user): bool => $user === null || $user->role === UserRole::Customer,
        );

        Gate::define(
            'use-recommendations',
            fn (?User $user): bool => $user === null || $user->role === UserRole::Customer,
        );

        RateLimiter::for('api-v1', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('ip:'.$request->ip()));

        RateLimiter::for('chatbot', function (Request $request): Limit {
            $customer = $request->user();

            return Limit::perMinute($customer === null ? 5 : 10)
                ->by($customer === null ? 'guest:'.$request->ip() : 'customer:'.$customer->getKey())
                ->response(fn (Request $request, array $headers): JsonResponse => response()->json([
                    'message' => 'Too many questions. Please wait a minute and try again.',
                ], 429, $headers));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }
}
