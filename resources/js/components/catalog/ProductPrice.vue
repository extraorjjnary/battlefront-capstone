<script setup>
import { formatCurrency } from '@/lib/currency';

defineProps({
    price: { type: String, required: true },
    discountPrice: { type: String, default: null },
    compact: { type: Boolean, default: false },
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
        <span
            class="max-w-full font-bold tracking-tight wrap-anywhere"
            :class="compact ? 'text-sm sm:text-xl' : 'text-xl'"
        >
            {{ formatCurrency(discountPrice ?? price) }}
        </span>
    </div>
</template>
