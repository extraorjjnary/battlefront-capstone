<?php

use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{Order, int|null, int, User} */
function singleAdminRefund(string $role = 'manager', bool $requested = true): array
{
    Storage::fake('local');
    $staff = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id')]);
    $approver = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', $role)->value('id')]);
    $order = Order::factory()->create(['status' => 'completed', 'payment_status' => 'verified']);
    $stock = Inventory::factory()->create(['quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($stock->product)->create(['quantity' => 1, 'price_at_time' => '100.00']);
    $order->sale()->create(['amount' => '100.00', 'sale_date' => today()]);
    $request = $requested ? app(HandleCustomerReturnRequest::class)->submit($order->user, $order->id, ['order_item_id' => $item->id, 'quantity' => 1, 'reason_code' => 'defective']) : null;
    $id = $request !== null ? app(HandleCustomerReturnRequest::class)->receive($staff, $request->id, 'opened', [UploadedFile::fake()->image('product.jpg')])
        : app(HandleCustomerReturn::class)->receive($staff, $item->id, 1, 'opened', 'Defective', 'defective', photos: [UploadedFile::fake()->image('product.jpg')]);
    app(HandleCustomerReturn::class)->decide($id, $approver, true, 'write_off', '100.00', 'Defect confirmed after inspection', resolution: ['type' => 'refund']);

    return [$order, $request?->id, (int) DB::table('customer_refunds')->where('customer_return_id', $id)->value('id'), $approver];
}

test('either Manager or Owner completes a refund alone with appropriate payment proof', function (string $role, string $method) {
    [$order, $requestId, $refund, $approver] = singleAdminRefund($role);
    $payload = ['amount' => '100.00', 'payment_method' => $method, 'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true];
    if ($method !== 'cash') {
        $payload['proof'] = UploadedFile::fake()->image('transfer.jpg');
    }
    $this->actingAs($approver)->post(route('administration.customer-refunds.payments.store', $refund), $payload)->assertSessionHasNoErrors();
    $payment = DB::table('customer_refund_payments')->sole();
    $this->assertDatabaseHas('customer_refund_payment_reviews', ['customer_refund_payment_id' => $payment->id, 'reviewed_by' => $approver->id, 'decision' => 'verified', 'confirmation_method' => 'approver']);
    expect($payment->external_reference)->toBeNull();
    $this->assertDatabaseCount('customer_refund_payment_reviews', 1);
    $response = $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->where('order.return_requests.0.status.value', 'refunded')->where('order.return_requests.0.refund.confirmed_paid_amount', '100.00'));
    $proof = $response->viewData('page')['props']['order']['return_requests'][0]['refund']['payments'][0]['proof'];
    if ($method === 'cash') {
        expect($proof)->toBeNull();
        $this->assertDatabaseCount('administration_evidence', 1);
    } else {
        expect($proof)->not->toBeNull();
        $this->get(route('orders.refund-payments.proof.show', [$order->id, $payment->id, $proof['id']]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->withToken($order->user->createToken('phone')->plainTextToken)->getJson($proof['url'])->assertOk();
    }
    expect($order->user->notifications()->where('data->event', 'refund.completed')->count())->toBe(1);
})->with(['manager', 'owner'])->with(['cash', 'gcash', 'maya', 'bank_transfer', 'other']);

test('missing payment confirmation and invalid noncash snapshots do not create payments', function (string $failure) {
    [$order, $requestId, $refund, $approver] = singleAdminRefund();
    $payload = ['amount' => '100.00', 'payment_method' => 'gcash', 'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true];
    $payload += match ($failure) {
        'missing image' => [],
        'pdf' => ['proof' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')],
        'oversized' => ['proof' => UploadedFile::fake()->image('large.jpg')->size(5121)],
        'confirmation' => ['proof' => UploadedFile::fake()->image('transfer.jpg')],
    };
    if ($failure === 'confirmation') {
        $payload['payment_confirmed'] = false;
    }
    $this->actingAs($approver)->post(route('administration.customer-refunds.payments.store', $refund), $payload)->assertSessionHasErrors($failure === 'confirmation' ? 'payment_confirmed' : 'proof');
    $this->assertDatabaseCount('customer_refund_payments', 0);
    $this->assertDatabaseCount('customer_refund_payment_reviews', 0);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with(['missing image', 'pdf', 'oversized', 'confirmation']);

test('a payment submission token makes repeat partial transfers idempotent without replacing proof', function () {
    [$order, $requestId, $refund, $approver] = singleAdminRefund();
    $payload = ['amount' => '40.25', 'payment_method' => 'gcash', 'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('transfer.jpg')];
    $this->actingAs($approver)->post(route('administration.customer-refunds.payments.store', $refund), $payload)->assertSessionHasNoErrors();
    $original = DB::table('administration_evidence')->where('subject_type', 'refund_payment')->sole();
    $this->post(route('administration.customer-refunds.payments.store', $refund), $payload)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('customer_refund_payments', 1);
    $this->assertDatabaseHas('customer_refunds', ['id' => $refund, 'paid_amount' => '40.25']);
    Storage::disk('local')->assertExists($original->path);
    expect(Storage::disk('local')->allFiles())->toHaveCount(2);
    $this->post(route('administration.customer-refunds.payments.store', $refund), [...$payload, 'amount' => '41.00'])->assertSessionHasErrors('submission_key');
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.return_requests.0.status.value', 'refund_processing'));
    $this->actingAs($approver)->post(route('administration.customer-refunds.payments.store', $refund), ['amount' => '59.75', 'payment_method' => 'cash', 'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true])->assertSessionHasNoErrors();
    expect($order->user->notifications()->where('data->event', 'refund.completed')->count())->toBe(1);
});

test('customer proof access conceals foreign orders private legacy attachments and Staff photos', function () {
    [$order, $requestId, $refund, $approver] = singleAdminRefund();
    app(HandleCustomerReturn::class)->recordPayment($refund, $approver, '100.00', 'gcash', (string) Str::uuid(), true, UploadedFile::fake()->image('transfer.jpg'));
    $payment = DB::table('customer_refund_payments')->sole();
    $proof = DB::table('administration_evidence')->where('subject_type', 'refund_payment')->sole();
    $url = route('orders.refund-payments.proof.show', [$order->id, $payment->id, $proof->id]);
    $this->actingAs(User::factory()->customer()->create())->get($url)->assertNotFound();
    $this->actingAs($order->user)->get($url)->assertOk();
    $product = DB::table('administration_evidence')->where('subject_type', 'customer_return')->sole();
    $this->get(route('orders.refund-payments.proof.show', [$order->id, $payment->id, $product->id]))->assertNotFound();
    $privateId = DB::table('administration_evidence')->insertGetId(['subject_type' => 'refund_payment', 'subject_id' => $payment->id, 'uploaded_by' => $approver->id,
        'path' => $proof->path.'.private', 'original_name' => 'private.jpg', 'mime_type' => 'image/jpeg', 'created_at' => now(), 'customer_visible' => false]);
    $this->get(route('orders.refund-payments.proof.show', [$order->id, $payment->id, $privateId]))->assertNotFound();
    Storage::disk('local')->delete($proof->path);
    $this->get($url)->assertNotFound();
});

test('refund payment confirmation does not require the separate legacy reconciliation permission', function () {
    [$order, $requestId, $refund, $approver] = singleAdminRefund();
    DB::table('administration_role_permissions')->where('administration_role_id', $approver->administration_role_id)
        ->where('administration_permission_id', DB::table('administration_permissions')->where('code', 'returns.reconcile')->value('id'))->delete();
    $actor = User::query()->findOrFail($approver->id);
    request()->attributes->remove('administration-role-permissions:'.$actor->administration_role_id);
    expect($actor->can('returns.reconcile'))->toBeFalse();
    $this->actingAs($actor)->post(route('administration.customer-refunds.payments.store', $refund), ['amount' => '100.00', 'payment_method' => 'cash', 'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('customer_refund_payment_reviews', ['reviewed_by' => $actor->id, 'confirmation_method' => 'approver']);
});

test('customer proof web and API endpoints reject unauthenticated and foreign bearer requests', function () {
    [$order, $requestId, $refund, $approver] = singleAdminRefund();
    app(HandleCustomerReturn::class)->recordPayment($refund, $approver, '100.00', 'gcash', (string) Str::uuid(), true, UploadedFile::fake()->image('proof.jpg'));
    $payment = DB::table('customer_refund_payments')->sole();
    $photo = DB::table('administration_evidence')->where('subject_type', 'refund_payment')->sole();
    $parameters = [$order->id, $payment->id, $photo->id];
    $this->get(route('orders.refund-payments.proof.show', $parameters))->assertRedirectToRoute('login');
    $this->getJson(route('api.v1.orders.refund-payments.proof.show', $parameters))->assertUnauthorized();
    $other = User::factory()->customer()->create();
    $this->withToken($other->createToken('phone')->plainTextToken)->getJson(route('api.v1.orders.refund-payments.proof.show', $parameters))->assertNotFound();
});

test('new refund payment and review histories remain immutable after nullable-reference migration', function () {
    [$order, $requestId, $refund, $approver] = singleAdminRefund();
    app(HandleCustomerReturn::class)->recordPayment($refund, $approver, '100.00', 'cash', (string) Str::uuid(), true);
    $payment = DB::table('customer_refund_payments')->sole();
    expect(fn () => DB::table('customer_refund_payments')->where('id', $payment->id)->update(['amount' => '1.00']))->toThrow(QueryException::class);
    expect(fn () => DB::table('customer_refund_payments')->where('id', $payment->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('customer_refund_payment_reviews')->where('customer_refund_payment_id', $payment->id)->update(['confirmation_method' => null]))->toThrow(QueryException::class);
});

test('rejected audit insertion rolls back payment confirmation proof and completion notification', function () {
    [$order, $requestId, $refund, $approver] = singleAdminRefund();
    DB::unprepared("CREATE TRIGGER fail_new_refund_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'customer_refund.paid' BEGIN SELECT RAISE(ABORT, 'Forced payment audit failure'); END");
    Exceptions::fake();
    try {
        $this->actingAs($approver)->post(route('administration.customer-refunds.payments.store', $refund), ['amount' => '100.00', 'payment_method' => 'gcash',
            'submission_key' => (string) Str::uuid(), 'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('proof.jpg')])->assertServerError();
    } finally {
        DB::unprepared('DROP TRIGGER fail_new_refund_audit');
    }
    $this->assertDatabaseCount('customer_refund_payments', 0);
    $this->assertDatabaseCount('customer_refund_payment_reviews', 0);
    $this->assertDatabaseHas('customer_refunds', ['id' => $refund, 'paid_amount' => '0.00']);
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    expect($order->user->notifications()->where('data->event', 'refund.completed')->count())->toBe(0);
});

test('Staff-assisted refunds expose confirmed payment proof even without a customer request entry', function () {
    [$order, $requestId, $refund, $approver] = singleAdminRefund(requested: false);
    expect($requestId)->toBeNull();
    app(HandleCustomerReturn::class)->recordPayment($refund, $approver, '100.00', 'gcash', (string) Str::uuid(), true, UploadedFile::fake()->image('proof.jpg'));
    $this->actingAs($order->user)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
        ->has('order.return_requests', 0)->has('order.refund_payments', 1)->where('order.refund_payments.0.confirmation_method', 'approver')->has('order.refund_payments.0.proof.id'));
});
