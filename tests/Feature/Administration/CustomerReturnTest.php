<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('customer return cases can be searched by product, requester, order, or reason', function () {
    $administrator = User::factory()->administrator()->create();
    $product = Product::factory()->create(['name' => 'Search return product']);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->for($product)->create();
    $returnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id,
        'requester_id' => $administrator->id,
        'status' => 'pending',
        'reason' => 'Search return reason',
        'created_at' => now()->subDays(4),
        'updated_at' => now(),
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $returnId,
        'order_item_id' => $item->id,
        'quantity' => 1,
        'condition' => 'sealed',
    ]);

    $response = $this->actingAs($administrator)->get(route('administration.customer-returns.index', ['q' => 'Search return product', 'overdue' => 1]));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.q', 'Search return product')
        ->where('filters.overdue', true)
        ->where('returns.total', 1)
        ->where('returns.data.0.id', $returnId));

    expect(fn () => DB::table('customer_returns')->where('id', $returnId)->delete())
        ->toThrow(QueryException::class);
});

test('return search uses the customer and order lookup shows remaining returnable units', function () {
    $administrator = User::factory()->administrator()->create();
    $customer = User::factory()->customer()->create(['name' => 'Actual return customer']);
    $product = Product::factory()->create(['name' => 'Return quantity product']);
    $order = Order::factory()->for($customer)->create(['status' => OrderStatus::Completed]);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2]);
    $returnId = DB::table('customer_returns')->insertGetId([
        'order_id' => $order->id,
        'requester_id' => $administrator->id,
        'status' => 'pending',
        'reason' => 'Return already received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_return_items')->insert([
        'customer_return_id' => $returnId,
        'order_item_id' => $item->id,
        'quantity' => 1,
        'condition' => 'opened',
    ]);

    $this->actingAs($administrator)
        ->get(route('administration.customer-returns.index', ['q' => $customer->name, 'order_id' => $order->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('returns.total', 1)
            ->where('returns.data.0.customer_name', $customer->name)
            ->where('selected_order.items.0.remaining_returnable', 1));

    $this->actingAs($administrator)
        ->get(route('administration.customer-returns.index', ['order_id' => $order->reference]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_order.id', $order->id)
            ->where('selected_order.reference', $order->reference));
});

test('a completed purchase can be returned and manually refunded without rewriting its sale', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified, 'product_subtotal' => '200.00', 'total_amount' => '200.00']);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '100.00']);
    $sale = $order->sale()->create(['amount' => '200.00', 'sale_date' => today()]);

    $this->actingAs($requester)->post(route('administration.customer-returns.store'), [
        'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'opened', 'reason_code' => 'defective', 'reason' => 'Customer reported a defect',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('customer_returns')->where('order_id', $order->id)->value('id');
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(1);

    $this->actingAs($requester)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '100.00', 'reason' => 'Inspection confirms a fault',
    ])->assertSessionHasErrors('decision');
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '100.00', 'reason' => 'Inspection confirms a fault',
    ])->assertSessionHasErrors('evidence');
    $this->actingAs($requester)->post(route('administration.evidence.store', ['subjectType' => 'customer_return', 'subjectId' => $returnId]), [
        'files' => [UploadedFile::fake()->image('return.jpg')],
    ])->assertSessionHasNoErrors();
    $evidence = DB::table('administration_evidence')->where('subject_type', 'customer_return')->where('subject_id', $returnId)->first();
    $this->actingAs($requester)->get(route('administration.evidence.show', $evidence->id))
        ->assertOk()->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('content-disposition', 'inline; filename=return.jpg');
    $this->actingAs($requester)->get(route('administration.customer-returns.index', ['case' => $returnId]))
        ->assertInertia(fn (Assert $page) => $page->where("evidence.{$returnId}.0.mime_type", 'image/jpeg'));
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '100.00', 'reason' => 'Inspection confirms a fault',
    ])->assertSessionHasNoErrors();

    $refundId = DB::table('customer_refunds')->where('customer_return_id', $returnId)->value('id');
    $this->actingAs($requester)->post(route('administration.customer-refunds.payments.store', $refundId), [
        'amount' => '60.00', 'payment_method' => 'cash', 'external_reference' => 'CASH-1',
    ])->assertSessionHasNoErrors();
    $paymentId = DB::table('customer_refund_payments')->where('customer_refund_id', $refundId)->value('id');
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'customer_refund.paid', 'subject_type' => 'refund_payment', 'subject_id' => $paymentId,
    ]);
    $this->actingAs($requester)->post(route('administration.customer-refunds.payments.store', $refundId), [
        'amount' => '40.00', 'payment_method' => 'cash', 'external_reference' => 'CASH-2',
    ])->assertSessionHasNoErrors();
    $this->actingAs($requester)->post(route('administration.customer-refunds.payments.store', $refundId), [
        'amount' => '1.00', 'payment_method' => 'cash', 'external_reference' => 'CASH-3',
    ])->assertSessionHasErrors('amount');

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($sale->fresh()->amount)->toBe('200.00')
        ->and((float) DB::table('customer_refunds')->where('id', $refundId)->value('paid_amount'))->toBe(100.0);
    expect(DB::table('customer_refund_payments')->where('customer_refund_id', $refundId)->count())->toBe(2);
    expect(fn () => DB::table('customer_returns')->where('id', $returnId)->delete())->toThrow(QueryException::class);
});

