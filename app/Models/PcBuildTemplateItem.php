<?php

namespace App\Models;

use Database\Factories\PcBuildTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $product_id
 * @property string $slot_key
 * @property string $component_type
 * @property int $quantity
 * @property array<string, mixed>|null $configuration
 */
#[Fillable(['pc_build_template_id', 'product_id', 'component_type', 'slot_key', 'quantity', 'position', 'configuration'])]
class PcBuildTemplateItem extends Model
{
    /** @use HasFactory<PcBuildTemplateItemFactory> */
    use HasFactory;

    /** @return BelongsTo<PcBuildTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PcBuildTemplate::class, 'pc_build_template_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'position' => 'integer', 'configuration' => 'array'];
    }
}
