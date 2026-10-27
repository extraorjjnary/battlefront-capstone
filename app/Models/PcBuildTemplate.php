<?php

namespace App\Models;

use Database\Factories\PcBuildTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'description', 'budget', 'is_active', 'edit_version', 'created_by_id'])]
class PcBuildTemplate extends Model
{
    /** @use HasFactory<PcBuildTemplateFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (PcBuildTemplate $template): void {
            $template->uuid ??= (string) Str::uuid();
        });
    }

    /** @return HasMany<PcBuildTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PcBuildTemplateItem::class, 'pc_build_template_id')->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['budget' => 'decimal:2', 'is_active' => 'boolean', 'edit_version' => 'integer'];
    }
}
