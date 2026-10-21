<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $address
 * @property string $city
 * @property string|null $contact_number
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $email
 * @property string|null $operating_hours
 * @property bool $is_published
 * @property string|null $seed_key
 * @property-read bool $is_operational
 */
#[Fillable(['name', 'address', 'city', 'contact_number', 'email', 'operating_hours', 'latitude', 'longitude', 'is_published'])]
#[Appends(['is_operational'])]
#[Hidden(['seed_key'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the users associated with the branch.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Scope a query to the configured operational branch.
     *
     * @param  Builder<Branch>  $query
     */
    #[Scope]
    protected function operational(Builder $query): void
    {
        $query->where('city', config('battlefront.operational_branch_city'));
    }

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /**
     * @param  Builder<Branch>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Get whether the branch is operational.
     *
     * @return Attribute<bool, never>
     */
    protected function isOperational(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->city === config('battlefront.operational_branch_city'),
        );
    }
}
