<script setup>
defineProps({
    price: { type: String, required: true },
    discountPrice: { type: String, default: null },
});

const priceFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
});

const formatPrice = (value) => priceFormatter.format(Number(value));
</script>

<template>
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span
            v-if="discountPrice"
            class="text-muted-foreground text-sm line-through"
        >
            {{ formatPrice(price) }}
        </span>
        <span class="text-xl font-bold tracking-tight">
            {{ formatPrice(discountPrice ?? price) }}
        </span>
    </div>
</template>
