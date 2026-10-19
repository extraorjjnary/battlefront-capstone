<script setup>
import { Head, Link } from '@inertiajs/vue3';
import StockReconciliationController from '@/actions/App/Http/Controllers/Administration/StockReconciliationController';

defineProps({
    inventories: { type: Object, required: true },
    selected_product_id: { type: Number, default: null },
    movements: { type: Object, default: null },
});

defineOptions({
    layout: { breadcrumbs: [{ title: 'Stock reconciliation', href: StockReconciliationController.index() }] },
});
</script>

<template>
    <Head title="Stock reconciliation" />
    <main class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-6 lg:p-10">
        <header class="border-border bg-card border p-6">
            <p class="text-primary text-xs font-semibold tracking-widest uppercase">Inventory control</p>
            <h1 class="mt-2 text-2xl font-bold">Stock reconciliation</h1>
            <p class="text-muted-foreground mt-2 text-sm">Compare current stock with recorded movement balances and open quarantine cases. Discrepancies require investigation; this view never changes stock.</p>
        </header>
        <div class="border-border bg-card overflow-x-auto border">
            <table class="w-full min-w-220 text-left text-sm">
                <thead class="bg-secondary text-xs uppercase"><tr><th class="p-3">Product</th><th class="p-3">Current sellable</th><th class="p-3">Movement balance</th><th class="p-3">Current held</th><th class="p-3">Movement held</th><th class="p-3">Open cases</th><th class="p-3">Result</th></tr></thead>
                <tbody>
                    <tr v-for="item in inventories.data" :key="item.product_id" class="border-border border-t">
                        <td class="p-3"><Link :href="StockReconciliationController.index({ query: { product_id: item.product_id, page: inventories.current_page } })" class="text-primary font-semibold underline">{{ item.name }}</Link><br><span class="text-muted-foreground text-xs">{{ item.product_code }}</span></td>
                        <td class="p-3 tabular-nums">{{ item.sellable }}</td><td class="p-3 tabular-nums">{{ item.ledger_sellable ?? 'Unknown' }}</td>
                        <td class="p-3 tabular-nums">{{ item.quarantined }}</td><td class="p-3 tabular-nums">{{ item.ledger_quarantined ?? 'Unknown' }}</td><td class="p-3 tabular-nums">{{ item.case_expected }}</td>
                        <td class="p-3" :class="item.status === 'discrepancy' ? 'text-destructive font-semibold' : 'text-muted-foreground'">{{ item.status === 'legacy_unknown' ? 'No movement baseline' : item.status }}</td>
                    </tr>
                    <tr v-if="inventories.data.length === 0"><td colspan="7" class="p-6">No inventory records.</td></tr>
                </tbody>
            </table>
        </div>
        <nav v-if="inventories.last_page > 1" class="flex justify-between text-sm" aria-label="Inventory reconciliation pages">
            <Link v-if="inventories.prev_page_url" :href="inventories.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span>
            <Link v-if="inventories.next_page_url" :href="inventories.next_page_url" class="text-primary underline">Next</Link>
        </nav>
        <section v-if="movements" class="border-border bg-card border p-5">
            <h2 class="text-xl font-semibold">Movement history for product #{{ selected_product_id }}</h2>
            <p class="text-muted-foreground mt-1 text-sm">Each row records before, change, and after quantities. Older stock without a movement baseline is shown as unknown.</p>
            <div class="mt-4 space-y-2">
                <article v-for="movement in movements.data" :key="movement.id" class="border-border border p-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-2"><strong>{{ movement.kind.replaceAll('_', ' ') }} #{{ movement.id }}</strong><time>{{ movement.created_at }}</time></div>
                    <p class="text-muted-foreground mt-1">{{ movement.actor_name }} · Sellable {{ movement.sellable_before }} {{ movement.sellable_delta >= 0 ? '+' : '' }}{{ movement.sellable_delta }} = {{ movement.sellable_after }} · Held {{ movement.quarantined_before }} {{ movement.quarantined_delta >= 0 ? '+' : '' }}{{ movement.quarantined_delta }} = {{ movement.quarantined_after }}</p>
                    <Link v-if="movement.href" :href="movement.href" class="text-primary mt-1 inline-block underline">{{ movement.reference_type }} #{{ movement.reference_id }}</Link>
                    <p v-else-if="movement.reference_type" class="text-muted-foreground mt-1">{{ movement.reference_type }} #{{ movement.reference_id }}</p>
                </article>
                <p v-if="movements.data.length === 0" class="text-muted-foreground text-sm">No recorded movements for this product.</p>
            </div>
            <nav v-if="movements.last_page > 1" class="mt-4 flex justify-between text-sm" aria-label="Movement history pages">
                <Link v-if="movements.prev_page_url" :href="movements.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span>
                <Link v-if="movements.next_page_url" :href="movements.next_page_url" class="text-primary underline">Next</Link>
            </nav>
        </section>
    </main>
</template>
