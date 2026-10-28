<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\WishlistReportRequest;
use App\Services\Reporting\WishlistReportExportService;
use App\Services\Reporting\WishlistReportService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WishlistReportController extends Controller
{
    public function index(WishlistReportRequest $request, WishlistReportService $reports): Response
    {
        return Inertia::render('Administration/Reports/Wishlist', $reports->generate($request->filters()));
    }

    public function csv(WishlistReportRequest $request, WishlistReportService $reports, WishlistReportExportService $exports): StreamedResponse
    {
        $this->authorize('reports.export');

        return $exports->csv($reports, $request->filters());
    }
}
