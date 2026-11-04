<script setup>
import { useForm, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';
import OrderPaymentReconciliationController from '@/actions/App/Http/Controllers/Administration/OrderPaymentReconciliationController';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
import { formatDateTime } from '@/lib/dateTime';

const props = defineProps({
    orderId: { type: Number, required: true },
    payment: { type: Object, required: true },
});
const form = useForm({});
const { start, stop } = usePoll(10000, { only: ['order'], preserveScroll: true }, { autoStart: false });
watch(() => props.payment.state, (state) => {
    if (['paid', 'closed'].includes(state)) stop();
    else start();
}, { immediate: true });

function reconcile() {
    if (!props.payment.can_reconcile || form.processing) return;
    form.submit(OrderPaymentReconciliationController(props.orderId), { preserveScroll: true });
}
</script>

<template>
    <section class="border-border mt-5 border-t pt-5" aria-label="Online payment recovery">
        <h3 class="text-sm font-semibold">{{ payment.label }}</h3>
        <p class="text-muted-foreground mt-2 text-sm">{{ payment.guidance }}</p>
        <dl class="mt-3 grid gap-2 text-sm">
            <div><dt class="text-muted-foreground">Payment deadline</dt><dd>{{ formatDateTime(payment.merchant_cutoff_at) }}</dd></div>
            <div><dt class="text-muted-foreground">Last confirmed check</dt><dd>{{ payment.last_checked_at ? formatDateTime(payment.last_checked_at) : 'Not checked yet' }}</dd></div>
            <div v-if="payment.next_check_at && !['paid', 'closed'].includes(payment.state)"><dt class="text-muted-foreground">Next automatic check</dt><dd>{{ formatDateTime(payment.next_check_at) }}</dd></div>
            <div><dt class="text-muted-foreground">Stock</dt><dd>{{ payment.stock_disposition }}</dd></div>
        </dl>
        <Button v-if="payment.can_reconcile" class="mt-3" variant="outline" :disabled="form.processing" @click="reconcile">
            {{ form.processing ? 'Requesting check...' : 'Reconcile payment' }}
        </Button>
        <InputError :message="form.errors.payment" />
        <details v-if="payment.history?.length" class="mt-4 text-sm">
            <summary class="cursor-pointer font-medium">Payment history</summary>
            <ol class="mt-2 space-y-3">
                <li v-for="flow in payment.history" :key="flow.id">
                    <p>{{ flow.outcome }} - {{ formatDateTime(flow.created_at) }}</p>
                    <p class="text-muted-foreground break-all">{{ flow.provider_reference || 'Provider reference not confirmed' }}</p>
                    <p v-if="flow.payment_id" class="text-muted-foreground break-all">{{ flow.payment_id }}</p>
                    <ul v-if="flow.events?.length" class="mt-2 space-y-1">
                        <li v-for="event in flow.events" :key="event.id">
                            {{ event.label }} - {{ formatDateTime(event.at) }}
                            <span v-if="event.reference" class="block break-all">{{ event.reference }}</span>
                        </li>
                    </ul>
                </li>
            </ol>
        </details>
    </section>
</template>
