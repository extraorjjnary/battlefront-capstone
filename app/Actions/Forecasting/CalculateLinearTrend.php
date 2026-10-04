<?php

namespace App\Actions\Forecasting;

use App\Services\Reporting\QuarterlySalesAggregationService;

/**
 * @phpstan-import-type History from QuarterlySalesAggregationService
 * @phpstan-import-type TargetQuarter from PrepareQuarterlyForecastHistory
 *
 * @phpstan-type Forecast array{method: 'linear_trend', dimension: 'product'|'category', entity_id: int, timezone: string, status: 'ok'|'insufficient_history'|'missing_history', minimum_quarters: int, available_quarters: int, source_period: array{start: string, end_exclusive: string}|null, target_quarter: TargetQuarter, slope: numeric-string|null, intercept: numeric-string|null, raw_forecast_quantity: numeric-string|null, forecast_quantity: numeric-string|null, was_clamped: bool|null}
 */
class CalculateLinearTrend
{
    private const MINIMUM_QUARTERS = 4;

    public function __construct(private readonly PrepareQuarterlyForecastHistory $prepareHistory = new PrepareQuarterlyForecastHistory) {}

    /**
     * Fit every supplied quarter with x = 1..n, including zero demand.
     * The caller owns completed-history coverage; the next quarter uses x = n + 1.
     *
     * @param  History  $history
     * @return Forecast
     */
    public function execute(array $history, int $entityId): array
    {
        $prepared = $this->prepareHistory->execute($history, $entityId);
        $quarters = $prepared['quarters'];
        $available = count($quarters);

        $result = [
            'method' => 'linear_trend',
            'dimension' => $history['dimension'],
            'entity_id' => $entityId,
            'timezone' => $history['timezone'],
            'status' => $available === 0 ? 'missing_history' : 'insufficient_history',
            'minimum_quarters' => self::MINIMUM_QUARTERS,
            'available_quarters' => $available,
            'source_period' => null,
            'target_quarter' => $prepared['target_quarter'],
            'slope' => null,
            'intercept' => null,
            'raw_forecast_quantity' => null,
            'forecast_quantity' => null,
            'was_clamped' => null,
        ];

        if ($available < self::MINIMUM_QUARTERS) {
            return $result;
        }

        $sumX = $sumY = $sumXX = $sumXY = '0';
        foreach ($quarters as $index => $quarter) {
            $x = (string) ($index + 1);
            $y = (string) $quarter['quantity_sold'];
            $sumX = bcadd($sumX, $x, 0);
            $sumY = bcadd($sumY, $y, 0);
            $sumXX = bcadd($sumXX, bcmul($x, $x, 0), 0);
            $sumXY = bcadd($sumXY, bcmul($x, $y, 0), 0);
        }

        /**
         * With D = nΣx² - (Σx)², slope = B/D and intercept = A/D.
         * Keep these fractions exact until output; rounded coefficients must not
         * feed the projection. D is positive for consecutive x with n >= 4.
         */
        $count = (string) $available;
        $denominator = bcsub(bcmul($count, $sumXX, 0), bcmul($sumX, $sumX, 0), 0);
        $slopeNumerator = bcsub(bcmul($count, $sumXY, 0), bcmul($sumX, $sumY, 0), 0);
        $interceptNumerator = bcsub(bcmul($sumY, $sumXX, 0), bcmul($sumX, $sumXY, 0), 0);
        $projectionNumerator = bcadd($interceptNumerator, bcmul($slopeNumerator, bcadd($count, '1', 0), 0), 0);
        $wasClamped = bccomp($projectionNumerator, '0', 0) < 0;

        $result['status'] = 'ok';
        $result['source_period'] = [
            'start' => $quarters[0]['start'],
            'end_exclusive' => $prepared['target_quarter']['start'],
        ];
        $result['slope'] = $this->roundFraction($slopeNumerator, $denominator, 6);
        $result['intercept'] = $this->roundFraction($interceptNumerator, $denominator, 6);
        $result['raw_forecast_quantity'] = $this->roundFraction($projectionNumerator, $denominator, 6);
        $result['forecast_quantity'] = $wasClamped ? '0.00' : $this->roundFraction($projectionNumerator, $denominator, 2);
        $result['was_clamped'] = $wasClamped;

        return $result;
    }

    /**
     * Round an exact fraction to nearest, with ties away from zero.
     * Integer remainder comparison avoids intermediate truncation and double rounding.
     *
     * @param  numeric-string  $numerator
     * @param  numeric-string  $denominator  Positive integer.
     * @return numeric-string
     */
    private function roundFraction(string $numerator, string $denominator, int $precision): string
    {
        $negative = bccomp($numerator, '0', 0) < 0;
        $magnitude = $negative ? bcsub('0', $numerator, 0) : $numerator;
        $factor = bcpow('10', (string) $precision, 0);
        $scaled = bcmul($magnitude, $factor, 0);
        $rounded = bcdiv($scaled, $denominator, 0);
        $remainder = bcmod($scaled, $denominator, 0);
        if (bccomp(bcmul($remainder, '2', 0), $denominator, 0) >= 0) {
            $rounded = bcadd($rounded, '1', 0);
        }
        if ($negative && bccomp($rounded, '0', 0) !== 0) {
            $rounded = bcsub('0', $rounded, 0);
        }

        return bcdiv($rounded, $factor, $precision);
    }
}
