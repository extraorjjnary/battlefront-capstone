<?php

namespace App\Http\Controllers;

use App\Services\CustomerRefundProof;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerRefundProofController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, int $order, int $payment, int $evidence, CustomerRefundProof $proof): StreamedResponse
    {
        return $proof->show($request->user(), $order, $payment, $evidence);
    }
}
