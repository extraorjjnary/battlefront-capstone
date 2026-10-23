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
        class="surface-panel border-border bg-card space-y-4 border p-5 sm:p-6"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h2 id="return-requests-heading" class="text-xl font-semibold">
                    Returns and refunds
                </h2>
                <p class="text-muted-foreground text-sm">
                    Requests are reviewed manually after Battlefront receives
                    the item. Submitting a request does not guarantee approval
                    or a refund.
                </p>
            </div>
            <Button
                v-if="order.can_request_return"
                type="button"
                @click="requestReturn"
                >Request Return/Refund</Button
            >
        </div>
        <ReturnInstructions
            v-if="awaitingReceipt && order.return_instructions"
            :instructions="order.return_instructions"
        />
        <div
            v-if="order.return_eligibility"
            class="border-border space-y-2 border-t pt-4 text-sm"
        >
            <p v-if="order.return_eligibility.received_at">
                Purchase received
                {{ formatDateTime(order.return_eligibility.received_at) }} ({{
                    order.return_eligibility.receipt_source === 'pickup'
                        ? 'pickup handover'
                        : 'delivery'
                }}).
            </p>
            <p v-else>
                Original receipt date is not recorded. Return eligibility needs
                manual confirmation.
            </p>
            <p v-if="!order.return_eligibility.window_approved">
                The proposed {{ order.return_eligibility.window_days }}-day
                discretionary window awaits store approval and is not enforced.
            </p>
            <p v-if="order.return_eligibility.deadline_at">
                {{
                    order.return_eligibility.window_approved
                        ? 'Discretionary deadline'
                        : 'Proposed discretionary deadline'
                }}: {{ formatDateTime(order.return_eligibility.deadline_at) }}.
            </p>
            <p
                v-if="
                    order.return_eligibility.window_approved &&
                    order.return_eligibility.deadline_passed
                "
            >
                The Changed mind window has expired. Defect and other remedy
                concerns can still be submitted for manual review.
            </p>
            <p
                v-else-if="
                    order.return_eligibility.window_approved &&
                    order.return_eligibility.remaining_seconds !== null
                "
            >
                {{
                    Math.ceil(order.return_eligibility.remaining_seconds / 3600)
                }}
                hours remaining in the discretionary window.
            </p>
            <p>
                Defect, damage, wrong-item, description, and Other claims
                receive manual assessment without an automatic refund guarantee.
            </p>
        </div>
        <p
            v-for="item in order.items"
            :key="`balance-${item.id}`"
            class="text-muted-foreground text-sm"
        >
            {{ item.product.name }}: {{ item.remaining_returnable ?? 0 }} of
            {{ item.quantity }} purchased units remain returnable, subject to
            eligibility.
        </p>
        <article
            v-for="entry in order.return_requests"
            :key="entry.id"
            class="border-border space-y-2 border-t pt-4"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold">
                        {{ entry.product.name }} · {{ entry.quantity }}
                        {{ entry.quantity === 1 ? 'unit' : 'units' }}
                    </h3>
                    <p class="text-sm font-medium">{{ entry.status.label }}</p>
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
                Return received by Battlefront
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
                    Customer resolution not recorded.
                </p>
                <template v-else>
                    <p>
                        {{ entry.resolution.type }} resolution:
                        {{ entry.resolution.status }}
                    </p>
                    <p
                        v-if="entry.resolution.summary"
                        class="whitespace-pre-wrap"
                    >
                        {{ entry.resolution.summary }}
                    </p>
                    <p v-if="entry.resolution.completed_at">
                        Fulfilled
                        {{ formatDateTime(entry.resolution.completed_at) }}
                    </p>
                </template>
            </div>
            <p
                v-if="entry.eligibility_path === 'manual_review'"
                class="text-muted-foreground text-sm"
            >
                Eligibility submitted for manual review.
            </p>
            <p v-if="entry.refund" class="text-sm">
                Refund approved.
                {{
                    entry.status.value === 'refunded'
                        ? 'Refund completed through confirmed payments.'
                        : entry.refund.payment_recorded
                          ? 'Payment recorded; completion requires confirmed payment covering the approved refund.'
                          : 'No active refund payment recorded.'
                }}
            </p>
            <p
                v-if="
                    entry.resolution?.type === 'refund' &&
                    entry.resolution.summary
                "
                class="text-sm whitespace-pre-wrap"
            >
                Refund explanation: {{ entry.resolution.summary }}
            </p>
            <dl v-if="entry.refund" class="grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">Approved refund</dt>
                    <dd>{{ formatCurrency(entry.refund.approved_amount) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        Confirmed refund payments
                    </dt>
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
                    <dt class="text-muted-foreground">
                        Awaiting confirmed payment
                    </dt>
                    <dd>{{ formatCurrency(entry.refund.remaining_amount) }}</dd>
                </div>
            </dl>
        </article>
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
                class="text-primary underline"
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

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{
                        confirming
                            ? 'Confirm return request'
                            : 'Request Return/Refund'
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
                                This request requires manual eligibility
                                assessment. Submission does not establish
                                entitlement to a refund or other remedy.
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="return-notes"
                                >Additional notes
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
