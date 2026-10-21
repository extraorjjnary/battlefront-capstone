<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Inertia\Testing\AssertableInertia as Assert;

dataset('branch management endpoints', [
    'listing' => ['get', 'index'],
    'create form' => ['get', 'create'],
    'create' => ['post', 'store'],
    'details' => ['get', 'show'],
    'edit form' => ['get', 'edit'],
    'update' => ['put', 'update'],
]);

test('guests must sign in for branch management', function (string $method, string $action) {
    $branch = Branch::factory()->create();

    $this->{$method}(route('administration.branches.'.$action, $branch))
        ->assertRedirectToRoute('login');
})->with('branch management endpoints');

test('customers cannot manage branches', function (string $method, string $action) {
    $branch = Branch::factory()->create();
    $customer = User::factory()->customer()->create();
    $before = $branch->refresh()->toArray();

    $this->actingAs($customer)->{$method}(route('administration.branches.'.$action, $branch), [
        'name' => 'Unauthorized change', 'city' => 'New City',
    ])->assertForbidden();

    expect($branch->refresh()->toArray())->toBe($before);
    $this->assertDatabaseCount('branches', 1);
})->with('branch management endpoints');

test('administrators can list all branches and view management forms and unpublished details', function () {
    $administrator = User::factory()->administrator()->create();
    $this->seed(BranchSeeder::class);
    $draft = Branch::factory()->unpublished()->create(['city' => 'A New City']);

    $this->actingAs($administrator)->get(route('administration.branches.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Branches/Index')
            ->has('branches.data', 6)->where('branches.data.0.city', 'Sagay City')
            ->where('branches.data.1.id', $draft->id)->where('branches.data.1.is_published', false)
            ->missing('branches.data.0.seed_key'));

    $this->get(route('administration.branches.create'))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Branches/Create'));
    $this->get(route('administration.branches.edit', $draft))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Branches/Edit')->where('branch.id', $draft->id));
    $this->get(route('administration.branches.show', $draft))
        ->assertInertia(fn (Assert $page) => $page->component('Administration/Branches/Show')
            ->where('branch.id', $draft->id)->where('branch.is_published', false)->missing('branch.seed_key'));
});

