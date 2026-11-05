<?php

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\ChatbotKnowledge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayMongoFlow;
use App\Models\PayMongoWebhookInbox;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Repositories\Reporting\SalesReportRepository;
use App\Services\Cart\CartService;
use App\Services\DemonstrationCleanup;
use App\Services\DemonstrationProvisioning;
use App\Services\DemonstrationRehearsal;
use App\Services\Order\OrderPlacementService;
use App\Services\Order\OrderProcessingService;
use App\Services\Wishlist\WishlistService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['catalog-demo.enabled' => true, 'catalog-demo.database' => DB::connection()->getDatabaseName(),
        'demonstration.host' => DB::connection()->getConfig('host'), 'demonstration.connection' => DB::connection()->getName(),
        'demonstration.cleanup_enabled' => true, 'demonstration.rehearsal_enabled' => true,
        'demonstration.rehearsal_run_id' => (string) Str::uuid(), 'demonstration.rehearsal_key_hash' => hash('sha256', 'private-test-rehearsal-credential-2026')]);
    Product::factory()->available()->for(Category::factory())->count(654)->sequence(fn ($sequence) => ['name' => 'Cleanup catalog '.$sequence->index])->create(['is_catalog_imported' => true]);
    app(DemonstrationRehearsal::class)->audit('demo.rehearsal.started', 'run', 0, ['run' => config('demonstration.rehearsal_run_id')]);
    $baseline = Order::factory()->make(['user_id' => User::factory()->create()->id, 'status' => 'completed', 'payment_status' => 'verified', 'product_subtotal' => '10.00', 'total_amount' => '10.00']);
    $baseline->forceFill(['development_fixture_key' => 'cloud-demo:V1:baseline-test'])->save();
    Sale::factory()->for($baseline)->create(['amount' => '10.00']);
    DemonstrationProvisioning::audit('order', $baseline->id);
    DemonstrationProvisioning::audit('demonstration', 0, ['entries' => [], 'fingerprint' => app(DemonstrationProvisioning::class)->historyFingerprint()], 'demo.history.prepared');
});

function rehearsalMark(string $type, Model $model): void
{
    $records = app(DemonstrationRehearsal::class);
    $records->audit('demo.rehearsal.created', $type, $model->id, ['run' => config('demonstration.rehearsal_run_id'), 'owner_id' => $model->user_id ?? $model->id, 'fingerprint' => $records->fingerprint($model)]);
}

function rehearsalOrder(bool $completed = false): Order
{
    $product = Product::first();
    $order = Order::factory()->create(['payment_origin' => 'manual', 'product_subtotal' => '100.00', 'total_amount' => '100.00',
        'status' => $completed ? 'completed' : 'pending', 'payment_status' => $completed ? 'verified' : 'pending']);
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'price_at_time' => '100.00']);
    DB::table('inventory_movements')->insert(['product_id' => $product->id, 'actor_id' => null, 'kind' => 'order_placement', 'reference_type' => 'order',
        'reference_id' => $order->id, 'sellable_before' => 10, 'sellable_delta' => -1, 'sellable_after' => 9,
        'quarantined_before' => 0, 'quarantined_delta' => 0, 'quarantined_after' => 0, 'created_at' => now()]);
    rehearsalMark('order', $order);
    if ($completed) {
        $sale = Sale::factory()->for($order)->create(['amount' => '100.00']);
        app(DemonstrationRehearsal::class)->audit('demo.rehearsal.sale.posted', 'order', $order->id, [
            'run' => config('demonstration.rehearsal_run_id'), 'fingerprint' => app(DemonstrationRehearsal::class)->fingerprint($order), 'sale' => $sale->fresh()->getAttributes()]);
    }

    return $order;
}

test('dry runs preserve all records and orders scope does not touch accounts or FAQs', function () {
    $order = rehearsalOrder();
    $user = User::factory()->customer()->create();
    $faq = ChatbotKnowledge::factory()->create();
    rehearsalMark('user', $user);
    rehearsalMark('faq', $faq);
    $before = DB::table('audit_logs')->count();
    $stock = Product::first()->inventory->quantity;
    $this->artisan('battlefront:cleanup-demo --scope=all --confirm-demo')->assertSuccessful();
    expect($order->fresh()->status->value)->toBe('pending');
    expect(DB::table('audit_logs')->count())->toBe($before);
    $this->artisan('battlefront:cleanup-demo --scope=orders --confirm-demo --apply')->assertSuccessful();
    expect($order->fresh()->status->value)->toBe('cancelled');
    expect(Product::first()->inventory->quantity)->toBe($stock + 1);
    expect($user->fresh()->is_active)->toBeTrue();
    expect($faq->fresh())->not->toBeNull();
    $this->artisan('battlefront:cleanup-demo --scope=orders --confirm-demo --apply')->assertSuccessful();
    expect(Product::first()->inventory->quantity)->toBe($stock + 1);
});

