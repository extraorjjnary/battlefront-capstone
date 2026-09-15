<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderPaymentProofController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Order $order): StreamedResponse
    {
        $path = $order->payment_proof_path;

        abort_unless(
            $order->payment_method->requiresPaymentProof()
                && is_string($path)
                && Str::startsWith($path, 'payment-proofs/')
                && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, headers: [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
