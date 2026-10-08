<?php

namespace App\Console\Commands;

use App\Models\CustomerProductView;
use App\Models\CustomerSearch;
use App\Models\GuestRecommendationProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-expired-customer-searches')]
#[Description('Remove expired customer search and product view activity.')]
class PruneExpiredCustomerSearches extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = CustomerSearch::query()
            ->where('expires_at', '<=', now())
            ->delete();
        $deletedProductViews = CustomerProductView::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $deletedGuestProfiles = GuestRecommendationProfile::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Deleted {$deleted} expired customer searches, {$deletedProductViews} expired product views, and {$deletedGuestProfiles} expired guest profiles.");

        return self::SUCCESS;
    }
}
