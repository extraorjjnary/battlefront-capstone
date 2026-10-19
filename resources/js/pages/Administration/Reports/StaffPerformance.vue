<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StaffPerformanceController from '@/actions/App/Http/Controllers/Administration/StaffPerformanceController';
import { formatCurrency } from '@/lib/currency';
import { formatCalendarDate } from '@/lib/dateTime';

const props = defineProps({
    filters: { type: Object, required: true },
    staff: { type: Array, required: true },
    unattributed_count: { type: Number, required: true },
    can_view_all: { type: Boolean, required: true },
    selected_handler_id: { type: Number, required: true },
    selected_handler_name: { type: String, required: true },
    transactions: { type: Object, required: true },
});
const from = ref(props.filters.from);
const to = ref(props.filters.to);
function applyFilters() {
    router.get(StaffPerformanceController.index(), {
        from: from.value || undefined,
        to: to.value || undefined,
        handler_id: props.can_view_all ? props.selected_handler_id : undefined,
    }, { preserveState: true });
}
function staffUrl(handlerId) {
    return StaffPerformanceController.index({ query: {
        handler_id: handlerId,
        from: from.value || undefined,
        to: to.value || undefined,
    } });
}
defineOptions({ layout: { breadcrumbs: [{ title: 'Staff sales', href: StaffPerformanceController.index() }] } });
</script>

<template>
    <Head title="Staff sales performance" />
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 lg:p-10">
        <div><p class="text-primary text-xs font-semibold tracking-widest uppercase">Sales accountability</p><h1 class="mt-2 text-2xl font-bold">{{ can_view_all ? 'Staff sales performance' : 'My sales' }}</h1></div>
        <form class="border-border bg-card flex flex-wrap items-end gap-3 border p-4" @submit.prevent="applyFilters">
            <label class="text-sm">From<input v-model="from" type="date" class="border-input bg-background mt-1 block border p-2" /></label>
            <label class="text-sm">To<input v-model="to" type="date" class="border-input bg-background mt-1 block border p-2" /></label>
            <button type="submit" class="bg-primary text-primary-foreground px-4 py-2 text-sm font-semibold">Apply</button>
            <Link :href="StaffPerformanceController.index({ query: can_view_all ? { handler_id: selected_handler_id } : {} })" class="text-primary px-2 py-2 text-sm underline">All dates</Link>
        </form>
        <p class="text-muted-foreground text-sm">Sales count and gross product revenue use the sale completion date. Refunds use their recorded payment date and are attributed to the original sale handler. <template v-if="can_view_all">{{ unattributed_count }} legacy sales have no handler.</template></p>
        <div class="border-border bg-card overflow-x-auto border">
            <table class="w-full min-w-[36rem] text-left text-sm"><thead class="bg-secondary text-muted-foreground"><tr><th class="p-3">Staff</th><th class="p-3">Orders</th><th class="p-3">Gross item sales</th><th class="p-3">Refunds paid</th><th class="p-3">Period net</th></tr></thead>
                <tbody><tr v-for="person in staff" :key="person.id" class="border-border border-t"><td class="p-3 font-medium"><Link v-if="can_view_all" :href="staffUrl(person.id)" class="text-primary underline">{{ person.name }}</Link><template v-else>{{ person.name }}</template></td><td class="p-3">{{ person.orders_count }}</td><td class="p-3">{{ formatCurrency(person.gross_sales) }}</td><td class="p-3">{{ formatCurrency(person.refunds_paid) }}</td><td class="p-3 font-semibold">{{ formatCurrency(Number(person.gross_sales) - Number(person.refunds_paid)) }}</td></tr></tbody>
            </table>
            <p v-if="staff.length === 0" class="text-muted-foreground p-6 text-sm">No attributed sales in this period.</p>
        </div>
        <section class="space-y-3" aria-label="Attributed sales">
            <div><h2 class="text-lg font-semibold">{{ can_view_all ? `${selected_handler_name}'s sales` : 'My sales records' }}</h2><p class="text-muted-foreground text-sm">{{ transactions.total }} attributed sale records{{ filters.from || filters.to ? ' in the selected dates' : ' across all dates' }}. Historical records without a handler are not assigned to an account.</p></div>
            <div class="border-border bg-card overflow-x-auto border"><table class="w-full min-w-[32rem] text-left text-sm"><thead class="bg-secondary text-muted-foreground"><tr><th class="p-3">Sale date</th><th class="p-3">Order</th><th class="p-3">Gross item sales</th></tr></thead><tbody><tr v-for="sale in transactions.data" :key="sale.id" class="border-border border-t"><td class="p-3">{{ formatCalendarDate(sale.sale_date) }}</td><td class="p-3"><Link :href="sale.order_url" class="text-primary underline">{{ sale.order_reference }}</Link></td><td class="p-3">{{ formatCurrency(sale.gross_sales) }}</td></tr></tbody></table><p v-if="!transactions.data.length" class="text-muted-foreground p-5 text-sm">No recorded sales match these filters.</p></div>
            <nav v-if="transactions.last_page > 1" class="flex justify-between text-sm" aria-label="Sale pages"><Link v-if="transactions.prev_page_url" :href="transactions.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="transactions.next_page_url" :href="transactions.next_page_url" class="text-primary underline">Next</Link></nav>
        </section>
    </main>
</template>
