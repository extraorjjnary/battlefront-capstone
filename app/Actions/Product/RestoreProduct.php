<?php

namespace App\Actions\Product;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreProduct
{
    public function execute(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            $product = Product::onlyTrashed()->lockForUpdate()->findOrFail($productId);
            if (! Category::query()->whereKey($product->category_id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['restore' => 'Restore the product category before restoring this product.']);
            }

            $product->deleted_by_id = null;
            $product->is_active = false;
            $product->edit_version++;
            $product->restore();
            DB::table('audit_logs')->insert([
                'actor_id' => auth()->id(), 'action' => 'product.restored', 'subject_type' => 'product',
                'subject_id' => $product->id, 'changes' => json_encode(['name' => $product->name], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}
