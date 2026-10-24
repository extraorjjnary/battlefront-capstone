<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import FileInput from '@/components/FileInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency } from '@/lib/currency';
import { update } from '@/routes/administration/orders/cancellation-request';
import { store as recordPayment } from '@/routes/administration/customer-refunds/payments';
import { show as showRefundProof } from '@/routes/orders/refund-payments/proof';
import { show as showEvidence } from '@/routes/administration/evidence';

const props = defineProps({
    orderId: { type: Number, required: true },
    cancellation: { type: Object, required: true },
    paymentMethod: { type: String, default: 'gcash' },
    administration: { type: Boolean, default: false },
});
const review = useForm({
    decision: '',
    amount_received: '',
    decision_note: '',
    account_checked: false,
});
const payment = useForm({
    amount: '',
    payment_method: props.paymentMethod,
    external_reference: '',
    submission_key: null,
    payment_confirmed: false,
    proof: null,
});

watch(
    () => props.cancellation.payment_submission_key,
    (key) => {
        payment.submission_key = key;
        payment.amount = props.cancellation.refund?.unrecorded_amount ?? '';
    },
    { immediate: true },
);

function decide(decision) {
    if (
        !props.cancellation.can_review ||
        review.processing ||
        !review.account_checked
    )
        return;
    review.decision = decision;
    review.submit(update(props.orderId), { preserveScroll: true });
}

function submitPayment() {
    if (
        !props.cancellation.can_record_payment ||
        payment.processing ||
        !payment.payment_confirmed
    )
        return;
    payment.submit(recordPayment(props.cancellation.refund.id), {
        preserveScroll: true,
        onSuccess: () => {
            payment.reset('proof', 'external_reference', 'payment_confirmed');
        },
    });
}
</script>

