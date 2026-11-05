<?php

use App\Models\Category;
use App\Models\ChatbotKnowledge;
use App\Models\Forecast;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PcBuildTemplate;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use App\Services\DemonstrationProvisioning;
use App\Services\Forecasting\ProductForecastPreparationService;
use App\Services\Reporting\ForecastingService;
use Database\Seeders\DevelopmentHistoricalSalesSeeder;
use Database\Seeders\DevelopmentSupplierSeeder;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\HistoricalSalesCatalogFixtures;

function prepareHostedDemonstration(): void
{
    test()->freezeTime();
    HistoricalSalesCatalogFixtures::createProducts();
    foreach (DevelopmentSupplierSeeder::catalog() as $supplier) {
        foreach (array_keys($supplier['products']) as $code) {
            if (! Product::query()->where('product_code', $code)->exists()) {
                Product::factory()->available()->create(['product_code' => $code, 'is_catalog_imported' => true]);
            }
        }
    }
    Product::factory()->available()->for(Category::query()->first())->count(654 - Product::count())
        ->sequence(fn ($sequence) => ['name' => 'Hosted fixture product '.$sequence->index])->create(['is_catalog_imported' => true]);
    DB::table('audit_logs')->insert(Product::query()->get()->map(fn ($product) => [
        'actor_id' => null, 'action' => 'catalog.demo_inventory.provisioned', 'subject_type' => 'product',
        'subject_id' => $product->id, 'reason' => 'Test inventory preparation', 'changes' => '{}', 'created_at' => now(),
    ])->all());
    config(['catalog-demo.enabled' => true, 'catalog-demo.database' => DB::connection()->getDatabaseName(),
        'demonstration.connection' => DB::connection()->getName(), 'demonstration.provisioning_enabled' => true,
        'demonstration.forecasting_enabled' => true, 'demonstration.password' => 'Private-Test-Only!47291',
        'app.timezone' => 'UTC']);
    test()->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturn(true);
    Mail::fake();
    Notification::fake();
    app()->instance('env', 'production');
}

function hostedDemoSnapshot(): array
{
    $snapshot = [];
    foreach (['users', 'products', 'inventories', 'orders', 'order_items', 'sales', 'shipments', 'audit_logs', 'branches', 'chatbot_knowledge', 'suppliers', 'product_supplier', 'pc_build_templates', 'product_component_profiles'] as $table) {
        $snapshot[$table] = DB::table($table)->get()->toArray();
    }

    return $snapshot;
}

test('hosted provisioning defaults to a zero-write dry run and gates every write', function () {
    prepareHostedDemonstration();
    $before = hostedDemoSnapshot();
    $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true])->assertSuccessful();
    expect(hostedDemoSnapshot())->toEqual($before);
    foreach (['catalog-demo.enabled' => false, 'catalog-demo.database' => 'wrong', 'demonstration.host' => 'wrong', 'demonstration.connection' => 'wrong', 'demonstration.provisioning_enabled' => false] as $key => $value) {
        $original = config($key);
        config([$key => $value]);
        $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true, '--apply' => true])->assertFailed();
        config([$key => $original]);
    }
    $this->artisan('battlefront:provision-demo', ['--apply' => true])->assertFailed();
    expect(hostedDemoSnapshot())->toEqual($before);
});

