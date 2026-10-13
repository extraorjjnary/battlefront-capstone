<?php

use Illuminate\Foundation\Exceptions\Renderer\Renderer;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

test('HTTP errors avoid the browser debug renderer while still reporting failures', function () {
    expect(config('app.debug'))->toBeFalse();
    $this->mock(Renderer::class)->shouldNotReceive('render');
    Exceptions::fake();
    Route::get('/_test/server-error', fn () => throw new RuntimeException('Expected test failure'));

    $this->get('/_test/missing-page')->assertNotFound();
    $this->get('/_test/server-error')->assertInternalServerError()
        ->assertDontSee('Expected test failure');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Expected test failure');
});
