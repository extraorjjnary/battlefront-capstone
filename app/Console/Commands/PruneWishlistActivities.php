<?php

namespace App\Console\Commands;

use App\Models\WishlistActivity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-wishlist-activities')]
#[Description('Prune wishlist activity older than one year')]
class PruneWishlistActivities extends Command
{
    public function handle(): int
    {
        $cutoff = now('UTC')->subDays(365);
        $deleted = 0;

        do {
            $batch = WishlistActivity::query()
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(5000)
                ->delete();
            $deleted += $batch;
        } while ($batch === 5000);

        $this->info("Pruned {$deleted} wishlist activity records.");

        return self::SUCCESS;
    }
}
