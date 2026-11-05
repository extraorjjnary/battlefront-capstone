<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

function assertBattlefrontFaviconLinks(TestResponse $response): void
{
    $response->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    expect($xpath->evaluate('count(//head/link[@rel="icon" and @href="/favicon.svg?v=battlefront-bct" and @sizes="any" and @type="image/svg+xml"])'))->toBe(1.0);
    expect($xpath->evaluate('count(//head/link[@rel="icon" and @href="/favicon.ico?v=battlefront-bct" and @sizes="16x16 32x32" and @type="image/x-icon"])'))->toBe(1.0);
    expect($xpath->evaluate('count(//head/link[@rel="apple-touch-icon" and @href="/apple-touch-icon.png?v=battlefront-bct" and @sizes="180x180"])'))->toBe(1.0);
    expect($xpath->evaluate('count(//head/link[@href="/favicon.svg" or @href="/favicon.ico" or @href="/apple-touch-icon.png"])'))->toBe(0.0);
}

test('the public storefront advertises the Battlefront favicon and fallbacks', function () {
    $response = $this->get(route('home'));

    assertBattlefrontFaviconLinks($response);
});

test('customer and administration dashboards advertise the same Battlefront icons', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    assertBattlefrontFaviconLinks($response);
})->with(['customer', 'administrator']);
