<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ShipmentIndexRequest;
use App\Services\Reporting\ShipmentExportService;
use Symfony\Component\HttpFoundation\Response;

class OrderShipmentExportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ShipmentIndexRequest $request, string $format, ShipmentExportService $exports): Response
    {
        return $exports->download($format, ShipmentIndexRequest::normalizeFilters($request->validated()));
    }
}
