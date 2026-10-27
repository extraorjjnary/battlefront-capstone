<?php

namespace App\Models;

use Database\Factories\PcBuildEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** @property array{components?: list<array{product_id: int, component_type: string, quantity: int}>, warning_count?: int, ruleset_version?: int, cart_group_uuid?: string, validation_status?: string} $context */
#[Fillable([
    'pc_build_id',
    'user_id',
    'event_type',
    'configuration_fingerprint',
    'context',
    'occurred_at',
])]
#[Hidden(['id', 'user_id'])]
class PcBuildEvent extends Model
{
    /** @use HasFactory<PcBuildEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (PcBuildEvent $event): void {
            $event->event_uuid ??= (string) Str::uuid();
        });

        static::updating(function (): never {
            throw new \DomainException('PC Builder activity records are immutable.');
        });

        static::deleting(function (): never {
            throw new \DomainException('PC Builder activity records cannot be deleted.');
        });
    }

    /** @return BelongsTo<PcBuild, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(PcBuild::class, 'pc_build_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
