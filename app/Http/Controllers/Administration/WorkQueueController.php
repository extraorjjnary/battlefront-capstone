<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\AdministrationWorkQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkQueueController extends Controller
{
    public function index(Request $request, AdministrationWorkQueue $queue): Response
    {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', 'string', 'in:stock_adjustment,damage,supplier_return,customer_handover,refund,refund_reconciliation,customer_return,customer_request,customer_resolution,order,shipping,cancellation_review,cancellation_refund'],
            'responsible' => ['nullable', 'string', 'max:255'],
            'older_than' => ['nullable', 'integer', 'in:1,7,30'],
        ]);

        return Inertia::render('Administration/WorkQueue', [
            'entries' => $queue->paginate($request->user(), $filters),
            'filters' => array_intersect_key($filters, array_flip(['kind', 'responsible', 'older_than'])),
        ]);
    }
}
