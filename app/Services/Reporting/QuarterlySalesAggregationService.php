<?php

namespace App\Services\Reporting;

use App\Repositories\Reporting\QuarterlySalesRepository;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * @phpstan-type Quarter array{year: int, quarter: int, start: string, end_exclusive: string, quantity_sold: int, item_revenue: numeric-string}
 * @phpstan-type History array{dimension: 'product'|'category', timezone: string, start: string, end_exclusive: string, series: list<array{entity_id: int, quarters: list<Quarter>}>}
 */
class QuarterlySalesAggregationService
{
    public function __construct(private readonly QuarterlySalesRepository $repository) {}

    /**
     * @param  list<int>|null  $productIds
     * @return History
     */
    public function products(CarbonImmutable $start, CarbonImmutable $endExclusive, ?array $productIds = null): array
    {
        return $this->aggregate('product', $start, $endExclusive, $productIds);
    }

    /**
     * Categories reflect current product membership, since no category snapshot is stored.
     *
     * @param  list<int>|null  $categoryIds
     * @return History
     */
    public function categories(CarbonImmutable $start, CarbonImmutable $endExclusive, ?array $categoryIds = null): array
    {
        return $this->aggregate('category', $start, $endExclusive, $categoryIds);
    }

    /**
     * @param  list<int>|null  $productIds
     * @return History
     */
    public function completedProducts(int $quarterCount, ?array $productIds = null, ?CarbonImmutable $asOf = null): array
    {
        [$start, $end] = $this->completedWindow($quarterCount, $asOf);

        return $this->products($start, $end, $productIds);
    }

    /**
     * @param  list<int>|null  $categoryIds
     * @return History
     */
    public function completedCategories(int $quarterCount, ?array $categoryIds = null, ?CarbonImmutable $asOf = null): array
    {
        [$start, $end] = $this->completedWindow($quarterCount, $asOf);

        return $this->categories($start, $end, $categoryIds);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function completedWindow(int $quarterCount, ?CarbonImmutable $asOf): array
    {
        if ($quarterCount < 1) {
            throw new InvalidArgumentException('Quarter count must be positive.');
        }
        $timezone = config('app.timezone');
        $end = ($asOf ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfQuarter();

        return [$end->subQuarters($quarterCount), $end];
    }

    /**
     * @param  'product'|'category'  $dimension
     * @param  list<int>|null  $ids
     * @return History
     */
    private function aggregate(string $dimension, CarbonImmutable $start, CarbonImmutable $endExclusive, ?array $ids): array
    {
        $timezone = config('app.timezone');
        $start = $start->setTimezone($timezone);
        $endExclusive = $endExclusive->setTimezone($timezone);
        if (! $start->equalTo($start->startOfQuarter())
            || ! $endExclusive->equalTo($endExclusive->startOfQuarter())
            || ! $start->lessThan($endExclusive)) {
            throw new InvalidArgumentException('History requires increasing quarter-aligned midnight boundaries in the application timezone.');
        }
        if ($ids !== null) {
            $ids = array_map($this->validateId(...), $ids);
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_NUMERIC);
            if ($ids !== [] && $this->repository->existingIds($dimension, $ids) !== $ids) {
                throw new InvalidArgumentException('One or more requested entities do not exist.');
            }
        }
        $buckets = [];
        for ($quarter = $start; $quarter->lessThan($endExclusive); $quarter = $quarter->addQuarter()) {
            $buckets[$quarter->toDateString()] = [
                'year' => $quarter->year,
                'quarter' => $quarter->quarter,
                'start' => $quarter->toDateString(),
                'end_exclusive' => $quarter->addQuarter()->toDateString(),
                'quantity_sold' => 0,
                'item_revenue' => '0.00',
            ];
        }
        $series = [];
        foreach ($ids ?? [] as $id) {
            $series[$id] = $buckets;
        }
        if ($ids !== [] && $buckets !== []) {
            foreach ($this->repository->aggregate($dimension, array_values($buckets), $ids) as $row) {
                $id = $row['entity_id'];
                $series[$id] ??= $buckets;
                $bucket = &$series[$id][$row['start']];
                $bucket['quantity_sold'] += $row['quantity_sold'];
                $bucket['item_revenue'] = bcadd($bucket['item_revenue'], $row['item_revenue'], 2);
                unset($bucket);
            }
        }
        ksort($series, SORT_NUMERIC);
        $result = [];
        foreach ($series as $id => $quarters) {
            $result[] = ['entity_id' => $id, 'quarters' => array_values($quarters)];
        }

        return [
            'dimension' => $dimension,
            'timezone' => $timezone,
            'start' => $start->toDateString(),
            'end_exclusive' => $endExclusive->toDateString(),
            'series' => $result,
        ];
    }

    private function validateId(mixed $id): int
    {
        if (! is_int($id) || $id < 1) {
            throw new InvalidArgumentException('Entity IDs must be positive integers.');
        }

        return $id;
    }
}
