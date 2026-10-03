<?php

namespace App\Actions\Forecasting;

use App\Services\Reporting\QuarterlySalesAggregationService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * @phpstan-import-type History from QuarterlySalesAggregationService
 * @phpstan-import-type Quarter from QuarterlySalesAggregationService
 *
 * @phpstan-type Forecast array{method: 'moving_average', dimension: 'product'|'category', entity_id: int, timezone: string, status: 'ok'|'insufficient_history'|'missing_history', window_size: int, available_quarters: int, source_period: array{start: string, end_exclusive: string}|null, target_quarter: array{year: int, quarter: int, start: string, end_exclusive: string}, forecast_quantity: numeric-string|null}
 */
class CalculateMovingAverage
{
    private const WINDOW_SIZE = 4;

    /**
     * Use the latest full year of supplied demand, including zero observations.
     * History coverage is owned by the caller and the shared aggregation contract.
     *
     * @param  History  $history
     * @return Forecast
     */
    public function execute(array $history, int $entityId): array
    {
        if ($entityId < 1) {
            throw new InvalidArgumentException('Entity IDs must be positive integers.');
        }

        $target = $this->validateEnvelope($history);
        $quarters = $this->selectQuarters($history['series'], $entityId);
        $this->validateQuarters($quarters, $history['start'], $target, $history['timezone']);
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
            'target_quarter' => [
                'year' => $target->year,
                'quarter' => $target->quarter,
                'start' => $target->toDateString(),
                'end_exclusive' => $target->addQuarter()->toDateString(),
            ],
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
            'end_exclusive' => $target->toDateString(),
        ];
        /** Integer demand divided by four is exact at two decimal places. */
        $result['forecast_quantity'] = bcdiv($total, (string) self::WINDOW_SIZE, 2);

        return $result;
    }

    /** @param array<string, mixed> $history */
    private function validateEnvelope(array $history): CarbonImmutable
    {
        if (! in_array($history['dimension'] ?? null, ['product', 'category'], true)
            || ! is_string($history['timezone'] ?? null)
            || ! is_array($history['series'] ?? null)
            || ! array_is_list($history['series'])) {
            throw new InvalidArgumentException('Expected a quarterly aggregation history envelope.');
        }

        $start = $this->quarterBoundary($history['start'] ?? null, $history['timezone']);
        $end = $this->quarterBoundary($history['end_exclusive'] ?? null, $history['timezone']);
        if (! $start->lessThan($end)) {
            throw new InvalidArgumentException('History boundaries must be increasing.');
        }

        return $end;
    }

    /**
     * @param  list<mixed>  $series
     * @return list<Quarter>
     */
    private function selectQuarters(array $series, int $entityId): array
    {
        $selected = [];
        $seen = [];
        foreach ($series as $entity) {
            if (! is_array($entity) || ! is_int($entity['entity_id'] ?? null)
                || $entity['entity_id'] < 1 || isset($seen[$entity['entity_id']])
                || ! is_array($entity['quarters'] ?? null) || ! array_is_list($entity['quarters'])) {
                throw new InvalidArgumentException('History series require unique positive entity IDs and quarter lists.');
            }
            $seen[$entity['entity_id']] = true;
            if ($entity['entity_id'] === $entityId) {
                $selected = $entity['quarters'];
            }
        }

        return $selected;
    }

    /** @param list<mixed> $quarters */
    private function validateQuarters(array $quarters, string $start, CarbonImmutable $end, string $timezone): void
    {
        $expectedStart = $start;
        foreach ($quarters as $quarter) {
            if (! is_array($quarter) || ! is_int($quarter['quantity_sold'] ?? null)
                || $quarter['quantity_sold'] < 0 || ($quarter['start'] ?? null) !== $expectedStart) {
                throw new InvalidArgumentException('Quarter observations require nonnegative integer demand and consecutive ordered periods.');
            }
            $boundary = $this->quarterBoundary($quarter['start'], $timezone);
            $expectedStart = $boundary->addQuarter()->toDateString();
            if (($quarter['year'] ?? null) !== $boundary->year
                || ($quarter['quarter'] ?? null) !== $boundary->quarter
                || ($quarter['end_exclusive'] ?? null) !== $expectedStart) {
                throw new InvalidArgumentException('Quarter metadata must describe one calendar quarter.');
            }
        }

        if ($quarters !== [] && $expectedStart !== $end->toDateString()) {
            throw new InvalidArgumentException('Quarter observations must cover the supplied history period.');
        }
    }

    private function quarterBoundary(mixed $value, string $timezone): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(01|04|07|10)-01$/D', $value) || $timezone === '') {
            throw new InvalidArgumentException('History requires calendar-quarter date boundaries and an explicit timezone.');
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid history date or timezone.', previous: $exception);
        }
        if ($date === null || $date->year < 1 || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('Invalid history date.');
        }

        return $date;
    }
}
