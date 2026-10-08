<?php

use App\Actions\Forecasting\CalculateMovingAverage;

function movingAverageHistory(array $quantities, string $start = '2025-10-01', string $timezone = 'UTC'): array
{
    $date = new DateTimeImmutable($start, new DateTimeZone($timezone));
    $quarters = [];
    foreach ($quantities as $quantity) {
        $next = $date->modify('+3 months');
        $quarters[] = [
            'year' => (int) $date->format('Y'),
            'quarter' => intdiv((int) $date->format('n') - 1, 3) + 1,
            'start' => $date->format('Y-m-d'),
            'end_exclusive' => $next->format('Y-m-d'),
            'quantity_sold' => $quantity,
            'item_revenue' => '0.00',
        ];
        $date = $next;
    }

    return [
        'dimension' => 'product', 'timezone' => $timezone,
        'start' => $start, 'end_exclusive' => $date->format('Y-m-d'),
        'series' => [['entity_id' => 7, 'quarters' => $quarters]],
    ];
}

test('returns the complete forecast contract with exactly four observations', function () {
    $history = movingAverageHistory([25, 30, 35, 40]);

    $result = (new CalculateMovingAverage)->execute($history, 7);

    expect($result)->toBe([
        'method' => 'moving_average', 'dimension' => 'product', 'entity_id' => 7,
        'timezone' => 'UTC', 'status' => 'ok', 'window_size' => 4, 'available_quarters' => 4,
        'source_period' => ['start' => '2025-10-01', 'end_exclusive' => '2026-10-01'],
        'target_quarter' => ['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01'],
        'forecast_quantity' => '32.50',
    ]);
});

test('uses only the latest four quarters and the requested entity', function () {
    $history = movingAverageHistory([999, 800, 700, 600, 2, 4, 6, 8], '2024-10-01');
    array_unshift($history['series'], ['entity_id' => 1, 'quarters' => movingAverageHistory([50, 50, 50, 50])['series'][0]['quarters']]);

    $result = (new CalculateMovingAverage)->execute($history, 7);

    expect($result['forecast_quantity'])->toBe('5.00');
    expect($result['available_quarters'])->toBe(8);
    expect($result['source_period'])->toBe(['start' => '2025-10-01', 'end_exclusive' => '2026-10-01']);
});

test('keeps zero observations in every position and preserves exact decimal demand', function (array $quantities, string $expected) {
    $result = (new CalculateMovingAverage)->execute(movingAverageHistory($quantities), 7);

    expect($result['status'])->toBe('ok');
    expect($result['forecast_quantity'])->toBe($expected);
})->with([
    'all zero' => [[0, 0, 0, 0], '0.00'],
    'leading zeros' => [[0, 0, 0, 1], '0.25'],
    'internal zeros' => [[1, 0, 0, 1], '0.50'],
    'trailing zeros' => [[3, 0, 0, 0], '0.75'],
    'sum beyond integer range' => [[PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX - 1], '9223372036854775806.75'],
]);

test('returns insufficient history without reducing the divisor', function (array $quantities) {
    $history = movingAverageHistory($quantities);

    $result = (new CalculateMovingAverage)->execute($history, 7);

    expect($result['status'])->toBe('insufficient_history');
    expect($result['available_quarters'])->toBe(count($quantities));
    expect($result['window_size'])->toBe(4);
    expect($result['forecast_quantity'])->toBeNull();
    expect($result['source_period'])->toBeNull();
    expect($result['target_quarter']['start'])->toBe($history['end_exclusive']);
})->with([[[10]], [[10, 10]], [[10, 10, 10]]]);

test('returns missing history for an absent entity or empty series', function (string $case) {
    $history = movingAverageHistory([10, 10, 10, 10]);
    match ($case) {
        'absent entity' => $history['series'][0]['entity_id'] = 8,
        'empty series' => $history['series'] = [],
        'empty quarters' => $history['series'][0]['quarters'] = [],
    };

    $result = (new CalculateMovingAverage)->execute($history, 7);

    expect($result['status'])->toBe('missing_history');
    expect($result['available_quarters'])->toBe(0);
    expect($result['forecast_quantity'])->toBeNull();
    expect($result['source_period'])->toBeNull();
    expect($result['target_quarter'])->toBe(['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01']);
})->with(['absent entity', 'empty series', 'empty quarters']);

test('targets the following quarter including year rollover in the supplied timezone', function (string $start, array $expected) {
    $history = movingAverageHistory([1, 1, 1, 1], $start, 'Asia/Shanghai');

    $result = (new CalculateMovingAverage)->execute($history, 7);

    expect($result['target_quarter'])->toBe($expected);
    expect($result['timezone'])->toBe('Asia/Shanghai');
})->with([
    'Q4 to Q1' => ['2026-01-01', ['year' => 2027, 'quarter' => 1, 'start' => '2027-01-01', 'end_exclusive' => '2027-04-01']],
    'leap year' => ['2023-04-01', ['year' => 2024, 'quarter' => 2, 'start' => '2024-04-01', 'end_exclusive' => '2024-07-01']],
]);

test('rejects malformed evaluation baseline history', function (string $case) {
    $history = movingAverageHistory([1, 2, 3, 4]);
    $quarters = &$history['series'][0]['quarters'];
    match ($case) {
        'gap' => array_splice($quarters, 1, 1),
        'invalid demand' => $quarters[1]['quantity_sold'] = -1,
    };

    expect(fn () => (new CalculateMovingAverage)->execute($history, 7))->toThrow(InvalidArgumentException::class);
})->with(['gap', 'invalid demand']);