test('the received quantity cannot exceed the purchased quantity across pending returns', function () {
    $actor = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'price_at_time' => '50.00']);
    $order->sale()->create(['amount' => '50.00', 'sale_date' => today()]);

    $payload = ['order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'sealed', 'reason_code' => 'wrong_item', 'reason' => 'Wrong product delivered'];
    $this->actingAs($actor)->post(route('administration.customer-returns.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($actor)->post(route('administration.customer-returns.store'), $payload)->assertSessionHasErrors('quantity');
    expect(DB::table('customer_returns')->where('order_id', $order->id)->count())->toBe(1);
});

test('restocking a customer return requires an explicit sellability inspection', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'price_at_time' => '50.00']);
    $order->sale()->create(['amount' => '50.00', 'sale_date' => today()]);
    $sellableBefore = $product->inventory->quantity;

    $this->actingAs($requester)->post(route('administration.customer-returns.store'), [
        'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'opened', 'reason_code' => 'changed_mind', 'reason' => 'Customer returned the item',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('customer_returns')->where('order_id', $order->id)->value('id');
    $decision = [
        'decision' => 'accept', 'disposition' => 'restock', 'approved_amount' => '0.00',
        'reason' => 'Inspection found no damage',
    ];

    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), $decision)
        ->assertSessionHasErrors('inspection_confirmed');
    expect($product->inventory->fresh()->quantity)->toBe($sellableBefore);

    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), [
        ...$decision, 'inspection_confirmed' => '1',
    ])->assertSessionHasNoErrors();

    expect($product->inventory->fresh()->quantity)->toBe($sellableBefore + 1)
        ->and($product->inventory->fresh()->quarantined_quantity)->toBe(0);
    $this->assertDatabaseHas('customer_returns', [
        'id' => $returnId,
        'decision_reason' => 'Sellability inspected and confirmed. Inspection found no damage',
    ]);
});

test('a rejected customer return remains quarantined until a single customer handover', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'price_at_time' => '50.00']);
    $order->sale()->create(['amount' => '50.00', 'sale_date' => today()]);
    $this->actingAs($requester)->post(route('administration.customer-returns.store'), [
        'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'opened', 'reason_code' => 'changed_mind', 'reason' => 'Customer requested a return',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('customer_returns')->where('order_id', $order->id)->value('id');

    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'reject', 'disposition' => 'supplier_return', 'approved_amount' => '50.00', 'reason' => 'No eligible return reason',
    ])->assertSessionHasErrors(['disposition', 'approved_amount']);
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'reject', 'reason' => 'No eligible return reason',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customer_returns', ['id' => $returnId, 'status' => 'rejected_pending_handover']);
    $this->assertDatabaseCount('supplier_returns', 0);
    $this->assertDatabaseCount('customer_refunds', 0);
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(1);

    $this->actingAs($requester)->post(route('administration.customer-returns.handover', $returnId))->assertSessionHasNoErrors();
    $this->actingAs($requester)->post(route('administration.customer-returns.handover', $returnId))->assertSessionHasErrors('handover');
    $this->assertDatabaseHas('customer_returns', ['id' => $returnId, 'status' => 'rejected']);
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(0);
});

