<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Reporting\OperationalExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OperationalExportController extends Controller
{
    public function __invoke(Request $request, string $feature, string $format, OperationalExportService $exports): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', 'in:mine,all,to_review'],
            'case' => ['nullable', 'integer', 'min:1'],
            'request_id' => ['nullable', 'integer', 'min:1'],
            'payment_id' => ['nullable', 'integer', 'min:1'],
            'overdue' => ['nullable', 'boolean'],
        ]);
        abort_unless($request->user()->can(in_array($feature, ['customer-returns', 'customer-requests'], true) ? 'orders.view' : 'inventory.view'), 403);

        return $exports->download($request->user(), $feature, $format, $filters);
    }
}
