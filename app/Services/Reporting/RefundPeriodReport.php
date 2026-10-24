<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RefundPeriodReport
{
    /**
     * @param  array{from: string, to: string, product_id: ?int, category_id: ?int}  $filters
     * @param  numeric-string  $grossSales
     * @return array<string, mixed>
     */
    public function generate(array $filters, string $grossSales): array
    {
        $timezone = config('battlefront.timezone');
        $fromUtc = CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()->toDateTimeString();
        $endExclusiveUtc = CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc()->toDateTimeString();
        $payments = $this->paymentQuery($filters)
            ->where('payments.paid_at', '>=', $fromUtc)
            ->where('payments.paid_at', '<', $endExclusiveUtc)
            ->whereExists(fn (Builder $reviews): Builder => $reviews->selectRaw('1')
                ->from('customer_refund_payment_reviews as verified_reviews')
                ->whereColumn('verified_reviews.customer_refund_payment_id', 'payments.id')
                ->where('verified_reviews.decision', 'verified')
                ->where('verified_reviews.created_at', '<', $endExclusiveUtc));
        $reversals = $this->reversalQuery($filters)
            ->where('reviews.created_at', '>=', $fromUtc)
            ->where('reviews.created_at', '<', $endExclusiveUtc);
        $paid = (string) (clone $payments)->sum('payments.amount');

        $balances = $this->refundQuery($filters)
            ->where('refunds.created_at', '<', $endExclusiveUtc)
            ->select('refunds.id', 'refunds.customer_return_id', 'refunds.approved_amount')
            ->selectSub(DB::table('customer_refund_payments as paid')
                ->selectRaw('COALESCE(SUM(paid.amount), 0)')
                ->whereColumn('paid.customer_refund_id', 'refunds.id')
                ->where('paid.paid_at', '<', $endExclusiveUtc)
                ->whereExists(fn (Builder $reviews): Builder => $reviews->selectRaw('1')
                    ->from('customer_refund_payment_reviews as verified_reviews')
                    ->whereColumn('verified_reviews.customer_refund_payment_id', 'paid.id')
                    ->where('verified_reviews.decision', 'verified')
                    ->where('verified_reviews.created_at', '<', $endExclusiveUtc)), 'paid_at_cutoff');
        $outstanding = (string) DB::query()->fromSub($balances, 'balances')
            ->selectRaw('COALESCE(SUM(CASE WHEN approved_amount > paid_at_cutoff THEN approved_amount - paid_at_cutoff ELSE 0 END), 0) as amount')
            ->value('amount');

        return [
            'gross_item_sales' => $grossSales,
            'refund_payments' => bcadd($this->numericAmount($paid), '0', 2),
            'gross_less_refunds' => bcsub($grossSales, $this->numericAmount($paid), 2),
            'outstanding_at_cutoff' => bcadd($this->numericAmount($outstanding), '0', 2),
            'cutoff' => $filters['to'],
            'outstanding_refunds' => DB::query()->fromSub($balances, 'balances')
                ->select('id', 'customer_return_id', 'approved_amount', 'paid_at_cutoff')
                ->selectRaw('approved_amount - paid_at_cutoff as outstanding_amount')
                ->whereColumn('approved_amount', '>', 'paid_at_cutoff')
                ->orderBy('id')->paginate(10, ['*'], 'balance_page')->withQueryString(),
            'payments' => (clone $payments)
                ->select('payments.id', 'payments.customer_refund_id', 'refunds.customer_return_id', 'payments.amount', 'payments.paid_at', 'payments.external_reference')
                ->orderByDesc('payments.paid_at')->orderByDesc('payments.id')
                ->paginate(10, ['*'], 'refund_page')->withQueryString(),
            'reversals' => (clone $reversals)
                ->select('reviews.id', 'reviews.customer_refund_payment_id', 'reviews.reason', 'reviews.created_at', 'payments.amount', 'refunds.customer_return_id')
                ->orderByDesc('reviews.created_at')->orderByDesc('reviews.id')
                ->paginate(10, ['*'], 'refund_reversal_page')->withQueryString(),
        ];
    }

    /** @param array{product_id: ?int, category_id: ?int} $filters */
    private function refundQuery(array $filters): Builder
    {
        return DB::table('customer_refunds as refunds')
            ->whereNotNull('refunds.customer_return_id')
            ->when($filters['product_id'] !== null || $filters['category_id'] !== null,
                fn (Builder $query): Builder => $query->whereExists(fn (Builder $items): Builder => $items
                    ->selectRaw('1')
                    ->from('customer_return_items as returned_items')
                    ->join('order_items', 'order_items.id', '=', 'returned_items.order_item_id')
                    ->join('products', 'products.id', '=', 'order_items.product_id')
                    ->whereColumn('returned_items.customer_return_id', 'refunds.customer_return_id')
                    ->when($filters['product_id'], fn (Builder $matching, int $id): Builder => $matching->where('products.id', $id))
                    ->when($filters['category_id'], fn (Builder $matching, int $id): Builder => $matching->where('products.category_id', $id))));
    }

    /** @param array{product_id: ?int, category_id: ?int} $filters */
    private function paymentQuery(array $filters): Builder
    {
        return $this->refundQuery($filters)
            ->join('customer_refund_payments as payments', 'payments.customer_refund_id', '=', 'refunds.id');
    }

    /** @param array{product_id: ?int, category_id: ?int} $filters */
    private function reversalQuery(array $filters): Builder
    {
        return $this->paymentQuery($filters)
            ->join('customer_refund_payment_reviews as reviews', 'reviews.customer_refund_payment_id', '=', 'payments.id')
            ->where('reviews.decision', 'voided');
    }

    /** @return numeric-string */
    private function numericAmount(string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new \UnexpectedValueException('A report amount must be numeric.');
        }

        return $amount;
    }
}
