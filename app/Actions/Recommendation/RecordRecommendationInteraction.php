<?php

namespace App\Actions\Recommendation;

use App\Models\Product;
use App\Models\RecommendationInteraction;

class RecordRecommendationInteraction
{
    /**
     * Record one anonymous recommendation interaction event.
     *
     * @param  array{event_id: string, event_type: string, placement: string, position: int, reason_code: string|null}  $interaction
     */
    public function __invoke(Product $product, array $interaction): void
    {
        $now = now();

        RecommendationInteraction::query()->insertOrIgnore([[
            'event_id' => $interaction['event_id'],
            'product_id' => $product->id,
            'event_type' => $interaction['event_type'],
            'placement' => $interaction['placement'],
            'position' => $interaction['position'],
            'reason_code' => $interaction['reason_code'],
            'expires_at' => $now->copy()->addDays(90),
            'created_at' => $now,
            'updated_at' => $now,
        ]]);
    }
}
