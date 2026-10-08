<?php

namespace App\Http\Controllers;

use App\Actions\User\RecordCustomerProductView;
use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\Catalog\ProductCatalogRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProductViewController extends Controller
{
    public function store(Request $request, int $product, ProductCatalogRepository $catalog, RecordCustomerProductView $recordView): Response
    {
        $user = $request->user();
        abort_if($user instanceof User && $user->role !== UserRole::Customer, 403);

        if (! $request->prefetch()) {
            $recordView($user, $catalog->findEligibleOrFail($product), $request->attributes->get('guest_recommendation_profile'));
        }

        return response()->noContent();
    }
}
