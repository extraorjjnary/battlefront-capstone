<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ProductIndexRequest;
use App\Http\Requests\Administration\SaveProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProductIndexRequest $request): Response
    {
        $filters = $request->validated();
        $filters['category_id'] = $request->filled('category_id')
            ? $request->integer('category_id')
            : null;
        $filters['tag_id'] = $request->filled('tag_id')
            ? $request->integer('tag_id')
            : null;

        $products = Product::query()
            ->select([
                'id',
                'name',
                'category_id',
                'brand',
                'price',
                'discount_price',
                'image_path',
                'is_featured',
                'is_active',
            ])
            ->with([
                'category:id,name,is_active',
                'tags:id,name',
            ])
            ->when(
                $filters['q'] ?? null,
                fn (Builder $query, string $search): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%"),
                ),
            )
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int $categoryId): Builder => $query->where('category_id', $categoryId),
            )
            ->when(
                $filters['brand'] ?? null,
                fn (Builder $query, string $brand): Builder => $query->where('brand', $brand),
            )
            ->when(
                $filters['tag_id'] ?? null,
                fn (Builder $query, int $tagId): Builder => $query->whereHas(
                    'tags',
                    fn (Builder $query): Builder => $query->whereKey($tagId),
                ),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('is_active', $status === 'active'),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(12)
            ->appends($filters)
            ->through(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'brand' => $product->brand,
                'price' => $product->price,
                'discount_price' => $product->discount_price,
                'image_url' => $product->image_url,
                'is_featured' => $product->is_featured,
                'is_active' => $product->is_active,
                'category' => [
                    'name' => $product->category->name,
                    'is_active' => $product->category->is_active,
                ],
                'tags' => $product->tags
                    ->map(fn (Tag $tag): array => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                    ])
                    ->values(),
            ]);

        return Inertia::render('Administration/Products/Index', [
            'products' => $products,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'brand' => $filters['brand'] ?? null,
                'tag_id' => $filters['tag_id'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'filter_options' => [
                ...$this->productOptions(),
                'brands' => Product::query()
                    ->select('brand')
                    ->distinct()
                    ->orderBy('brand')
                    ->pluck('brand'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Administration/Products/Create', $this->productOptions());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SaveProductRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids'], $validated['image']);
        $imagePath = $this->storeImage($request);
        $validated['image_path'] = $imagePath;

        try {
            $product = DB::transaction(function () use ($validated, $tagIds): Product {
                $product = Product::query()->create($validated);

                $product->tags()->sync($tagIds);

                return $product;
            });
        } catch (Throwable $exception) {
            $this->deleteManagedImage($imagePath);

            throw $exception;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Product created successfully'),
            'action' => [
                'label' => __('Go to Inventory'),
                'url' => route('administration.inventory.index', [
                    'q' => $product->name,
                ]),
            ],
        ]);

        return to_route('administration.products.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product): Response
    {
        $product->load('tags:id,name');

        return Inertia::render('Administration/Products/Edit', [
            ...$this->productOptions(),
            'product' => [
                ...$product->only([
                    'id',
                    'name',
                    'description',
                    'category_id',
                    'brand',
                    'price',
                    'is_featured',
                    'discount_price',
                    'is_active',
                ]),
                'image_url' => $product->image_url,
                'tag_ids' => $product->tags->pluck('id')->all(),
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SaveProductRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();
        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids'], $validated['image']);
        $oldImagePath = $product->image_path;
        $newImagePath = $request->hasFile('image')
            ? $this->storeImage($request)
            : null;

        if ($newImagePath !== null) {
            $validated['image_path'] = $newImagePath;
        }

        try {
            DB::transaction(function () use ($product, $validated, $tagIds): void {
                $product->update($validated);

                $product->tags()->sync($tagIds);
            });
        } catch (Throwable $exception) {
            $this->deleteManagedImage($newImagePath);

            throw $exception;
        }

        if ($newImagePath !== null) {
            $this->deleteManagedImage($oldImagePath);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Product updated.'),
        ]);

        return to_route('administration.products.index');
    }

    /**
     * Get the selectable category and tag options for product administration.
     *
     * @return array{
     *     categories: Collection<int, Category>,
     *     tags: Collection<int, Tag>
     * }
     */
    private function productOptions(): array
    {
        return [
            'categories' => Category::query()
                ->select(['id', 'name', 'is_active'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'tags' => Tag::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * Store a validated product image on the public disk.
     */
    private function storeImage(SaveProductRequest $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $path = $request->file('image')->storePublicly('products', 'public');

        if ($path === false) {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }

    /**
     * Delete only product images managed by this upload flow.
     */
    private function deleteManagedImage(?string $path): void
    {
        if ($path !== null && str_starts_with($path, 'products/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
