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
 * @phpstan-type TargetQuarter array{year: int, quarter: int, start: string, end_exclusive: string}
 */
class PrepareQuarterlyForecastHistory
{
    /**
     * Validate supplied coverage without querying, filling gaps, or consulting the clock.
     *
     * @param  History  $history
     * @return array{quarters: list<Quarter>, target_quarter: TargetQuarter}
     */
    public function execute(array $history, int $entityId): array
    {
        if ($entityId < 1) {
            throw new InvalidArgumentException('Entity IDs must be positive integers.');
        }

        $target = $this->validateEnvelope($history);
        $quarters = $this->selectQuarters($history['series'], $entityId);
        $this->validateQuarters($quarters, $history['start'], $target, $history['timezone']);

        return [
            'quarters' => $quarters,
            'target_quarter' => [
                'year' => $target->year,
                'quarter' => $target->quarter,
                'start' => $target->toDateString(),
                'end_exclusive' => $target->addQuarter()->toDateString(),
            ],
        ];
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
