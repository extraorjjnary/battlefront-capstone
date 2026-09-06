<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $category_id
 * @property string $brand
 * @property string $price
 * @property bool $is_featured
 * @property string|null $discount_price
 * @property string|null $image_url
 * @property bool $is_active
 * @property Carbon $created_at
 * @property-read Category $category
 */
#[Fillable([
    'name',
    'description',
    'category_id',
    'brand',
    'price',
    'is_featured',
    'discount_price',
    'image_url',
    'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The name of the "updated at" column.
     *
     * @var null
     */
    public const UPDATED_AT = null;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_featured' => false,
        'is_active' => true,
    ];

    /**
     * Get the category that contains the product.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope a query to active products.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_featured' => 'boolean',
            'discount_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
