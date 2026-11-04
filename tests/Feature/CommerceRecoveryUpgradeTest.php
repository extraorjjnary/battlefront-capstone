<?php

use App\Enums\PaymentMethod;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayMongoFlow;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('recovery and supplier migrations preserve existing commerce when applied after newer main migrations', function (string $driver) {
    if ($driver === 'mysql' && getenv('BATTLEFRONT_PAYMONGO_MYSQL_TESTS') !== '1') {
        $this->markTestSkipped('MySQL upgrades run only in the explicitly enabled disposable MySQL suite.');
    }
    $originalConnection = DB::getDefaultConnection();
    $databaseName = null;
    config(['database.connections.commerce_upgrade' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    if ($driver === 'mysql') {
        $server = array_replace(config('database.connections.mysql'), ['url' => null, 'database' => null]);
        config(['database.connections.commerce_upgrade_server' => $server]);
        $databaseName = 'battlefront_commerce_upgrade_test_'.bin2hex(random_bytes(6));
        DB::connection('commerce_upgrade_server')->statement('CREATE DATABASE `'.$databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.commerce_upgrade' => array_replace($server, ['database' => $databaseName])]);
    }
    $incomingMigrations = [
        database_path('migrations/2026_10_30_162208_add_recovery_tracking_to_paymongo_flows_and_orders.php'),
        database_path('migrations/2026_10_31_152936_create_product_supplier_table.php'),
    ];
    $baselineMigrations = array_values(array_diff(glob(database_path('migrations/*.php')), $incomingMigrations));
    DB::setDefaultConnection('commerce_upgrade');
    Schema::clearResolvedInstance('db.schema');

    try {
        expect(Artisan::call('migrate', [
            '--database' => 'commerce_upgrade', '--path' => $baselineMigrations,
            '--realpath' => true, '--no-interaction' => true,
        ]))->toBe(0);
        $customer = User::factory()->customer()->create();
        $order = Order::factory()->for($customer)->create([
            'payment_method' => PaymentMethod::Online, 'payment_origin' => 'paymongo',
            'payment_status' => 'verified', 'total_amount' => '100.00',
        ]);
        $inventory = Inventory::factory()->create(['quantity' => 7]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $inventory->product_id, 'quantity' => 2, 'price_at_time' => '50.00',
        ]);
        $flow = PayMongoFlow::unguarded(fn (): PayMongoFlow => PayMongoFlow::query()->create([
            'order_id' => $order->id, 'mode' => 'test', 'channel' => 'hosted_checkout',
            'status' => 'paid', 'payment_id' => 'pay_retained', 'checkout_session_id' => 'cs_retained',
            'amount_minor' => 10000, 'currency' => 'PHP', 'merchant_cutoff_at' => now()->addDay(),
        ]));
        $existingRows = [
            'users' => $customer->getRawOriginal(), 'orders' => $order->getRawOriginal(),
            'inventories' => $inventory->getRawOriginal(), 'order_items' => $item->getRawOriginal(),
            'paymongo_flows' => $flow->getRawOriginal(),
        ];

        expect(Artisan::call('migrate', [
            '--database' => 'commerce_upgrade', '--path' => $incomingMigrations,
            '--realpath' => true, '--no-interaction' => true,
        ]))->toBe(0);

        foreach ($existingRows as $table => $row) {
            $this->assertDatabaseHas($table, $row, 'commerce_upgrade');
        }
        expect($flow->fresh()->requires_attention)->toBeFalse()
            ->and($flow->fresh()->recovery_failures)->toBe(0)
            ->and($order->fresh()->payment_closure_requested_at)->toBeNull();
        $this->assertDatabaseCount('product_supplier', 0, 'commerce_upgrade');
        $this->assertDatabaseMissing('administration_roles', ['code' => 'it'], 'commerce_upgrade');
    } finally {
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        DB::purge('commerce_upgrade');
        if ($databaseName !== null) {
            if (preg_match('/\Abattlefront_commerce_upgrade_test_[a-f0-9]{12}\z/', $databaseName) !== 1) {
                throw new RuntimeException('Refusing to clean up an unexpected upgrade database name.');
            }
            DB::connection('commerce_upgrade_server')->statement('DROP DATABASE `'.$databaseName.'`');
            DB::purge('commerce_upgrade_server');
        }
    }
})->with(['sqlite', 'mysql']);
