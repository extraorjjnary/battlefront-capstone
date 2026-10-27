<?php

namespace App\Models;

use Database\Factories\PcBuildItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'pc_build_id',
    'product_id',
    'component_specification_revision_id',
    'component_type',
    'slot_key',
    'quantity',
    'position',
    'configuration',
])]
#[Hidden(['id', 'pc_build_id', 'product_id', 'component_specification_revision_id'])]
class PcBuildItem extends Model
{
    /** @use HasFactory<PcBuildItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (PcBuildItem $item): void {
            $item->item_key ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<PcBuild, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(PcBuild::class, 'pc_build_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ComponentSpecificationRevision, $this> */
    public function specificationRevision(): BelongsTo
    {
        return $this->belongsTo(ComponentSpecificationRevision::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'position' => 'integer',
            'configuration' => 'array',
        ];
    }
}
