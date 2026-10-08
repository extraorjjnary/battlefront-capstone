<?php

namespace App\Console\Commands;

use App\Models\RecommendationInteraction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('app:prune-expired-recommendation-interactions')]
#[Description('Remove expired recommendation impression and click events.')]
class PruneExpiredRecommendationInteractions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = 0;
        RecommendationInteraction::query()->where('expires_at', '<=', now())->select('id')
            ->chunkById(500, function (Collection $records) use (&$deleted): void {
                $deleted += RecommendationInteraction::query()->whereKey($records->modelKeys())->where('expires_at', '<=', now())->delete();
            });

        $this->info("Deleted {$deleted} expired recommendation interactions.");

        return self::SUCCESS;
    }
}
