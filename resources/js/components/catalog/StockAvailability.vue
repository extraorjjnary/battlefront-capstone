<script setup>
import { CheckCircle2, CircleX, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps({
    inventory: { type: Object, required: true },
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
};

const state = computed(
    () => states[props.inventory.status] ?? states.out_of_stock,
);
</script>

<template>
    <span
        class="inline-flex w-fit items-center gap-2 border px-2.5 py-1.5 text-xs font-semibold"
        :class="state.class"
    >
        <component :is="state.icon" class="size-3.5" aria-hidden="true" />
        {{ state.label }}
    </span>
</template>
