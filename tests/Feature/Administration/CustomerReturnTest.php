<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a completed purchase can be returned and manually refunded without rewriting its sale', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified, 'product_subtotal' => '200.00', 'total_amount' => '200.00']);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 2, 'price_at_time' => '100.00']);
    $sale = $order->sale()->create(['amount' => '200.00', 'sale_date' => today()]);

    $this->actingAs($requester)->post(route('administration.customer-returns.store'), [
        'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'opened', 'reason' => 'Customer reported a defect',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('customer_returns')->where('order_id', $order->id)->value('id');
    expect($product->inventory->fresh()->quarantined_quantity)->toBe(1);

    $this->actingAs($requester)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '100.00', 'reason' => 'Inspection confirms a fault',
    ])->assertSessionHasErrors('decision');
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '100.00', 'reason' => 'Inspection confirms a fault',
    ])->assertSessionHasNoErrors();

    $refundId = DB::table('customer_refunds')->where('customer_return_id', $returnId)->value('id');
    $this->actingAs($requester)->post(route('administration.customer-refunds.payments.store', $refundId), [
        'amount' => '60.00', 'payment_method' => 'cash', 'external_reference' => 'CASH-1',
    ])->assertSessionHasNoErrors();
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

    $payload = ['order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'sealed', 'reason' => 'Wrong product delivered'];
    $this->actingAs($actor)->post(route('administration.customer-returns.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($actor)->post(route('administration.customer-returns.store'), $payload)->assertSessionHasErrors('quantity');
    expect(DB::table('customer_returns')->where('order_id', $order->id)->count())->toBe(1);
});

test('a rejected customer return remains quarantined until a single customer handover', function () {
    $requester = User::factory()->administrator()->create();
    $reviewer = User::factory()->administrator()->create();
    $product = Product::factory()->available()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Verified]);
    $item = OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'price_at_time' => '50.00']);
    $order->sale()->create(['amount' => '50.00', 'sale_date' => today()]);
    $this->actingAs($requester)->post(route('administration.customer-returns.store'), [
        'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'opened', 'reason' => 'Customer requested a return',
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
        'order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'damaged', 'reason' => 'Customer reported damaged item',
    ])->assertSessionHasNoErrors();
    $returnId = DB::table('customer_returns')->where('order_id', $order->id)->value('id');
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
    $this->assertDatabaseCount('customer_refund_payments', 1);
});
