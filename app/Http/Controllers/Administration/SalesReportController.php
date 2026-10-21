<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SalesReportRequest;
use App\Services\Reporting\RecommendationEngagementReport;
use App\Services\Reporting\RefundPeriodReport;
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
        return $exports->printReport($request->effectiveFilters(), $request->user()->can('reports.profit'));
    }

    public function pdf(SalesReportRequest $request, SalesReportExportService $exports): DocumentResponse
    {
        return $exports->pdf($request->effectiveFilters(), $request->user()->can('reports.profit'));
    }

    public function csv(SalesReportRequest $request, SalesReportExportService $exports): StreamedResponse
    {
        return $exports->csv($request->effectiveFilters(), $request->user()->can('reports.profit'));
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
        RefundPeriodReport $refundPeriodReport,
    ): Response {
        $report = $salesReportService->generate($request->effectiveFilters(), $request->user()->can('reports.profit'));
        $filters = $report['filters'];
        $engagement = $recommendationEngagementReport->generate(
            CarbonImmutable::parse((string) $filters['from'], config('battlefront.timezone')),
            CarbonImmutable::parse((string) $filters['to'], config('battlefront.timezone')),
        );
        $request->rememberFilters($filters);

        return Inertia::render('Administration/Reports/Sales', [
            ...$report,
            'recommendation_engagement' => $engagement,
            'refund_measures' => $refundPeriodReport->generate($filters, $report['kpis']['total_revenue']),
        ]);
    }
}
