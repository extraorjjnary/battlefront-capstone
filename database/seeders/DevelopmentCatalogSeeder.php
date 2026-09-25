<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DevelopmentCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! App::environment(['local', 'testing']) || Product::query()->where('is_catalog_imported', true)->exists()) {
            return;
        }

        $categoryDescription = 'Synthetic category for development and testing only; not Battlefront operational data.';
        $productDescription = 'Synthetic development sample only; not a Battlefront stocked product.';

        $categoryNames = [
            '[DEMO] Graphics Cards',
            '[DEMO] Processors',
            '[DEMO] Peripherals',
            '[DEMO] Storage',
        ];

        $categories = [];

        foreach ($categoryNames as $categoryName) {
            $categories[$categoryName] = Category::query()->updateOrCreate(
                ['name' => $categoryName],
                [
                    'description' => $categoryDescription,
                    'is_active' => true,
                ],
            );
        }

        $tagNames = [
            'Budget Friendly',
            'Content Creation',
            'Gaming',
            'High Performance',
            'Mechanical',
            'Productivity',
            'Wireless',
        ];

        $tags = [];

        foreach ($tagNames as $tagName) {
            $tags[$tagName] = Tag::query()->updateOrCreate(['name' => $tagName]);
        }

        /** @var list<array{name: string, category: string, brand: string, price: string, is_featured: bool, discount_price: string|null, image_path: string, is_active: bool, quantity: int, reorder_level: int, tags: list<string>}> $products */
        $products = [
            [
                'name' => '[DEMO] NVIDIA Atlas Graphics Card',
                'category' => '[DEMO] Graphics Cards',
                'brand' => 'NVIDIA',
                'price' => '39999.00',
                'is_featured' => true,
                'discount_price' => '36999.00',
                'image_path' => 'images/demo-products/graphics-card.png',
                'is_active' => true,
                'quantity' => 8,
                'reorder_level' => 3,
                'tags' => ['Gaming', 'Content Creation', 'High Performance'],
            ],
            [
                'name' => '[DEMO] AMD Nova Graphics Card',
                'category' => '[DEMO] Graphics Cards',
                'brand' => 'AMD',
                'price' => '29999.00',
                'is_featured' => false,
                'discount_price' => null,
                'image_path' => 'images/demo-products/graphics-card.png',
                'is_active' => true,
                'quantity' => 0,
                'reorder_level' => 3,
                'tags' => ['Gaming', 'Budget Friendly'],
            ],
            [
                'name' => '[DEMO] Intel Horizon Processor',
                'category' => '[DEMO] Processors',
                'brand' => 'Intel',
                'price' => '18999.00',
                'is_featured' => false,
                'discount_price' => '16999.00',
                'image_path' => 'images/demo-products/processor.png',
                'is_active' => true,
                'quantity' => 12,
                'reorder_level' => 4,
                'tags' => ['Gaming', 'Productivity'],
            ],
            [
                'name' => '[DEMO] AMD Forge Processor',
                'category' => '[DEMO] Processors',
                'brand' => 'AMD',
                'price' => '12999.00',
                'is_featured' => false,
                'discount_price' => null,
                'image_path' => 'images/demo-products/processor.png',
                'is_active' => true,
                'quantity' => 4,
                'reorder_level' => 5,
                'tags' => ['Gaming', 'Productivity', 'Budget Friendly'],
            ],
            [
                'name' => '[DEMO] Logitech Pulse Wireless Mouse',
                'category' => '[DEMO] Peripherals',
                'brand' => 'Logitech',
                'price' => '1499.00',
                'is_featured' => true,
                'discount_price' => '1199.00',
                'image_path' => 'images/demo-products/peripherals.png',
                'is_active' => true,
                'quantity' => 25,
                'reorder_level' => 5,
                'tags' => ['Gaming', 'Wireless', 'Budget Friendly'],
            ],
            [
                'name' => '[DEMO] Keychron Studio Mechanical Keyboard',
                'category' => '[DEMO] Peripherals',
                'brand' => 'Keychron',
                'price' => '4999.00',
                'is_featured' => false,
                'discount_price' => null,
                'image_path' => 'images/demo-products/peripherals.png',
                'is_active' => true,
                'quantity' => 6,
                'reorder_level' => 2,
                'tags' => ['Gaming', 'Productivity', 'Mechanical'],
            ],
            [
                'name' => '[DEMO] Samsung Sprint NVMe SSD',
                'category' => '[DEMO] Storage',
                'brand' => 'Samsung',
                'price' => '4599.00',
                'is_featured' => false,
                'discount_price' => null,
                'image_path' => 'images/demo-products/storage.png',
                'is_active' => true,
                'quantity' => 15,
                'reorder_level' => 5,
                'tags' => ['Productivity', 'Content Creation', 'High Performance'],
            ],
            [
                'name' => '[DEMO] Crucial Archive SATA SSD',
                'category' => '[DEMO] Storage',
                'brand' => 'Crucial',
                'price' => '2999.00',
                'is_featured' => false,
                'discount_price' => null,
                'image_path' => 'images/demo-products/storage.png',
                'is_active' => false,
                'quantity' => 10,
                'reorder_level' => 3,
                'tags' => ['Productivity', 'Budget Friendly'],
            ],
        ];

        foreach ($products as $productData) {
            $product = Product::query()->updateOrCreate(
                ['name' => $productData['name']],
                [
                    'product_code' => 'DEV'.strtoupper(substr(hash('sha256', $productData['name']), 0, 16)),
                    'description' => $productDescription,
                    'category_id' => $categories[$productData['category']]->id,
                    'brand' => $productData['brand'],
                    'price' => $productData['price'],
                    'is_featured' => $productData['is_featured'],
                    'discount_price' => $productData['discount_price'],
                    'image_path' => $productData['image_path'],
                    'is_active' => $productData['is_active'],
                ],
            );

            Inventory::query()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity' => $productData['quantity'],
                    'reorder_level' => $productData['reorder_level'],
                ],
            );

            $product->tags()->sync(
                array_map(
                    fn (string $tagName): int => $tags[$tagName]->id,
                    $productData['tags'],
                ),
            );
        }
    }
}
