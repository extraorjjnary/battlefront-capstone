<?php

namespace App\Actions\Forecasting;

use App\Models\Forecast;
use App\Models\Product;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

class PersistForecast
{
    /**
     * Persist a completed product forecast without repeating its calculation.
     *
     * @param  array<string, mixed>  $result
     */
    public function execute(array $result): Forecast
    {
        if (($result['dimension'] ?? null) === 'category') {
            throw new InvalidArgumentException('Category forecast results cannot be persisted by the product-only Forecast entity.');
        }
        if (($result['dimension'] ?? null) !== 'product') {
            throw new InvalidArgumentException('A product forecast result is required.');
        }

        $method = $result['method'] ?? null;
        if (! in_array($method, ['moving_average', 'linear_trend'], true)) {
            throw new InvalidArgumentException('Unsupported forecast method.');
        }
        if (($result['status'] ?? null) !== 'ok') {
            throw new InvalidArgumentException('Only completed forecast results can be persisted.');
        }

        $productId = $result['entity_id'] ?? null;
        if (! is_int($productId) || $productId < 1) {
            throw new InvalidArgumentException('A positive product ID is required.');
        }

        $demand = $result['forecast_quantity'] ?? null;
        if (! is_string($demand) || ! is_numeric($demand)
            || ! preg_match('/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D', $demand)) {
            throw new InvalidArgumentException('Forecast demand must be a nonnegative two-decimal value within DECIMAL(12,2).');
        }

        [$forecastQuarter, $sourceQuarters] = $this->validatePeriods($result);
        $availableQuarters = $result['available_quarters'] ?? null;
        if (! is_int($availableQuarters) || $availableQuarters < 4) {
            throw new InvalidArgumentException('A completed forecast requires at least four available quarters.');
        }

        if ($method === 'moving_average') {
            if (($result['window_size'] ?? null) !== 4 || $sourceQuarters !== 4) {
                throw new InvalidArgumentException('Moving average requires a four-quarter source window.');
            }
        } elseif (($result['minimum_quarters'] ?? null) !== 4 || $sourceQuarters !== $availableQuarters) {
            throw new InvalidArgumentException('Linear trend requires all available completed source quarters.');
        } else {
            $this->validateLinearMetadata($result, $demand);
        }

        if (! Product::query()->whereKey($productId)->exists()) {
            throw new InvalidArgumentException('The forecast product does not exist.');
        }

        Forecast::query()->upsert([[
            'product_id' => $productId,
            'method' => $method,
            'predicted_demand' => $demand,
            'forecast_quarter' => $forecastQuarter,
            'generated_at' => CarbonImmutable::now(),
        ]], ['product_id', 'method', 'forecast_quarter'], ['predicted_demand', 'generated_at']);

        return Forecast::query()
            ->where('product_id', $productId)
            ->where('method', $method)
            ->where('forecast_quarter', $forecastQuarter)
            ->sole();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{string, int}
     */
    private function validatePeriods(array $result): array
    {
        $target = $result['target_quarter'] ?? null;
        $source = $result['source_period'] ?? null;
        $timezoneName = $result['timezone'] ?? null;
        if (! is_array($target) || ! is_array($source) || ! is_string($timezoneName) || $timezoneName === '') {
            throw new InvalidArgumentException('Complete forecast periods and timezone are required.');
        }

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid forecast timezone.', previous: $exception);
        }

        $targetStart = $this->quarterBoundary($target['start'] ?? null, $timezone);
        $targetEnd = $targetStart->addQuarter();
        if (($target['year'] ?? null) !== $targetStart->year
            || ($target['quarter'] ?? null) !== $targetStart->quarter
            || ($target['end_exclusive'] ?? null) !== $targetEnd->toDateString()
            || $targetStart->greaterThan(CarbonImmutable::now($timezone)->startOfQuarter())) {
            throw new InvalidArgumentException('The target must be one complete quarter after completed history.');
        }

        $sourceStart = $this->quarterBoundary($source['start'] ?? null, $timezone);
        if (($source['end_exclusive'] ?? null) !== $targetStart->toDateString()) {
            throw new InvalidArgumentException('The source period must end at the target quarter.');
        }

        $sourceQuarters = (($targetStart->year - $sourceStart->year) * 4)
            + $targetStart->quarter - $sourceStart->quarter;
        if ($sourceQuarters < 4) {
            throw new InvalidArgumentException('The source period must contain at least four completed quarters.');
        }

        return [sprintf('%04d-Q%d', $targetStart->year, $targetStart->quarter), $sourceQuarters];
    }

    private function quarterBoundary(mixed $value, DateTimeZone $timezone): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{4}-(01|04|07|10)-01$/D', $value)) {
            throw new InvalidArgumentException('Forecast periods require calendar-quarter date boundaries.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid forecast period date.', previous: $exception);
        }
        if ($date === null || $date->year < 1 || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('Invalid forecast period date.');
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  numeric-string  $demand
     */
    private function validateLinearMetadata(array $result, string $demand): void
    {
        foreach (['slope', 'intercept', 'raw_forecast_quantity'] as $field) {
            $value = $result[$field] ?? null;
            if (! is_string($value) || ! preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]{6}$/D', $value)) {
                throw new InvalidArgumentException('Linear trend metadata is incomplete or malformed.');
            }
        }

        $clamped = $result['was_clamped'] ?? null;
        $raw = $result['raw_forecast_quantity'];
        if (! is_bool($clamped)
            || ($clamped && ($demand !== '0.00' || bccomp($raw, '0', 6) > 0))
            || (! $clamped && bccomp($raw, '0', 6) < 0)) {
            throw new InvalidArgumentException('Linear trend clamp metadata is inconsistent.');
        }

        /**
         * Both outputs round independently from the exact projection. Their rounding
         * intervals must overlap: half a cent plus half a millionth. Checking this
         * bound preserves valid double-rounding cases without recalculating demand.
         */
        $difference = bcsub($demand, $raw, 7);
        if (! $clamped && (bccomp($difference, '0.0050005', 7) >= 0
            || bccomp($difference, '-0.0050005', 7) <= 0)) {
            throw new InvalidArgumentException('Linear trend demand is inconsistent with its raw projection.');
        }
    }
}