test('hosted fixtures create all 34 active preapproved accounts and preserve stock on rerun', function () {
    prepareHostedDemonstration();
    $before = hostedDemoSnapshot();
    $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true, '--apply' => true])->assertSuccessful();
    expect(User::count())->toBe(34);
    // HTTP test requests need Laravel's testing CSRF handling after production provisioning.
    app()->instance('env', 'testing');
    foreach (app(DemonstrationProvisioning::class)->accounts() as $email => $account) {
        $user = User::query()->where('email', $email)->sole();
        expect($user->is_active)->toBeTrue()->and($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->administrationRoleCode())->toBe($account['role'])
            ->and(Hash::check(config('demonstration.password'), $user->password))->toBeTrue();
        $this->post(route('login.store'), ['email' => $email, 'password' => config('demonstration.password')])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));
    }
    expect(DB::table('audit_logs')->where('subject_type', 'user')->where('changes->verification_method', 'administratively_preapproved_demonstration_not_otp')->count())->toBe(34);
    foreach (['products', 'inventories'] as $table) {
        expect(hostedDemoSnapshot()[$table])->toEqual($before[$table]);
    }
    $customer = User::query()->where('email', 'test@example.com')->sole();
    $customer->update(['password' => 'Changed-Privately!3782']);
    $prepared = hostedDemoSnapshot();
    config(['demonstration.password' => null]);
    $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true, '--apply' => true])->assertSuccessful();
    expect(hostedDemoSnapshot())->toEqual($prepared);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
    $this->post(route('register.store'), [
        'name' => 'Ordinary Customer', 'email' => 'ordinary@example.test',
        'password' => 'Ordinary-Private!47291', 'password_confirmation' => 'Ordinary-Private!47291',
        'role' => 'administrator',
    ])->assertSessionHasNoErrors();
    $ordinary = User::query()->where('email', 'ordinary@example.test')->sole();
    expect($ordinary->hasVerifiedEmail())->toBeFalse()->and($ordinary->role->value)->toBe('customer');
    $this->assertGuest();
    Mail::assertSentCount(1);
});

test('conflicting and renamed accounts abort the entire hosted preparation', function (bool $renamed) {
    prepareHostedDemonstration();
    $user = User::factory()->create(['email' => $renamed ? 'renamed@example.test' : 'ADMIN@example.com']);
    if ($renamed) {
        DemonstrationProvisioning::audit('user', $user->id, ['email' => 'admin@example.com']);
    }
    $before = hostedDemoSnapshot();
    $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true, '--apply' => true])->assertFailed();
    expect(hostedDemoSnapshot())->toEqual($before);
})->with([false, true]);

test('predictable or absent hosted credentials are rejected without writes', function ($password) {
    prepareHostedDemonstration();
    config(['demonstration.password' => $password]);
    $before = hostedDemoSnapshot();
    $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true, '--apply' => true])->assertFailed();
    expect(hostedDemoSnapshot())->toEqual($before);
})->with([null, 'password', 'customer123', 'WeakPassword']);

test('late fixture failure rolls back accounts and all reference changes', function () {
    prepareHostedDemonstration();
    $before = hostedDemoSnapshot();
    Order::creating(fn () => throw new RuntimeException('Injected fixture failure'));
    $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true, '--apply' => true])->assertFailed();
    expect(hostedDemoSnapshot())->toEqual($before);
});

test('hosted coverage is durable, fixture-only, independently gated and invalidated by damage', function () {
    prepareHostedDemonstration();
    app(DemonstrationProvisioning::class)->provision(true);
    $product = Product::query()->where('product_code', '10003')->sole();
    $preparation = app(ProductForecastPreparationService::class);
    $before = $preparation->prepareMonthly($product);
    expect($before['status'])->toBe('ready')->and($before['coverage']['source_kind'])->toBe('synthetic_development');
    $sale = Sale::factory()->create(['sale_date' => now()->subMonth()->toDateString()]);
    OrderItem::factory()->for($sale->order)->for($product)->create(['quantity' => 9999]);
    expect($preparation->prepareMonthly($product)['history'])->toEqual($before['history']);
    config(['demonstration.provisioning_enabled' => false]);
    expect($preparation->prepareMonthly($product)['status'])->toBe('ready');
    config(['demonstration.forecasting_enabled' => false]);
    expect(app(SalesHistoryCoverageRepository::class)->forProductCode('10003'))->toBeNull();
    config(['demonstration.forecasting_enabled' => true]);
    DB::table('order_items')->where('order_id', Order::query()->whereNotNull('development_fixture_key')->first()->id)->increment('quantity');
    expect($preparation->prepareMonthly($product)['status'])->toBe('history_unavailable');
    config(['demonstration.provisioning_enabled' => true]);
    $damaged = hostedDemoSnapshot();
    $this->artisan('battlefront:provision-demo', ['--confirm-demo' => true, '--apply' => true])->assertFailed();
    expect(hostedDemoSnapshot())->toEqual($damaged);
});

