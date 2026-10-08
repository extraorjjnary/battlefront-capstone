<?php

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;

class RecordCustomerProductView
{
    public function __invoke(?User $user, Product $product): bool
    {
        if (
            $user === null
            || $user->role !== UserRole::Customer
            || ! $user->product_view_recommendations_enabled
        ) {
            return false;
        }

        $recentlyRecorded = $user->productViews()
            ->whereBelongsTo($product)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();

        if ($recentlyRecorded) {
            return false;
        }

        $user->productViews()->create([
            'product_id' => $product->id,
            'expires_at' => now()->addDays(90),
        ]);

        return true;
    }
}
