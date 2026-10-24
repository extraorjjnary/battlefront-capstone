<script setup>
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    maximum: { type: String, required: true },
    monetary: { type: Boolean, required: true },
    errors: { type: Object, default: () => ({}) },
});
const partial = ref(false);
const amount = ref(props.maximum);
watch(
    () => [props.maximum, props.monetary],
    () => {
        partial.value = false;
        amount.value = props.maximum;
    },
);
const approvedAmount = computed(() =>
    props.monetary ? (partial.value ? amount.value : props.maximum) : '0.00',
);
</script>

<template>
    <div class="space-y-2">
        <input type="hidden" name="approved_amount" :value="approvedAmount" />
        <Label>Refund amount (₱)</Label>
        <Input
            v-if="monetary && partial"
            v-model="amount"
            aria-label="Partial refund amount"
            type="text"
            inputmode="decimal"
            pattern="[0-9]+([.][0-9]{1,2})?"
            required
        />
        <Input
            v-else
            aria-label="Calculated refund amount"
            :model-value="approvedAmount"
            readonly
        />
        <InputError :message="errors.approved_amount" />
        <template v-if="monetary">
            <p class="text-muted-foreground text-sm">
                Item value: ₱{{ maximum }}. Shipping is not included.
            </p>
            <label class="flex items-center gap-2 text-sm"
                ><input v-model="partial" type="checkbox" />Partial
                refund</label
            >
            <template v-if="partial">
                <Label>Reason shown to customer</Label>
                <Input
                    name="resolution_summary"
                    aria-label="Partial refund explanation"
                    required
                    minlength="5"
                    maxlength="2000"
                />
                <InputError
                    :message="errors.resolution_summary || errors.summary"
                />
            </template>
        </template>
        <p v-else class="text-muted-foreground text-sm">
            No refund for this outcome.
        </p>
        <p class="text-muted-foreground text-sm">
            Approval does not make a payment or complete the outcome.
        </p>
    </div>
</template>