test('all retires only marked customers and removes only unchanged marked FAQs', function () {
    $marked = User::factory()->customer()->create();
    $real = User::factory()->customer()->create();
    $conflict = User::factory()->customer()->create();
    rehearsalMark('user', $marked);
    rehearsalMark('user', $conflict);
    Order::factory()->for($conflict)->create();
    $token = $marked->createToken('rehearsal')->accessToken;
    $credentials = [$marked->password, $marked->email_verified_at->toISOString()];
    $faq = ChatbotKnowledge::factory()->create();
    $edited = ChatbotKnowledge::factory()->create();
    rehearsalMark('faq', $faq);
    rehearsalMark('faq', $edited);
    $edited->update(['response_template' => 'Changed outside captured provenance']);
    $this->artisan('battlefront:cleanup-demo --scope=all --confirm-demo --apply')->assertExitCode(2);
    expect($marked->fresh()->is_active)->toBeFalse();
    expect([$marked->fresh()->password, $marked->fresh()->email_verified_at->toISOString()])->toBe($credentials);
    expect($token->fresh())->toBeNull();
    expect($real->fresh()->is_active)->toBeTrue();
    expect($conflict->fresh()->is_active)->toBeTrue();
    expect($faq->fresh())->toBeNull();
    expect($edited->fresh())->not->toBeNull();
});

test('preserves all predefined accounts including the active Owner', function () {
    foreach (app(DemonstrationProvisioning::class)->accounts() as $email => $account) {
        $role = $account['role'];
        $user = User::factory()->create(['email' => $email, 'role' => $role ? 'administrator' : 'customer',
            'administration_role_id' => $role ? DB::table('administration_roles')->where('code', $role)->value('id') : null]);
        rehearsalMark('user', $user);
    }
    $before = DB::table('users')->orderBy('id')->get()->toJson();
    $this->artisan('battlefront:cleanup-demo --scope=all --confirm-demo --apply')->assertExitCode(2);
    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before);
});

test('requires explicit confirmation scope permission and exact target', function (string $guard) {
    if ($guard !== 'confirm' && $guard !== 'scope') {
        config([$guard => $guard === 'demonstration.cleanup_enabled' || $guard === 'catalog-demo.enabled' ? false : 'wrong-target']);
    }
    $command = 'battlefront:cleanup-demo --apply'.($guard === 'confirm' ? '' : ' --confirm-demo').($guard === 'scope' ? '' : ' --scope=all');
    $count = DB::table('audit_logs')->count();
    $this->artisan($command)->assertFailed();
    expect(DB::table('audit_logs')->count())->toBe($count);
})->with(['confirm', 'scope', 'demonstration.cleanup_enabled', 'catalog-demo.enabled', 'catalog-demo.database', 'demonstration.host', 'demonstration.connection']);

test('archives only proven offline rehearsals consistently and preserves forecasting fingerprint and stock', function () {
    $order = rehearsalOrder(true);
    $real = Order::factory()->create(['status' => 'completed', 'payment_status' => 'verified', 'product_subtotal' => '25.00', 'total_amount' => '25.00']);
    Sale::factory()->for($real)->create(['amount' => '25.00']);
    $fingerprint = app(DemonstrationProvisioning::class)->historyFingerprint();
    $stock = Product::first()->inventory->quantity;
    $snapshots = [$order->fresh()->getAttributes(), $order->items()->first()->getAttributes(), $order->sale->getAttributes()];
    $repository = app(SalesReportRepository::class);
    expect((float) $repository->recordedSalesSummary()->total_revenue)->toBe(135.0);
    $this->artisan('battlefront:cleanup-demo --scope=orders --confirm-demo --apply')->assertSuccessful();
    expect((float) $repository->recordedSalesSummary()->total_revenue)->toBe(35.0);
    expect((float) $repository->summary(['1900-01-01', '2100-01-01'], Product::first()->id, null)->total_revenue)->toBe(0.0);
    expect([$order->fresh()->getAttributes(), $order->items()->first()->getAttributes(), $order->sale()->first()->getAttributes()])->toBe($snapshots);
    expect(app(DemonstrationProvisioning::class)->historyFingerprint())->toBe($fingerprint);
    expect(Product::first()->inventory->quantity)->toBe($stock);
    config(['demonstration.cleanup_enabled' => false, 'demonstration.rehearsal_enabled' => false]);
    expect((float) $repository->recordedSalesSummary()->total_revenue)->toBe(35.0);
});

