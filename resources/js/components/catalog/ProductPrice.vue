<script setup>
import { formatCurrency } from '@/lib/currency';

defineProps({
    price: { type: String, required: true },
    discountPrice: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span
            v-if="discountPrice"
            class="text-muted-foreground text-sm line-through"
        >
            {{ formatCurrency(price) }}
        </span>
        <span class="text-xl font-bold tracking-tight">
            {{ formatCurrency(discountPrice ?? price) }}
        </span>
    </div>
</template>
