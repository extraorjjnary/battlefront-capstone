<?php

namespace App\Services\Forecasting;

use App\Models\Product;
use App\Repositories\Reporting\MonthlySalesRepository;
use App\Repositories\Reporting\SalesHistoryCoverageRepository;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * @phpstan-import-type Coverage from SalesHistoryCoverageRepository
 *
 * @phpstan-type Month array{year: int, month: int, start: string, end_exclusive: string, quantity_sold: int}
 * @phpstan-type MonthlyHistory array{product_id: int, timezone: string, start: string, end_exclusive: string, months: list<Month>}
 * @phpstan-type MonthlyPreparation array{status: 'ready'|'insufficient_history'|'history_unavailable'|'history_unsuitable', message: string, product_id: int, product_code: string, timezone: string, source_period: array{start: string, end_exclusive: string}, target_quarter: array{year: int, quarter: int, start: string, end_exclusive: string}, covered_months: int|null, coverage: Coverage|null, history: MonthlyHistory|null, positive_sales_months: list<int>|null}
 */
class ProductForecastPreparationService
{
    private const REQUIRED_MONTHS = 36;

    public function __construct(
        private readonly SalesHistoryCoverageRepository $coverageRepository,
        private readonly MonthlySalesRepository $monthlySales,
    ) {}

    /**
     * Prepare the approved monthly contract without invoking a calculator or persistence.
     *
     * @return MonthlyPreparation
     */
    public function prepareMonthly(Product $product, ?CarbonImmutable $asOf = null): array
    {
        if (! $product->exists || $product->id < 1) {
            throw new InvalidArgumentException('Forecast preparation requires a persisted product.');
        }

        $timezone = config('app.timezone');
        $targetStart = ($asOf ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfQuarter();
        $sourceStart = $targetStart->subMonths(self::REQUIRED_MONTHS);
        $coverage = $this->coverageRepository->forProductCode($product->product_code);
        $result = [
            'status' => 'history_unavailable',
            'message' => 'Trusted monthly sales history is unavailable for the required source period.',
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'timezone' => $timezone,
            'source_period' => ['start' => $sourceStart->toDateString(), 'end_exclusive' => $targetStart->toDateString()],
            'target_quarter' => [
                'year' => $targetStart->year, 'quarter' => $targetStart->quarter,
                'start' => $targetStart->toDateString(), 'end_exclusive' => $targetStart->addMonths(3)->toDateString(),
            ],
            'covered_months' => null,
            'coverage' => $coverage,
            'history' => null,
            'positive_sales_months' => null,
        ];
        if ($coverage === null || $coverage['end_exclusive'] < $targetStart->toDateString()) {
            return $result;
        }
        foreach ($coverage['unavailable_months'] as $month) {
            if ($month >= $sourceStart->toDateString() && $month < $targetStart->toDateString()) {
                return $result;
            }
        }

        $coveredMonths = 0;
        for ($month = $sourceStart; $month->lessThan($targetStart); $month = $month->addMonth()) {
            if ($month->toDateString() >= $coverage['start']) {
                $coveredMonths++;
            }
        }
        $result['covered_months'] = $coveredMonths;
        if ($coveredMonths < self::REQUIRED_MONTHS) {
            $result['status'] = 'insufficient_history';
            $result['message'] = "{$coveredMonths} completed months covered; 36 are required.";

            return $result;
        }

        $months = [];
        for ($month = $sourceStart; $month->lessThan($targetStart); $month = $month->addMonth()) {
            $months[] = [
                'year' => $month->year, 'month' => $month->month,
                'start' => $month->toDateString(), 'end_exclusive' => $month->addMonth()->toDateString(),
                'quantity_sold' => 0,
            ];
        }
        $recorded = $this->monthlySales->aggregateProduct($product->id, $months);
        $quantities = $this->recordedQuantities(array_column($months, 'start'), $recorded);
        foreach ($months as $index => $month) {
            $months[$index]['quantity_sold'] = $quantities[$month['start']] ?? 0;
        }
        $positiveMonths = [];
        foreach (array_chunk($months, 12) as $block) {
            $positiveMonths[] = count(array_filter($block, fn (array $month): bool => $month['quantity_sold'] > 0));
        }
        $result['history'] = [
            'product_id' => $product->id, 'timezone' => $timezone,
            'start' => $sourceStart->toDateString(), 'end_exclusive' => $targetStart->toDateString(),
            'months' => $months,
        ];
        $result['positive_sales_months'] = $positiveMonths;
        if ($positiveMonths !== [0, 0, 0] && array_filter($positiveMonths, fn (int $count): bool => $count < 6) !== []) {
            $result['status'] = 'history_unsuitable';
            $result['message'] = 'History requires at least six positive-sales months in each 12-month block, unless all 36 months are zero.';

            return $result;
        }
        $result['status'] = 'ready';
        $result['message'] = 'Ready to forecast from 36 trusted completed months.';

        return $result;
    }

    /**
     * Validate observations before filling only trusted, unrecorded months.
     *
     * @param  list<string>  $expectedStarts
     * @param  list<array{start: string, quantity_sold: mixed}>  $recorded
     * @return array<string, int>
     */
    private function recordedQuantities(array $expectedStarts, array $recorded): array
    {
        $quantities = [];
        foreach ($recorded as $row) {
            if (! in_array($row['start'], $expectedStarts, true) || array_key_exists($row['start'], $quantities)
                || ! is_int($row['quantity_sold']) || $row['quantity_sold'] < 0) {
                throw new InvalidArgumentException('Recorded monthly history contains an invalid or duplicate observation.');
            }
            $quantities[$row['start']] = $row['quantity_sold'];
        }

        return $quantities;
    }
}
