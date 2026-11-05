<?php

namespace App\Repositories\Reporting;

use App\Services\DemonstrationProvisioning;
use App\Services\HostedDemoHistory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class MonthlySalesRepository
{
    /**
     * Return recorded groups only. Coverage and zero observations belong to preparation.
     *
     * @param  list<array{start: string, end_exclusive: string}>  $months
     * @return list<array{start: string, quantity_sold: int}>
     */
    public function aggregateProduct(int $productId, array $months): array
    {
        return $this->aggregateProducts([$productId], $months)[$productId] ?? [];
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<array{start: string, end_exclusive: string}>  $months
     * @return array<int, list<array{start: string, quantity_sold: int}>>
     */
    public function aggregateProducts(array $productIds, array $months): array
    {
        if ($productIds === [] || $months === [] || array_filter($productIds, fn (int $id): bool => $id < 1) !== []) {
            throw new InvalidArgumentException('Monthly aggregation requires a product and month intervals.');
        }
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Monthly sales aggregation supports MySQL and SQLite.');
        }

        $cases = [];
        $bindings = [];
        foreach ($months as $month) {
            $cases[] = 'WHEN sales.sale_date >= ? AND sales.sale_date < ? THEN ?';
            array_push($bindings, $month['start'], $month['end_exclusive'], $month['start']);
        }
        $demo = app(DemonstrationProvisioning::class);
        $demoProductIds = $demo->enabled()
            ? DB::table('products')->whereIn('product_code', array_keys($demo->coverage()))->pluck('id')->all() : [];
        $rows = DB::table('sales')
            ->join('orders', 'orders.id', '=', 'sales.order_id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->when($demo->enabled(), fn ($query) => $query->where(function ($query) use ($demoProductIds): void {
                $query->where(function ($query) use ($demoProductIds): void {
                    $query->whereIn('order_items.product_id', $demoProductIds)
                        ->where('orders.development_fixture_key', 'like', HostedDemoHistory::FIXTURE_PREFIX.'%')
                        ->whereIn('orders.id', DB::table('audit_logs')->where('action', 'demo.record.created')
                            ->where('subject_type', 'order')->where('changes->profile', DemonstrationProvisioning::PROFILE)->select('subject_id'));
                })->orWhere(function ($query) use ($demoProductIds): void {
                    $query->whereNotIn('order_items.product_id', $demoProductIds)
                        ->where(fn ($query) => $query->whereNull('orders.development_fixture_key')
                            ->orWhere('orders.development_fixture_key', 'not like', HostedDemoHistory::FIXTURE_PREFIX.'%'));
                });
            }))
            ->whereIn('order_items.product_id', $productIds)
            ->where('sales.sale_date', '>=', $months[0]['start'])
            ->where('sales.sale_date', '<', $months[array_key_last($months)]['end_exclusive'])
            ->select('order_items.product_id')
            ->selectRaw('CASE '.implode(' ', $cases).' END AS month_start', $bindings)
            ->selectRaw('SUM(order_items.quantity) AS quantity_sold')
            ->groupBy('order_items.product_id', 'month_start')
            ->orderBy('order_items.product_id')->orderBy('month_start')->get();

        $results = [];
        foreach ($rows as $row) {
            $quantity = $row->quantity_sold;
            if ((! is_int($quantity) && ! is_string($quantity))
                || ($integer = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])) === false) {
                throw new RuntimeException('Monthly quantities must be nonnegative integers within the supported range.');
            }
            $results[(int) $row->product_id][] = ['start' => (string) $row->month_start, 'quantity_sold' => $integer];
        }

        return $results;
    }
}
