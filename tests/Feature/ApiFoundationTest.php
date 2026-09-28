<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['api', 'throttle:api-v1'])
        ->prefix('api/v1/_test')
        ->group(function (): void {
            Route::post('validation', function (Request $request) {
                return response()->json(['data' => $request->validate([
                    'name' => ['required', 'string'],
                ])]);
            });

            Route::get('protected', fn () => response()->json(['data' => ['status' => 'ok']]))
                ->middleware('auth');

            Route::get('customer-only', fn () => response()->json(['data' => ['status' => 'ok']]))
                ->middleware(['auth', 'can:use-customer-cart']);

            Route::get('error', fn () => throw new RuntimeException('Sensitive internal detail'));
        });
});

test('the versioned health route returns a JSON resource envelope', function () {
    $this->get('/api/v1/health')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['data' => ['status' => 'ok']]);
});

test('a resource and an unpaginated collection use data envelopes', function () {
    $users = User::factory()->count(2)->create();

    Route::get('/api/v1/_test/resource', fn () => JsonResource::make($users->first()->fresh()))
        ->middleware('api');
    Route::get('/api/v1/_test/collection', fn () => JsonResource::collection($users))
        ->middleware('api');

    $this->get('/api/v1/_test/resource')
        ->assertOk()
        ->assertJsonPath('data.id', $users->first()->id);

    $this->get('/api/v1/_test/collection')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $users->first()->id);
});

test('a paginated collection includes data, navigation links, and page metadata', function () {
    User::factory()->count(3)->create();

    Route::get('/api/v1/_test/paginated', fn () => JsonResource::collection(
        User::query()->orderBy('id')->paginate(2),
    ))->middleware('api');

    $this->get('/api/v1/_test/paginated')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
});

test('API validation failure returns 422 with field errors without an Accept header', function () {
    $this->post('/api/v1/_test/validation', [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('errors.name.0', 'The name field is required.')
        ->assertJsonStructure(['message', 'errors']);
});

test('an unauthenticated API request returns 401 JSON without a login redirect', function () {
    $this->get('/api/v1/_test/protected')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('an unauthorized API request returns 403 JSON', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->get('/api/v1/_test/customer-only')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

test('an unknown API route returns 404 JSON', function () {
    $this->get('/api/v1/missing')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

test('an unexpected API error returns a generic production 500 JSON response', function () {
    config(['app.debug' => false]);

    $this->get('/api/v1/_test/error')
        ->assertInternalServerError()
        ->assertExactJson(['message' => 'Server Error']);
});

test('the API returns 429 JSON after sixty requests from one IP', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.56']);

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $allowedResponse = $this->get('/api/v1/health');
    }

    $allowedResponse->assertOk();

    $this->get('/api/v1/health')
        ->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['message']);
});

test('the web dashboard still redirects guests to the login page', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
