<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\OrderIndexRequest;
use App\Models\Order;
use App\Repositories\Order\AdministratorOrderRepository;
use App\Services\AdministratorOrderPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly AdministratorOrderRepository $orderRepository,
        private readonly AdministratorOrderPresenter $presenter,
    ) {}

    /**
     * Display the administrator order directory.
     */
    public function index(OrderIndexRequest $request): Response
    {
        $filters = $request->validated();
        $statusFilter = $filters['status'] ?? 'active';
        $filters['status'] = $statusFilter;
        $directory = $this->orderRepository->directory($filters, $statusFilter);

        return Inertia::render('Administration/Orders/Index', $this->presenter->directory($directory, $filters, $statusFilter));
    }

    /**
     * Display complete processing details for an order.
     */
    public function show(Request $request, Order $order): Response
    {
        $order = $this->orderRepository->loadDetails($order);

        return Inertia::render('Administration/Orders/Show', $this->presenter->detail($order, $request->user()));
    }
}
