<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerRefundProof
{
    public function show(User $customer, int $orderId, int $paymentId, int $evidenceId): StreamedResponse
    {
        abort_unless($customer->role === UserRole::Customer, 403);
        $file = DB::table('administration_evidence as evidence')
            ->join('customer_refund_payments as payments', 'payments.id', '=', 'evidence.subject_id')
            ->join('customer_refunds as refunds', 'refunds.id', '=', 'payments.customer_refund_id')
            ->join('customer_returns as returns', 'returns.id', '=', 'refunds.customer_return_id')
            ->join('orders', 'orders.id', '=', 'returns.order_id')
            ->where('orders.id', $orderId)->where('orders.user_id', $customer->id)->where('payments.id', $paymentId)
            ->where('evidence.id', $evidenceId)->where('evidence.subject_type', 'refund_payment')->where('evidence.customer_visible', true)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('customer_refund_payment_reviews as reviews')
                ->whereColumn('reviews.customer_refund_payment_id', 'payments.id')->where('reviews.decision', 'verified')->where('reviews.confirmation_method', 'approver')
                ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)'))
            ->first(['evidence.path', 'evidence.mime_type']);
        abort_if($file === null || ! in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true) || ! Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, 'refund-payment-proof', ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
