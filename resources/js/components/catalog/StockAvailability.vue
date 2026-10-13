<script setup>
import { CheckCircle2, CircleX, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    inventory: { type: Object, default: null },
});

const states = {
    in_stock: {
        label: 'In stock',
        icon: CheckCircle2,
        class: 'border-primary/40 bg-primary/10 text-foreground',
    },
    low_stock: {
        label: 'Low stock',
        icon: TriangleAlert,
        class: 'border-border bg-secondary text-foreground',
    },
    out_of_stock: {
        label: 'Out of stock',
        icon: CircleX,
        class: 'border-border bg-muted text-muted-foreground',
    },
    unavailable: {
        label: 'Stock unavailable',
        icon: CircleX,
        class: 'border-border bg-muted text-muted-foreground',
    },
};

const state = computed(() => {
    const quantity = props.inventory?.quantity;
    if (!Number.isInteger(quantity) || quantity < 0) {
        return states.unavailable;
    }
    if (quantity === 0) {
        return states.out_of_stock;
    }
    return states[props.inventory?.status] ?? states.unavailable;
});
const quantityLabel = computed(() => {
    if (state.value === states.in_stock) {
        return `${props.inventory.quantity} available`;
    }
    if (state.value === states.low_stock) {
        return `Only ${props.inventory.quantity} left`;
    }
    return null;
});
</script>

<template>
    <span
        class="inline-flex w-fit max-w-full items-center gap-2 border px-2.5 py-1.5 text-xs font-semibold"
        :class="state.class"
    >
        <component :is="state.icon" class="size-3.5 shrink-0" aria-hidden="true" />
        <span class="min-w-0 wrap-anywhere">{{ state.label }}<template v-if="quantityLabel"> · {{ quantityLabel }}</template></span>
    </span>
</template>
