<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SalesReportRequest;
use App\Services\Reporting\RecommendationEngagementReport;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    /**
     * Display the administrator sales dashboard and reports.
     */
    public function index(
        SalesReportRequest $request,
        SalesReportService $salesReportService,
        RecommendationEngagementReport $recommendationEngagementReport,
    ): Response {
        $report = $salesReportService->generate($request->validated());
        $filters = $report['filters'];

        return Inertia::render('Administration/Reports/Sales', [
            ...$report,
            'recommendation_engagement' => $recommendationEngagementReport->generate(
                CarbonImmutable::parse((string) $filters['from']),
                CarbonImmutable::parse((string) $filters['to']),
            ),
        ]);
    }
}
