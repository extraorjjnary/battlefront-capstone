<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    store,
    withdraw,
} from '@/actions/App/Http/Controllers/CustomerReturnRequestController';
import InputError from '@/components/InputError.vue';
import ReturnInstructions from '@/components/ReturnInstructions.vue';
import { show as showRefundProof } from '@/routes/orders/refund-payments/proof';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency } from '@/lib/currency';
import { formatDateTime } from '@/lib/dateTime';

const props = defineProps({ order: { type: Object, required: true } });
const open = ref(false);
const confirming = ref(false);
const withdrawing = ref(null);
const form = useForm({
    order_item_id: '',
    quantity: 1,
    reason_code: '',
    reason: '',
});
const withdrawalForm = useForm({});
const refundPayments = computed(
    () =>
        props.order.refund_payments ??
        props.order.return_requests?.flatMap(
            (entry) => entry.refund?.payments ?? [],
        ) ??
        [],
);
const awaitingReceipt = computed(() =>
    props.order.return_requests?.some(
        (entry) => entry.status.value === 'submitted',
    ),
);
const eligibleItems = computed(() =>
    props.order.items.filter((item) => item.can_request),
);
const selectedItem = computed(() =>
    eligibleItems.value.find((item) => item.id === Number(form.order_item_id)),
);

const outcomeLabels = {
    repair: 'Repair',
    replacement: 'Replacement',
    other: 'Other outcome',
};
const outcomeStatusLabels = { pending: 'Pending', completed: 'Completed' };

function requestReturn() {
    if (!props.order.can_request_return || form.processing) return;
    form.reset();
    form.clearErrors();
    confirming.value = false;
    open.value = true;
}

function submit() {
    if (!confirming.value || form.processing || !props.order.can_request_return)
        return;
    form.submit(store(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
        onError: () => {
            confirming.value = false;
        },
    });
}

function submitWithdrawal() {
    if (!withdrawing.value?.can_withdraw || withdrawalForm.processing) return;
    withdrawalForm.submit(
        withdraw({
            order: props.order.id,
            returnRequest: withdrawing.value.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                withdrawing.value = null;
            },
        },
    );
}
</script>

