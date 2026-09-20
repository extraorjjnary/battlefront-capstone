<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SalesReportRequest;
use App\Services\Reporting\SalesReportService;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    /**
     * Display the administrator sales dashboard and reports.
     */
    public function index(SalesReportRequest $request, SalesReportService $salesReportService): Response
    {
        return Inertia::render(
            'Administration/Reports/Sales',
            $salesReportService->generate($request->validated()),
        );
    }
}
