<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\PcBuilder\PcBuildAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

class PcBuildAnalyticsController extends Controller
{
    public function index(PcBuildAnalyticsService $service): Response
    {
        return Inertia::render('Administration/PcBuildAnalytics/Index', $service->report());
    }
}
