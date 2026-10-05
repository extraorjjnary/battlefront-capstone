<?php

use App\Actions\Forecasting\AdditiveHoltWintersModel;

test('detrends two cycles without converting linear growth into seasonality', function () {
    $state = (new AdditiveHoltWintersModel)->initialize(range(1, 36));

    expect($state['level'])->toBe('24.000000000000');
    expect($state['trend'])->toBe('1.000000000000');
    expect($state['seasonal'])->toBe(array_fill(0, 12, '0.000000000000'));
});

test('centers an additive calendar profile independently of its level', function () {
    $profile = [18, 14, 12, 8, 10, 12, 16, 20, 24, 28, 26, 22];

    $state = (new AdditiveHoltWintersModel)->initialize(array_merge($profile, $profile, $profile));

    expect($state['level'])->toBe('17.500000000000');
    expect($state['trend'])->toBe('0.000000000000');
    expect($state['seasonal'])->toBe(['0.500000000000', '-3.500000000000', '-5.500000000000', '-9.500000000000', '-7.500000000000', '-5.500000000000', '-1.500000000000', '2.500000000000', '6.500000000000', '10.500000000000', '8.500000000000', '4.500000000000']);
});

test('updates level trend and gamma star from independently worked steps', function () {
    $model = new AdditiveHoltWintersModel;
    $initial = $model->initialize(array_fill(0, 36, 10));
    $parameters = ['alpha' => '0.1', 'beta' => '0.2', 'gamma' => '0.3'];

    $first = $model->advance($initial, 14, 0, $parameters);
    $second = $model->advance($first['state'], 10, 1, $parameters);

    expect($first['prediction'])->toBe('10.000000000000');
    expect($first['state']['level'])->toBe('10.400000000000');
    expect($first['state']['trend'])->toBe('0.080000000000');
    expect($first['state']['seasonal'][0])->toBe('1.080000000000');
    expect($second['prediction'])->toBe('10.480000000000');
    expect($second['state']['level'])->toBe('10.432000000000');
    expect($second['state']['trend'])->toBe('0.070400000000');
    expect($second['state']['seasonal'][1])->toBe('-0.129600000000');
    expect($initial['level'])->toBe('10.000000000000');
});

test('searches the full grid with isolated candidates and reproduces a nontrivial optimum', function () {
    $model = new AdditiveHoltWintersModel;

    $fit = $model->fit([...array_fill(0, 24, 10), ...range(11, 22)]);

    expect($fit['candidates_evaluated'])->toBe(900);
    expect($fit['parameters'])->toBe(['alpha' => '0.9', 'beta' => '0.9', 'gamma' => '0.0']);
    expect($fit['fitting_sse'])->toBe('1.085551312030');
    expect($fit['state']['level'])->toBe('21.999999808847');
    expect($fit['state']['trend'])->toBe('0.999999070517');
    expect(array_column($model->forecast($fit['state']), 'raw_quantity'))->toBe(['22.999998879364', '23.999997949881', '24.999997020398']);
});

test('chooses the smallest tied tuple and bypasses search only for all zero history', function (int $quantity, int $count) {
    $fit = (new AdditiveHoltWintersModel)->fit(array_fill(0, 36, $quantity));

    expect($fit['parameters'])->toBe(['alpha' => '0.1', 'beta' => '0.0', 'gamma' => '0.0']);
    expect($fit['fitting_sse'])->toBe('0.000000000000');
    expect($fit['candidates_evaluated'])->toBe($count);
})->with(['constant' => [10, 900], 'all zero' => [0, 0]]);

test('scores raw negative one step predictions before applying output clamps', function () {
    $fit = (new AdditiveHoltWintersModel)->fit([...range(24, 1), ...array_fill(0, 12, 0)]);

    expect(bccomp($fit['fitting_sse'], '1', 12))->toBeGreaterThanOrEqual(0);
    expect($fit['parameters'])->not->toBe(['alpha' => '0.1', 'beta' => '0.0', 'gamma' => '0.0']);
});

test('retains negative raw estimates while clamping each month without feedback', function () {
    $model = new AdditiveHoltWintersModel;
    $state = ['level' => '4.000000000000', 'trend' => '-1.000000000000', 'seasonal' => ['-5.000000000000', '0.000000000000', '5.000000000000', ...array_fill(0, 9, '0.000000000000')]];
    $original = $state;

    $months = $model->forecast($state);

    expect(array_column($months, 'raw_quantity'))->toBe(['-2.000000000000', '2.000000000000', '6.000000000000']);
    expect(array_column($months, 'usable_quantity'))->toBe(['0.000000000000', '2.000000000000', '6.000000000000']);
    expect(array_column($months, 'was_clamped'))->toBe([true, false, false]);
    expect($model->roundNonnegative($model->sum(array_column($months, 'usable_quantity'))))->toBe('8.00');
    expect($state)->toBe($original);
});

test('rounds only the final nonnegative total half up', function (string $value, string $expected) {
    expect((new AdditiveHoltWintersModel)->roundNonnegative($value))->toBe($expected);
})->with([
    ['0.004999999999', '0.00'], ['0.005000000000', '0.01'], ['0.005000000001', '0.01'], ['1.005000000000', '1.01'], ['9999999999.995000000000', '10000000000.00'],
]);

test('sums internal quantities before presentation rounding and truncates explicitly', function () {
    $model = new AdditiveHoltWintersModel;

    expect($model->roundNonnegative($model->sum(['0.004', '0.004', '0.004'])))->toBe('0.01');
    expect($model->roundNonnegative('0.004'))->toBe('0.00');
    expect($model->sum(['0.0000000000009', '0.0000000000009']))->toBe('0.000000000000');
    expect(fn () => $model->roundNonnegative('-0.000000000001'))->toThrow(InvalidArgumentException::class);
});

test('computes large totals without native integer overflow', function () {
    $fit = (new AdditiveHoltWintersModel)->fit(array_fill(0, 36, PHP_INT_MAX));

    expect($fit['state']['level'])->toBe('9223372036854775807.000000000000');
    expect($fit['fitting_sse'])->toBe('0.000000000000');
});

test('rejects malformed numerical series', function (array $values) {
    expect(fn () => (new AdditiveHoltWintersModel)->fit($values))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [[]], 'short' => [array_fill(0, 35, 1)], 'long' => [array_fill(0, 37, 1)],
    'negative' => [[-1, ...array_fill(0, 35, 1)]], 'decimal' => [[1.5, ...array_fill(0, 35, 1)]],
    'string' => [['1', ...array_fill(0, 35, 1)]], 'boolean' => [[true, ...array_fill(0, 35, 1)]], 'null' => [[null, ...array_fill(0, 35, 1)]],
    'not list' => [[1 => 1, ...array_fill(0, 35, 1)]],
]);
