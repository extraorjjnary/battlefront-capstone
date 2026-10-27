<?php

namespace App\Models;

use Database\Factories\PcBuildCartGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['cart_id', 'name', 'mode', 'ruleset_version', 'configuration_snapshot'])]
class PcBuildCartGroup extends Model
{
    /** @use HasFactory<PcBuildCartGroupFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (PcBuildCartGroup $group): void {
            $group->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'pc_build_cart_group_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ruleset_version' => 'integer',
            'configuration_snapshot' => 'array',
        ];
    }
}
