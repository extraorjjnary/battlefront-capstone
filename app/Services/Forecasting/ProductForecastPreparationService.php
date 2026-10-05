<?php

namespace App\Services\Forecasting;

use App\Models\Product;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use App\Services\Reporting\QuarterlySalesAggregationService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * @phpstan-import-type Coverage from SalesHistoryCoverageRepository
 * @phpstan-import-type History from QuarterlySalesAggregationService
 *
 * @phpstan-type LegacyCoverage array{start: string, end_exclusive: string, unavailable_quarters: list<string>, timezone: string, source_kind: 'operational_prepared'|'synthetic_development', sales_scope: 'all_sagay_sales'|'captured_system_transactions'|'development_fixture_transactions'}
 * @phpstan-type Preparation array{status: 'ready'|'insufficient_history'|'history_unavailable', product_id: int, product_code: string, timezone: string, source_period: array{start: string, end_exclusive: string}, target_quarter: array{year: int, quarter: int, start: string, end_exclusive: string}, covered_quarters: int|null, coverage: Coverage|LegacyCoverage|null, history: History|null}
 */
class ProductForecastPreparationService
{
    private const REQUIRED_QUARTERS = 4;

    public function __construct(
        private readonly SalesHistoryCoverageRepository $coverageRepository,
        private readonly QuarterlySalesAggregationService $aggregation,
    ) {}

    /**
     * Prove coverage before constructing observations. Source dates always describe
     * the required window; the declaration remains separate from recorded sales.
     *
     * @return Preparation
     */
    public function prepare(Product $product, ?CarbonImmutable $asOf = null): array
    {
        if (! $product->exists || $product->id < 1) {
            throw new InvalidArgumentException('Forecast preparation requires a persisted product.');
        }

        $timezone = config('app.timezone');
        $targetStart = ($asOf ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfQuarter();
        $sourceStart = $targetStart->subQuarters(self::REQUIRED_QUARTERS);
        /** @var Coverage|LegacyCoverage|null $coverage */
        $coverage = $this->coverageRepository->forProductCode($product->product_code);
        $result = [
            'status' => 'history_unavailable',
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'timezone' => $timezone,
            'source_period' => [
                'start' => $sourceStart->toDateString(),
                'end_exclusive' => $targetStart->toDateString(),
            ],
            'target_quarter' => [
                'year' => $targetStart->year,
                'quarter' => $targetStart->quarter,
                'start' => $targetStart->toDateString(),
                'end_exclusive' => $targetStart->addQuarter()->toDateString(),
            ],
            'covered_quarters' => null,
            'coverage' => $coverage,
            'history' => null,
        ];

        if ($coverage === null || ($coverage['granularity'] ?? null) === 'month'
            || ! array_key_exists('unavailable_quarters', $coverage)
            || $coverage['end_exclusive'] < $targetStart->toDateString()) {
            return $result;
        }

        foreach ($coverage['unavailable_quarters'] as $quarter) {
            if ($quarter >= $sourceStart->toDateString() && $quarter < $targetStart->toDateString()) {
                return $result;
            }
        }

        $coveredQuarters = 0;
        for ($quarter = $sourceStart; $quarter->lessThan($targetStart); $quarter = $quarter->addQuarter()) {
            if ($quarter->toDateString() >= $coverage['start']) {
                $coveredQuarters++;
            }
        }
        $result['covered_quarters'] = $coveredQuarters;
        if ($coveredQuarters < self::REQUIRED_QUARTERS) {
            $result['status'] = 'insufficient_history';

            return $result;
        }

        $result['status'] = 'ready';
        $result['history'] = $this->aggregation->products($sourceStart, $targetStart, [$product->id]);

        return $result;
    }
}
