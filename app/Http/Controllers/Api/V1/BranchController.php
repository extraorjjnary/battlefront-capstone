<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BranchResource;
use App\Services\BranchDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController extends Controller
{
    public function index(BranchDirectory $branchDirectory): AnonymousResourceCollection
    {
        return BranchResource::collection($branchDirectory->all());
    }
}
