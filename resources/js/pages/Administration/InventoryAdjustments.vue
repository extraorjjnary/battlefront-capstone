<script setup>
import PageHeader from '@/components/PageHeader.vue';
import StockAdjustmentSummary from '@/components/administration/StockAdjustmentSummary.vue';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import InventoryAdjustmentController from '@/actions/App/Http/Controllers/Administration/InventoryAdjustmentController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import InputError from '@/components/InputError.vue';
import SearchField from '@/components/SearchField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { ref, watch } from 'vue';

const requestReasonCodes = ref({});
const requestedQuantities = ref({});

const props = defineProps({
    requests: { type: Object, required: true },
    selected_case: { type: Number, default: null },
    filters: Object,
});
const page = usePage();
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters?.q,
    currentSearch: () => props.filters?.q,
    route: InventoryAdjustmentController.index,
    query: () => ({ case: props.selected_case ?? undefined }),
});
watch(
    () => props.filters?.q,
    (query) => {
        search.value = query ?? '';
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Stock requests',
                href: InventoryAdjustmentController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Stock requests" />
        <p v-if="selected_case" class="text-sm">
            Showing stock request #{{ selected_case }} ·
            <Link
                :href="InventoryAdjustmentController.index()"
                class="text-primary underline"
                >Show all</Link
            >
        </p>
        <PageHeader title="Stock requests">
            <template #actions
                ><Button variant="outline" as-child
                    ><Link :href="InventoryController.index()"
                        >Inventory</Link
                    ></Button
                ></template
            >
        </PageHeader>
        <SearchField
            id="adjustment-search"
            v-model="search"
            class="max-w-xl"
            label="Search stock requests"
            placeholder="Product, requester, or reason"
            :is-searching="isSearching"
            @clear="clearSearch"
        />
        <p
            v-if="requests.data.length === 0"
            class="border-border bg-card text-muted-foreground border p-6 text-sm"
        >
            No stock requests found.
        </p>
        <article
            v-for="entry in requests.data"
            :key="entry.id"
            class="surface-card border-border bg-card space-y-4 border p-5"
        >
            <div class="flex flex-wrap justify-between gap-3">
                <div>
                    <h2 class="font-semibold">{{ entry.product_name }}</h2>
                    <p class="text-muted-foreground text-sm">
                        {{
                            entry.reason.startsWith(
                                'Physical sellable stock count:',
                            )
                                ? 'Physical count submitted by'
                                : 'Stock change requested by'
                        }}
                        {{ entry.requester_name }}
                    </p>
                    <p class="mt-2 text-sm">{{ entry.reason }}</p>
                    <p
                        v-if="entry.reason_code"
                        class="text-muted-foreground text-sm"
                    >
                        Reason:
                        {{ entry.reason_code.replaceAll('_', ' ') }}
                    </p>
                    <p
                        v-if="entry.replaces_request_id"
                        class="text-muted-foreground text-sm"
                    >
                        Replaces request #{{ entry.replaces_request_id }}
                    </p>
                    <p
                        v-if="entry.replacement_id"
                        class="text-muted-foreground text-sm"
                    >
                        Replaced by request #{{ entry.replacement_id }}
                    </p>
                    <p
                        v-if="entry.decision_reason"
                        class="text-muted-foreground text-sm"
                    >
                        Decision: {{ entry.decision_reason }}
                    </p>
                </div>
                <span class="text-sm capitalize">{{ entry.status }}</span>
            </div>
            <StockAdjustmentSummary
                :current-quantity="
                    entry.status === 'pending'
                        ? entry.current_quantity
                        : entry.observed_quantity
                "
                :requested-quantity="entry.target_quantity"
            />
            <p
                v-if="
                    entry.status === 'pending' &&
                    entry.current_quantity !== entry.observed_quantity
                "
                class="text-destructive text-sm"
            >
                Stock was {{ entry.observed_quantity }} units when requested.
                Stock changed after this request; submit a new request.
            </p>
            <Form
                v-if="
                    entry.status === 'pending' &&
                    entry.requester_id !== page.props.auth.user.id &&
                    page.props.auth.can?.approveInventory
                "
                v-bind="InventoryAdjustmentController.decide.form(entry.id)"
                v-slot="{ errors, processing }"
                class="max-w-full min-w-0 space-y-3"
            >
                <label
                    :for="`decision-reason-${entry.id}`"
                    class="block max-w-full min-w-0 text-sm"
                    >Review notes</label
                >
                <Input
                    class="min-h-11"
                    :id="`decision-reason-${entry.id}`"
                    name="reason"
                    required
                    minlength="5"
                    maxlength="2000"
                />
                <InputError
                    :message="
                        errors.reason || errors.reason_code || errors.decision
                    "
                />
                <div class="flex gap-2">
                    <Button
                        class="min-h-11"
                        type="submit"
                        name="decision"
                        value="approve"
                        :disabled="processing"
                        >Approve</Button
                    >
                    <Button
                        class="min-h-11"
                        type="submit"
                        name="decision"
                        value="reject"
                        variant="outline"
                        :disabled="processing"
                        >Reject</Button
                    >
                </div>
            </Form>
            <Form
                v-if="
                    page.props.auth.can?.requestInventory &&
                    entry.status === 'pending' &&
                    entry.requester_id === page.props.auth.user.id
                "
                v-bind="InventoryAdjustmentController.withdraw.form(entry.id)"
                v-slot="{ errors, processing }"
                class="flex max-w-full min-w-0 flex-wrap items-end gap-2"
            >
                <label class="max-w-full min-w-0 text-sm"
                    >Withdrawal notes<Input
                        aria-label="Notes"
                        class="min-h-11"
                        name="reason"
                        required
                        minlength="5"
                        maxlength="2000"
                /></label>
                <Button
                    class="min-h-11"
                    type="submit"
                    variant="outline"
                    :disabled="processing"
                    >Withdraw request</Button
                >
                <InputError :message="errors.reason || errors.withdrawal" />
            </Form>
            <Form
                v-if="
                    page.props.auth.can?.requestInventory &&
                    entry.status === 'withdrawn' &&
                    !entry.replacement_id &&
                    entry.requester_id === page.props.auth.user.id
                "
                v-bind="InventoryAdjustmentController.replace.form(entry.id)"
                v-slot="{ errors, processing }"
                class="grid max-w-full min-w-0 gap-2 sm:grid-cols-2"
            >
                <label class="max-w-full min-w-0 text-sm"
                    >New quantity<Input
                        class="min-h-11"
                        name="quantity"
                        type="number"
                        min="0"
                        required
                        :default-value="entry.target_quantity"
                        @update:model-value="
                            requestedQuantities[entry.id] = $event
                        "
                /></label>
                <StockAdjustmentSummary
                    class="sm:col-span-2"
                    :current-quantity="entry.current_quantity"
                    :requested-quantity="
                        requestedQuantities[entry.id] ?? entry.target_quantity
                    "
                />
                <label
                    v-if="entry.initial_reorder_level !== null"
                    class="max-w-full min-w-0 text-sm"
                    >Initial reorder level<Input
                        class="min-h-11"
                        name="reorder_level"
                        type="number"
                        min="0"
                        :default-value="entry.initial_reorder_level"
                /></label>
                <select
                    name="reason_code"
                    :value="requestReasonCodes[entry.id] ?? ''"
                    @change="requestReasonCodes[entry.id] = $event.target.value"
                    required
                    class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                >
                    <option value="">Select a reason</option>
                    <option value="theft_loss">Theft or loss</option>
                    <option value="found_stock">Found stock</option>
                    <option value="count_error">Count correction</option>
                    <option value="receiving_error">
                        {{
                            entry.initial_reorder_level !== null
                                ? 'Initial receipt'
                                : 'Receiving error'
                        }}
                    </option>
                    <option value="unit_of_measure_error">
                        Wrong stock unit
                    </option>
                    <option value="other">Other</option>
                </select>
                <label class="max-w-full min-w-0 text-sm sm:col-span-2"
                    >{{
                        requestReasonCodes[entry.id] === 'other'
                            ? 'Additional notes (required)'
                            : 'Additional notes (optional)'
                    }}<Input
                        class="min-h-11"
                        name="reason"
                        :required="requestReasonCodes[entry.id] === 'other'"
                        minlength="5"
                        maxlength="2000"
                /></label>
                <p class="text-muted-foreground text-sm sm:col-span-2">
                    For damaged stock, use
                    <Link
                        :href="DamageReportController.index()"
                        class="text-primary-ink underline"
                        >Damage Reports</Link
                    >.
                </p>
                <Button class="min-h-11" type="submit" :disabled="processing"
                    >Send new request</Button
                >
                <InputError
                    :message="
                        errors.quantity ||
                        errors.reason ||
                        errors.reason_code ||
                        errors.replacement
                    "
                />
            </Form>
        </article>
        <div v-if="requests.last_page > 1" class="flex justify-between text-sm">
            <Link
                v-if="requests.prev_page_url"
                :href="requests.prev_page_url"
                class="text-primary underline"
                >Previous</Link
            ><span v-else></span>
            <Link
                v-if="requests.next_page_url"
                :href="requests.next_page_url"
                class="text-primary underline"
                >Next</Link
            >
        </div>
    </main>
</template>
