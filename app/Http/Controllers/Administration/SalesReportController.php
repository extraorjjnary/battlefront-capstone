<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SalesReportRequest;
use App\Services\Reporting\RecommendationEngagementReport;
use App\Services\Reporting\SalesReportExportService;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response as DocumentResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function printReport(SalesReportRequest $request, SalesReportExportService $exports): DocumentResponse
    {
        return $exports->printReport($request->validated());
    }

    public function pdf(SalesReportRequest $request, SalesReportExportService $exports): DocumentResponse
    {
        return $exports->pdf($request->validated());
    }

    public function csv(SalesReportRequest $request, SalesReportExportService $exports): StreamedResponse
    {
        return $exports->csv($request->validated());
    }

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
