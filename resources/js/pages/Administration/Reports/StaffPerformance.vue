<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StaffPerformanceController from '@/actions/App/Http/Controllers/Administration/StaffPerformanceController';
import { formatCurrency } from '@/lib/currency';

const props = defineProps({
    filters: { type: Object, required: true },
    staff: { type: Array, required: true },
    unattributed_count: { type: Number, required: true },
    can_view_all: { type: Boolean, required: true },
});
const from = ref(props.filters.from);
const to = ref(props.filters.to);
function applyFilters() {
    router.get(StaffPerformanceController.index(), { from: from.value, to: to.value }, { preserveState: true });
}
defineOptions({ layout: { breadcrumbs: [{ title: 'Staff sales', href: StaffPerformanceController.index() }] } });
</script>

<template>
    <Head title="Staff sales performance" />
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 lg:p-10">
        <div><p class="text-primary text-xs font-semibold tracking-widest uppercase">Sales accountability</p><h1 class="mt-2 text-2xl font-bold">{{ can_view_all ? 'Staff sales performance' : 'My sales performance' }}</h1></div>
        <form class="border-border bg-card flex flex-wrap items-end gap-3 border p-4" @submit.prevent="applyFilters">
            <label class="text-sm">From<input v-model="from" type="date" class="border-input bg-background mt-1 block border p-2" /></label>
            <label class="text-sm">To<input v-model="to" type="date" class="border-input bg-background mt-1 block border p-2" /></label>
            <button type="submit" class="bg-primary text-primary-foreground px-4 py-2 text-sm font-semibold">Apply</button>
        </form>
        <p class="text-muted-foreground text-sm">Sales count and gross product revenue use the sale completion date. Refunds use their recorded payment date and are attributed to the original sale handler. <template v-if="can_view_all">{{ unattributed_count }} legacy sales have no handler.</template></p>
        <div class="border-border bg-card overflow-x-auto border">
            <table class="w-full min-w-[36rem] text-left text-sm"><thead class="bg-secondary text-muted-foreground"><tr><th class="p-3">Staff</th><th class="p-3">Orders</th><th class="p-3">Gross sales</th><th class="p-3">Refunds paid</th><th class="p-3">Net</th></tr></thead>
                <tbody><tr v-for="person in staff" :key="person.id" class="border-border border-t"><td class="p-3 font-medium">{{ person.name }}</td><td class="p-3">{{ person.orders_count }}</td><td class="p-3">{{ formatCurrency(person.gross_sales) }}</td><td class="p-3">{{ formatCurrency(person.refunds_paid) }}</td><td class="p-3 font-semibold">{{ formatCurrency(Number(person.gross_sales) - Number(person.refunds_paid)) }}</td></tr></tbody>
            </table>
            <p v-if="staff.length === 0" class="text-muted-foreground p-6 text-sm">No attributed sales in this period.</p>
        </div>
    </main>
</template>
