<?php

namespace App\Repositories\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Throwable;

class CatalogSearch
{
    /** @return list<int>|null Null requests the database fallback. */
    public function matchingIds(string $query): ?array
    {
        if (config('scout.driver') !== 'algolia') {
            return null;
        }

        $startedAt = hrtime(true);

        try {
            $result = Product::search($query)->options([
                'hitsPerPage' => 1000,
                'attributesToRetrieve' => ['objectID'],
                'advancedSyntax' => true,
                'advancedSyntaxFeatures' => ['exactPhrase'],
            ])->raw();

            if (($result['nbHits'] ?? 1001) > 1000 || ! ($result['exhaustiveNbHits'] ?? false)
                || count($result['hits'] ?? []) !== ($result['nbHits'] ?? -1)) {
                $this->logFallback('incomplete_results', $startedAt);

                return null;
            }

            $ids = [];
            foreach ($result['hits'] as $hit) {
                $id = filter_var($hit['objectID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || in_array($id, $ids, true)) {
                    $this->logFallback('invalid_results', $startedAt);

                    return null;
                }
                $ids[] = $id;
            }

            Log::debug('Catalog search completed.', [
                'result_count' => count($ids),
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ]);

            return $ids;
        } catch (Throwable) {
            $this->logFallback('provider_failure', $startedAt);

            return null;
        }
    }

    private function logFallback(string $reason, int $startedAt): void
    {
        Log::warning('Catalog search used database fallback.', [
            'reason' => $reason,
            'duration_ms' => $this->durationMilliseconds($startedAt),
        ]);
    }

    private function durationMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
