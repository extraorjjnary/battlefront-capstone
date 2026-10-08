<?php

namespace App\Models;

use Database\Factories\ForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property string $method
 * @property string $predicted_demand
 * @property string $forecast_quarter
 * @property Carbon $generated_at
 * @property-read Product $product
 */
#[Fillable(['product_id', 'method', 'predicted_demand', 'forecast_quarter', 'generated_at'])]
class Forecast extends Model
{
    /** @use HasFactory<ForecastFactory> */
    use HasFactory;

    /** @var bool */
    public $timestamps = false;

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'predicted_demand' => 'decimal:2',
            'generated_at' => 'datetime',
        ];
    }
}
