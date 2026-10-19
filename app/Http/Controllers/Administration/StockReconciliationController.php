<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Reporting\StockReconciliationReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockReconciliationController extends Controller
{
    public function index(Request $request, StockReconciliationReport $report): Response
    {
        $filters = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
            'movement_page' => ['nullable', 'integer', 'min:1'],
        ]);

        return Inertia::render('Administration/Reports/StockReconciliation', $report->page(
            isset($filters['product_id']) ? (int) $filters['product_id'] : null,
            $request->user(),
        ));
    }
}
