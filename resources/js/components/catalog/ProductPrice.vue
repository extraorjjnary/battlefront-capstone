<script setup>
import { formatCurrency } from '@/lib/currency';

defineProps({
    price: { type: String, required: true },
    discountPrice: { type: String, default: null },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
        <span
            v-if="discountPrice"
            class="text-muted-foreground max-w-full text-xs wrap-anywhere line-through sm:text-sm"
        >
            {{ formatCurrency(price) }}
        </span>
        <span
            class="max-w-full font-bold tracking-tight wrap-anywhere tabular-nums"
            :class="compact ? 'text-base sm:text-xl' : 'text-xl'"
        >
            {{ formatCurrency(discountPrice ?? price) }}
        </span>
    </div>
</template>
