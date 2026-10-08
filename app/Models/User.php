<?php

namespace App\Models;

use App\Enums\AppearancePreference;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property AppearancePreference $appearance
 * @property string|null $default_delivery_address
 * @property bool $search_recommendations_enabled
 * @property bool $product_view_recommendations_enabled
 * @property bool $personalized_recommendations_enabled
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cart|null $cart
 * @property-read Collection<int, Order> $orders
 */
#[Fillable(['name', 'email', 'password', 'default_delivery_address', 'appearance', 'search_recommendations_enabled', 'product_view_recommendations_enabled', 'personalized_recommendations_enabled'])]
#[Hidden([
    'password',
    'default_delivery_address',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'appearance' => 'system',
        'role' => 'customer',
        'search_recommendations_enabled' => true,
        'product_view_recommendations_enabled' => true,
        'personalized_recommendations_enabled' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'appearance' => AppearancePreference::class,
            'role' => UserRole::class,
            'search_recommendations_enabled' => 'boolean',
            'product_view_recommendations_enabled' => 'boolean',
            'personalized_recommendations_enabled' => 'boolean',
        ];
    }

    /**
     * Determine whether the user may access administrator features.
     */
    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Administrator;
    }

    /**
     * Get the customer's cart.
     *
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * Get the customer's orders.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get this customer's retained catalog searches.
     *
     * @return HasMany<CustomerSearch, $this>
     */
    public function searches(): HasMany
    {
        return $this->hasMany(CustomerSearch::class);
    }

    /**
     * Get this customer's retained product views.
     *
     * @return HasMany<CustomerProductView, $this>
     */
    public function productViews(): HasMany
    {
        return $this->hasMany(CustomerProductView::class);
    }
}
