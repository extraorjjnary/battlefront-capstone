<?php

use App\Actions\Chatbot\Context\ResolveUnsupportedContext;
use Illuminate\Support\Facades\Http;

test('returns explicit empty context for unsupported inquiries', function () {
    $context = (new ResolveUnsupportedContext)->execute();

    expect($context)->toBe([]);
});

arch('chatbot context resolvers remain provider independent')
    ->expect('App\Actions\Chatbot\Context')
    ->not->toUse(Http::class);
