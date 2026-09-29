<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\BuildCartViewData;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CartResource;
use App\Models\User;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request, BuildCartViewData $buildCartViewData): CartResource
    {
        /** @var User $customer */
        $customer = $request->user();

        return new CartResource($buildCartViewData->execute($customer));
    }
}
