<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\AdministrationWorkQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkQueueController extends Controller
{
    public function index(Request $request, AdministrationWorkQueue $queue): Response
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('Administration/WorkQueue', [
            'entries' => $queue->paginate($request->user()),
        ]);
    }
}
