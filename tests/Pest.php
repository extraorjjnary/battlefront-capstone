<?php

use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Chatbot verification must never consume the configured Gemini quota.
        config()->set('ai.providers.gemini.key', 'testing-only');
        ChatbotResponseAgent::fake()->preventStrayPrompts();
        ChatbotIntentAgent::fake()->preventStrayPrompts();
        Http::fake([
            'generativelanguage.googleapis.com/*' => fn () => throw new RuntimeException('Gemini HTTP requests must be explicitly faked in tests.'),
        ]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** @return array{package_weight_kg: string, package_length_cm: string, package_width_cm: string, package_height_cm: string, package_measurement_source: string} */
function validPackageMeasurements(): array
{
    return [
        'package_weight_kg' => '1.000',
        'package_length_cm' => '10.00',
        'package_width_cm' => '10.00',
        'package_height_cm' => '10.00',
        'package_measurement_source' => 'estimated',
    ];
}
