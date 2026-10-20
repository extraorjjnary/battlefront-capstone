<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DeleteProduct
{
    public function execute(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $lockedProduct->is_active = false;
            $lockedProduct->deleted_by_id = auth()->id();
            $lockedProduct->save();

            DB::table('audit_logs')->insert([
                'actor_id' => auth()->id(),
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