<template>
    <section
        v-if="
            order.can_request_return ||
            order.return_requests?.length ||
            refundPayments.length
        "
        aria-labelledby="return-requests-heading"
        class="min-w-0 space-y-4"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h2 id="return-requests-heading" class="text-xl font-semibold">
                    Returns and refunds
                </h2>
            </div>
            <Button
                v-if="order.can_request_return"
                type="button"
                @click="requestReturn"
                >Request a return</Button
            >
        </div>
        <p class="text-muted-foreground text-sm">
            We review requests after receiving the item. Approval and refunds
            are not guaranteed.
        </p>
        <ReturnInstructions
            v-if="awaitingReceipt && order.return_instructions"
            :instructions="order.return_instructions"
        />
        <p
            v-if="
                order.return_eligibility?.window_approved &&
                order.return_eligibility.deadline_passed
            "
            class="text-muted-foreground text-sm"
        >
            The change-of-mind period has ended. Other concerns can still be
            submitted for review.
        </p>
        <details class="border-border border-t pt-3 text-sm">
            <summary
                class="focus-visible:ring-ring min-h-11 cursor-pointer content-center font-medium focus-visible:ring-2 focus-visible:outline-none"
            >
                Return details
            </summary>
            <div
                v-if="order.return_eligibility"
                class="text-muted-foreground mt-2 space-y-2"
            >
                <p v-if="order.return_eligibility.received_at">
                    Received
                    {{ formatDateTime(order.return_eligibility.received_at) }}
                    ({{
                        order.return_eligibility.receipt_source === 'pickup'
                            ? 'pickup'
                            : 'delivery'
                    }}).
                </p>
                <p v-else>
                    Receipt date unconfirmed. We will review eligibility.
                </p>
                <p v-if="!order.return_eligibility.window_approved">
                    The proposed {{ order.return_eligibility.window_days }}-day
                    change-of-mind limit awaits store approval and is not
                    active.
                </p>
                <p v-if="order.return_eligibility.deadline_at">
                    {{
                        order.return_eligibility.window_approved
                            ? 'Change-of-mind deadline'
                            : 'Proposed change-of-mind deadline'
                    }}:
                    {{ formatDateTime(order.return_eligibility.deadline_at) }}.
                </p>

                <p
                    v-if="
                        order.return_eligibility.window_approved &&
                        !order.return_eligibility.deadline_passed &&
                        order.return_eligibility.remaining_seconds !== null
                    "
                >
                    {{
                        Math.ceil(
                            order.return_eligibility.remaining_seconds / 3600,
                        )
                    }}
                    hours left for change-of-mind requests.
                </p>
                <p>
                    Faults, damage, wrong items, description issues, and other
                    concerns are reviewed individually.
                </p>
            </div>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <p
                    v-for="item in order.items"
                    :key="`balance-${item.id}`"
                    class="text-muted-foreground text-sm"
                >
                    {{ item.product.name }}:
                    {{ item.remaining_returnable ?? 0 }} of
                    {{ item.quantity }} units available to request, subject to
                    review.
                </p>
            </div>
        </details>
        <article
            v-for="entry in order.return_requests"
            :key="entry.id"
            class="surface-card border-border bg-card min-w-0 space-y-3 border p-4 sm:p-5"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold">
                        {{ entry.product.name }} · {{ entry.quantity }}
                        {{ entry.quantity === 1 ? 'unit' : 'units' }}
                    </h3>
                    <p class="text-muted-foreground mt-1 text-sm font-medium">
                        {{ entry.status.label }}
                    </p>
                </div>
                <Button
                    v-if="entry.can_withdraw"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="withdrawalForm.processing"
                    @click="
                        withdrawalForm.clearErrors();
                        withdrawing = entry;
                    "
                    >Withdraw request</Button
                >
            </div>
            <p class="text-muted-foreground text-sm">
                Requested {{ formatDateTime(entry.submitted_at) }} ·
                {{ entry.reason_label }}
            </p>
            <p
                v-if="entry.reason"
                class="text-sm wrap-anywhere whitespace-pre-wrap"
            >
                {{ entry.reason }}
            </p>
            <p v-if="entry.received_at" class="text-muted-foreground text-sm">
                Item received
                {{ formatDateTime(entry.received_at) }}
            </p>
            <p v-if="entry.feedback" class="text-sm">{{ entry.feedback }}</p>
            <p
                v-if="entry.return_decision === 'accepted'"
                class="text-sm font-medium"
            >
                Return accepted
            </p>
            <div
                v-if="entry.resolution && entry.resolution.type !== 'refund'"
                class="space-y-1 text-sm"
            >
                <p v-if="entry.resolution.type === 'unknown'">
                    Outcome not recorded. Contact Battlefront for an update.
                </p>
                <template v-else>
                    <p>
                        {{
                            outcomeLabels[entry.resolution.type] ??
                            entry.resolution.type
                        }}:
                        {{
                            outcomeStatusLabels[entry.resolution.status] ??
                            entry.resolution.status
                        }}
                    </p>
                    <p
                        v-if="entry.resolution.summary"
                        class="whitespace-pre-wrap"
                    >
                        {{ entry.resolution.summary }}
                    </p>
                    <p v-if="entry.resolution.completed_at">
                        Completed
                        {{ formatDateTime(entry.resolution.completed_at) }}
                    </p>
                </template>
            </div>
            <p
                v-if="entry.eligibility_path === 'manual_review'"
                class="text-muted-foreground text-sm"
            >
                Eligibility needs review.
            </p>
            <p v-if="entry.refund" class="text-sm">
                {{
                    entry.status.value === 'refunded'
                        ? 'Refund completed through confirmed payments.'
                        : entry.refund.payment_recorded
                          ? 'Payment recorded. Awaiting full payment confirmation.'
                          : 'No refund payment recorded yet.'
                }}
            </p>
            <p
                v-if="
                    entry.resolution?.type === 'refund' &&
                    entry.resolution.summary
                "
                class="text-sm whitespace-pre-wrap"
            >
                Refund note: {{ entry.resolution.summary }}
            </p>
            <dl
                v-if="entry.refund"
                class="border-border grid gap-3 border-t pt-3 text-sm sm:grid-cols-3"
            >
                <div>
                    <dt class="text-muted-foreground">Approved</dt>
                    <dd>{{ formatCurrency(entry.refund.approved_amount) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Confirmed paid</dt>
                    <dd>
                        {{
                            formatCurrency(
                                entry.refund.confirmed_paid_amount ??
                                    entry.refund.verified_paid_amount,
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Awaiting confirmation</dt>
                    <dd>{{ formatCurrency(entry.refund.remaining_amount) }}</dd>
                </div>
            </dl>
        </article>
        <div
            v-if="refundPayments.length"
            class="border-border space-y-3 border-t pt-3"
        >
            <h3 class="font-semibold">Refund payments</h3>
            <div
                v-for="payment in refundPayments"
                :key="payment.id"
                class="space-y-1 text-sm"
            >
                <p>
                    {{
                        payment.payment_method === 'cash'
                            ? 'Cash refund confirmed'
                            : 'Refund payment confirmed'
                    }}
                    · {{ formatCurrency(payment.amount) }} ·
                    {{ formatDateTime(payment.paid_at) }}
                </p>
                <a
                    v-if="payment.proof"
                    :href="
                        showRefundProof({
                            order: order.id,
                            payment: payment.id,
                            evidence: payment.proof.id,
                        }).url
                    "
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary-ink inline-flex min-h-11 items-center underline underline-offset-4"
                    >View payment proof</a
                >
                <p
                    v-else-if="
                        payment.payment_method !== 'cash' &&
                        payment.confirmation_method === 'approver'
                    "
                >
                    Payment proof is unavailable. Contact Battlefront for
                    assistance.
                </p>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{
                        confirming
                            ? 'Confirm return request'
                            : 'Request a return'
                    }}</DialogTitle>
                    <DialogDescription
                        >Order {{ order.reference }}. Select one purchased item
                        per request.</DialogDescription
                    >
                </DialogHeader>
                <ReturnInstructions
                    v-if="order.return_instructions"
                    :instructions="order.return_instructions"
                />
                <form
                    class="space-y-4"
                    @submit.prevent="
                        confirming ? submit() : (confirming = true)
                    "
                >
                    <fieldset
                        v-if="!confirming"
                        class="space-y-4"
                        :disabled="form.processing"
                    >
                        <div class="space-y-2">
                            <Label for="return-item">Purchased item</Label>
                            <select
                                id="return-item"
                                v-model="form.order_item_id"
                                required
                                class="border-input bg-background focus-visible:ring-ring min-h-11 w-full min-w-0 rounded-md border px-3 py-2 focus-visible:ring-2"
                                @change="form.quantity = 1"
                            >
                                <option disabled value="">
                                    Select an item
                                </option>
                                <option
                                    v-for="item in eligibleItems"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.product.name }} ({{
                                        item.remaining_returnable
                                    }}
                                    eligible)
                                </option>
                            </select>
                            <InputError :message="form.errors.order_item_id" />
                        </div>
                        <div class="space-y-2">
                            <Label for="return-quantity">Quantity</Label>
                            <Input
                                id="return-quantity"
                                v-model="form.quantity"
                                type="number"
                                min="1"
                                :max="selectedItem?.remaining_returnable ?? 1"
                                step="1"
                                required
                            />
                            <InputError :message="form.errors.quantity" />
                        </div>
                        <div class="space-y-2">
                            <Label for="return-reason">Reason</Label>
                            <select
                                id="return-reason"
                                v-model="form.reason_code"
                                required
                                class="border-input bg-background focus-visible:ring-ring min-h-11 w-full rounded-md border px-3 py-2 focus-visible:ring-2"
                            >
                                <option disabled value="">
                                    Select a reason
                                </option>
                                <option
                                    v-for="(
                                        label, code
                                    ) in order.return_reason_options"
                                    :key="code"
                                    :value="code"
                                    :disabled="
                                        order.return_eligibility?.reasons[code]
                                            ?.can_request === false
                                    "
                                >
                                    {{ label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.reason_code" />
                            <p
                                v-if="
                                    order.return_eligibility?.reasons[
                                        form.reason_code
                                    ]?.manual_review
                                "
                                class="text-muted-foreground text-sm"
                            >
                                We will review eligibility. Approval, refunds,
                                and other outcomes are not guaranteed.
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="return-notes"
                                >Notes
                                {{
                                    form.reason_code === 'other'
                                        ? '(required)'
                                        : '(optional)'
                                }}</Label
                            >
                            <textarea
                                id="return-notes"
                                v-model="form.reason"
                                :required="form.reason_code === 'other'"
                                minlength="5"
                                maxlength="2000"
                                rows="3"
                                class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2"
                            />
                            <InputError :message="form.errors.reason" />
                        </div>
                    </fieldset>
                    <div v-else class="space-y-3 text-sm">
                        <p>
                            <strong>{{ selectedItem?.product.name }}</strong> ·
                            {{ form.quantity }}
                            {{ Number(form.quantity) === 1 ? 'unit' : 'units' }}
                        </p>
                        <p>
                            {{ order.return_reason_options[form.reason_code] }}
                        </p>
                        <p
                            v-if="form.reason"
                            class="wrap-anywhere whitespace-pre-wrap"
                        >
                            {{ form.reason }}
                        </p>
                        <p>
                            Battlefront must receive and inspect the item before
                            deciding your request. A refund is completed only
                            after payment confirmation.
                        </p>
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="form.processing"
                                >Cancel</Button
                            ></DialogClose
                        >
                        <Button
                            v-if="confirming"
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="confirming = false"
                            >Back</Button
                        >
                        <Button type="submit" :disabled="form.processing"
                            ><Spinner v-if="form.processing" />{{
                                confirming
                                    ? 'Confirm and submit'
                                    : 'Review request'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="withdrawing !== null"
            @update:open="
                (value) => {
                    if (!value) withdrawing = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>Withdraw this request?</DialogTitle
                    ><DialogDescription
                        >The item has not been received. This request will
                        remain in your history.</DialogDescription
                    ></DialogHeader
                >
                <InputError :message="withdrawalForm.errors.return_request" />
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="withdrawalForm.processing"
                        @click="withdrawing = null"
                        >Keep request</Button
                    >
                    <Button
                        type="button"
                        :disabled="withdrawalForm.processing"
                        @click="submitWithdrawal"
                        ><Spinner v-if="withdrawalForm.processing" />Confirm
                        withdrawal</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
