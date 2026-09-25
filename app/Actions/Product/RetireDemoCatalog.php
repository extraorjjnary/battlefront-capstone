<?php

namespace App\Actions\Product;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RetireDemoCatalog
{
    public const DESCRIPTION = 'Synthetic development sample only; not a Battlefront stocked product.';

    public const NAMES = [
        '[DEMO] NVIDIA Atlas Graphics Card', '[DEMO] AMD Nova Graphics Card',
        '[DEMO] Intel Horizon Processor', '[DEMO] AMD Forge Processor',
        '[DEMO] Logitech Pulse Wireless Mouse', '[DEMO] Keychron Studio Mechanical Keyboard',
        '[DEMO] Samsung Sprint NVMe SSD', '[DEMO] Crucial Archive SATA SSD',
    ];

    public function __construct(private readonly DeleteManagedProductImage $deleteImage) {}

    /**
     * Called inside the catalog import transaction. Foreign keys remain enabled.
     *
     * @return array{demo_deleted: int, demo_retained: int}
     */
    public function execute(): array
    {
        $result = ['demo_deleted' => 0, 'demo_retained' => 0];
        $names = [...self::NAMES, ...array_map(fn (string $name): string => substr($name, 7), self::NAMES)];
        $products = Product::query()->where('is_catalog_imported', false)
            ->where('description', self::DESCRIPTION)->whereIn('name', $names)->lockForUpdate()->get();

        foreach ($products as $product) {
            if ($product->orderItems()->exists() || $product->cartItems()->exists()) {
                $product->update(['is_active' => false, 'is_featured' => false, 'name' => preg_replace('/^\[DEMO\] /', '', $product->name)]);
                $result['demo_retained']++;

                continue;
            }

            $path = $product->image_path;
            $product->inventory()->delete();
            $product->tags()->detach();
            $product->delete();
            DB::afterCommit(fn () => $this->deleteImage->execute($path, $product->id));
            $result['demo_deleted']++;
        }

        $categories = Category::query()
            ->where('description', 'Synthetic category for development and testing only; not Battlefront operational data.')
            ->whereIn('name', ['[DEMO] Graphics Cards', '[DEMO] Processors', '[DEMO] Peripherals', '[DEMO] Storage'])
            ->lockForUpdate()->get();
        foreach ($categories as $category) {
            if (! $category->products()->exists()) {
                $category->delete();
            } elseif (! $category->products()->where(fn (Builder $query): Builder => $query->whereNull('description')->orWhere('description', '!=', self::DESCRIPTION))->exists()) {
                $name = substr($category->name, 7);
                if (Category::query()->where('name', $name)->exists()) {
                    $name = 'Historical '.$name.' '.$category->id;
                }
                $category->update(['name' => $name, 'is_active' => false]);
            }
        }

        return $result;
    }
}