test('administrators create informational drafts with only a name and city', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->post(route('administration.branches.store'), [
        'name' => '  Battlefront  North ', 'city' => ' New  City ',
    ])->assertSessionHasNoErrors()->assertRedirectToRoute('administration.branches.index');

    $branch = Branch::query()->sole();
    expect($branch->name)->toBe('Battlefront North');
    expect($branch->city)->toBe('New City');
    expect($branch->is_published)->toBeFalse();
    expect($branch->is_operational)->toBeFalse();
    expect($branch->seed_key)->toBeNull();
    $this->getJson(route('api.v1.branches.index'))->assertExactJson(['data' => []]);
    foreach (['inventories', 'orders', 'shipments', 'sales'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

test('administrators can publish branches with verified details and coordinate pairs', function (float $latitude, float $longitude) {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->post(route('administration.branches.store'), [
        'name' => 'Battlefront North', 'city' => 'New City', 'address' => '12 Confirmed Street',
        'contact_number' => '+63 912 345 6789', 'email' => 'north@example.test',
        'operating_hours' => 'Mon–Sat, 9 AM–5 PM', 'is_published' => true,
        'latitude' => $latitude, 'longitude' => $longitude,
    ])->assertSessionHasNoErrors()->assertRedirectToRoute('administration.branches.index');

    $this->assertDatabaseHas('branches', ['city' => 'New City', 'email' => 'north@example.test', 'is_published' => true]);
    $response = $this->getJson(route('api.v1.branches.index'))->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_operational', false)->assertJsonPath('data.0.email', 'north@example.test');
    expect($response->json('data.0.latitude'))->toEqual($latitude);
    expect($response->json('data.0.longitude'))->toEqual($longitude);
})->with(['verified location' => [10.1234567, 123.7654321], 'zero coordinates' => [0.0, 0.0], 'limits' => [-90.0, 180.0]]);

test('published branches require an address and hours', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->post(route('administration.branches.store'), [
        'name' => 'Battlefront North', 'city' => 'New City', 'is_published' => true,
    ])->assertSessionHasErrors([
        'address' => 'Enter a complete address before publishing.',
        'operating_hours' => 'Enter operating hours before publishing.',
    ]);

    $this->assertDatabaseCount('branches', 0);
});

test('branch validation rejects invalid supplied fields without saving', function (array $invalid, string $field) {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)->post(route('administration.branches.store'), [
        'name' => 'Battlefront North', 'city' => 'New City', ...$invalid,
    ])->assertSessionHasErrors($field);

    $this->assertDatabaseCount('branches', 0);
})->with([
    'missing name' => [['name' => ' '], 'name'],
    'missing city' => [['city' => ' '], 'city'],
    'long name' => [['name' => str_repeat('a', 256)], 'name'],
    'long city' => [['city' => str_repeat('a', 256)], 'city'],
    'long address' => [['address' => str_repeat('a', 256)], 'address'],
    'long hours' => [['operating_hours' => str_repeat('a', 256)], 'operating_hours'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'invalid telephone' => [['contact_number' => 'call me'], 'contact_number'],
    'long telephone' => [['contact_number' => str_repeat('1', 21)], 'contact_number'],
    'missing longitude' => [['latitude' => 10], 'longitude'],
    'missing latitude' => [['longitude' => 123], 'latitude'],
    'latitude range' => [['latitude' => 91, 'longitude' => 123], 'latitude'],
    'longitude range' => [['latitude' => 10, 'longitude' => -181], 'longitude'],
    'nonnumeric coordinate' => [['latitude' => 'north', 'longitude' => 123], 'latitude'],
    'nonfinite coordinate' => [['latitude' => 'INF', 'longitude' => 123], 'latitude'],
    'invalid publication' => [['is_published' => 'yes'], 'is_published'],
    'operational spoofing' => [['is_operational' => true], 'is_operational'],
    'seed identity spoofing' => [['seed_key' => 'sagay-city'], 'seed_key'],
    'id spoofing' => [['id' => 1], 'id'],
    'reserved city' => [['city' => 'sagay city'], 'city'],
]);

test('branch edits preserve identity and publication updates reach both directories', function () {
    $administrator = User::factory()->administrator()->create();
    $this->seed(BranchSeeder::class);
    $branch = Branch::query()->where('city', 'Bacolod City')->sole();
    $id = $branch->id;
    $seedKey = $branch->seed_key;
    $details = ['name' => 'Battlefront Updated', 'city' => 'Updated City', 'address' => 'Verified address',
        'email' => 'updated@example.test', 'operating_hours' => '10 AM–4 PM', 'latitude' => null, 'longitude' => null];

    $this->actingAs($administrator)->put(route('administration.branches.update', $branch), $details)
        ->assertSessionHasNoErrors()->assertRedirectToRoute('administration.branches.index');

    expect($branch->refresh()->id)->toBe($id);
    expect($branch->seed_key)->toBe($seedKey);
    expect($branch->is_published)->toBeTrue();
    $web = $this->get(route('branches.index'))->inertiaProps('branches');
    $api = $this->getJson(route('api.v1.branches.index'))->assertJsonCount(5, 'data')->json('data');
    expect($api)->toBe($web);
    expect(collect($api)->firstWhere('id', $id))->toMatchArray([...$details, 'is_operational' => false]);

    $this->put(route('administration.branches.update', $branch), [...$details, 'is_published' => false])
        ->assertSessionHasNoErrors();
    $this->get(route('branches.index'))->assertInertia(fn (Assert $page) => $page->has('branches', 4));
    expect(array_column($this->getJson(route('api.v1.branches.index'))->json('data'), 'id'))->not->toContain($id);

    $this->put(route('administration.branches.update', $branch), [...$details, 'is_published' => true])
        ->assertSessionHasNoErrors();
    $this->getJson(route('api.v1.branches.index'))->assertJsonCount(5, 'data');
});

test('legacy missing details can remain published but require verification on republication', function () {
    $this->seed(BranchSeeder::class);
    $administrator = User::factory()->administrator()->create();
    $branch = Branch::query()->where('city', 'Escalante City')->sole();
    $details = ['name' => $branch->name, 'city' => $branch->city, 'operating_hours' => $branch->operating_hours];

    $this->actingAs($administrator)->put(route('administration.branches.update', $branch), [...$details, 'email' => 'verified@example.test'])
        ->assertSessionHasNoErrors();
    expect($branch->refresh()->is_published)->toBeTrue();

    $this->put(route('administration.branches.update', $branch), [...$details, 'is_published' => false])->assertSessionHasNoErrors();
    $this->put(route('administration.branches.update', $branch), [...$details, 'is_published' => true])
        ->assertSessionHasErrors('address');
    expect($branch->refresh()->is_published)->toBeFalse();
});

test('verified published details cannot be cleared until the branch is unpublished', function () {
    $administrator = User::factory()->administrator()->create();
    $branch = Branch::factory()->create();

    $this->actingAs($administrator)->put(route('administration.branches.update', $branch), [
        'name' => $branch->name, 'city' => $branch->city, 'address' => null, 'operating_hours' => null,
    ])->assertSessionHasErrors(['address', 'operating_hours']);

    expect($branch->refresh()->address)->not->toBeNull();
});

test('Sagay cannot be moved or unpublished and duplicate cities are rejected', function () {
    $this->seed(BranchSeeder::class);
    $administrator = User::factory()->administrator()->create();
    $branch = Branch::operational()->sole();
    $details = ['name' => $branch->name, 'city' => $branch->city, 'address' => $branch->address, 'operating_hours' => $branch->operating_hours];

    $this->actingAs($administrator)->put(route('administration.branches.update', $branch), [...$details, 'is_published' => false])
        ->assertSessionHasErrors(['is_published' => 'Sagay City must remain published.']);
    $this->put(route('administration.branches.update', $branch), [...$details, 'city' => 'Another City'])
        ->assertSessionHasErrors('city');
    $this->post(route('administration.branches.store'), ['name' => 'Duplicate', 'city' => 'Bacolod City'])
        ->assertSessionHasErrors(['city' => 'A branch in this city already exists.']);

    expect(Branch::operational()->sole()->id)->toBe($branch->id);
    expect($branch->refresh()->is_published)->toBeTrue();
});

test('management provides no destructive deletion endpoint', function () {
    $administrator = User::factory()->administrator()->create();
    $branch = Branch::factory()->create();

    $this->actingAs($administrator)->delete(route('administration.branches.show', $branch))->assertMethodNotAllowed();

    $this->assertModelExists($branch);
    $this->get(route('administration.branches.show', PHP_INT_MAX))->assertNotFound();
});
