<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\ParallelTesting;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $parallelTesting = $app->make(ParallelTesting::class);

        if ($parallelTesting->token() === false) {
            $parallelTesting->resolveTokenUsing(static fn (): string => 'process-'.getmypid());
        }

        return $app;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        gc_collect_cycles();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
