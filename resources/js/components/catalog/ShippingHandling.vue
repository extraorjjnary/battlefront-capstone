<script setup>
import { formatCurrency } from '@/lib/currency';

defineProps({
    handling: { type: Object, required: true },
    showPreparation: { type: Boolean, default: true },
});
</script>

<template>
    <section aria-label="Shipping handling" class="space-y-2 text-sm">
        <h2 class="font-semibold">Shipping handling · {{ handling.label }}</h2>
        <p class="text-muted-foreground">
            {{ formatCurrency(handling.surcharge) }}
            additional surcharge
            <template v-if="showPreparation">
                · {{ handling.preparation_days }}
                {{ handling.preparation_days === 1 ? 'day' : 'days' }}
                preparation on configured delivery routes.
            </template>
        </p>
        <p class="text-muted-foreground">
            The surcharge applies once per delivery order, not per item.
        </p>
    </section>
</template>
