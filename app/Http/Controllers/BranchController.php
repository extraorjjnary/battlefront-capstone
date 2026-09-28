<?php

namespace App\Http\Controllers;

use App\Services\BranchDirectory;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    /**
     * Display the confirmed branch reference information.
     */
    public function index(BranchDirectory $branchDirectory): Response
    {
        return Inertia::render('Branches/Index', [
            'branches' => $branchDirectory->all(),
        ]);
    }
}
