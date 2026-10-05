<?php

namespace App\Repositories\Reporting;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type Coverage array{granularity: 'month', start: string, end_exclusive: string, unavailable_months: list<string>, timezone: string, source_kind: 'operational_prepared'|'synthetic_development', sales_scope: 'all_sagay_sales'|'captured_system_transactions'|'development_fixture_transactions'}
 */
class SalesHistoryCoverageRepository
{
    /**
     * Read preparation declarations only. EXT-42 evaluates readiness against its
     * required window; neither records nor the clock extend declared coverage.
     *
     * @return Coverage|null
     */
    public function forProductCode(string $productCode): ?array
    {
        if (preg_match(Product::CODE_PATTERN, $productCode) !== 1) {
            return null;
        }
        $operational = config('forecasting.operational_coverage');
        if (! is_array($operational)) {
            return null;
        }
        $development = App::environment(['local', 'testing']) ? $this->readDevelopment() : [];
        if (array_key_exists($productCode, $operational)) {
            if (array_key_exists($productCode, $development)) {
                return null;
            }

            return $this->validate($operational[$productCode], false);
        }

        return $this->validate($development[$productCode] ?? null, true);
    }

    /** @param array<string, Coverage> $entries */
    public function writeDevelopment(array $entries): void
    {
        $this->ensureDevelopmentEnvironment();
        $operational = config('forecasting.operational_coverage');
        if (! is_array($operational)) {
            throw new InvalidArgumentException('Operational coverage configuration must be an array.');
        }
        foreach ($entries as $code => $entry) {
            if (preg_match(Product::CODE_PATTERN, (string) $code) !== 1
                || array_key_exists($code, $operational)
                || $this->validate($entry, true) === null) {
                throw new InvalidArgumentException('Development history coverage contains invalid or conflicting product declarations.');
            }
        }
        ksort($entries, SORT_STRING);
        $disk = Storage::disk('local');
        $manifest = $this->manifestPath();
        if (! $disk->makeDirectory(dirname($manifest))) {
            throw new RuntimeException('Development history coverage directory could not be created.');
        }
        $path = $disk->path($manifest);
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $json = json_encode(['version' => 2, 'products' => (object) $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Development history coverage could not be published.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function clearDevelopment(): void
    {
        $this->ensureDevelopmentEnvironment();
        if (! Storage::disk('local')->delete($this->manifestPath())) {
            throw new RuntimeException('Previous development history coverage could not be invalidated.');
        }
    }

    /** @return array<string, mixed> */
    private function readDevelopment(): array
    {
        try {
            $contents = Storage::disk('local')->get($this->manifestPath());
            if ($contents === null) {
                return [];
            }
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ($manifest['version'] ?? null) !== 2 || ! is_array($manifest['products'] ?? null)) {
                return [];
            }

            return $manifest['products'];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return Coverage|null */
    private function validate(mixed $entry, bool $synthetic): ?array
    {
        if (! is_array($entry) || ($entry['granularity'] ?? null) !== 'month'
            || array_key_exists('unavailable_quarters', $entry)
            || ($entry['timezone'] ?? null) !== config('app.timezone')) {
            return null;
        }
        $kind = $synthetic ? 'synthetic_development' : 'operational_prepared';
        $scopes = $synthetic ? ['development_fixture_transactions'] : ['all_sagay_sales', 'captured_system_transactions'];
        if (($entry['source_kind'] ?? null) !== $kind || ! in_array($entry['sales_scope'] ?? null, $scopes, true)) {
            return null;
        }
        $start = $this->monthDate($entry['start'] ?? null);
        $end = $this->monthDate($entry['end_exclusive'] ?? null);
        $gaps = array_key_exists('unavailable_months', $entry) ? $entry['unavailable_months'] : [];
        if ($start === null || $end === null || $start->gt($end) || ! is_array($gaps) || ! array_is_list($gaps)) {
            return null;
        }
        $validatedGaps = [];
        foreach ($gaps as $gap) {
            $date = $this->monthDate($gap);
            if ($date === null || $date->lt($start) || $date->gte($end) || in_array($gap, $validatedGaps, true)) {
                return null;
            }
            $validatedGaps[] = $date->toDateString();
        }
        sort($validatedGaps, SORT_STRING);

        return [
            'granularity' => 'month',
            'start' => $start->toDateString(),
            'end_exclusive' => $end->toDateString(),
            'unavailable_months' => $validatedGaps,
            'timezone' => $entry['timezone'],
            'source_kind' => $kind,
            'sales_scope' => $entry['sales_scope'],
        ];
    }

    private function monthDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));

            return $date !== null && $date->year >= 1 && $date->toDateString() === $value && $date->equalTo($date->startOfMonth()) ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function manifestPath(): string
    {
        return config('forecasting.development_manifest');
    }

    private function ensureDevelopmentEnvironment(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('Synthetic history coverage is only allowed in local and testing environments.');
        }
    }
}
