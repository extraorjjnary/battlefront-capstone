<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    /**
     * Display the confirmed branch reference information.
     */
    public function index(): Response
    {
        $branches = Branch::query()
            ->get()
            ->sortBy([
                ['is_operational', 'desc'],
                ['city', 'asc'],
            ])
            ->values()
            ->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'city' => $branch->city,
                'contact_number' => $branch->contact_number,
                'latitude' => $branch->latitude !== null
                    ? round((float) $branch->latitude, 7)
                    : null,
                'longitude' => $branch->longitude !== null
                    ? round((float) $branch->longitude, 7)
                    : null,
                'email' => $branch->email,
                'operating_hours' => $branch->operating_hours,
                'is_operational' => $branch->is_operational,
            ]);

        return Inertia::render('Branches/Index', [
            'branches' => $branches,
        ]);
    }
}
