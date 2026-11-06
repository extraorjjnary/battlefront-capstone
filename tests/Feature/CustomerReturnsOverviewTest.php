<?php

use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Models\CustomerReturnRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests must sign in to view the returns overview', function () {
    $this->get(route('returns.index'))->assertRedirect(route('login'));
});

test('administrators cannot access the customer returns overview', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('returns.index'))->assertForbidden();
});

test('customers with no requests see an empty overview even when another customer has returns', function () {
    $foreign = CustomerReturnRequest::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('returns.index', ['user_id' => $foreign->user_id, 'order_id' => $foreign->order_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Returns/Index')->where('returnRequests.total', 0)->has('returnRequests.data', 0));
});

test('the overview paginates only owned requests newest first across orders including withdrawn history', function () {
    $customer = User::factory()->create();
    $orders = Order::factory()->for($customer)->count(2)->create();
    $requests = collect();
    for ($index = 0; $index < 13; $index++) {
        $item = OrderItem::factory()->for($orders[$index % 2])->create(['product_name_at_time' => 'Purchased keyboard']);
        $requests->push(CustomerReturnRequest::factory()->for($item, 'item')->create());
    }
    $latest = $requests->last();
    $latest->withdrawn_at = now();
    $latest->save();
    $foreign = CustomerReturnRequest::factory()->create();

    $this->actingAs($customer)->get(route('returns.index', ['user_id' => $foreign->user_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Returns/Index')
            ->where('returnRequests.total', 13)->where('returnRequests.last_page', 2)
            ->has('returnRequests.data', 12)
            ->where('returnRequests.data.0.id', $latest->id)
            ->where('returnRequests.data.0.status.value', 'withdrawn')
            ->where('returnRequests.data.0.order_id', $orders[0]->id)
            ->where('returnRequests.data.0.order_reference', $orders[0]->reference)
            ->where('returnRequests.data.0.product.name', 'Purchased keyboard')
            ->where('returnRequests.data.1.status.value', 'submitted')
            ->where('returnRequests.data.1.order_id', $orders[1]->id));
    $this->get(route('returns.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('returnRequests.total', 13)->has('returnRequests.data', 1)
            ->where('returnRequests.data.0.id', $requests->first()->id));
    $this->get(route('orders.show', $foreign->order_id))->assertNotFound();
});

/** @return array{Order, User, int} */
function overviewReceivedReturn(): array
{
    Storage::fake('local');
    $order = Order::factory()->create(['status' => 'completed', 'payment_status' => 'verified']);
    $stock = Inventory::factory()->create(['quantity' => 0]);
    $item = OrderItem::factory()->for($order)->for($stock->product)->create([
        'quantity' => 1, 'price_at_time' => '99.95', 'product_name_at_time' => 'Purchased hardware',
    ]);
    $order->sale()->create(['amount' => '99.95', 'sale_date' => today()]);
    $record = CustomerReturnRequest::factory()->for($item, 'item')->create();
    $staff = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'staff')->value('id')]);
    $approver = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'manager')->value('id')]);
    $returnId = app(HandleCustomerReturnRequest::class)->receive($staff, $record->id, 'opened', [UploadedFile::fake()->image('product.jpg')]);

    return [$order, $approver, $returnId];
}

test('received and rejected returns retain the shared customer statuses and public feedback', function () {
    [$order, $approver, $returnId] = overviewReceivedReturn();
    $this->actingAs($order->user)->get(route('returns.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('returnRequests.data.0.status.value', 'under_review')
            ->where('returnRequests.data.0.refund', null));
    app(HandleCustomerReturn::class)->decide($returnId, $approver, false, '', '0.00', 'Private rejection notes');

    $this->get(route('returns.index'))->assertInertia(fn (Assert $page) => $page
        ->where('returnRequests.data.0.status.value', 'rejected')
        ->where('returnRequests.data.0.feedback', 'Your return was not approved. Contact Battlefront about collecting your item.')
        ->missing('returnRequests.data.0.decision_reason'))
        ->assertDontSee('Private rejection notes');
});

test('refund progress counts confirmed payments only and overview reads preserve workflow records', function () {
    [$order, $approver, $returnId] = overviewReceivedReturn();
    $returns = app(HandleCustomerReturn::class);
    $returns->decide($returnId, $approver, true, 'write_off', '99.95', 'Private approval notes', resolution: ['type' => 'refund']);
    $refundId = (int) DB::table('customer_refunds')->where('customer_return_id', $returnId)->value('id');
    $confirmed = $this->recordLegacyRefundPayment($refundId, $approver, '40.25', 'cash', 'PRIVATE-CONFIRMED');
    $verifier = User::factory()->administrator()->create(['administration_role_id' => DB::table('administration_roles')->where('code', 'owner')->value('id')]);
    $returns->reviewPayment($confirmed, $verifier, 'verified', 'Private payment review');
    $this->recordLegacyRefundPayment($refundId, $approver, '59.70', 'cash', 'PRIVATE-UNCONFIRMED');
    $before = collect(['orders', 'customer_return_requests', 'customer_returns', 'customer_refunds', 'customer_refund_payments', 'customer_refund_payment_reviews', 'inventory_movements'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()->toArray()]);

    $this->actingAs($order->user)->get(route('returns.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('returnRequests.data.0.status.value', 'refund_processing')
            ->where('returnRequests.data.0.product.name', 'Purchased hardware')
            ->where('returnRequests.data.0.refund.approved_amount', '99.95')
            ->where('returnRequests.data.0.refund.confirmed_paid_amount', '40.25')
            ->where('returnRequests.data.0.refund.remaining_amount', '59.70')
            ->where('returnRequests.data.0.resolution.summary', null)
            ->missing('returnRequests.data.0.customer_return_id')
            ->missing('returnRequests.data.0.requester_id')
            ->missing('returnRequests.data.0.decision_reason'))
        ->assertDontSee('PRIVATE-')->assertDontSee('Private approval notes')->assertDontSee('Private payment review');
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->get()->toArray())->toEqual($rows);
    }

    $unconfirmed = (int) DB::table('customer_refund_payments')->where('external_reference', 'PRIVATE-UNCONFIRMED')->value('id');
    $returns->reviewPayment($unconfirmed, $verifier, 'verified', 'Final payment confirmed');
    $this->get(route('returns.index'))->assertInertia(fn (Assert $page) => $page
        ->where('returnRequests.data.0.status.value', 'refunded')
        ->where('returnRequests.data.0.refund.confirmed_paid_amount', '99.95')
        ->where('returnRequests.data.0.refund.remaining_amount', '0.00'));
});
