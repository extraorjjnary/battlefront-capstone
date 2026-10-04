<?php

use App\Actions\Forecasting\CalculateLinearTrend;

function linearTrendHistory(array $quantities, string $start = '2025-10-01', string $timezone = 'UTC'): array
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

test('returns the complete trend contract with exactly four observations', function () {
    $result = (new CalculateLinearTrend)->execute(linearTrendHistory([25, 30, 35, 40]), 7);

    expect($result)->toBe([
        'method' => 'linear_trend', 'dimension' => 'product', 'entity_id' => 7,
        'timezone' => 'UTC', 'status' => 'ok', 'minimum_quarters' => 4, 'available_quarters' => 4,
        'source_period' => ['start' => '2025-10-01', 'end_exclusive' => '2026-10-01'],
        'target_quarter' => ['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01'],
        'slope' => '5.000000', 'intercept' => '20.000000',
        'raw_forecast_quantity' => '45.000000', 'forecast_quantity' => '45.00', 'was_clamped' => false,
    ]);
});

test('fits demand patterns including zeros and retains signed projections', function (array $quantities, string $slope, string $intercept, string $raw, string $forecast, bool $clamped) {
    $result = (new CalculateLinearTrend)->execute(linearTrendHistory($quantities), 7);

    expect($result['status'])->toBe('ok');
    expect($result['slope'])->toBe($slope);
    expect($result['intercept'])->toBe($intercept);
    expect($result['raw_forecast_quantity'])->toBe($raw);
    expect($result['forecast_quantity'])->toBe($forecast);
    expect($result['was_clamped'])->toBe($clamped);
})->with([
    'constant' => [[10, 10, 10, 10], '0.000000', '10.000000', '10.000000', '10.00', false],
    'increasing' => [[5, 10, 15, 20], '5.000000', '0.000000', '25.000000', '25.00', false],
    'decreasing positive' => [[40, 35, 30, 25], '-5.000000', '45.000000', '20.000000', '20.00', false],
    'decreasing to zero' => [[4, 3, 2, 1], '-1.000000', '5.000000', '0.000000', '0.00', false],
    'negative projection' => [[9, 6, 3, 0], '-3.000000', '12.000000', '-3.000000', '0.00', true],
    'all zero' => [[0, 0, 0, 0], '0.000000', '0.000000', '0.000000', '0.00', false],
    'leading zeros' => [[0, 0, 0, 1], '0.300000', '-0.500000', '1.000000', '1.00', false],
    'internal zeros' => [[1, 0, 0, 1], '0.000000', '0.500000', '0.500000', '0.50', false],
    'trailing zeros' => [[3, 0, 0, 0], '-0.900000', '3.000000', '-1.500000', '0.00', true],
    'sparse repeating fractions' => [[0, 0, 3, 0, 0, 6, 0, 9], '0.857143', '-1.607143', '6.107143', '6.11', false],
    'sum and products beyond integer range' => [[PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX - 1], '-0.300000', '9223372036854775807.500000', '9223372036854775806.000000', '9223372036854775806.00', false],
]);

test('fits all supplied quarters and only the selected entity', function () {
    $history = linearTrendHistory([4, 8, 12, 20, 4, 8, 12, 20], '2024-10-01');
    array_unshift($history['series'], ['entity_id' => 1, 'quarters' => linearTrendHistory(array_fill(0, 8, 999), '2024-10-01')['series'][0]['quarters']]);

    $result = (new CalculateLinearTrend)->execute($history, 7);

    expect($result['available_quarters'])->toBe(8);
    expect($result['source_period'])->toBe(['start' => '2024-10-01', 'end_exclusive' => '2026-10-01']);
    expect($result['slope'])->toBe('1.238095');
    expect($result['intercept'])->toBe('5.428571');
    expect($result['raw_forecast_quantity'])->toBe('16.571429');
    expect($result['forecast_quantity'])->toBe('16.57');
});

test('returns insufficient history for fewer than four observations including zeros', function (array $quantities) {
    $history = linearTrendHistory($quantities);

    $result = (new CalculateLinearTrend)->execute($history, 7);

    expect($result['status'])->toBe('insufficient_history');
    expect($result['available_quarters'])->toBe(count($quantities));
    expect($result['minimum_quarters'])->toBe(4);
    expect($result['target_quarter']['start'])->toBe($history['end_exclusive']);
    foreach (['source_period', 'slope', 'intercept', 'raw_forecast_quantity', 'forecast_quantity', 'was_clamped'] as $field) {
        expect($result[$field])->toBeNull();
    }
})->with(['one' => [[10]], 'two' => [[10, 20]], 'three' => [[10, 20, 30]], 'three zeros' => [[0, 0, 0]]]);

