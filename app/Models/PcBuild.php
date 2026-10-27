<?php

namespace App\Models;

use Database\Factories\PcBuildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'mode', 'budget', 'edit_version'])]
#[Hidden(['id', 'user_id', 'share_token'])]
class PcBuild extends Model
{
    /** @use HasFactory<PcBuildFactory> */
    use HasFactory;

    protected $attributes = [
        'mode' => 'simple',
        'edit_version' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (PcBuild $build): void {
            $build->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PcBuildItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PcBuildItem::class);
    }

    /** @return HasMany<PcBuildEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PcBuildEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'edit_version' => 'integer',
        ];
    }
}
