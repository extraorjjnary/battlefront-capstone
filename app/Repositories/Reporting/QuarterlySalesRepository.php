<?php

namespace App\Repositories\Reporting;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class QuarterlySalesRepository
{
    /**
     * @param  'product'|'category'  $dimension
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function existingIds(string $dimension, array $ids): array
    {
        return array_values(DB::table($this->table($dimension))->whereIn('id', $ids)->orderBy('id')
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * SQLite groups by price to keep money out of REAL arithmetic.
     *
     * @param  'product'|'category'  $dimension
     * @param  non-empty-list<array{start: string, end_exclusive: string}>  $quarters
     * @param  list<int>|null  $ids
     * @return list<array{entity_id: int, start: string, quantity_sold: int, item_revenue: numeric-string}>
     */
    public function aggregate(string $dimension, array $quarters, ?array $ids): array
    {
        $column = $this->table($dimension).'.id';
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Quarterly sales aggregation supports MySQL and SQLite.');
        }
        $cases = [];
        $bindings = [];
        foreach ($quarters as $quarter) {
            $cases[] = 'WHEN sales.sale_date >= ? AND sales.sale_date < ? THEN ?';
            array_push($bindings, $quarter['start'], $quarter['end_exclusive'], $quarter['start']);
        }
        $query = DB::table('sales')
            ->join('orders', 'orders.id', '=', 'sales.order_id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.sale_date', '>=', $quarters[0]['start'])
            ->where('sales.sale_date', '<', $quarters[array_key_last($quarters)]['end_exclusive'])
            ->selectRaw($column.' AS entity_id')
            ->selectRaw('CASE '.implode(' ', $cases).' END AS quarter_start', $bindings)
            ->selectRaw('SUM(order_items.quantity) AS quantity_sold')
            ->groupBy($column, 'quarter_start')
            ->orderBy($column)->orderBy('quarter_start');
        if ($ids !== null) {
            $query->whereIn($column, $ids);
        }
        if ($driver === 'sqlite') {
            $query->selectRaw('CAST(order_items.price_at_time AS TEXT) AS unit_price')
                ->groupBy('order_items.price_at_time')->orderBy('order_items.price_at_time');
        } else {
            $query->selectRaw('CAST(SUM(order_items.quantity * order_items.price_at_time) AS CHAR) AS item_revenue');
        }
        $results = [];
        foreach ($query->get() as $row) {
            $quantity = (int) $row->quantity_sold;
            $money = $driver === 'sqlite' ? $row->unit_price : $row->item_revenue;
            if (! is_string($money) || ! is_numeric($money)) {
                throw new RuntimeException('The database must return monetary values as numeric strings.');
            }
            $results[] = [
                'entity_id' => (int) $row->entity_id,
                'start' => (string) $row->quarter_start,
                'quantity_sold' => $quantity,
                'item_revenue' => $driver === 'sqlite'
                    ? bcmul($money, (string) $quantity, 2)
                    : bcadd($money, '0.00', 2),
            ];
        }

        return $results;
    }

    /** @return 'products'|'categories' */
    private function table(string $dimension): string
    {
        return match ($dimension) {
            'product' => 'products',
            'category' => 'categories',
            default => throw new InvalidArgumentException('Unknown quarterly sales dimension.'),
        };
    }
}
