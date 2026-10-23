<script setup>
import { computed } from 'vue';

const props = defineProps({
    currentQuantity: { type: Number, required: true },
    requestedQuantity: { type: [Number, String], default: '' },
});

const requested = computed(() => {
    const value = Number(props.requestedQuantity);
    return props.requestedQuantity !== '' &&
        Number.isInteger(value) &&
        value >= 0
        ? value
        : null;
});
const difference = computed(() => {
    if (requested.value === null) {
        return '—';
    }
    const delta = requested.value - props.currentQuantity;
    return `${delta > 0 ? '+' : delta < 0 ? '−' : ''}${Math.abs(delta)} units`;
});
</script>

<template>
    <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm" aria-live="polite">
        <div>
            <dt class="text-muted-foreground">Current</dt>
            <dd>{{ currentQuantity }} units</dd>
        </div>
        <div>
            <dt class="text-muted-foreground">Requested</dt>
            <dd>{{ requested === null ? '—' : `${requested} units` }}</dd>
        </div>
        <div>
            <dt class="text-muted-foreground">Difference</dt>
            <dd>{{ difference }}</dd>
        </div>
    </dl>
</template>
