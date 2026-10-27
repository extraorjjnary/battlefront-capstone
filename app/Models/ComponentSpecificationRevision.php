<?php

namespace App\Models;

use Database\Factories\ComponentSpecificationRevisionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $specifications
 * @property list<array<string, string>> $evidence
 */
#[Fillable([
    'product_component_profile_id',
    'revision',
    'schema_version',
    'specifications',
    'evidence',
    'review_status',
    'reviewed_by_id',
    'reviewed_at',
])]
class ComponentSpecificationRevision extends Model
{
    /** @use HasFactory<ComponentSpecificationRevisionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Hardware specification revisions are immutable. Create a new revision.');
        });

        static::deleting(function (): never {
            throw new DomainException('Hardware specification revisions cannot be deleted.');
        });
    }

    /** @return BelongsTo<ProductComponentProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProductComponentProfile::class, 'product_component_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'schema_version' => 'integer',
            'specifications' => 'array',
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
