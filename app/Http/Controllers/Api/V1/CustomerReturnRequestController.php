<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerReturnRequest;
use App\Http\Resources\Api\V1\CustomerReturnRequestResource;
use App\Repositories\Order\CustomerOrderRepository;
use App\Services\CustomerReturnRequestPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerReturnRequestController extends Controller
{
    public function __construct(
        private readonly CustomerOrderRepository $orders,
        private readonly CustomerReturnRequestPresenter $presenter,
    ) {}

    public function index(Request $request, int $order): AnonymousResourceCollection
    {
        return CustomerReturnRequestResource::collection($this->presenter->forOrder($this->orders->find($request->user(), $order)));
    }

    public function show(Request $request, int $order, int $returnRequest): CustomerReturnRequestResource
    {
        $requests = $this->presenter->forOrder($this->orders->find($request->user(), $order));
        $record = collect($requests)->firstWhere('id', $returnRequest);
        abort_if($record === null, 404);

        return new CustomerReturnRequestResource($record);
    }

    public function store(StoreCustomerReturnRequest $request, int $order, HandleCustomerReturnRequest $returns): JsonResponse
    {
        $record = $returns->submit($request->user(), $order, $request->returnRequestData());

        return $this->show($request, $order, $record->id)->response()->setStatusCode(201);
    }

    public function withdraw(Request $request, int $order, int $returnRequest, HandleCustomerReturnRequest $returns): CustomerReturnRequestResource
    {
        $returns->withdraw($request->user(), $order, $returnRequest);

        return $this->show($request, $order, $returnRequest);
    }
}
