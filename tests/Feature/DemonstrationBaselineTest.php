<?php

use App\Enums\PaymentMethod;
use App\Jobs\ProcessPayMongoWebhook;
use App\Models\Category;
use App\Models\Order;
use App\Models\PayMongoFlow;
use App\Models\PayMongoWebhookInbox;
use App\Models\Product;
use App\Models\User;
use App\Services\DemonstrationBaseline;
use App\Services\DemonstrationProvisioning;
use Database\Seeders\DevelopmentSupplierSeeder;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\HistoricalSalesCatalogFixtures;

function prepareRestorableDemonstration(): void
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
        ->sequence(fn ($sequence) => ['name' => 'Baseline fixture '.$sequence->index])
        ->create(['is_catalog_imported' => true]);
    test()->fakeIsolatedStorage('public');
    test()->fakeIsolatedStorage('local');
    Storage::disk('public')->put('products/fixture.webp', 'test-image-bytes');
    DB::table('products')->update(['image_path' => 'products/fixture.webp']);
    foreach (DB::table('inventories')->orderBy('id')->get() as $index => $inventory) {
        $quantity = $index === 0 ? 0 : ($index < 4 ? 2 : 10);
        DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => $quantity, 'reorder_level' => 5]);
        $audit = DB::table('audit_logs')->insertGetId([
            'actor_id' => null, 'action' => 'catalog.demo_inventory.provisioned', 'subject_type' => 'product',
            'subject_id' => $inventory->product_id, 'reason' => 'Fixture',
            'changes' => json_encode(['profile' => 'retailer-demo-v1', 'quantity' => $quantity, 'reorder_level' => 5]),
            'created_at' => now(),
        ]);
        DB::table('inventory_movements')->insert([
            'product_id' => $inventory->product_id, 'actor_id' => null, 'kind' => 'demo_initialization',
            'reference_type' => 'audit_log', 'reference_id' => $audit,
            'sellable_before' => 0, 'sellable_delta' => $quantity, 'sellable_after' => $quantity,
            'quarantined_before' => 0, 'quarantined_delta' => 0, 'quarantined_after' => 0, 'created_at' => now(),
        ]);
    }
    config(['catalog-demo.enabled' => true, 'catalog-demo.database' => DB::connection()->getDatabaseName(),
        'demonstration.connection' => DB::connection()->getName(), 'demonstration.host' => DB::connection()->getConfig('host'),
        'demonstration.provisioning_enabled' => true, 'demonstration.forecasting_enabled' => true,
        'demonstration.password' => 'Private-Test-Only!47291', 'app.timezone' => 'UTC',
        'queue.default' => 'database', 'session.driver' => 'database', 'cache.default' => 'database',
        'services.paymongo.mode' => 'test', 'services.paymongo.enabled' => false]);
    test()->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturn(true);
    Mail::fake();
    Notification::fake();
    app(DemonstrationProvisioning::class)->provision(true);
    config(['demonstration.provisioning_enabled' => false, 'catalog-demo.provisioning_enabled' => false]);
}

