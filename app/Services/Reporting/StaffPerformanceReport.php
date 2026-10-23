<?php

namespace App\Services\Reporting;

use App\Repositories\Reporting\SalesReportRepository;
use App\Repositories\Reporting\StaffPerformanceRepository;
use BcMath\Number;
use Carbon\CarbonImmutable;

class StaffPerformanceReport
{
    public function __construct(
        private readonly StaffPerformanceRepository $reports,
        private readonly SalesReportRepository $sales,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function page(array $filters, bool $canViewAll, ?int $handlerId, int $actorId, bool $datesSupplied): array
    {
        $handlers = $this->reports->handlers($canViewAll, $actorId);
        $selectedHandler = $handlerId === null ? null : (clone $handlers)->where('users.id', $handlerId)->first(['users.id', 'users.name']);
        abort_if($handlerId !== null && $selectedHandler === null, 404);

        $useCurrentMonth = $canViewAll && ! isset($filters['handler_id'])
            && ! $datesSupplied;
        $timezone = config('battlefront.timezone');
        $from = $filters['from'] ?? ($useCurrentMonth ? CarbonImmutable::today($timezone)->startOfMonth()->toDateString() : null);
        $to = $filters['to'] ?? ($useCurrentMonth ? CarbonImmutable::today($timezone)->toDateString() : null);

        $recordedSales = $this->sales->attributedSales($from, $to);

        $sales = $this->reports->sales($handlers, $recordedSales);

        $refundStart = $from === null ? null : CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->toDateTimeString();
        $refundEnd = $to === null ? null : CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()->toDateTimeString();
        $refunds = $this->reports->refunds($refundStart, $refundEnd);

        $transactions = $this->reports->transactions($recordedSales, $handlerId);
        $orders = $this->reports->orders($transactions);
        $transactions?->through(fn (\stdClass $sale): array => [
            'id' => (int) $sale->id,
            'sale_date' => $sale->sale_date,
            'order_id' => (int) $sale->order_id,
            'order_reference' => $orders->get($sale->order_id)->reference,
            'gross_sales' => (string) $sale->product_revenue,
            'order_url' => route('administration.orders.show', $sale->order_id),
        ]);

        return [
            'filters' => ['from' => $from, 'to' => $to],
            'can_view_all' => $canViewAll,
            'selected_handler_id' => $handlerId,
            'selected_handler_name' => $selectedHandler?->name,
            'transactions' => $transactions,
            'staff' => $sales->map(fn (object $sale): array => [
                'id' => $sale->id,
                'name' => $sale->name,
                'orders_count' => (int) $sale->orders_count,
                'gross_sales' => (string) $sale->gross_sales,
                'refunds_paid' => (new Number(self::numericAmount($refunds[$sale->id] ?? '0.00')))->add('0.00', 2)->value,
                'net_sales' => (new Number(self::numericAmount($sale->gross_sales)))->sub(self::numericAmount($refunds[$sale->id] ?? '0.00'), 2)->value,
            ]),
            'unattributed_count' => $canViewAll ? $this->reports->unattributedCount($from, $to) : 0,
        ];
    }

    /**
     * @return numeric-string
     */
    private static function numericAmount(mixed $amount): string
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new \UnexpectedValueException('A stored monetary amount is not a decimal string.');
        }

        $decimal = (string) $amount;

        if (! self::isDecimalAmount($decimal)) {
            throw new \UnexpectedValueException('A stored monetary amount is not a valid decimal.');
        }

        return $decimal;
    }

    /**
     * @phpstan-assert-if-true numeric-string $amount
     */
    private static function isDecimalAmount(string $amount): bool
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/D', $amount) === 1;
    }
}
