<?php

namespace App\Actions\Forecasting;

use InvalidArgumentException;

/**
 * Pure numerical kernel; parameters are never administrator inputs.
 *
 * @phpstan-type State array{level: numeric-string, trend: numeric-string, seasonal: list<numeric-string>}
 * @phpstan-type Parameters array{alpha: numeric-string, beta: numeric-string, gamma: numeric-string}
 * @phpstan-type Fit array{state: State, parameters: Parameters, fitting_sse: numeric-string, candidates_evaluated: int}
 * @phpstan-type Projection array{raw_quantity: numeric-string, usable_quantity: numeric-string, was_clamped: bool}
 */
class AdditiveHoltWintersModel
{
    public const SCALE = 12;

    /**
     * Detrend two annual cycles, initialize at month 24, and center once.
     *
     * @param  list<int>  $quantities
     * @return State
     */
    public function initialize(array $quantities): array
    {
        $this->validateQuantities($quantities);
        $a = bcdiv($this->sum(array_map(strval(...), array_slice($quantities, 0, 12))), '12', self::SCALE);
        $b = bcdiv($this->sum(array_map(strval(...), array_slice($quantities, 12, 12))), '12', self::SCALE);
        $trend = bcdiv(bcsub($b, $a, self::SCALE), '12', self::SCALE);
        $level = bcadd($b, bcmul('5.5', $trend, self::SCALE), self::SCALE);
        $seasonal = [];
        for ($index = 0; $index < 12; $index++) {
            $offset = bcmul(bcsub((string) ($index + 1), '6.5', self::SCALE), $trend, self::SCALE);
            $first = bcsub(bcsub((string) $quantities[$index], $a, self::SCALE), $offset, self::SCALE);
            $second = bcsub(bcsub((string) $quantities[12 + $index], $b, self::SCALE), $offset, self::SCALE);
            $seasonal[] = bcdiv(bcadd($first, $second, self::SCALE), '2', self::SCALE);
        }
        $mean = bcdiv($this->sum($seasonal), '12', self::SCALE);

        return ['level' => $level, 'trend' => $trend, 'seasonal' => array_map(fn (string $value): string => bcsub($value, $mean, self::SCALE), $seasonal)];
    }

    /**
     * Forecast before updating, then update level, trend, and gamma-star seasonality.
     * Position 0..11 identifies the month of the annual cycle.
     *
     * @param  State  $state
     * @param  Parameters  $parameters
     * @return array{state: State, prediction: numeric-string}
     */
    public function advance(array $state, int $quantity, int $position, array $parameters): array
    {
        $oldSeason = $state['seasonal'][$position];
        $base = bcadd($state['level'], $state['trend'], self::SCALE);
        $prediction = bcadd($base, $oldSeason, self::SCALE);
        $level = bcadd(
            bcmul($parameters['alpha'], bcsub((string) $quantity, $oldSeason, self::SCALE), self::SCALE),
            bcmul(bcsub('1', $parameters['alpha'], self::SCALE), $base, self::SCALE), self::SCALE,
        );
        $trend = bcadd(
            bcmul($parameters['beta'], bcsub($level, $state['level'], self::SCALE), self::SCALE),
            bcmul(bcsub('1', $parameters['beta'], self::SCALE), $state['trend'], self::SCALE), self::SCALE,
        );
        $season = bcadd(
            bcmul($parameters['gamma'], bcsub((string) $quantity, $level, self::SCALE), self::SCALE),
            bcmul(bcsub('1', $parameters['gamma'], self::SCALE), $oldSeason, self::SCALE), self::SCALE,
        );
        $seasonal = array_map(fn (string $value, int $index): string => $index === $position ? $season : $value, $state['seasonal'], array_keys($state['seasonal']));

        return ['state' => ['level' => $level, 'trend' => $trend, 'seasonal' => $seasonal], 'prediction' => $prediction];
    }

    /**
     * Fit months 25..36 with raw squared errors and ascending lexicographic ties.
     * Gamma updates are first reused after this fitting cycle, so tied gamma is 0.
     *
     * @param  list<int>  $quantities
     * @return Fit
     */
    public function fit(array $quantities): array
    {
        $initial = $this->initialize($quantities);
        $best = ['state' => $initial, 'parameters' => ['alpha' => '0.1', 'beta' => '0.0', 'gamma' => '0.0'], 'fitting_sse' => '0.000000000000', 'candidates_evaluated' => 0];
        if (array_filter($quantities, fn (int $quantity): bool => $quantity > 0) === []) {
            return $best;
        }
        $bestScore = null;
        for ($alpha = 1; $alpha <= 9; $alpha++) {
            for ($beta = 0; $beta <= 9; $beta++) {
                for ($gamma = 0; $gamma <= 9; $gamma++) {
                    $parameters = ['alpha' => '0.'.$alpha, 'beta' => '0.'.$beta, 'gamma' => '0.'.$gamma];
                    $state = $initial;
                    $score = '0.000000000000';
                    for ($position = 0; $position < 12; $position++) {
                        $step = $this->advance($state, $quantities[24 + $position], $position, $parameters);
                        $error = bcsub((string) $quantities[24 + $position], $step['prediction'], self::SCALE);
                        $score = bcadd($score, bcmul($error, $error, self::SCALE), self::SCALE);
                        $state = $step['state'];
                    }
                    $best['candidates_evaluated']++;
                    if ($bestScore === null || bccomp($score, $bestScore, self::SCALE) < 0) {
                        $bestScore = $score;
                        $best['state'] = $state;
                        $best['parameters'] = $parameters;
                        $best['fitting_sse'] = $score;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Positions 0..2 retain s25..s27 after fitting. Never advance with forecasts.
     *
     * @param  State  $state
     * @return list<Projection>
     */
    public function forecast(array $state): array
    {
        $months = [];
        for ($horizon = 1; $horizon <= 3; $horizon++) {
            $raw = bcadd(bcadd($state['level'], bcmul((string) $horizon, $state['trend'], self::SCALE), self::SCALE), $state['seasonal'][$horizon - 1], self::SCALE);
            $clamped = bccomp($raw, '0', self::SCALE) < 0;
            $months[] = ['raw_quantity' => $raw, 'usable_quantity' => $clamped ? '0.000000000000' : $raw, 'was_clamped' => $clamped];
        }

        return $months;
    }

    /** @param list<numeric-string> $values
     * @return numeric-string
     */
    public function sum(array $values): string
    {
        $total = '0.000000000000';
        foreach ($values as $value) {
            $total = bcadd($total, $value, self::SCALE);
        }

        return $total;
    }

    /** @param numeric-string $quantity
     * @return numeric-string
     */
    public function roundNonnegative(string $quantity): string
    {
        if (bccomp($quantity, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Only nonnegative quantities can be finalized.');
        }

        return bcdiv(bcadd($quantity, '0.005', self::SCALE), '1', 2);
    }

    /** @param array<array-key, mixed> $quantities */
    private function validateQuantities(array $quantities): void
    {
        if (! array_is_list($quantities) || count($quantities) !== 36) {
            throw new InvalidArgumentException('Holt-Winters requires exactly 36 monthly quantities.');
        }
        foreach ($quantities as $quantity) {
            if (! is_int($quantity) || $quantity < 0) {
                throw new InvalidArgumentException('Monthly quantities must be nonnegative integers.');
            }
        }
    }
}