test('baseline inspection preserves database records and detects data schema and object drift', function () {
    prepareRestorableDemonstration();
    $baseline = app(DemonstrationBaseline::class);
    $before = $baseline->inspect();
    expect($baseline->inspect())->toBe($before)
        ->and($before['tables']['products']['count'])->toBe(654)
        ->and($before['tables']['users']['count'])->toBe(34);
    DB::table('chatbot_knowledge')->where('id', DB::table('chatbot_knowledge')->min('id'))->update(['priority' => 15]);
    expect($baseline->inspect()['tables'])->not->toBe($before['tables']);
    Storage::disk('public')->put('products/fixture.webp', 'changed');
    expect($baseline->inspect()['objects'])->not->toBe($before['objects']);
    DB::statement('CREATE INDEX baseline_test_index ON products (brand)');
    expect($baseline->inspect()['schema_sha256'])->not->toBe($before['schema_sha256']);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('baseline refuses unsafe targets transient state changed stock and missing images', function (string $condition) {
    prepareRestorableDemonstration();
    match ($condition) {
        'target' => config(['demonstration.host' => 'wrong']),
        'provisioning' => config(['demonstration.provisioning_enabled' => true]),
        'queue' => config(['queue.connections.database.connection' => 'other']),
        'cache' => config(['cache.default' => 'array']),
        'gateway' => config(['services.paymongo.enabled' => true]),
        'live' => config(['services.paymongo.mode' => 'live']),
        'user' => User::factory()->create(),
        'remember' => DB::table('users')->where('id', DB::table('users')->min('id'))->update(['remember_token' => 'stale']),
        'stock' => DB::table('inventories')->where('id', DB::table('inventories')->min('id'))->update(['quantity' => 1]),
        'jobs' => DB::table('jobs')->insert(['queue' => 'payments', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]),
        'otp' => DB::table('password_reset_tokens')->insert(['email' => 'test@example.com', 'token' => 'stale']),
        'image' => Storage::disk('public')->delete('products/fixture.webp'),
    };
    expect(fn () => app(DemonstrationBaseline::class)->inspect())->toThrow(RuntimeException::class);
})->with(['target', 'provisioning', 'queue', 'cache', 'gateway', 'live', 'user', 'remember', 'stock', 'jobs', 'otp', 'image']);

test('operator manifest requires acknowledgements refuses overwrite and verifies dump and release', function () {
    prepareRestorableDemonstration();
    $directory = sys_get_temp_dir().'/battlefront-baseline-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $dump = $directory.'/baseline.sql';
    $manifest = $directory.'/baseline.json';
    file_put_contents($dump, '-- fixture SQL archive; no database import is executed');
    $options = ['--capture' => $manifest, '--dump' => $dump, '--revision' => str_repeat('a', 40)];
    try {
        $this->artisan('battlefront:demo-baseline', $options)->assertFailed();
        $options += ['--confirm-demo' => true, '--quiesced' => true];
        $unsafe = $options;
        $unsafe['--capture'] = Storage::disk('public')->path('unsafe.json');
        $this->artisan('battlefront:demo-baseline', $unsafe)->assertFailed();
        $this->artisan('battlefront:demo-baseline', $options)->assertSuccessful();
        $bytes = file_get_contents($manifest);
        expect($bytes)->not->toContain('Private-Test-Only', 'code_hash', 'password"');
        $this->artisan('battlefront:demo-baseline', $options)->assertFailed();
        expect(file_get_contents($manifest))->toBe($bytes);
        unset($options['--capture']);
        $options['--verify'] = $manifest;
        $this->artisan('battlefront:demo-baseline', $options)->assertSuccessful();
        $options['--revision'] = str_repeat('b', 40);
        $this->artisan('battlefront:demo-baseline', $options)->assertFailed();
        $options['--revision'] = str_repeat('a', 40);
        file_put_contents($dump, 'altered archive');
        $this->artisan('battlefront:demo-baseline', $options)->assertFailed();
    } finally {
        if (is_file($manifest)) {
            unlink($manifest);
        }
        unlink($dump);
        rmdir($directory);
    }
});

test('old provider events cannot settle a new flow sharing an old numeric identity', function () {
    $order = Order::factory()->create([
        'payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo',
        'payment_status' => 'pending', 'total_amount' => '100.00',
    ]);
    $flow = PayMongoFlow::query()->create([
        'order_id' => $order->id, 'mode' => 'test', 'channel' => 'hosted_checkout', 'status' => 'open',
        'checkout_session_id' => 'cs_new_cycle', 'payment_intent_id' => 'pi_new_cycle',
        'amount_minor' => 10000, 'currency' => 'PHP', 'merchant_cutoff_at' => now()->addDay(),
    ]);
    $inbox = PayMongoWebhookInbox::query()->create([
        'mode' => 'test', 'event_id' => 'evt_retired_cycle', 'event_type' => 'checkout_session.payment.paid',
        'resource_id' => 'cs_retired_cycle', 'received_at' => now(),
        'verified_data' => ['attributes' => ['payment_intent_id' => 'pi_retired_cycle',
            'reference_number' => $order->reference, 'metadata' => ['order_id' => (string) $order->id],
            'payments' => [['id' => 'pay_retired_cycle', 'status' => 'paid', 'amount' => 10000, 'currency' => 'PHP']]]],
    ]);
    app()->call([new ProcessPayMongoWebhook($inbox->id), 'handle']);
    expect($inbox->fresh()->status)->toBe('unmatched')
        ->and($flow->fresh()->status)->toBe('open')
        ->and($order->fresh()->payment_status->value)->toBe('pending');
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('inventory_movements', 0);
});
