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
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '100.00',
    ])->assertSessionHasErrors('decision');
    $this->actingAs($reviewer)->post(route('administration.customer-returns.decide', $returnId), [
        'decision' => 'accept', 'disposition' => 'write_off', 'approved_amount' => '100.00',
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
