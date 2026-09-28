<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

test('registration creates a customer and returns a 30-day bearer token', function () {
    $this->travelTo(now()->startOfSecond());
    Event::fake([Registered::class]);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Mobile Customer',
        'email' => 'MOBILE@EXAMPLE.COM',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'Pixel 8',
        'role' => UserRole::Administrator->value,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.name', 'Mobile Customer')
        ->assertJsonPath('data.user.email', 'mobile@example.com')
        ->assertJsonPath('data.user.default_delivery_address', null)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_at', now()->addDays(30)->toIso8601String());

    $user = User::where('email', 'mobile@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::Customer);
    expect($response->json('data.user'))->toBe([
        'id' => $user->id,
        'name' => 'Mobile Customer',
        'email' => 'mobile@example.com',
        'default_delivery_address' => null,
    ]);
    expect($response->json('data.token'))->toBeString()->toContain('|');
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'Pixel 8',
    ]);
    Event::assertDispatched(Registered::class);
});

test('registration returns 422 validation errors without creating a user', function () {
    $this->post('/api/v1/auth/register', [
        'name' => '',
        'email' => 'invalid',
        'password' => 'password',
        'password_confirmation' => 'different',
    ])->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'password', 'device_name']]);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('login returns a bearer token for a customer without a web session', function () {
    $user = User::factory()->customer()->unverified()->create([
        'email' => 'customer@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'CUSTOMER@EXAMPLE.COM',
        'password' => 'password',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['user', 'token', 'token_type', 'expires_at']]);
    expect($response->json('data.token'))->toBeString()->toContain('|');
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'iPhone 15',
    ]);
    $this->get('/api/v1/profile')->assertUnauthorized();
});

test('login returns 401 for invalid credentials and issues no token', function () {
    config(['app.debug' => false]);
    $user = User::factory()->customer()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'Pixel 8',
    ])->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid credentials.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('login returns the same 401 for an administrator and issues no token', function () {
    config(['app.debug' => false]);
    $administrator = User::factory()->administrator()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $administrator->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid credentials.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('login returns 422 when the device name is missing', function () {
    $user = User::factory()->customer()->create();

    $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['device_name']]);
});

test('login returns 429 after five attempts for one email and IP address', function () {
    $user = User::factory()->customer()->create();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.57']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Pixel 8',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'Pixel 8',
    ])->assertTooManyRequests()->assertJsonStructure(['message']);
});

test('registration returns 429 after five attempts from one IP address', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.58']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/register', [])
        ->assertTooManyRequests()->assertJsonStructure(['message']);
});

test('logout returns 204 and revokes only the current token', function () {
    $user = User::factory()->customer()->create();
    $firstToken = $user->createToken('First device')->plainTextToken;
    $secondToken = $user->createToken('Second device')->plainTextToken;

    $this->withToken($firstToken)->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertDatabaseCount('personal_access_tokens', 1);
    $this->app['auth']->forgetGuards();
    $this->withToken($firstToken)->get('/api/v1/profile')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($secondToken)->get('/api/v1/profile')->assertOk();
});
