<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DeleteProduct
{
    public const PROTECTED_MESSAGE = 'This product has linked records in another module and cannot be deleted. Deactivate it instead.';

    public function __construct(
        private readonly SalesHistoryCoverageRepository $coverage,
        private readonly DeleteManagedProductImage $deleteManagedProductImage,
    ) {}

    public function execute(Product $product): void
    {
        try {
            DB::transaction(function () use ($product): void {
                $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
                try {
                    $hasHistory = $this->coverage->hasProductReference($lockedProduct->product_code);
                } catch (RuntimeException $exception) {
                    report($exception);
                    throw ValidationException::withMessages([
                        'deletion' => 'This product cannot be deleted because its historical references could not be verified. Check the history metadata and try again.',
                    ]);
                }
                if ($lockedProduct->orderItems()->exists()
                    || $lockedProduct->cartItems()->exists()
                    || $lockedProduct->forecasts()->exists()
                    || DB::table('customer_product_views')->where('product_id', $lockedProduct->id)->exists()
                    || DB::table('recommendation_interactions')->where('product_id', $lockedProduct->id)->exists()
                    || DB::table('inventory_adjustment_requests')->where('product_id', $lockedProduct->id)->exists()
                    || DB::table('inventory_movements')->where('product_id', $lockedProduct->id)->exists()
                    || DB::table('damage_reports')->where('product_id', $lockedProduct->id)->exists()
                    || DB::table('supplier_returns')->where('product_id', $lockedProduct->id)->exists()
                    || $hasHistory) {
                    throw ValidationException::withMessages(['deletion' => self::PROTECTED_MESSAGE]);
                }

                $imagePath = $lockedProduct->image_path;
                $lockedProduct->tags()->detach();
                $lockedProduct->inventory()->delete();

                DB::table('audit_logs')->insert([
                    'actor_id' => auth()->id(),
                    'action' => 'product.deleted',
                    'subject_type' => 'product',
                    'subject_id' => $lockedProduct->id,
                    'changes' => json_encode(['product_code' => $lockedProduct->product_code, 'name' => $lockedProduct->name], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $lockedProduct->delete();

                DB::afterCommit(function () use ($imagePath, $lockedProduct): void {
                    try {
                        $this->deleteManagedProductImage->execute($imagePath, $lockedProduct->id);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1451
                || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw ValidationException::withMessages(['deletion' => self::PROTECTED_MESSAGE]);
            }

            throw $exception;
        }
    }
}
