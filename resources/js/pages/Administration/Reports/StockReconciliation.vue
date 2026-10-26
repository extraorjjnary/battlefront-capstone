<script setup>
import PageHeader from '@/components/PageHeader.vue';
import StockAdjustmentSummary from '@/components/administration/StockAdjustmentSummary.vue';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import { Head, Link, usePage } from '@inertiajs/vue3';
import StockReconciliationController from '@/actions/App/Http/Controllers/Administration/StockReconciliationController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import SearchField from '@/components/SearchField.vue';
import { Form } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { computed, ref, watch } from 'vue';

const requestedQuantities = ref({});
const stockConfirmations = ref({});
const reasonCodes = ref({});

function resetStockConfirmation(event, inventoryId) {
    if (event.target.name !== 'stock_adjustment_confirmed') {
        stockConfirmations.value[inventoryId] = false;
    }
}

function countSubmitted(inventoryId) {
    if (canAdjustStock.value) {
        stockConfirmations.value[inventoryId] = false;
        delete requestedQuantities.value[inventoryId];
        delete reasonCodes.value[inventoryId];
    }
}

const props = defineProps({
    inventories: { type: Object, required: true },
    selected_product_id: { type: Number, default: null },
    movements: { type: Object, default: null },
    filters: { type: Object, required: true },
});
const page = usePage();
const canAdjustStock = computed(() =>
    Boolean(page.props.auth.can?.adjustInventory),
);
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters.q,
    currentSearch: () => props.filters.q,
    route: StockReconciliationController.index,
    query: () => ({ product_id: props.selected_product_id ?? undefined }),
});
watch(
    () => props.filters.q,
    (query) => {
        search.value = query ?? '';
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Stock checks',
                href: StockReconciliationController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Stock checks" />
        <PageHeader title="Stock checks" />
        <p class="text-muted-foreground text-sm" role="note">
            Compare stock records with recorded changes and open cases. This
            does not count or change stock. Review any mismatch before
            {{
                canAdjustStock
                    ? 'applying a stock correction.'
                    : 'requesting a change.'
            }}
        </p>
        <SearchField
            id="reconciliation-search"
            v-model="search"
            class="max-w-xl"
            label="Search inventory"
            placeholder="Product name or code"
            :is-searching="isSearching"
            @clear="clearSearch"
        />
        <div class="border-border bg-card overflow-x-auto border">
            <table class="w-full min-w-220 text-left text-sm">
                <thead class="bg-secondary text-xs uppercase">
                    <tr>
                        <th class="p-3">Product</th>
                        <th class="p-3">Available now</th>
                        <th class="p-3">Last recorded available</th>
                        <th class="p-3">Held now</th>
                        <th class="p-3">Last recorded held</th>
                        <th class="p-3">Units held by open cases</th>
                        <th class="p-3">Record check</th>
                        <th class="p-3">Physical count</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in inventories.data"
                        :key="item.product_id"
                        class="border-border border-t"
                    >
                        <td class="p-3">
                            <Link
                                :href="
                                    StockReconciliationController.index({
                                        query: {
                                            product_id: item.product_id,
                                            q: filters.q,
                                            page: inventories.current_page,
                                        },
                                    })
                                "
                                class="text-primary font-semibold underline"
                                >{{ item.name }}</Link
                            ><br /><span
                                class="text-muted-foreground text-xs"
                                >{{ item.product_code }}</span
                            >
                        </td>
                        <td class="p-3 tabular-nums">{{ item.sellable }}</td>
                        <td class="p-3 tabular-nums">
                            {{ item.ledger_sellable ?? 'Unknown' }}
                        </td>
                        <td class="p-3 tabular-nums">{{ item.quarantined }}</td>
                        <td class="p-3 tabular-nums">
                            {{ item.ledger_quarantined ?? 'Unknown' }}
                        </td>
                        <td class="p-3 tabular-nums">
                            {{ item.case_expected
                            }}<span class="text-muted-foreground block text-xs"
                                >Damage {{ item.case_breakdown.damage }} ·
                                Customer
                                {{ item.case_breakdown.customer_return }} ·
                                Supplier
                                {{ item.case_breakdown.supplier_return }}</span
                            >
                        </td>
                        <td
                            class="p-3"
                            :class="
                                item.status === 'discrepancy'
                                    ? 'text-destructive font-semibold'
                                    : 'text-muted-foreground'
                            "
                        >
                            <p>
                                {{
                                    item.status === 'legacy_unknown'
                                        ? 'No earlier stock record'
                                        : item.status === 'discrepancy'
                                          ? 'Investigate mismatch'
                                          : 'Records match'
                                }}
                            </p>
                            <ul
                                v-if="item.findings.length"
                                class="mt-1 space-y-1 text-xs font-normal"
                            >
                                <li
                                    v-for="finding in item.findings"
                                    :key="finding"
                                >
                                    {{ finding }}
                                </li>
                            </ul>
                            <p
                                v-if="
                                    item.sellable_difference !== null &&
                                    item.sellable_difference !== 0
                                "
                                class="mt-1 text-xs font-normal"
                            >
                                Available stock difference:
                                {{ item.sellable_difference > 0 ? '+' : ''
                                }}{{ item.sellable_difference }}
                            </p>
                            <p
                                v-if="
                                    item.held_ledger_difference !== null &&
                                    item.held_ledger_difference !== 0
                                "
                                class="mt-1 text-xs font-normal"
                            >
                                Held stock difference:
                                {{ item.held_ledger_difference > 0 ? '+' : ''
                                }}{{ item.held_ledger_difference }}
                            </p>
                            <p
                                v-if="item.case_difference !== 0"
                                class="mt-1 text-xs font-normal"
                            >
                                Open case difference:
                                {{ item.case_difference > 0 ? '+' : ''
                                }}{{ item.case_difference }}
                            </p>
                            <p
                                v-if="item.first_problem_movement_id"
                                class="mt-1 text-xs font-normal"
                            >
                                First affected stock change #{{
                                    item.first_problem_movement_id
                                }}
                            </p>
                        </td>
                        <td class="p-3">
                            <details
                                v-if="
                                    canAdjustStock ||
                                    page.props.auth.can
                                        ?.requestInventoryAdjustment
                                "
                            >
                                <summary
                                    class="text-primary cursor-pointer underline"
                                >
                                    Record shelf count
                                </summary>
                                <Form
                                    :key="`count-${item.inventory_id}-${item.sellable}`"
                                    @input.capture="
                                        resetStockConfirmation(
                                            $event,
                                            item.inventory_id,
                                        )
                                    "
                                    @change.capture="
                                        resetStockConfirmation(
                                            $event,
                                            item.inventory_id,
                                        )
                                    "
                                    @success="countSubmitted(item.inventory_id)"
                                    v-bind="
                                        InventoryController.count.form(
                                            item.inventory_id,
                                        )
                                    "
                                    v-slot="{ errors, processing }"
                                    class="mt-2 grid max-w-full min-w-0 min-w-56 gap-2"
                                >
                                    <StockAdjustmentSummary
                                        :quantity-label="
                                            canAdjustStock
                                                ? 'Proposed'
                                                : 'Requested'
                                        "
                                        :current-quantity="item.sellable"
                                        :requested-quantity="
                                            requestedQuantities[
                                                item.inventory_id
                                            ] ?? item.sellable
                                        "
                                    />
                                    <input
                                        type="hidden"
                                        name="observed_quantity"
                                        :value="item.sellable"
                                    />
                                    <label class="max-w-full min-w-0 text-xs"
                                        >Available units counted<Input
                                            class="min-h-11"
                                            name="quantity"
                                            type="number"
                                            min="0"
                                            required
                                            :default-value="item.sellable"
                                            @update:model-value="
                                                requestedQuantities[
                                                    item.inventory_id
                                                ] = $event
                                            "
                                    /></label>
                                    <label class="max-w-full min-w-0 text-xs"
                                        >Count reason<select
                                            name="reason_code"
                                            v-model="
                                                reasonCodes[item.inventory_id]
                                            "
                                            required
                                            class="border-input bg-background focus-visible:ring-ring mt-1 min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            <option value="">
                                                Select a reason
                                            </option>
                                            <option value="theft_loss">
                                                Theft or loss
                                            </option>
                                            <option value="found_stock">
                                                Found stock
                                            </option>
                                            <option value="count_error">
                                                Counting error
                                            </option>
                                            <option value="receiving_error">
                                                Receiving error
                                            </option>
                                            <option
                                                value="unit_of_measure_error"
                                            >
                                                Wrong stock unit
                                            </option>
                                            <option value="other">Other</option>
                                        </select></label
                                    >
                                    <label class="max-w-full min-w-0 text-xs"
                                        >{{
                                            canAdjustStock &&
                                            reasonCodes[item.inventory_id] ===
                                                'theft_loss'
                                                ? 'Notes (Required)'
                                                : 'Notes (Optional)'
                                        }}<Input
                                            aria-label="Notes"
                                            class="min-h-11"
                                            name="reason"
                                            :required="
                                                canAdjustStock &&
                                                reasonCodes[
                                                    item.inventory_id
                                                ] === 'theft_loss'
                                            "
                                            minlength="5"
                                            maxlength="2000"
                                            placeholder="Who counted and when"
                                    /></label>
                                    <p class="text-muted-foreground text-xs">
                                        For damaged stock, use
                                        <Link
                                            :href="
                                                DamageReportController.index()
                                            "
                                            class="text-primary-ink underline"
                                            >Damage Reports</Link
                                        >.
                                    </p>
                                    <p
                                        v-if="!canAdjustStock"
                                        class="text-muted-foreground text-xs"
                                    >
                                        Manager or Owner approval is required
                                        before stock changes.
                                    </p>
                                    <label
                                        v-else
                                        class="flex items-start gap-2 text-xs"
                                    >
                                        <input
                                            v-model="
                                                stockConfirmations[
                                                    item.inventory_id
                                                ]
                                            "
                                            type="checkbox"
                                            name="stock_adjustment_confirmed"
                                            value="1"
                                            required
                                            class="mt-1"
                                        />
                                        I confirm the proposed quantity,
                                        difference, and reason above. Apply this
                                        stock change immediately.
                                    </label>
                                    <Button
                                        class="min-h-11"
                                        type="submit"
                                        size="sm"
                                        :disabled="
                                            processing ||
                                            (canAdjustStock &&
                                                !stockConfirmations[
                                                    item.inventory_id
                                                ])
                                        "
                                        >{{
                                            canAdjustStock
                                                ? 'Apply count correction'
                                                : 'Submit for approval'
                                        }}</Button
                                    >
                                    <p
                                        v-if="
                                            errors.quantity ||
                                            errors.reason_code ||
                                            errors.reason ||
                                            errors.stock_adjustment_confirmed
                                        "
                                        class="text-destructive text-xs"
                                    >
                                        {{
                                            errors.quantity ||
                                            errors.reason_code ||
                                            errors.reason ||
                                            errors.stock_adjustment_confirmed
                                        }}
                                    </p>
                                </Form>
                            </details>
                            <span v-else class="text-muted-foreground"
                                >View only</span
                            >
                        </td>
                    </tr>
                    <tr v-if="inventories.data.length === 0">
                        <td colspan="8" class="p-6">No inventory records.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav
            v-if="inventories.last_page > 1"
            class="flex justify-between text-sm"
            aria-label="Stock check pages"
        >
            <Link
                v-if="inventories.prev_page_url"
                :href="inventories.prev_page_url"
                class="text-primary underline"
                >Previous</Link
            ><span v-else></span>
            <Link
                v-if="inventories.next_page_url"
                :href="inventories.next_page_url"
                class="text-primary underline"
                >Next</Link
            >
        </nav>
        <section
            v-if="movements"
            class="surface-card border-border bg-card border p-5"
        >
            <h2 class="text-xl font-semibold">
                Stock history for product #{{ selected_product_id }}
            </h2>
            <p class="text-muted-foreground mt-1 text-sm">
                Before, change, and after quantities. Earlier stock cannot be
                checked without a starting record.
            </p>
            <div class="mt-4 space-y-2">
                <article
                    v-for="movement in movements.data"
                    :key="movement.id"
                    class="border-border border p-3 text-sm"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <strong
                            >{{ movement.kind.replaceAll('_', ' ') }} #{{
                                movement.id
                            }}</strong
                        ><time>{{ movement.created_at }}</time>
                    </div>
                    <p class="text-muted-foreground mt-1">
                        {{ movement.actor_name }} · Available
                        {{ movement.sellable_before }}
                        {{ movement.sellable_delta >= 0 ? '+' : ''
                        }}{{ movement.sellable_delta }} =
                        {{ movement.sellable_after }} · Held
                        {{ movement.quarantined_before }}
                        {{ movement.quarantined_delta >= 0 ? '+' : ''
                        }}{{ movement.quarantined_delta }} =
                        {{ movement.quarantined_after }}
                    </p>
                    <Link
                        v-if="movement.href"
                        :href="movement.href"
                        class="text-primary mt-1 inline-block underline"
                        >{{ movement.reference_type }} #{{
                            movement.reference_id
                        }}</Link
                    >
                    <p
                        v-else-if="movement.reference_type"
                        class="text-muted-foreground mt-1"
                    >
                        {{ movement.reference_type }} #{{
                            movement.reference_id
                        }}
                    </p>
                </article>
                <p
                    v-if="movements.data.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No stock changes recorded.
                </p>
            </div>
            <nav
                v-if="movements.last_page > 1"
                class="mt-4 flex justify-between text-sm"
                aria-label="Movement history pages"
            >
                <Link
                    v-if="movements.prev_page_url"
                    :href="movements.prev_page_url"
                    class="text-primary underline"
                    >Previous</Link
                ><span v-else></span>
                <Link
                    v-if="movements.next_page_url"
                    :href="movements.next_page_url"
                    class="text-primary underline"
                    >Next</Link
                >
            </nav>
        </section>
    </main>
</template>