test('rejects payment evidence and inconsistent or incomplete provenance', function (string $reason) {
    $order = rehearsalOrder($reason === 'posted');
    if ($reason === 'gateway') {
        $order->forceFill(['payment_origin' => 'paymongo'])->save();
    }
    if ($reason === 'proof') {
        $order->forceFill(['payment_proof_path' => 'payment-proofs/retain.jpg'])->save();
    }
    if ($reason === 'items') {
        DB::table('order_items')->where('order_id', $order->id)->update(['quantity' => 2]);
    }
    if ($reason === 'posted') {
        $order->forceFill(['assigned_handler_id' => User::factory()->create()->id])->save();
    }
    $before = $order->fresh()->getAttributes();
    $this->artisan('battlefront:cleanup-demo --scope=orders --confirm-demo --apply')->assertExitCode(2);
    expect($order->fresh()->getAttributes())->toBe($before);
})->with(['gateway', 'proof', 'items', 'posted']);

test('private opt in marks registration but preserves normal OTP and denies closed runs', function () {
    Mail::fake();
    $this->post(route('demonstration.rehearsal.store'), ['password' => 'wrong', 'fictional' => 1])->assertSessionHasErrors('password');
    $this->post(route('demonstration.rehearsal.store'), ['password' => 'private-test-rehearsal-credential-2026', 'fictional' => 1])->assertRedirect();
    $this->post(route('register.store'), ['name' => 'Fictional rehearsal', 'email' => 'rehearsal@example.test',
        'password' => 'Strong-Registration-Example-2026!', 'password_confirmation' => 'Strong-Registration-Example-2026!'])->assertRedirect();
    $user = User::where('email', 'rehearsal@example.test')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    expect($user->role->value)->toBe('customer');
    expect(app(DemonstrationRehearsal::class)->matches('user', $user))->toBeTrue();
    $this->assertGuest();
    $this->artisan('battlefront:cleanup-demo --scope=all --confirm-demo --apply')->assertSuccessful();
    $this->post(route('demonstration.rehearsal.store'), ['password' => 'private-test-rehearsal-credential-2026', 'fictional' => 1])->assertStatus(409);
});

test('real placement and authorized offline completion record matching rehearsal provenance', function () {
    $customer = User::factory()->customer()->create();
    $handler = User::factory()->administrator()->create();
    $run = DB::table('audit_logs')->where('action', 'demo.rehearsal.started')->first();
    $context = ['run' => config('demonstration.rehearsal_run_id'), 'run_record' => $run->id, 'user_id' => $customer->id];
    $this->actingAs($customer);
    request()->attributes->set('demonstration_rehearsal', $context);
    $product = Product::first();
    $item = app(CartService::class)->add($customer, $product->id, 1);
    $order = app(OrderPlacementService::class)->execute($customer, [
        'cart_item_ids' => [$item->id], 'recipient_name' => $customer->name, 'contact_number' => '09171234567',
        'fulfillment_method' => FulfillmentMethod::Pickup, 'delivery_address' => null,
        'payment_method' => PaymentMethod::Cash, 'payment_proof_path' => null,
    ]);
    expect(app(DemonstrationRehearsal::class)->matches('order', $order))->toBeTrue();
    $order->forceFill(['assigned_handler_id' => $handler->id])->save();
    $this->actingAs($handler);
    request()->attributes->set('demonstration_rehearsal', [...$context, 'user_id' => $handler->id]);
    $processing = app(OrderProcessingService::class);
    $processing->updateStatus($order, OrderStatus::Processing, $handler);
    $processing->confirmPickup($order->fresh(), true, true, $handler);
    expect(app(DemonstrationCleanup::class)->archiveRefusal($order->fresh()))->toBeNull();
    $this->artisan('battlefront:cleanup-demo --scope=orders --confirm-demo --apply')->assertSuccessful();
    expect(app(DemonstrationRehearsal::class)->archivedOrderIds())->toContain($order->id);
});

