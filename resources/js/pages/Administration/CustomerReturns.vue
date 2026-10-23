<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Form, Head, Link, usePage, useForm } from '@inertiajs/vue3';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';
import AdministrationEvidenceController from '@/actions/App/Http/Controllers/Administration/AdministrationEvidenceController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import InputError from '@/components/InputError.vue';
import SearchField from '@/components/SearchField.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ref, reactive, watch } from 'vue';
import { formatDateTime } from '@/lib/dateTime';

const requestReasonCode = ref('');

const props = defineProps({
    returns: Object,
    customerRequests: Object,
    selected_request: { type: Number, default: null },
    selected_order: Object,
    suppliers: Array,
    refundPayments: Object,
    evidence: Object,
    paymentEvidence: Object,
    selected_case: { type: Number, default: null },
    selected_payment: { type: Number, default: null },
    filters: Object,
});
const page = usePage();
const receiptRequest = ref(null);
const receiptForm = useForm({ condition: '' });
function receiveCustomerRequest(entry) {
    if (!entry.can_receive || receiptForm.processing) return;
    if (receiptRequest.value !== entry.id) {
        receiptRequest.value = entry.id;
        receiptForm.reset();
        receiptForm.clearErrors();
        return;
    }
    receiptForm.submit(CustomerReturnController.receiveRequest(entry.id), {
        preserveScroll: true,
        onSuccess: () => {
            receiptRequest.value = null;
            receiptForm.reset();
        },
    });
}
const returnLabels = {
    pending: 'Awaiting review',
    accepted: 'Accepted',
    rejected_pending_handover: 'Rejected, awaiting return to customer',
    rejected: 'Rejected, returned to customer',
};
const paymentLabels = {
    cash: 'Cash',
    gcash: 'GCash',
    maya: 'Maya',
    bank_transfer: 'Bank transfer',
    other: 'Other',
};
const decisions = reactive({});
const returnStatusHelp = {
    pending: 'Another administrator must inspect and review this return.',
    accepted: 'Accepted. Check the stock and refund status below.',
    rejected_pending_handover:
        'Rejected items stay on hold until returned to the customer.',
    rejected: 'Rejected and handed back to the customer.',
};
const stockHandling = (entry) => {
    if (entry.status === 'pending') return 'Held while awaiting a decision';
    if (entry.status === 'rejected_pending_handover')
        return 'Held while awaiting customer handover';
    if (entry.status === 'rejected') return 'Handed back to the customer';
    if (entry.disposition === 'restock') return 'Returned to available stock';
    if (entry.disposition === 'write_off') return 'Written off';
    if (entry.disposition === 'supplier_return' && entry.held_quantity > 0)
        return 'Held for supplier return';

    return 'Supplier return: no units on hold';
};
const refundProgress = (entry) => {
    if (!entry.refund_id) return 'No refund approved';
    if (entry.approved_amount === '0.00' || Number(entry.approved_amount) === 0)
        return 'No refund amount approved';
    if (entry.refund_fully_verified) return 'Approved amount fully verified';
    if (entry.refund_has_disputed_payment)
        return 'Payment disputed; review required';
    if (entry.refund_has_verified_payment)
        return 'Partly verified; balance remains';
    if (entry.refund_has_unreviewed_payment)
        return 'Payment recorded; awaiting review';

    return 'Approved; no payment has been verified';
};
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters?.q,
    currentSearch: () => props.filters?.q,
    route: CustomerReturnController.index,
    query: () => ({
        case: props.selected_case ?? undefined,
        request_id: props.selected_request ?? undefined,
        payment_id: props.selected_payment ?? undefined,
        overdue: props.filters?.overdue ? 1 : undefined,
    }),
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
                title: 'Customer returns',
                href: CustomerReturnController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Customer returns" />
        <p v-if="selected_case" class="text-sm">
            Showing customer return #{{ selected_case }} ·
            <Link
                :href="CustomerReturnController.index()"
                class="text-primary underline"
                >Show all</Link
            >
        </p>
        <PageHeader title="Customer returns" />
        <section
            class="surface-card border-border bg-card space-y-4 border p-5"
            aria-labelledby="customer-requests-heading"
        >
            <h2 id="customer-requests-heading" class="text-lg font-semibold">
                Customer requests awaiting item receipt
            </h2>
            <p class="text-muted-foreground text-sm">
                A request does not place stock on hold. Confirm receipt only
                after the actual product is received.
            </p>
            <p v-if="selected_request" class="text-sm">
                Showing request #{{ selected_request }} ·
                <Link
                    :href="CustomerReturnController.index()"
                    class="text-primary-ink underline"
                    >Show all</Link
                >
            </p>
            <p
                v-if="!customerRequests?.data.length"
                class="text-muted-foreground text-sm"
            >
                No customer requests awaiting receipt.
            </p>
            <article
                v-for="entry in customerRequests?.data"
                :key="entry.id"
                class="border-border space-y-3 border-t pt-4"
            >
                <h3 class="font-semibold">
                    {{ entry.product_name }} · {{ entry.quantity }} units
                </h3>
                <p class="text-sm">
                    {{ entry.customer_name }} ·
                    <Link
                        :href="OrderController.show(entry.order_id)"
                        class="text-primary-ink underline"
                        >{{ entry.order_reference }}</Link
                    >
                </p>
                <p class="text-muted-foreground text-sm">
                    {{ entry.reason_label }} · Requested
                    {{ formatDateTime(entry.created_at) }}
                </p>
                <p
                    v-if="entry.reason"
                    class="text-sm wrap-anywhere whitespace-pre-wrap"
                >
                    {{ entry.reason }}
                </p>
                <Link
                    v-if="entry.customer_return_id"
                    :href="
                        CustomerReturnController.index({
                            query: { case: entry.customer_return_id },
                        })
                    "
                    class="text-primary-ink text-sm underline"
                    >View received return #{{ entry.customer_return_id }}</Link
                >
                <p
                    v-else-if="entry.withdrawn"
                    class="text-muted-foreground text-sm"
                >
                    Withdrawn by customer
                </p>
                <form
                    v-else-if="
                        entry.can_receive && page.props.auth.can.requestReturns
                    "
                    class="space-y-3"
                    @submit.prevent="receiveCustomerRequest(entry)"
                >
                    <template v-if="receiptRequest === entry.id">
                        <Label :for="`receipt-condition-${entry.id}`"
                            >Inspected item condition</Label
                        >
                        <select
                            :id="`receipt-condition-${entry.id}`"
                            v-model="receiptForm.condition"
                            required
                            class="border-input bg-background min-h-11 w-full rounded-md border px-3 py-2 sm:max-w-xs"
                        >
                            <option disabled value="">
                                Select actual condition
                            </option>
                            <option value="sealed">Sealed</option>
                            <option value="opened">Opened</option>
                            <option value="damaged">Damaged</option>
                        </select>
                        <InputError
                            :message="receiptForm.errors.condition"
                        /><InputError
                            :message="receiptForm.errors.quantity"
                        /><InputError
                            :message="receiptForm.errors.order_item_id"
                        /><InputError
                            :message="receiptForm.errors.return_request"
                        />
                        <p class="text-sm">
                            Confirm that you have physically received all
                            {{ entry.quantity }} requested units. They will be
                            placed in quarantine for review by another employee.
                        </p>
                    </template>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="receiptForm.processing"
                        >{{
                            receiptRequest === entry.id
                                ? 'Confirm physical receipt'
                                : 'Receive requested item'
                        }}</Button
                    >
                </form>
            </article>
            <nav
                v-if="customerRequests?.last_page > 1"
                aria-label="Customer requests pages"
                class="flex flex-wrap gap-2"
            >
                <Button
                    v-for="link in customerRequests.links.filter(
                        (entry) => entry.url,
                    )"
                    :key="link.label"
                    variant="outline"
                    size="sm"
                    as-child
                    ><Link
                        :href="link.url"
                        :aria-current="link.active ? 'page' : undefined"
                        ><span v-html="link.label" /></Link
                ></Button>
            </nav>
        </section>
        <p class="text-muted-foreground text-sm" role="note">
            Returned items are held for inspection. Refunds are paid outside
            Battlefront and recorded separately.
        </p>
        <section
            class="surface-card border-border bg-card space-y-4 border p-5"
        >
            <h2 class="text-lg font-semibold">Receive a return</h2>
            <p class="text-muted-foreground text-sm">
                Items cannot be sold until inspection is complete.
            </p>
            <Form
                v-bind="CustomerReturnController.index.form()"
                class="flex max-w-full min-w-0 flex-wrap items-end gap-3"
                ><div class="space-y-2">
                    <Label for="lookup-order">Order ID or reference</Label
                    ><Input
                        class="min-h-11"
                        id="lookup-order"
                        name="order_id"
                        type="text"
                        :default-value="selected_order?.reference"
                        placeholder="BF-000123"
                        required
                    />
                </div>
                <Button class="min-h-11" type="submit" variant="outline"
                    >Find completed order</Button
                ></Form
            >
            <Form
                v-if="selected_order && page.props.auth.can?.requestReturns"
                v-bind="CustomerReturnController.store.form()"
                v-slot="{ errors, processing }"
                class="grid max-w-full min-w-0 gap-4 sm:grid-cols-2"
            >
                <p class="text-sm sm:col-span-2">
                    Order {{ selected_order.reference }}
                </p>
                <div class="space-y-2">
                    <Label for="return-item">Purchased item</Label
                    ><select
                        id="return-item"
                        name="order_item_id"
                        required
                        class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <option value="">Select item</option>
                        <option
                            v-for="item in selected_order.items"
                            :key="item.id"
                            :value="item.id"
                            :disabled="item.remaining_returnable === 0"
                        >
                            {{ item.product }} ·
                            {{ item.remaining_returnable }} of
                            {{ item.quantity }} units still returnable · ₱{{
                                item.price_at_time
                            }}
                            each
                        </option></select
                    ><InputError :message="errors.order_item_id" />
                </div>
                <div class="space-y-2">
                    <Label for="return-units">Units received</Label
                    ><Input
                        class="min-h-11"
                        id="return-units"
                        name="quantity"
                        type="number"
                        min="1"
                        required
                    /><InputError :message="errors.quantity" />
                </div>
                <div class="space-y-2">
                    <Label for="return-condition">Condition</Label
                    ><select
                        id="return-condition"
                        name="condition"
                        class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <option value="sealed">Sealed</option>
                        <option value="opened">Opened</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <Label for="return-reason-code">Reason</Label
                    ><select
                        id="return-reason-code"
                        name="reason_code"
                        v-model="requestReasonCode"
                        required
                        class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <option value="">Select a reason</option>
                        <option value="wrong_item">Wrong item</option>
                        <option value="defective">Defective</option>
                        <option value="transit_damage">
                            Damaged in transit
                        </option>
                        <option value="not_as_described">
                            Not as described
                        </option>
                        <option value="changed_mind">Changed mind</option>
                        <option value="other">Other</option></select
                    ><InputError :message="errors.reason_code" />
                </div>
                <div class="space-y-2">
                    <Label for="return-reason">{{
                        requestReasonCode === 'other'
                            ? 'Additional notes (required)'
                            : 'Additional notes (optional)'
                    }}</Label
                    ><Input
                        class="min-h-11"
                        id="return-reason"
                        name="reason"
                        :required="requestReasonCode === 'other'"
                        minlength="5"
                        maxlength="2000"
                    /><InputError :message="errors.reason" />
                </div>
                <Button class="min-h-11" type="submit" :disabled="processing"
                    >Receive and hold items</Button
                >
            </Form>
            <p v-else class="text-muted-foreground text-sm">
                Find a completed order to select its purchased items.
            </p>
        </section>
        <section class="space-y-3" aria-label="Return cases">
            <Link
                :href="
                    CustomerReturnController.index({
                        query: {
                            case: selected_case ?? undefined,
                            payment_id: selected_payment ?? undefined,
                            q: filters?.q ?? undefined,
                            overdue: filters?.overdue ? undefined : 1,
                        },
                    })
                "
                class="text-primary text-sm underline"
            >
                {{
                    filters?.overdue
                        ? 'Show all customer returns'
                        : 'Show overdue return follow-up'
                }}
            </Link>
            <SearchField
                id="return-search"
                v-model="search"
                class="max-w-xl"
                label="Search returns"
                placeholder="Product, customer, order ID, or reason"
                :is-searching="isSearching"
                @clear="clearSearch"
            />
            <p
                v-if="returns.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No customer returns recorded.
            </p>
            <article
                v-for="entry in returns.data"
                :key="entry.id"
                class="surface-card border-border bg-card space-y-3 border p-5"
            >
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">
                            {{ entry.product_name }} ·
                            {{ entry.quantity }} units
                        </h2>
                        <p class="text-muted-foreground text-sm">
                            Customer: {{ entry.customer_name }} · Order
                            <Link
                                :href="
                                    OrderController.show(
                                        entry.purchase_order_id,
                                    )
                                "
                                class="text-primary underline"
                                >#{{ entry.purchase_order_id }}</Link
                            >
                            · {{ entry.condition }} · Received by
                            {{ entry.requester_name }}
                        </p>
                        <p class="mt-1 text-sm">
                            {{ entry.reason }} · {{ entry.age_days }} days old
                        </p>
                    </div>
                    <span class="text-sm font-semibold capitalize">{{
                        returnLabels[entry.status] ??
                        entry.status.replaceAll('_', ' ')
                    }}</span>
                </div>
                <p class="text-muted-foreground text-sm">
                    {{
                        returnStatusHelp[entry.status] ??
                        'Review the case history and stock or refund details below.'
                    }}
                </p>
                <p
                    v-if="entry.inspection_overdue"
                    class="text-destructive text-sm font-semibold"
                >
                    Return follow-up overdue
                </p>
                <div class="grid gap-2 text-sm sm:grid-cols-3">
                    <p>
                        <strong>Decision:</strong>
                        {{
                            returnLabels[entry.status] ??
                            entry.status.replaceAll('_', ' ')
                        }}
                    </p>
                    <p>
                        <strong>Stock:</strong> {{ stockHandling(entry) }} ·
                        {{ entry.held_quantity }} held
                    </p>
                    <p><strong>Refund:</strong> {{ refundProgress(entry) }}</p>
                </div>
                <p v-if="entry.decision_reason" class="text-sm">
                    Review notes: {{ entry.decision_reason }}
                </p>
                <div
                    v-if="evidence[entry.id]?.length"
                    class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3"
                >
                    <a
                        v-for="file in evidence[entry.id]"
                        :key="file.id"
                        :href="
                            AdministrationEvidenceController.show(file.id).url
                        "
                        target="_blank"
                        rel="noopener noreferrer"
                        class="border-border bg-background text-primary space-y-2 border p-2 underline"
                    >
                        <img
                            v-if="
                                [
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                ].includes(file.mime_type)
                            "
                            :src="
                                AdministrationEvidenceController.show(file.id)
                                    .url
                            "
                            :alt="`Inspection photo: ${file.original_name}`"
                            class="bg-muted max-h-56 w-full object-contain"
                            loading="lazy"
                        />
                        <span class="block">{{ file.original_name }}</span>
                    </a>
                </div>
                <Form
                    v-if="
                        page.props.auth.can?.requestReturns &&
                        (evidence[entry.id]?.length ?? 0) < 5
                    "
                    v-bind="
                        AdministrationEvidenceController.store.form({
                            subjectType: 'customer_return',
                            subjectId: entry.id,
                        })
                    "
                    v-slot="{ errors, processing }"
                    class="flex max-w-full min-w-0 flex-wrap items-end gap-2"
                    ><label class="max-w-full min-w-0 text-sm"
                        >Photos or documents<input
                            name="files[]"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                            class="mt-1 block w-full max-w-full min-w-0" /></label
                    ><Button
                        class="min-h-11"
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        >Upload files</Button
                    ><InputError :message="errors.files || errors['files.0']"
                /></Form>
                <Form
                    v-if="
                        entry.status === 'pending' &&
                        entry.requester_id !== page.props.auth.user.id &&
                        page.props.auth.can?.approveReturns
                    "
                    v-bind="CustomerReturnController.decide.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="grid max-w-full min-w-0 gap-3 sm:grid-cols-2"
                >
                    <div class="space-y-1">
                        <Label>Decision</Label
                        ><select
                            aria-label="Decision"
                            v-model="decisions[entry.id]"
                            name="decision"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="accept">Accept</option>
                            <option value="reject">Reject</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label>Review notes</Label
                        ><Input
                            aria-label="Notes"
                            class="min-h-11"
                            name="reason"
                            required
                            minlength="5"
                            maxlength="2000"
                        /><InputError :message="errors.reason" />
                    </div>
                    <div
                        v-if="decisions[entry.id] !== 'reject'"
                        class="space-y-1"
                    >
                        <Label>Stock action</Label
                        ><select
                            aria-label="Stock action"
                            name="disposition"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="restock">
                                Return to sellable stock after inspection
                            </option>
                            <option value="write_off">
                                Write off and remove from stock
                            </option>
                            <option value="supplier_return">
                                Keep held and start a supplier return
                            </option>
                        </select>
                    </div>
                    <label
                        v-if="decisions[entry.id] !== 'reject'"
                        class="flex max-w-full min-w-0 items-start gap-2 text-sm"
                        ><input
                            name="inspection_confirmed"
                            type="checkbox"
                            value="1"
                            class="mt-1" /><span
                            >I inspected the item and confirm it is suitable for
                            sale. Required when choosing restock.<InputError
                                :message="errors.inspection_confirmed" /></span
                    ></label>
                    <div
                        v-if="decisions[entry.id] !== 'reject'"
                        class="space-y-1"
                    >
                        <Label :for="`refund-${entry.id}`"
                            >Approved refund (₱)</Label
                        ><Input
                            class="min-h-11"
                            :id="`refund-${entry.id}`"
                            name="approved_amount"
                            type="number"
                            min="0"
                            step="0.01"
                            value="0"
                        />
                        <p class="text-muted-foreground text-xs">
                            Approval does not pay the refund. Record the payment
                            separately.
                        </p>
                        <InputError :message="errors.approved_amount" />
                    </div>
                    <div
                        v-if="decisions[entry.id] !== 'reject'"
                        class="space-y-1"
                    >
                        <Label>Supplier, if needed</Label
                        ><select
                            aria-label="Supplier"
                            name="supplier_id"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="">Select supplier</option>
                            <option
                                v-for="supplier in suppliers"
                                :key="supplier.id"
                                :value="supplier.id"
                            >
                                {{ supplier.name }}
                            </option></select
                        ><InputError :message="errors.supplier_id" />
                    </div>
                    <Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Record decision</Button
                    ><InputError
                        :message="errors.decision || errors.evidence"
                    />
                </Form>
                <Form
                    v-if="
                        entry.status === 'rejected_pending_handover' &&
                        page.props.auth.can?.dispatchReturns
                    "
                    v-bind="CustomerReturnController.handBack.form(entry.id)"
                    v-slot="{ errors, processing }"
                    class="flex max-w-full min-w-0 gap-2"
                    ><Button
                        class="min-h-11"
                        type="submit"
                        :disabled="processing"
                        >Confirm handover to customer</Button
                    ><InputError :message="errors.handover"
                /></Form>
                <div
                    v-if="entry.refund_id"
                    class="border-border bg-muted/40 space-y-2 border p-3 text-sm"
                >
                    <p>
                        Approved ₱{{ entry.approved_amount }} · Verified ₱{{
                            entry.verified_paid_amount
                        }}
                        · Awaiting review ₱{{ entry.awaiting_review_amount }} ·
                        Disputed ₱{{ entry.disputed_amount }} · Balance ₱{{
                            entry.refund_balance
                        }}
                    </p>
                    <div
                        v-for="payment in refundPayments[entry.refund_id] ?? []"
                        :id="`refund-payment-${payment.id}`"
                        :key="payment.id"
                        class="border-border space-y-2 border p-3"
                    >
                        <p>
                            ₱{{ payment.amount }} ·
                            {{
                                paymentLabels[payment.payment_method] ??
                                payment.payment_method
                            }}
                            · {{ payment.external_reference }} ·
                            {{
                                payment.review?.decision === 'voided'
                                    ? 'Voided after dispute'
                                    : (payment.review?.decision ??
                                      'Awaiting review')
                            }}
                        </p>
                        <div
                            v-if="
                                page.props.auth.can?.viewOrders &&
                                paymentEvidence[payment.id]?.length
                            "
                            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <a
                                v-for="file in paymentEvidence[payment.id]"
                                :key="file.id"
                                :href="
                                    AdministrationEvidenceController.show(
                                        file.id,
                                    ).url
                                "
                                target="_blank"
                                rel="noopener noreferrer"
                                class="border-border bg-background text-primary space-y-2 border p-2 underline"
                                ><img
                                    v-if="
                                        [
                                            'image/jpeg',
                                            'image/png',
                                            'image/webp',
                                        ].includes(file.mime_type)
                                    "
                                    :src="
                                        AdministrationEvidenceController.show(
                                            file.id,
                                        ).url
                                    "
                                    :alt="`Payment proof: ${file.original_name}`"
                                    class="bg-muted max-h-56 w-full object-contain"
                                    loading="lazy"
                                /><span class="block">{{
                                    file.original_name
                                }}</span></a
                            >
                        </div>
                        <Form
                            v-if="
                                page.props.auth.can?.recordRefundPayments &&
                                payment.recorded_by ===
                                    page.props.auth.user.id &&
                                (paymentEvidence[payment.id]?.length ?? 0) < 5
                            "
                            v-bind="
                                AdministrationEvidenceController.store.form({
                                    subjectType: 'refund_payment',
                                    subjectId: payment.id,
                                })
                            "
                            v-slot="{ errors, processing }"
                            class="flex max-w-full min-w-0 flex-wrap items-end gap-2"
                            ><label
                                >Payment proof<input
                                    name="files[]"
                                    type="file"
                                    multiple
                                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    class="mt-1 block w-full max-w-full min-w-0" /></label
                            ><Button
                                class="min-h-11"
                                type="submit"
                                variant="outline"
                                :disabled="processing"
                                >Upload files</Button
                            ><InputError
                                :message="errors.files || errors['files.0']"
                        /></Form>
                        <p
                            v-if="payment.review?.reason"
                            class="text-muted-foreground"
                        >
                            Review: {{ payment.review.reason }}
                        </p>
                        <Form
                            v-if="
                                page.props.auth.can?.reconcileReturns &&
                                payment.recorded_by !==
                                    page.props.auth.user.id &&
                                !['verified', 'voided'].includes(
                                    payment.review?.decision,
                                )
                            "
                            v-bind="
                                CustomerReturnController.reviewPayment.form(
                                    payment.id,
                                )
                            "
                            v-slot="{ errors, processing }"
                            class="grid max-w-full min-w-0 gap-2 sm:grid-cols-2"
                        >
                            <select
                                aria-label="Decision"
                                name="decision"
                                class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <option value="verified">
                                    Verify payment record
                                </option>
                                <option value="disputed">
                                    Dispute payment record
                                </option>
                                <option
                                    v-if="
                                        payment.review?.decision === 'disputed'
                                    "
                                    value="voided"
                                >
                                    Void disputed payment and reverse amount
                                </option>
                            </select>
                            <Input
                                aria-label="Notes"
                                class="min-h-11"
                                name="reason"
                                required
                                minlength="5"
                                maxlength="2000"
                                placeholder="Review notes"
                            />
                            <Button
                                class="min-h-11"
                                type="submit"
                                :disabled="processing"
                                >Record review</Button
                            ><InputError
                                :message="errors.decision || errors.reason"
                            />
                        </Form>
                    </div>
                    <Form
                        v-if="
                            page.props.auth.can?.recordRefundPayments &&
                            entry.refund_has_outstanding_balance
                        "
                        v-bind="
                            CustomerReturnController.recordPayment.form(
                                entry.refund_id,
                            )
                        "
                        v-slot="{ errors, processing }"
                        class="grid max-w-full min-w-0 gap-2 sm:grid-cols-3"
                        ><Input
                            aria-label="Amount paid"
                            class="min-h-11"
                            name="amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                            :max="entry.refund_balance"
                            placeholder="Amount paid"
                            required /><select
                            aria-label="Payment method"
                            name="payment_method"
                            class="border-input bg-background focus-visible:ring-ring min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                            <option value="maya">Maya</option>
                            <option value="bank_transfer">Bank transfer</option>
                            <option value="other">Other</option></select
                        ><Input
                            aria-label="Receipt or reference"
                            class="min-h-11"
                            name="external_reference"
                            placeholder="Receipt or transfer reference"
                            required /><Button
                            class="min-h-11"
                            type="submit"
                            :disabled="processing"
                            >Record payment</Button
                        ><InputError
                            :message="
                                errors.amount || errors.external_reference
                            "
                    /></Form>
                </div>
            </article>
            <div
                v-if="returns.last_page > 1"
                class="flex justify-between text-sm"
            >
                <Link
                    v-if="returns.prev_page_url"
                    :href="returns.prev_page_url"
                    class="text-primary underline"
                    >Previous</Link
                ><span v-else></span
                ><Link
                    v-if="returns.next_page_url"
                    :href="returns.next_page_url"
                    class="text-primary underline"
                    >Next</Link
                >
            </div>
        </section>
    </main>
</template>
