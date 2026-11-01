<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import OrderShipmentExportController from '@/actions/App/Http/Controllers/Administration/OrderShipmentExportController';
import PageHeader from '@/components/PageHeader.vue';
import OrdersDirectoryTabs from '@/components/OrdersDirectoryTabs.vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { formatCurrency } from '@/lib/currency';
import { formatDateTime } from '@/lib/dateTime';

const props = defineProps({
    shipments: { type: Object, required: true },
    filters: { type: Object, required: true },
    status_options: { type: Array, required: true },
    can_export: { type: Boolean, default: false },
    document_limit: { type: Number, required: true },
});
const page = usePage();
const shipmentStatus = ref(props.filters.shipment_status);
const reconciliation = ref(props.filters.reconciliation);
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const { search, isSearching, cancelPendingSearch } = useDebouncedSearch({
    initialSearch: props.filters.q,
    currentSearch: () => props.filters.q,
    route: OrderController.index,
    query: selectedFilters,
});
const formats = [
    { value: 'csv', label: 'Export CSV' },
    { value: 'pdf', label: 'Download PDF' },
    { value: 'print', label: 'Print' },
];
function selectedFilters() {
    return {
        view: 'shipments',
        shipment_status: shipmentStatus.value,
        reconciliation: reconciliation.value,
        from: from.value || undefined,
        to: to.value || undefined,
    };
}
const dirty = computed(
    () =>
        shipmentStatus.value !== props.filters.shipment_status ||
        reconciliation.value !== props.filters.reconciliation ||
        from.value !== (props.filters.from ?? '') ||
        to.value !== (props.filters.to ?? '') ||
        search.value.trim() !== (props.filters.q ?? ''),
);
function applyFilters() {
    cancelPendingSearch();
    router.visit(
        OrderController.index({
            query: {
                ...selectedFilters(),
                q: search.value.trim() || undefined,
            },
        }),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}
function clearFilters() {
    cancelPendingSearch();
    router.visit(OrderController.index({ query: { view: 'shipments' } }), {
        preserveScroll: true,
        replace: true,
    });
}
function shipmentPage(options) {
    return OrderController.index({
        query: {
            ...props.filters,
            view: 'shipments',
            page: options.query.page,
        },
    });
}
function exportUrl(format) {
    return OrderShipmentExportController.url(format, { query: props.filters });
}
function documentAvailable(format) {
    return (
        props.shipments.total > 0 &&
        !dirty.value &&
        !isSearching.value &&
        (format === 'csv' || props.shipments.total <= props.document_limit)
    );
}
watch(
    () => props.filters,
    (filters) => {
        shipmentStatus.value = filters.shipment_status;
        reconciliation.value = filters.reconciliation;
        from.value = filters.from ?? '';
        to.value = filters.to ?? '';
        search.value = filters.q ?? '';
    },
);
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Orders', href: OrderController.index() },
            { title: 'Shipments' },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Shipment records" />
        <PageHeader title="Shipment records" />
        <OrdersDirectoryTabs active="shipments" />
        <p class="text-muted-foreground text-sm">
            Review packed parcels, LBC handoffs, and carrier receipts. Open an
            order to handle its shipment.
        </p>
        <section
            class="border-border bg-card space-y-4 border p-5"
            aria-label="Shipment search and filters"
        >
            <div class="grid gap-2">
                <Label for="shipment-search">Search shipments</Label>
                <Input
                    id="shipment-search"
                    v-model="search"
                    maxlength="255"
                    placeholder="Order, customer, recipient, tracking, or receipt reference"
                />
                <InputError :message="page.props.errors?.q" />
                <span class="sr-only" role="status">{{
                    isSearching ? 'Updating shipments...' : ''
                }}</span>
            </div>
            <form
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-2">
                    <Label for="shipment-status">Shipment status</Label>
                    <Select v-model="shipmentStatus">
                        <SelectTrigger id="shipment-status" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active"
                                >Active shipments</SelectItem
                            ><SelectItem value="all">All shipments</SelectItem>
                            <SelectItem
                                v-for="status in status_options"
                                :key="status.value"
                                :value="status.value"
                                >{{ status.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="page.props.errors?.shipment_status" />
                </div>
                <div class="grid gap-2">
                    <Label for="shipment-reconciliation"
                        >Carrier reconciliation</Label
                    >
                    <Select v-model="reconciliation">
                        <SelectTrigger
                            id="shipment-reconciliation"
                            class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all"
                                >All reconciliation states</SelectItem
                            ><SelectItem value="confirmed">Confirmed</SelectItem
                            ><SelectItem value="unconfirmed"
                                >Unconfirmed</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="page.props.errors?.reconciliation" />
                </div>
                <div class="grid gap-2">
                    <Label for="shipment-from">Handoff from</Label
                    ><Input
                        id="shipment-from"
                        v-model="from"
                        type="date"
                    /><InputError :message="page.props.errors?.from" />
                </div>
                <div class="grid gap-2">
                    <Label for="shipment-to">Handoff to</Label
                    ><Input
                        id="shipment-to"
                        v-model="to"
                        type="date"
                        :min="from || undefined"
                    /><InputError :message="page.props.errors?.to" />
                </div>
                <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-4">
                    <Button type="submit">Apply filters</Button
                    ><Button
                        type="button"
                        variant="outline"
                        @click="clearFilters"
                        >Clear filters</Button
                    >
                    <p class="text-muted-foreground self-center text-xs">
                        Handoff dates use Philippine time. Undated records are
                        excluded when dates are selected.
                    </p>
                </div>
            </form>
        </section>
        <section class="space-y-4" aria-labelledby="shipment-list-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="shipment-list-heading" class="font-semibold">
                    {{ shipments.total }} matching shipments
                </h2>
                <div
                    v-if="can_export"
                    class="flex flex-wrap gap-2"
                    aria-label="Shipment documents"
                >
                    <template v-for="format in formats" :key="format.value">
                        <Button
                            v-if="documentAvailable(format.value)"
                            as-child
                            variant="outline"
                            size="sm"
                            ><a
                                :href="exportUrl(format.value)"
                                :target="
                                    format.value === 'print'
                                        ? '_blank'
                                        : undefined
                                "
                                rel="noopener noreferrer"
                                >{{ format.label }}</a
                            ></Button
                        >
                        <Button v-else disabled variant="outline" size="sm">{{
                            format.label
                        }}</Button>
                    </template>
                </div>
            </div>
            <p
                v-if="can_export && shipments.total > document_limit"
                class="text-muted-foreground text-sm"
            >
                PDF and print support up to {{ document_limit }} shipments.
                Narrow your filters or export all matches as CSV.
            </p>
            <p v-if="can_export && dirty" class="text-muted-foreground text-sm">
                Apply your filters before exporting or printing.
            </p>
            <InputError :message="page.props.errors?.export" />
            <div
                v-if="shipments.data.length"
                class="border-border overflow-x-auto border"
            >
                <table class="w-full min-w-[1100px] text-left text-sm">
                    <caption class="sr-only">
                        Shipment package and carrier records matching the
                        applied filters
                    </caption>
                    <thead class="bg-secondary">
                        <tr>
                            <th scope="col" class="p-3">
                                Order and destination
                            </th>
                            <th scope="col" class="p-3">Status</th>
                            <th scope="col" class="p-3">Packed parcels</th>
                            <th scope="col" class="p-3">
                                Tracking and receipt
                            </th>
                            <th scope="col" class="p-3">
                                Delivery and carrier costs
                            </th>
                            <th scope="col" class="p-3">Handoff</th>
                            <th scope="col" class="p-3">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="shipment in shipments.data"
                            :key="shipment.id"
                            class="border-border bg-card border-t align-top"
                        >
                            <td class="max-w-60 space-y-1 p-3 break-words">
                                <p class="font-medium">
                                    {{ shipment.reference }}
                                </p>
                                <p>{{ shipment.recipient }}</p>
                                <p class="text-muted-foreground">
                                    {{ shipment.destination || 'Not recorded' }}
                                </p>
                            </td>
                            <td class="p-3">
                                <Badge variant="outline">{{
                                    shipment.status.label
                                }}</Badge>
                            </td>
                            <td class="space-y-1 p-3 tabular-nums">
                                <p>
                                    Parcels:
                                    {{
                                        shipment.package_count ?? 'Not recorded'
                                    }}
                                </p>
                                <p>
                                    Weight:
                                    {{
                                        shipment.weight === null
                                            ? 'Not recorded'
                                            : shipment.weight + ' kg'
                                    }}
                                </p>
                                <p>
                                    Size:
                                    {{
                                        [
                                            shipment.length,
                                            shipment.width,
                                            shipment.height,
                                        ].every((value) => value !== null)
                                            ? [
                                                  shipment.length,
                                                  shipment.width,
                                                  shipment.height,
                                              ].join(' × ') + ' cm'
                                            : 'Not recorded'
                                    }}
                                </p>
                            </td>
                            <td class="max-w-48 space-y-1 p-3 break-words">
                                <p>
                                    Tracking:
                                    {{ shipment.tracking || 'Not recorded' }}
                                </p>
                                <p class="text-muted-foreground">
                                    Receipt:
                                    {{ shipment.receipt || 'Not recorded' }}
                                </p>
                            </td>
                            <td class="space-y-1 p-3 tabular-nums">
                                <p>
                                    Customer:
                                    {{ formatCurrency(shipment.delivery_fee) }}
                                </p>
                                <p>
                                    Carrier:
                                    {{
                                        shipment.carrier_cost === null
                                            ? 'Not recorded'
                                            : formatCurrency(
                                                  shipment.carrier_cost,
                                              )
                                    }}
                                </p>
                                <Badge variant="outline">{{
                                    shipment.reconciliation
                                }}</Badge>
                            </td>
                            <td class="p-3">
                                {{
                                    shipment.handed_at
                                        ? formatDateTime(shipment.handed_at)
                                        : 'Not recorded'
                                }}
                            </td>
                            <td class="p-3">
                                <Button as-child size="sm" variant="outline"
                                    ><Link
                                        :href="
                                            OrderController.show(
                                                shipment.order_id,
                                            )
                                        "
                                        >View order</Link
                                    ></Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-else
                class="border-border bg-card space-y-2 border p-8 text-center"
            >
                <h3 class="font-medium">No matching shipments</h3>
                <p class="text-muted-foreground text-sm">
                    Try another status or clear your filters. Orders without a
                    shipment are available in Orders.
                </p>
            </div>
            <CatalogPagination
                :current-page="shipments.current_page"
                :last-page="shipments.last_page"
                :route="shipmentPage"
                label="Shipment pages"
            />
        </section>
    </main>
</template>