test('gateway flows and inbox evidence survive even when an order is incorrectly marked offline', function () {
    $order = rehearsalOrder();
    $flow = PayMongoFlow::query()->create(['order_id' => $order->id, 'mode' => 'test', 'channel' => 'hosted_checkout',
        'status' => 'open', 'amount_minor' => 10000, 'currency' => 'PHP', 'checkout_session_id' => 'cs_preserved', 'merchant_cutoff_at' => now()->addDay()]);
    $inbox = PayMongoWebhookInbox::query()->create(['mode' => 'test', 'event_id' => 'evt_preserved', 'event_type' => 'payment.paid',
        'resource_id' => 'cs_preserved', 'verified_data' => [], 'status' => 'pending', 'received_at' => now(), 'attempts' => 0]);
    $before = [$flow->fresh()->getAttributes(), $inbox->fresh()->getAttributes()];
    $this->artisan('battlefront:cleanup-demo --scope=orders --confirm-demo --apply')->assertExitCode(2);
    expect([$flow->fresh()->getAttributes(), $inbox->fresh()->getAttributes()])->toBe($before);
    expect($order->fresh()->status->value)->toBe('pending');
});

test('unexpected failures roll back cancellation stock and run closure together', function () {
    $first = rehearsalOrder();
    $second = rehearsalOrder();
    $before = [DB::table('inventory_movements')->count(), DB::table('audit_logs')->count(), Product::first()->inventory->quantity];
    Order::updating(function (Order $order) use ($second): void {
        if ($order->id === $second->id) {
            throw new RuntimeException('Injected cleanup failure');
        }
    });
    $this->artisan('battlefront:cleanup-demo --scope=orders --confirm-demo --apply')->assertFailed();
    expect($first->fresh()->status->value)->toBe('pending');
    expect([DB::table('inventory_movements')->count(), DB::table('audit_logs')->count(), Product::first()->inventory->quantity])->toBe($before);
});

test('post creation edits permanently withdraw eligibility even if reverted', function () {
    $faq = ChatbotKnowledge::factory()->create();
    rehearsalMark('faq', $faq);
    $original = $faq->response_template;
    $faq->update(['response_template' => 'Revised support answer']);
    $faq->update(['response_template' => $original]);
    expect(app(DemonstrationRehearsal::class)->matches('faq', $faq))->toBeFalse();
    $this->artisan('battlefront:cleanup-demo --scope=all --confirm-demo --apply')->assertExitCode(2);
    expect($faq->fresh())->not->toBeNull();
});

test('private page hides credentials and expired recording cannot tag new accounts', function () {
    $this->get(route('demonstration.rehearsal.show'))->assertOk()
        ->assertDontSee('private-test-rehearsal-credential-2026')
        ->assertDontSee(config('demonstration.rehearsal_key_hash'));
    $this->post(route('demonstration.rehearsal.store'), ['password' => 'private-test-rehearsal-credential-2026', 'fictional' => 1])->assertRedirect();
    $this->travel(61)->minutes();
    $this->post(route('register.store'), ['name' => 'Expired rehearsal', 'email' => 'expired@example.test',
        'password' => 'Strong-Registration-Example-2026!', 'password_confirmation' => 'Strong-Registration-Example-2026!'])->assertStatus(409);
    expect(User::where('email', 'expired@example.test')->exists())->toBeFalse();
    config(['demonstration.rehearsal_enabled' => false]);
    $this->get(route('demonstration.rehearsal.show'))->assertNotFound();
});

test('dry run and apply both defer customer retirement when shopping dependencies exist at preflight', function () {
    $user = User::factory()->customer()->create();
    rehearsalMark('user', $user);
    $this->actingAs($user);
    $context = ['run' => config('demonstration.rehearsal_run_id'), 'run_record' => DB::table('audit_logs')->where('action', 'demo.rehearsal.started')->value('id')];
    request()->attributes->set('demonstration_rehearsal', $context);
    app(WishlistService::class)->add($user, Product::first()->id);
    $dry = app(DemonstrationCleanup::class)->execute('all', false);
    $apply = app(DemonstrationCleanup::class)->execute('all', true);
    expect(collect($dry)->firstWhere('type', 'user')['action'])->toBe('excluded');
    expect(collect($apply)->firstWhere('type', 'user')['action'])->toBe('excluded');
    expect($user->fresh()->is_active)->toBeTrue();
    expect($user->wishlistItems()->count())->toBe(0);
});
