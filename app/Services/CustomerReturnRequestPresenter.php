<?php

namespace App\Services;

use App\Models\Order;
use App\Support\OperationalRequestReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class CustomerReturnRequestPresenter
{
    /** @return list<array<string, mixed>> */
    public function forOrder(Order $order): array
    {
        $verified = DB::table('customer_refund_payments as payments')
            ->join('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
            ->where('reviews.decision', 'verified')
            ->whereRaw('reviews.id = (select max(latest.id) from customer_refund_payment_reviews as latest where latest.customer_refund_payment_id = payments.id)')
            ->select('payments.customer_refund_id')->selectRaw('SUM(payments.amount) as verified_amount')
            ->groupBy('payments.customer_refund_id');

        return array_values(DB::table('customer_return_requests as requests')
            ->join('order_items', 'order_items.id', '=', 'requests.order_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('customer_returns as returns', 'returns.id', '=', 'requests.customer_return_id')
            ->leftJoin('customer_refunds as refunds', 'refunds.customer_return_id', '=', 'returns.id')
            ->leftJoinSub($verified, 'verified', 'verified.customer_refund_id', '=', 'refunds.id')
            ->where('requests.order_id', $order->id)->where('requests.user_id', $order->user_id)
            ->select('requests.*', 'order_items.product_id', 'returns.status as return_status', 'returns.created_at as received_at',
                'refunds.approved_amount', 'verified.verified_amount')
            ->selectRaw('COALESCE(order_items.product_name_at_time, products.name) as product_name')
            ->orderByDesc('requests.id')->get()
            ->map(fn (stdClass $row): array => $this->present($row, $order))->all());
    }

    /** @return array<string, mixed> */
    private function present(stdClass $row, Order $order): array
    {
        $refund = null;
        $status = 'submitted';
        $feedback = null;
        if ($row->withdrawn_at !== null) {
            $status = 'withdrawn';
        } elseif ($row->customer_return_id !== null) {
            $status = match ($row->return_status) {
                'accepted' => 'approved',
                'rejected', 'rejected_pending_handover' => 'rejected',
                default => 'under_review',
            };
            if ($status === 'approved') {
                $feedback = 'Your return has been approved.';
                $approved = self::numericAmount((string) ($row->approved_amount ?? '0.00'));
                if (bccomp($approved, '0.00', 2) > 0) {
                    $approved = bcadd($approved, '0.00', 2);
                    $verified = bcadd(self::numericAmount((string) ($row->verified_amount ?? '0.00')), '0.00', 2);
                    $complete = bccomp($verified, $approved, 2) >= 0;
                    $status = $complete ? 'refunded' : 'refund_processing';
                    $refund = ['approved_amount' => $approved, 'verified_paid_amount' => $verified,
                        'remaining_amount' => $complete ? '0.00' : bcsub($approved, $verified, 2)];
                }
            } elseif ($status === 'rejected') {
                $feedback = $row->return_status === 'rejected_pending_handover'
                    ? 'Your return was not approved. Contact Battlefront about collecting your item.'
                    : 'Your return was not approved. Your item has been handed back.';
            }
        }

        return [
            'id' => (int) $row->id, 'order_id' => $order->id, 'order_reference' => $order->reference,
            'order_item_id' => (int) $row->order_item_id,
            'product' => ['id' => (int) $row->product_id, 'name' => $row->product_name],
            'quantity' => (int) $row->quantity, 'reason_code' => $row->reason_code,
            'reason_label' => OperationalRequestReason::customerReturnOptions()[$row->reason_code], 'reason' => $row->reason,
            'submitted_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'withdrawn_at' => $row->withdrawn_at === null ? null : Carbon::parse($row->withdrawn_at)->toIso8601String(),
            'received_at' => $row->received_at === null ? null : Carbon::parse($row->received_at)->toIso8601String(),
            'status' => ['value' => $status, 'label' => match ($status) {
                'withdrawn' => 'Withdrawn', 'under_review' => 'Item received / Under review',
                'approved' => 'Approved', 'rejected' => 'Rejected', 'refund_processing' => 'Refund processing',
                'refunded' => 'Refund completed', default => 'Request submitted / Awaiting item receipt',
            }],
            'can_withdraw' => $status === 'submitted', 'feedback' => $feedback, 'refund' => $refund,
        ];
    }

    /** @return numeric-string */
    private static function numericAmount(string $amount): string
    {
        if (! is_numeric($amount) || preg_match('/^\d+(?:\.\d+)?$/D', $amount) !== 1) {
            throw new \UnexpectedValueException('A stored refund amount is not a valid decimal.');
        }

        return $amount;
    }
}
