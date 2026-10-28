<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuildEvent;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class PcBuildAnalyticsService
{
    /** @return array<string, mixed> */
    public function report(): array
    {
        $from = now()->subDays(30);
        $events = PcBuildEvent::query()->where('occurred_at', '>=', $from)
            ->whereIn('event_type', ['saved', 'updated', 'duplicated', 'added_to_cart'])->orderBy('occurred_at')->get();
        $savedFingerprints = $events->whereIn('event_type', ['saved', 'updated', 'duplicated'])->pluck('configuration_fingerprint')->filter()->unique();
        $cartFingerprints = $events->where('event_type', 'added_to_cart')->pluck('configuration_fingerprint')->filter()->unique();
        $componentCounts = [];
        $combinationCounts = [];
        $warningCount = 0;
        foreach ($events as $event) {
            $components = $event->context['components'] ?? [];
            foreach ($components as $component) {
                $id = $component['product_id'];
                $componentCounts[$id] = ($componentCounts[$id] ?? 0) + 1;
            }
            if (in_array($event->event_type, ['saved', 'updated', 'duplicated'], true)) {
                $byType = collect($components)->keyBy('component_type');
                $cpuId = $byType->get('processor')['product_id'] ?? null;
                $gpuId = $byType->get('graphics_card')['product_id'] ?? null;
                if ($cpuId !== null && $gpuId !== null) {
                    $key = $cpuId.':'.$gpuId;
                    $combinationCounts[$key] = ($combinationCounts[$key] ?? 0) + 1;
                }
            }
            $warningCount += (int) ($event->context['warning_count'] ?? 0);
        }

        $combinationProductIds = [];
        foreach (array_keys($combinationCounts) as $key) {
            $combinationProductIds = [...$combinationProductIds, ...array_map('intval', explode(':', $key))];
        }
        $productIds = array_values(array_unique([...array_keys($componentCounts), ...$combinationProductIds]));
        $productNames = Product::withTrashed()->whereKey($productIds)->pluck('name', 'id');
        $popularComponents = collect($componentCounts)->map(fn (int $count, int|string $id): array => [
            'product_name' => $productNames->get($id, 'Removed catalog product'),
            'selections' => $count,
        ])->sortByDesc('selections')->take(10)->values();
        $popularCombinations = collect($combinationCounts)->map(function (int $count, string $key) use ($productNames): array {
            [$cpuId, $gpuId] = array_map('intval', explode(':', $key));

            return [
                'processor' => $productNames->get($cpuId, 'Removed processor'),
                'graphics_card' => $productNames->get($gpuId, 'Removed graphics card'),
                'saved_builds' => $count,
            ];
        })->sortByDesc('saved_builds')->take(10)->values();
        $orders = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.pc_build_group_uuid')
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.created_at', '>=', $from)
            ->selectRaw('order_items.pc_build_name_at_time AS name, COUNT(DISTINCT orders.id) AS orders_count, COUNT(DISTINCT order_items.pc_build_group_uuid) AS configurations_count, SUM(order_items.price_at_time * order_items.quantity) AS gross_revenue')
            ->groupBy('order_items.pc_build_name_at_time')
            ->orderByDesc('orders_count')
            ->get();

        $savedCount = $savedFingerprints->count();
        $addedCount = $cartFingerprints->count();

        return [
            'period_days' => 30,
            'summary' => [
                'saved_builds' => $savedCount,
                'added_to_cart' => $addedCount,
                'conversion_percent' => $savedCount === 0 ? 0 : round(($addedCount / $savedCount) * 100, 1),
                'abandoned_builds' => $savedFingerprints->diff($cartFingerprints)->count(),
                'gross_revenue' => (string) $orders->sum('gross_revenue'),
                'ordered_configurations' => (int) $orders->sum('configurations_count'),
                'compatibility_warnings' => $warningCount,
            ],
            'popular_components' => $popularComponents,
            'popular_combinations' => $popularCombinations,
            'ordered_builds' => $orders,
        ];
    }
}
