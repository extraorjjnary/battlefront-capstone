<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Reporting\StaffPerformanceReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffPerformanceController extends Controller
{
    public function index(Request $request, StaffPerformanceReport $reports): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'handler_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $canViewAll = in_array($request->user()->administrationRoleCode(), ['manager', 'owner'], true)
            && $request->user()->can('staff_reports.view');
        $handlerId = isset($filters['handler_id']) ? (int) $filters['handler_id'] : ($canViewAll ? null : $request->user()->id);
        abort_if(! $canViewAll && $handlerId !== $request->user()->id, 403);

        return Inertia::render('Administration/Reports/StaffPerformance', $reports->page($filters, $canViewAll, $handlerId, $request->user()->id, $request->has('from') || $request->has('to')));
    }
}