<template>
    <section
        class="border-border bg-card space-y-4 border p-5"
        aria-labelledby="cancellation-heading"
    >
        <div>
            <h2 id="cancellation-heading" class="font-semibold">
                {{ cancellation.status.label }}
            </h2>
            <p
                v-if="cancellation.status.value === 'requested'"
                class="text-muted-foreground mt-1 text-sm"
            >
                Order handling is paused while Battlefront checks the actual
                wallet transaction.
            </p>
            <p v-if="cancellation.customer_note" class="mt-2 text-sm">
                {{ cancellation.customer_note }}
            </p>
            <p v-if="cancellation.decision_note" class="mt-2 text-sm">
                {{ cancellation.decision_note }}
            </p>
        </div>

        <div v-if="cancellation.refund" class="space-y-2 text-sm">
            <p>
                Refund amount:
                <strong>{{
                    formatCurrency(cancellation.refund.approved_amount)
                }}</strong>
            </p>
            <p>
                Confirmed refunded:
                {{ formatCurrency(cancellation.refund.confirmed_paid_amount) }}
            </p>
            <p>
                Remaining:
                {{ formatCurrency(cancellation.refund.outstanding_amount) }}
            </p>
            <p class="text-muted-foreground">
                Refunds are manual GCash/Maya transfers. Approval alone does not
                mean payment was sent.
            </p>
            <ul v-if="cancellation.refund.payments.length" class="space-y-2">
                <li v-for="item in cancellation.refund.payments" :key="item.id">
                    {{ formatCurrency(item.amount) }} ·
                    {{ item.payment_method.toUpperCase() }}
                    <a
                        v-if="item.proof"
                        :href="
                            administration
                                ? showEvidence.url(item.proof.id)
                                : showRefundProof.url({
                                      order: orderId,
                                      payment: item.id,
                                      evidence: item.proof.id,
                                  })
                        "
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary-ink ml-2 underline"
                        >View refund proof</a
                    >
                </li>
            </ul>
        </div>

        <form
            v-if="cancellation.can_review"
            class="space-y-4"
            @submit.prevent="decide('approve')"
        >
            <p class="text-muted-foreground text-sm">
                Check the GCash/Maya account transaction, not just the
                screenshot. Enter zero only when no payment was received. The
                refund is capped at the saved order total, including delivery.
            </p>
            <div class="space-y-2">
                <Label for="cancellation-received"
                    >Verified amount received (PHP)</Label
                >
                <Input
                    id="cancellation-received"
                    v-model="review.amount_received"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    :disabled="review.processing"
                />
            </div>
            <div class="space-y-2">
                <Label for="cancellation-note"
                    >Decision explanation (visible to customer)</Label
                >
                <Textarea
                    id="cancellation-note"
                    v-model="review.decision_note"
                    minlength="5"
                    maxlength="2000"
                    required
                    :disabled="review.processing"
                />
            </div>
            <div class="flex items-start gap-2">
                <Checkbox
                    id="cancellation-account-checked"
                    v-model="review.account_checked"
                    :disabled="review.processing"
                />
                <Label for="cancellation-account-checked"
                    >I checked the actual wallet account and confirmed the
                    amount received.</Label
                >
            </div>
            <InputError
                v-for="(error, field) in review.errors"
                :key="field"
                :message="error"
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="review.processing || !review.account_checked"
                    >Approve cancellation</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="review.processing || !review.account_checked"
                    @click="decide('reject')"
                    >Reject request</Button
                >
            </div>
        </form>

        <form
            v-if="cancellation.can_record_payment"
            class="space-y-4"
            @submit.prevent="submitPayment"
        >
            <h3 class="font-medium">Record an actual refund transfer</h3>
            <p class="text-muted-foreground text-sm">
                Confirm the recipient with the customer before sending. Record
                only after the transfer succeeds.
            </p>
            <div class="space-y-2">
                <Label for="cancellation-refund-amount"
                    >Amount sent (PHP)</Label
                >
                <Input
                    id="cancellation-refund-amount"
                    v-model="payment.amount"
                    type="number"
                    min="0.01"
                    :max="cancellation.refund.unrecorded_amount"
                    step="0.01"
                    required
                    :disabled="payment.processing"
                />
            </div>
            <div class="space-y-2">
                <Label for="cancellation-refund-method">Wallet</Label>
                <Select
                    v-model="payment.payment_method"
                    :disabled="payment.processing"
                >
                    <SelectTrigger id="cancellation-refund-method"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent
                        ><SelectItem value="gcash">GCash</SelectItem
                        ><SelectItem value="maya"
                            >Maya</SelectItem
                        ></SelectContent
                    >
                </Select>
            </div>
            <div class="space-y-2">
                <Label for="cancellation-refund-reference"
                    >Outgoing transaction reference</Label
                >
                <Input
                    id="cancellation-refund-reference"
                    v-model="payment.external_reference"
                    maxlength="255"
                    required
                    :disabled="payment.processing"
                />
            </div>
            <div class="space-y-2">
                <Label for="cancellation-refund-proof"
                    >Refund screenshot (visible to customer)</Label
                >
                <FileInput
                    id="cancellation-refund-proof"
                    accept=".jpg,.jpeg,.png,.webp"
                    required
                    :disabled="payment.processing"
                    @change="payment.proof = $event.target.files?.[0] ?? null"
                />
            </div>
            <div class="flex items-start gap-2">
                <Checkbox
                    id="cancellation-refund-confirmed"
                    v-model="payment.payment_confirmed"
                    :disabled="payment.processing"
                />
                <Label for="cancellation-refund-confirmed"
                    >I confirmed that the outgoing transfer succeeded.</Label
                >
            </div>
            <InputError
                v-for="(error, field) in payment.errors"
                :key="field"
                :message="error"
            />
            <Button
                type="submit"
                :disabled="
                    payment.processing ||
                    !payment.payment_confirmed ||
                    !payment.proof
                "
                >Confirm refund payment</Button
            >
        </form>
    </section>
</template>
