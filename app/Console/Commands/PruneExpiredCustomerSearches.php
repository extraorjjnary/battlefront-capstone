<?php

namespace App\Console\Commands;

use App\Models\CustomerProductView;
use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('app:prune-expired-customer-searches')]
#[Description('Remove expired customer search and product view activity.')]
class PruneExpiredCustomerSearches extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $counts = [];
        foreach ([CustomerSearch::class, CustomerProductView::class, GuestRecommendationProfile::class] as $model) {
            $deleted = 0;
            $model::query()->where('expires_at', '<=', now())->select('id')
                ->chunkById(500, function (Collection $records) use ($model, &$deleted): void {
                    $deleted += $model::query()->whereKey($records->modelKeys())->where('expires_at', '<=', now())->delete();
                });
            $counts[] = $deleted;
        }
        [$deleted, $deletedProductViews, $deletedGuestProfiles] = $counts;

        $this->info("Deleted {$deleted} expired customer searches, {$deletedProductViews} expired product views, and {$deletedGuestProfiles} expired guest profiles.");

        return self::SUCCESS;
    }
}
