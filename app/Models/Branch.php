<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property-read string|null $email
 * @property-read string $operating_hours
 * @property-read bool $is_operational
 */
#[Fillable(['name', 'address', 'city', 'contact_number', 'latitude', 'longitude'])]
#[Appends(['email', 'operating_hours', 'is_operational'])]
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

    /**
     * Get the branch's confirmed reference email.
     *
     * @return Attribute<string, never>|Attribute<null, never>
     */
    protected function email(): Attribute
    {
        return Attribute::get(function (mixed $value, array $attributes): ?string {
            $email = config('battlefront.branch_emails.'.$this->city);

            return is_string($email) ? $email : null;
        });
    }

    /**
     * Get the branch's configured operating hours.
     *
     * @return Attribute<string, never>
     */
    protected function operatingHours(): Attribute
    {
        return Attribute::get(function (): string {
            $operatingHours = config('battlefront.operating_hours');

            return is_string($operatingHours) ? $operatingHours : '';
        });
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