test('another administrator can reconcile a recorded refund payment without changing its amount', function () {
    $requester = User::factory()->administrator()->create();
    $approver = User::factory()->administrator()->create();
    $recorder = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'price_at_time' => '50.00']);
    $order->sale()->create(['amount' => '50.00', 'sale_date' => today()]);
    $this->actingAs($requester)->post(route('administration.customer-returns.store'), [
        'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'damaged', 'reason_code' => 'transit_damage', 'reason' => 'Customer reported damaged item',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('customer_returns')->where('order_id', $order->id)->value('id');
    $this->actingAs($approver)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '50.00', 'reason' => 'Inspection confirmed damage',
    ])->assertSessionHasErrors('evidence');
    $this->actingAs($requester)->post(route('administration.evidence.store', ['subjectType' => 'customer_return', 'subjectId' => $returnId]), [
        'files' => [UploadedFile::fake()->image('return-damage.jpg')],
    ])->assertSessionHasNoErrors();
    $this->actingAs($approver)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '50.00', 'reason' => 'Inspection confirmed damage',
    ])->assertSessionHasNoErrors();
    $refundId = DB::table('customer_refunds')->where('customer_return_id', $returnId)->value('id');
    $this->actingAs($recorder)->post(route('administration.customer-refunds.payments.store', $refundId), [
        'amount' => '30.00', 'payment_method' => 'cash', 'external_reference' => 'CASH-REVIEW-1',
    ])->assertSessionHasNoErrors();
    $paymentId = DB::table('customer_refund_payments')->where('customer_refund_id', $refundId)->value('id');
    $staff = User::factory()->administrator()->create([
        'administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id'),
    ]);

    $this->actingAs($staff)->post(route('administration.customer-refund-payments.review', $paymentId), [
        'decision' => 'verified', 'reason' => 'Attempt by unauthorized staff',
    ])->assertForbidden();
    $this->actingAs($recorder)->post(route('administration.customer-refund-payments.review', $paymentId), [
        'decision' => 'verified', 'reason' => 'Cash record checked in ledger',
    ])->assertSessionHasErrors('decision');
    $this->actingAs($reviewer)->post(route('administration.customer-refund-payments.review', $paymentId), [
        'decision' => 'disputed', 'reason' => 'Receipt reference needs checking',
    ])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('administration.customer-refund-payments.review', $paymentId), [
        'decision' => 'verified', 'reason' => 'Receipt checked against cash book',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customer_refund_payment_reviews', ['customer_refund_payment_id' => $paymentId, 'decision' => 'verified']);
    expect((float) DB::table('customer_refunds')->where('id', $refundId)->value('paid_amount'))->toBe(30.0);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'customer_refund.payment_reviewed', 'subject_type' => 'refund_payment', 'subject_id' => $paymentId,
    ]);
    $this->assertDatabaseCount('customer_refund_payments', 1);

    $this->actingAs($requester)->post(route('administration.customer-refunds.payments.store', $refundId), [
        'amount' => '20.00', 'payment_method' => 'cash', 'external_reference' => 'CASH-REVIEW-2',
    ])->assertSessionHasNoErrors();
    $disputedPaymentId = DB::table('customer_refund_payments')->where('external_reference', 'CASH-REVIEW-2')->value('id');
    $this->actingAs($reviewer)->post(route('administration.customer-refund-payments.review', $disputedPaymentId), [
        'decision' => 'disputed', 'reason' => 'Receipt does not match the cash book',
    ])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('administration.customer-refund-payments.review', $disputedPaymentId), [
        'decision' => 'voided', 'reason' => 'Confirmed duplicate payment record',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('customer_refund_payment_reviews', [
        'customer_refund_payment_id' => $disputedPaymentId,
        'decision' => 'voided',
        'reason' => 'Confirmed duplicate payment record',
    ]);
    expect((float) DB::table('customer_refunds')->where('id', $refundId)->value('paid_amount'))->toBe(30.0);
    $this->assertDatabaseCount('customer_refund_payments', 2);
    $this->actingAs($reviewer)->get(route('administration.customer-returns.index', ['case' => $returnId]))
        ->assertInertia(fn (Assert $page) => $page->where('returns.data.0.verified_paid_amount', '30.00')
            ->where('returns.data.0.awaiting_review_amount', '0.00')
            ->where('returns.data.0.disputed_amount', '0.00')
            ->where('returns.data.0.refund_balance', '20.00')
            ->where('returns.data.0.refund_has_outstanding_balance', true));
});
