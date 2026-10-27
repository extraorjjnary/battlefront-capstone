<?php

namespace App\Services\PcBuilder;

use App\Models\Product;

class ComponentReadinessService
{
    /** @return list<array<string, mixed>> */
    public function candidates(): array
    {
        return Product::query()->customerEligible()
            ->with(['category', 'inventory', 'componentProfile.revisions'])
            ->orderBy('name')->orderBy('id')->get()
            ->map(fn (Product $product): array => $this->present($product))->all();
    }

    /** @return array<string, mixed> */
    private function present(Product $product): array
    {
        $types = [
            'Processor' => 'processor', 'Motherboard' => 'motherboard', 'RAM' => 'memory',
            'Storage' => 'storage', 'Graphics Card' => 'graphics_card', 'Power Supply' => 'power_supply',
            'PC Case' => 'case', 'Monitor' => 'monitor',
        ];
        $profile = $product->componentProfile;
        $revision = $profile?->revisions->firstWhere('revision', $profile->current_revision);
        $type = $profile?->component_type ?? ($types[$product->category?->name] ?? null);
        $excluded = preg_match('/laptop ram|sodimm|flashdrive|flash drive|enclosure|external case|card reader/i', $product->name) === 1;
        $status = 'needs_identity';
        $reason = 'Confirm exact model, hardware revision, included accessories and manufacturer evidence.';
        if ($profile !== null) {
            $status = $profile->review_status === 'approved' && $revision?->review_status === 'approved' ? 'reviewed' : 'needs_review';
            $reason = $status === 'reviewed' ? 'Specifications reviewed; complete-build compatibility must still be checked.' : 'Review the draft and complete missing technical evidence.';
        } elseif ($excluded || $type === null) {
            $status = 'not_core_component';
            $reason = 'Not automatically eligible for a core desktop slot. Manually review peripherals and ambiguous products.';
            $type = null;
        }

        return [
            'product_id' => $product->id, 'name' => $product->name, 'product_code' => $product->product_code,
            'category' => $product->category?->name, 'suggested_type' => $type,
            'status' => $status, 'reason' => $reason, 'stock_quantity' => $product->inventory?->quantity ?? 0,
            'profile_id' => $profile?->id,
        ];
    }
}