test('returns missing history for an absent entity or empty observations', function (string $case) {
    $history = linearTrendHistory([1, 2, 3, 4]);
    match ($case) {
        'absent entity' => $history['series'][0]['entity_id'] = 8,
        'empty series' => $history['series'] = [],
        'empty quarters' => $history['series'][0]['quarters'] = [],
    };

    $result = (new CalculateLinearTrend)->execute($history, 7);

    expect($result['status'])->toBe('missing_history');
    expect($result['available_quarters'])->toBe(0);
    expect($result['minimum_quarters'])->toBe(4);
    expect($result['entity_id'])->toBe(7);
    expect($result['target_quarter'])->toBe(['year' => 2026, 'quarter' => 4, 'start' => '2026-10-01', 'end_exclusive' => '2027-01-01']);
    foreach (['source_period', 'slope', 'intercept', 'raw_forecast_quantity', 'forecast_quantity', 'was_clamped'] as $field) {
        expect($result[$field])->toBeNull();
    }
})->with(['absent entity', 'empty series', 'empty quarters']);

test('targets the following quarter including year rollover in the supplied timezone', function (string $start, array $expected) {
    $history = linearTrendHistory([1, 2, 3, 4], $start, 'Asia/Shanghai');

    $result = (new CalculateLinearTrend)->execute($history, 7);

    expect($result['target_quarter'])->toBe($expected);
    expect($result['timezone'])->toBe('Asia/Shanghai');
})->with([
    'Q4 to Q1' => ['2026-01-01', ['year' => 2027, 'quarter' => 1, 'start' => '2027-01-01', 'end_exclusive' => '2027-04-01']],
    'leap year' => ['2023-04-01', ['year' => 2024, 'quarter' => 2, 'start' => '2024-04-01', 'end_exclusive' => '2024-07-01']],
]);

test('rounds positive projection ties away from zero', function () {
    $quantities = array_fill(0, 16, 0);
    $quantities[6] = 1;

    $result = (new CalculateLinearTrend)->execute(linearTrendHistory($quantities), 7);

    expect($result['raw_forecast_quantity'])->toBe('0.025000');
    expect($result['forecast_quantity'])->toBe('0.03');
    expect($result['was_clamped'])->toBeFalse();
});

test('clamps a negative projection even when its two-decimal value would be zero', function () {
    $quantities = array_fill(0, 100, 0);
    $quantities[32] = 1;

    $result = (new CalculateLinearTrend)->execute(linearTrendHistory($quantities), 7);

    expect($result['slope'])->toBe('-0.000210');
    expect($result['intercept'])->toBe('0.020606');
    expect($result['raw_forecast_quantity'])->toBe('-0.000606');
    expect($result['forecast_quantity'])->toBe('0.00');
    expect($result['was_clamped'])->toBeTrue();
});

test('rounds coefficient and projection ties away from zero at six decimal places', function (int $count, int $index, int $quantity, string $field, string $expected) {
    $quantities = array_fill(0, $count, 0);
    $quantities[$index] = $quantity;

    $result = (new CalculateLinearTrend)->execute(linearTrendHistory($quantities), 7);

    expect($result[$field])->toBe($expected);
})->with([
    'positive intercept 0.0078125' => [256, 85, 1, 'intercept', '0.007813'],
    'negative intercept -0.0078125' => [256, 255, 1, 'intercept', '-0.007813'],
    'positive projection 0.0078125' => [512, 511, 1, 'raw_forecast_quantity', '0.007813'],
    'negative projection -0.0078125' => [512, 0, 2, 'raw_forecast_quantity', '-0.007813'],
]);

test('normalizes rounded negative zero while retaining the exact clamping decision', function () {
    $quantities = array_fill(0, 4000, 0);
    $quantities[1332] = 1;

    $result = (new CalculateLinearTrend)->execute(linearTrendHistory($quantities), 7);

    expect($result['slope'])->toBe('0.000000');
    expect($result['raw_forecast_quantity'])->toBe('0.000000');
    expect($result['forecast_quantity'])->toBe('0.00');
    expect($result['was_clamped'])->toBeTrue();
});

test('projects from exact fractions instead of rounded coefficients', function () {
    $quantities = array_fill(0, 1000, 0);
    $quantities[999] = 1;

    $result = (new CalculateLinearTrend)->execute(linearTrendHistory($quantities), 7);

    expect($result['slope'])->toBe('0.000006');
    expect($result['intercept'])->toBe('-0.002000');
    expect($result['raw_forecast_quantity'])->toBe('0.004000');
    expect($result['forecast_quantity'])->toBe('0.00');
});

test('preserves inputs and ignores revenue and global decimal scale', function () {
    $history = linearTrendHistory([0, 0, 3, 0, 0, 6, 0, 9]);
    $original = $history;
    $action = new CalculateLinearTrend;
    $before = $action->execute($history, 7);
    expect($history)->toBe($original);
    $history['series'][0]['quarters'][0]['item_revenue'] = '999999999.99';
    $originalScale = bcscale(12);

    try {
        expect($action->execute($history, 7))->toBe($before);
    } finally {
        bcscale($originalScale);
    }
});
