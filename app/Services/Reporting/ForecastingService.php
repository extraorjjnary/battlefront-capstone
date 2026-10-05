<?php

namespace App\Services\Reporting;

use App\Actions\Forecasting\CalculateMovingAverage;
use App\Actions\Forecasting\PersistForecast;
use App\Models\Forecast;
use App\Models\Product;
use App\Services\Forecasting\ProductForecastPreparationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * @phpstan-import-type Preparation from ProductForecastPreparationService
 */
class ForecastingService
{
    public function __construct(
        private readonly ProductForecastPreparationService $preparation,
        private readonly CalculateMovingAverage $movingAverage,
        private readonly PersistForecast $persistForecast,
    ) {}

    /**
     * @param  array{q?: string|null, product_id?: int|string|null, page?: int|string|null, forecast_page?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function page(array $filters): array
    {
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $search = trim($filters['q'] ?? '');
        $selected = $productId === null ? null : Product::with('category')->findOrFail($productId);
        $readiness = $selected === null ? null : $this->readinessData($this->preparation->prepare($selected));

        return [
            'products' => Product::query()->with('category')
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', '%'.$search.'%')->orWhere('product_code', 'like', '%'.$search.'%')))
                ->orderBy('name')->orderBy('id')->paginate(15)->withQueryString()
                ->through($this->productData(...)),
            'selected_product' => $selected === null ? null : $this->productData($selected),
            'filters' => ['q' => $search, 'product_id' => $productId],
            'readiness' => $readiness,
            'timezone' => config('app.timezone'),
            'forecasts' => Forecast::query()->with('product.category')
                ->when($productId !== null, fn (Builder $query) => $query->where('product_id', $productId))
                ->orderByDesc('generated_at')->orderByDesc('id')
                ->paginate(15, ['*'], 'forecast_page')->withQueryString()
                ->through($this->savedData(...)),
        ];
    }

    /** @return array<string, mixed> */
    public function generate(Product $product): array
    {
        $prepared = $this->preparation->prepare($product);
        $base = [
            ...$this->readinessData($prepared),
            'product' => $this->productData($product->loadMissing('category')),
            'observations' => [],
            'forecast_quantity' => null,
            'generated_at' => null,
        ];
        if ($prepared['status'] !== 'ready') {
            return $base;
        }

        if ($prepared['history'] === null) {
            throw ValidationException::withMessages(['forecast' => 'Prepared history is unavailable. Please refresh and try again.']);
        }
        $result = $this->movingAverage->execute($prepared['history'], $product->id);
        if ($result['status'] !== 'ok' || $result['forecast_quantity'] === null) {
            throw ValidationException::withMessages(['forecast' => 'The forecast could not be calculated from the prepared history.']);
        }
        if (bccomp($result['forecast_quantity'], '9999999999.99', 2) > 0) {
            throw ValidationException::withMessages(['forecast' => 'This forecast exceeds the supported quantity and could not be saved.']);
        }
        try {
            $forecast = $this->persistForecast->execute($result);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['forecast' => 'This forecast could not be saved: '.$exception->getMessage()]);
        }
        $observations = [];
        foreach ($prepared['history']['series'][0]['quarters'] as $quarter) {
            $observations[] = [
                'label' => 'Q'.$quarter['quarter'].' '.$quarter['year'],
                'start' => $quarter['start'],
                'quantity_sold' => $quarter['quantity_sold'],
            ];
        }
        $quantity = $forecast->predicted_demand;

        return [
            ...$base,
            'id' => $forecast->id,
            'method' => 'moving_average',
            'observations' => $observations,
            'forecast_quantity' => $quantity,
            'generated_at' => $forecast->generated_at->toIso8601String(),
            'message' => "Estimated quarterly demand: {$quantity} units for {$base['target_label']}, based on the average of four completed quarters.",
            'guidance' => 'Use this estimate alongside business judgment when planning stock. Actual demand may differ.',
        ];
    }

    /**
     * @param  Preparation  $prepared
     * @return array<string, mixed>
     */
    private function readinessData(array $prepared): array
    {
        $status = $prepared['status'];
        $message = match ($status) {
            'ready' => 'Ready to forecast from four completed source quarters.',
            'insufficient_history' => $prepared['covered_quarters'].' completed quarters covered; four are required. No forecast was saved.',
            'history_unavailable' => 'Complete sales history is unavailable for the required four quarters. History preparation or import must establish coverage. No forecast was saved.',
        };

        return [
            'status' => $status,
            'covered_quarters' => $prepared['covered_quarters'],
            'source_period' => $prepared['source_period'],
            'source_label' => $this->periodLabel($prepared['source_period']['start'], $prepared['source_period']['end_exclusive']),
            'target_quarter' => $prepared['target_quarter'],
            'target_label' => 'Q'.$prepared['target_quarter']['quarter'].' '.$prepared['target_quarter']['year'],
            'timezone' => $prepared['timezone'],
            'is_synthetic' => ($prepared['coverage']['source_kind'] ?? null) === 'synthetic_development',
            'sales_scope_label' => match ($prepared['coverage']['sales_scope'] ?? null) {
                'all_sagay_sales' => 'All Sagay sales',
                'captured_system_transactions' => 'Captured system transactions',
                'development_fixture_transactions' => 'Synthetic development transactions',
                default => null,
            },
            'message' => $message,
        ];
    }

    /** @return array{id: int, name: string, product_code: string, category: string, is_active: bool, is_synthetic: bool} */
    private function productData(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'product_code' => $product->product_code,
            'category' => $product->category->name,
            'is_active' => $product->is_active,
            'is_synthetic' => str_starts_with($product->product_code, 'DEVHIST40'),
        ];
    }

    /** @return array<string, mixed> */
    private function savedData(Forecast $forecast): array
    {
        $target = CarbonImmutable::create((int) substr($forecast->forecast_quarter, 0, 4), ((int) substr($forecast->forecast_quarter, -1) - 1) * 3 + 1, 1, 0, 0, 0, config('app.timezone'));

        return [
            'id' => $forecast->id,
            'product' => $this->productData($forecast->product),
            'method' => $forecast->method,
            'method_label' => $forecast->method === 'linear_trend' ? 'Linear trend — legacy' : 'Moving average',
            'is_legacy' => $forecast->method === 'linear_trend',
            'forecast_quantity' => $forecast->predicted_demand,
            'target_label' => $this->quarterLabel($target),
            'source_label' => $forecast->method === 'moving_average'
                ? $this->periodLabel($target->subQuarters(4)->toDateString(), $target->toDateString()).' (inferred from four-quarter method)'
                : 'Not retained with this saved forecast',
            'generated_at' => $forecast->generated_at->toIso8601String(),
        ];
    }

    private function periodLabel(string $start, string $endExclusive): string
    {
        return $this->quarterLabel(CarbonImmutable::parse($start)).' – '.$this->quarterLabel(CarbonImmutable::parse($endExclusive)->subQuarter());
    }

    private function quarterLabel(CarbonImmutable $quarter): string
    {
        return 'Q'.$quarter->quarter.' '.$quarter->year;
    }
}