test('retirement protects the last owner and revokes only owned demonstration access', function () {
    prepareHostedDemonstration();
    app(DemonstrationProvisioning::class)->provision(true);
    $this->artisan('battlefront:disable-demo-accounts', ['--confirm-demo' => true, '--apply' => true])->assertFailed();
    $owner = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'owner')->value('id')]);
    $user = User::query()->where('email', 'test@example.com')->sole();
    $user->createToken('defense');
    $before = hostedDemoSnapshot();
    $this->artisan('battlefront:disable-demo-accounts', ['--confirm-demo' => true])->assertSuccessful();
    expect(hostedDemoSnapshot())->toEqual($before);
    $this->artisan('battlefront:disable-demo-accounts', ['--confirm-demo' => true, '--apply' => true])->assertSuccessful();
    expect(User::where('is_active', true)->count())->toBe(1)->and($owner->fresh()->is_active)->toBeTrue()->and($user->tokens()->count())->toBe(0);
    app(DemonstrationProvisioning::class)->provision(true);
    expect(User::where('is_active', true)->count())->toBe(1);
});

test('hosted forecasts retain provenance and refuse to overwrite unowned forecast keys', function () {
    prepareHostedDemonstration();
    app(DemonstrationProvisioning::class)->provision(true);
    $product = Product::query()->where('product_code', '10003')->sole();
    $forecast = app(ForecastingService::class)->generate($product);
    expect(DemonstrationProvisioning::owns('forecast', $forecast['id']))->toBeTrue()
        ->and($forecast['is_synthetic'])->toBeTrue();
    $second = Product::query()->where('product_code', '43720093')->sole();
    $preserved = Forecast::factory()->for($second)->create([
        'method' => 'additive_holt_winters', 'forecast_quarter' => now()->format('Y-m').'/'.now()->addMonths(2)->format('Y-m'),
    ]);
    $before = $preserved->getAttributes();
    expect(fn () => app(ForecastingService::class)->generate($second))
        ->toThrow(ValidationException::class);
    expect($preserved->fresh()->getAttributes())->toEqual($before);
});

test('hosted history matches the original calendar plan while preserving preexisting commerce and reference edits', function () {
    prepareHostedDemonstration();
    $summarize = fn () => Order::query()->with(['items.product', 'user'])->orderBy('created_at')->get()->map(fn ($order) => [
        'email' => $order->user->email, 'date' => $order->created_at->toDateTimeString(),
        'payment' => $order->payment_method->value, 'fulfillment' => $order->fulfillment_method->value,
        'subtotal' => $order->product_subtotal, 'total' => $order->total_amount,
        'items' => $order->items->map(fn ($item) => [$item->product->product_code, $item->quantity, $item->price_at_time])->all(),
    ])->all();
    app()->instance('env', 'testing');
    Storage::fake('local');
    DB::beginTransaction();
    try {
        $this->seed(DevelopmentHistoricalSalesSeeder::class);
        $expected = $summarize();
    } finally {
        DB::rollBack();
    }
    app()->instance('env', 'production');
    app(DemonstrationProvisioning::class)->provision(true);
    expect($summarize())->toEqual($expected);
    $ordinary = Sale::factory()->create();
    $saved = $ordinary->fresh()->getAttributes();
    $faq = ChatbotKnowledge::query()->first();
    $faq->update(['response_template' => 'Preserved administrator edit', 'is_active' => false]);
    $template = PcBuildTemplate::query()->first();
    $template->update(['name' => 'Preserved administrator template']);
    app(DemonstrationProvisioning::class)->provision(true);
    expect($ordinary->fresh()->getAttributes())->toEqual($saved)
        ->and($faq->fresh()->response_template)->toBe('Preserved administrator edit')
        ->and($faq->fresh()->is_active)->toBeFalse()
        ->and($template->fresh()->name)->toBe('Preserved administrator template');
});
