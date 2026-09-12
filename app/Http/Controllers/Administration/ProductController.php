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
use Inertia\Inertia;
use Inertia\Response;

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
                'image_url',
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
        unset($validated['tag_ids']);

        $product = DB::transaction(function () use ($validated, $tagIds): Product {
            $product = Product::query()->create($validated);

            $product->tags()->sync($tagIds);

            return $product;
        });

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
                    'image_url',
                    'is_active',
                ]),
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
        unset($validated['tag_ids']);

        DB::transaction(function () use ($product, $validated, $tagIds): void {
            $product->update($validated);

            $product->tags()->sync($tagIds);
        });

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
}
