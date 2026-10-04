<?php

namespace App\Actions\Forecasting;

use App\Services\Reporting\QuarterlySalesAggregationService;

/**
 * @phpstan-import-type History from QuarterlySalesAggregationService
 *
 * @phpstan-type Forecast array{method: 'moving_average', dimension: 'product'|'category', entity_id: int, timezone: string, status: 'ok'|'insufficient_history'|'missing_history', window_size: int, available_quarters: int, source_period: array{start: string, end_exclusive: string}|null, target_quarter: array{year: int, quarter: int, start: string, end_exclusive: string}, forecast_quantity: numeric-string|null}
 */
class CalculateMovingAverage
{
    private const WINDOW_SIZE = 4;

    public function __construct(private readonly PrepareQuarterlyForecastHistory $prepareHistory = new PrepareQuarterlyForecastHistory) {}

    /**
     * Use the latest full year of supplied demand, including zero observations.
     * History coverage is owned by the caller and the shared aggregation contract.
     *
     * @param  History  $history
     * @return Forecast
     */
    public function execute(array $history, int $entityId): array
    {
        $prepared = $this->prepareHistory->execute($history, $entityId);
        $quarters = $prepared['quarters'];
        $target = $prepared['target_quarter'];
        $available = count($quarters);

        $result = [
            'method' => 'moving_average',
            'dimension' => $history['dimension'],
            'entity_id' => $entityId,
            'timezone' => $history['timezone'],
            'status' => $available === 0 ? 'missing_history' : 'insufficient_history',
            'window_size' => self::WINDOW_SIZE,
            'available_quarters' => $available,
            'source_period' => null,
            'target_quarter' => $target,
            'forecast_quantity' => null,
        ];

        if ($available < self::WINDOW_SIZE) {
            return $result;
        }

        $window = array_slice($quarters, -self::WINDOW_SIZE);
        $total = '0';
        foreach ($window as $quarter) {
            $total = bcadd($total, (string) $quarter['quantity_sold'], 0);
        }

        $result['status'] = 'ok';
        $result['source_period'] = [
            'start' => $window[0]['start'],
            'end_exclusive' => $target['start'],
        ];
        /** Integer demand divided by four is exact at two decimal places. */
        $result['forecast_quantity'] = bcdiv($total, (string) self::WINDOW_SIZE, 2);

        return $result;
    }
}
