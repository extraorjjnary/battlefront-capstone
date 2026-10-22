<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteProduct
{
    public function execute(Product $product): void
    {
        $actorId = auth()->id();
        $actorId = $actorId === null ? null : max(0, (int) $actorId);

        DB::transaction(function () use ($product, $actorId): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            DB::table('inventories')->where('product_id', $lockedProduct->id)->lockForUpdate()->first();
            if ($this->hasOutstandingOperations($lockedProduct)) {
                throw ValidationException::withMessages([
                    'deletion' => 'This product still has stock or unfinished work. Deactivate it, then resolve its inventory and open operations before moving it to Archived products.',
                ]);
            }

            $lockedProduct->is_active = false;
            $lockedProduct->deleted_by_id = $actorId;
            $lockedProduct->edit_version++;
            $lockedProduct->save();

            DB::table('audit_logs')->insert([
                'actor_id' => $actorId,
                'action' => 'product.deleted',
                'subject_type' => 'product',
                'subject_id' => $lockedProduct->id,
                'changes' => json_encode(['product_code' => $lockedProduct->product_code, 'name' => $lockedProduct->name], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $lockedProduct->delete();
        }, attempts: 3);
    }

    private function hasOutstandingOperations(Product $product): bool
    {
        if (DB::table('inventories')->where('product_id', $product->id)
            ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('quarantined_quantity', '>', 0))->exists()) {
            return true;
        }

        if (DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)->whereIn('orders.status', ['pending', 'processing'])->exists()) {
            return true;
        }

        if (DB::table('inventory_adjustment_requests')->where('product_id', $product->id)->where('status', 'pending')->exists()
            || DB::table('damage_reports')->where('product_id', $product->id)->where('status', 'pending')->exists()
            || DB::table('supplier_returns')->where('product_id', $product->id)->whereIn('status', ['pending', 'approved', 'dispatched', 'rejected_follow_up'])->exists()) {
            return true;
        }

        if (DB::table('supplier_returns as returns')->where('returns.product_id', $product->id)
            ->where('returns.status', 'closed')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('supplier_return_resolutions as resolutions')
                ->whereColumn('resolutions.supplier_return_id', 'returns.id'))->exists()) {
            return true;
        }

        $returnItems = DB::table('customer_return_items as items')
            ->join('order_items', 'order_items.id', '=', 'items.order_item_id')
            ->where('order_items.product_id', $product->id)
            ->join('customer_returns as returns', 'returns.id', '=', 'items.customer_return_id');

        return $returnItems->whereIn('returns.status', ['pending', 'rejected_pending_handover'])->exists()
            || DB::table('customer_refunds as refunds')
                ->join('customer_returns as returns', 'returns.id', '=', 'refunds.customer_return_id')
                ->join('customer_return_items as items', 'items.customer_return_id', '=', 'returns.id')
                ->join('order_items', 'order_items.id', '=', 'items.order_item_id')
                ->where('order_items.product_id', $product->id)
                ->whereRaw('refunds.approved_amount > (select COALESCE(SUM(payments.amount), 0) from customer_refund_payments as payments where payments.customer_refund_id = refunds.id and exists (select 1 from customer_refund_payment_reviews as reviews where reviews.customer_refund_payment_id = payments.id and reviews.decision = ? and reviews.id = (select MAX(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)))', ['verified'])
                ->exists();
    }
}
