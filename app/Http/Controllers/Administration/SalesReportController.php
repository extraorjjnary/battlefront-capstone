<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SalesReportRequest;
use App\Services\Reporting\RecommendationEngagementReport;
use App\Services\Reporting\SalesReportExportService;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as DocumentResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function printReport(SalesReportRequest $request, SalesReportExportService $exports): DocumentResponse
    {
        return $exports->printReport($request->effectiveFilters());
    }

    public function pdf(SalesReportRequest $request, SalesReportExportService $exports): DocumentResponse
    {
        return $exports->pdf($request->effectiveFilters());
    }

    public function csv(SalesReportRequest $request, SalesReportExportService $exports): StreamedResponse
    {
        return $exports->csv($request->effectiveFilters());
    }

    public function reset(SalesReportRequest $request): RedirectResponse
    {
        $request->clearSavedFilters();

        return to_route('administration.reports.sales');
    }

    /**
     * Display the administrator sales dashboard and reports.
     */
    public function index(
        SalesReportRequest $request,
        SalesReportService $salesReportService,
        RecommendationEngagementReport $recommendationEngagementReport,
    ): Response {
        $report = $salesReportService->generate($request->effectiveFilters());
        $filters = $report['filters'];
        $engagement = $recommendationEngagementReport->generate(
            CarbonImmutable::parse((string) $filters['from']),
            CarbonImmutable::parse((string) $filters['to']),
        );
        $request->rememberFilters($filters);

        return Inertia::render('Administration/Reports/Sales', [
            ...$report,
            'recommendation_engagement' => $engagement,
        ]);
    }
}
