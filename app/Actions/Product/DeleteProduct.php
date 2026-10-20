<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DeleteProduct
{
    public function execute(Product $product): void
    {
        $actorId = auth()->id();
        $actorId = $actorId === null ? null : max(0, (int) $actorId);

        DB::transaction(function () use ($product, $actorId): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $lockedProduct->is_active = false;
            $lockedProduct->deleted_by_id = $actorId;
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
}
