<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StaffPerformanceController from '@/actions/App/Http/Controllers/Administration/StaffPerformanceController';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { formatCalendarDate } from '@/lib/dateTime';

const props = defineProps({
    filters: { type: Object, required: true },
    staff: { type: Array, required: true },
    unattributed_count: { type: Number, required: true },
    can_view_all: { type: Boolean, required: true },
    selected_handler_id: { type: Number, default: null },
    selected_handler_name: { type: String, default: null },
    transactions: { type: Object, default: null },
});
const from = ref(props.filters.from);
const to = ref(props.filters.to);
function applyFilters() {
    router.get(
        StaffPerformanceController.index(),
        {
            from: from.value || '',
            to: to.value || '',
            handler_id: props.can_view_all
                ? (props.selected_handler_id ?? undefined)
                : undefined,
        },
        { preserveState: true },
    );
}
function handlerUrl(handlerId) {
    return StaffPerformanceController.index({
        query: {
            handler_id: handlerId ?? undefined,
            from: from.value || '',
            to: to.value || '',
        },
    });
}
defineOptions({
    layout: (props) => ({
        breadcrumbs: [
            {
                title: props.can_view_all ? 'Sales by handler' : 'My sales',
                href: StaffPerformanceController.index(),
            },
        ],
    }),
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head :title="can_view_all ? 'Sales by handler' : 'My sales'" />
        <PageHeader :title="can_view_all ? 'Sales by handler' : 'My sales'" />
        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyFilters"
        >
            <label class="max-w-full min-w-0 text-sm"
                >From<input
                    v-model="from"
                    type="date"
                    class="border-input bg-background mt-1 block border p-2"
            /></label>
            <label class="max-w-full min-w-0 text-sm"
                >To<input
                    v-model="to"
                    type="date"
                    class="border-input bg-background mt-1 block border p-2"
            /></label>
            <Button class="min-h-11" type="submit">Apply filters</Button>
            <Link
                :href="
                    StaffPerformanceController.index({
                        query: {
                            handler_id: can_view_all
                                ? (selected_handler_id ?? undefined)
                                : undefined,
                            from: '',
                            to: '',
                        },
                    })
                "
                class="text-primary px-2 py-2 text-sm underline"
                >All dates</Link
            >
        </form>
        <p class="text-muted-foreground text-sm">
            Sales use the completion date. Verified refunds use the payment date
            and the original order handler.
            <template v-if="can_view_all"
                >{{ unattributed_count }} older sales have no assigned order
                handler.</template
            >
        </p>
        <div class="border-border bg-card overflow-x-auto border">
            <table class="w-full min-w-[36rem] text-left text-sm">
                <thead class="bg-secondary text-muted-foreground">
                    <tr>
                        <th class="p-3">Order handler</th>
                        <th class="p-3">Orders</th>
                        <th class="p-3">Product sales</th>
                        <th class="p-3">Verified refunds</th>
                        <th class="p-3">After verified refunds</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="person in staff"
                        :key="person.id"
                        class="border-border border-t"
                    >
                        <td class="p-3 font-medium">
                            <Link
                                v-if="can_view_all"
                                :href="handlerUrl(person.id)"
                                class="text-primary underline"
                                >{{ person.name }}</Link
                            ><template v-else>{{ person.name }}</template>
                        </td>
                        <td class="p-3">{{ person.orders_count }}</td>
                        <td class="p-3">
                            {{ formatCurrency(person.gross_sales) }}
                        </td>
                        <td class="p-3">
                            {{ formatCurrency(person.refunds_paid) }}
                        </td>
                        <td class="p-3 font-semibold">
                            {{ formatCurrency(person.net_sales) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-if="staff.length === 0"
                class="text-muted-foreground p-6 text-sm"
            >
                No handler sales in this period.
            </p>
        </div>
        <p
            v-if="can_view_all && !transactions"
            class="text-muted-foreground text-sm"
        >
            Select an order handler to view individual sales transactions.
        </p>
        <section
            v-if="transactions"
            class="space-y-3"
            aria-label="Attributed sales"
        >
            <Link
                v-if="can_view_all"
                :href="handlerUrl(null)"
                class="text-primary text-sm underline"
                >Back to team summary</Link
            >
            <div>
                <h2 class="text-lg font-semibold">
                    {{
                        can_view_all
                            ? `${selected_handler_name}'s sales`
                            : 'My sales records'
                    }}
                </h2>
                <p class="text-muted-foreground text-sm">
                    {{ transactions.total }} sales{{
                        filters.from || filters.to
                            ? ' in the selected dates'
                            : ' across all dates'
                    }}. Older sales without an assigned handler remain
                    unassigned.
                </p>
            </div>
            <div class="border-border bg-card overflow-x-auto border">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <thead class="bg-secondary text-muted-foreground">
                        <tr>
                            <th class="p-3">Sale date</th>
                            <th class="p-3">Order</th>
                            <th class="p-3">Product sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="sale in transactions.data"
                            :key="sale.id"
                            class="border-border border-t"
                        >
                            <td class="p-3">
                                {{ formatCalendarDate(sale.sale_date) }}
                            </td>
                            <td class="p-3">
                                <Link
                                    :href="sale.order_url"
                                    class="text-primary underline"
                                    >{{ sale.order_reference }}</Link
                                >
                            </td>
                            <td class="p-3">
                                {{ formatCurrency(sale.gross_sales) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="!transactions.data.length"
                    class="text-muted-foreground p-5 text-sm"
                >
                    No recorded sales match these filters.
                </p>
            </div>
            <nav
                v-if="transactions.last_page > 1"
                class="flex justify-between text-sm"
                aria-label="Sale pages"
            >
                <Link
                    v-if="transactions.prev_page_url"
                    :href="transactions.prev_page_url"
                    class="text-primary underline"
                    >Previous</Link
                ><span v-else></span
                ><Link
                    v-if="transactions.next_page_url"
                    :href="transactions.next_page_url"
                    class="text-primary underline"
                    >Next</Link
                >
            </nav>
        </section>
    </main>
</template>
