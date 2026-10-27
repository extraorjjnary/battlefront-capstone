<script setup>
import { Button } from '@/components/ui/button';
defineProps({
    label: String,
    required: Boolean,
    items: Array,
    disabled: Boolean,
});
defineEmits(['select', 'remove']);
</script>

<template>
    <section
        class="border-border grid gap-4 border-b py-5 last:border-0 sm:grid-cols-[10rem_minmax(0,1fr)_auto] sm:items-start"
    >
        <h3 class="font-semibold">
            {{ label }}
            <span v-if="required" class="text-primary-ink" aria-label="required"
                >*</span
            >
        </h3>
        <div class="min-w-0">
            <p v-if="!items.length" class="text-muted-foreground text-sm">
                No {{ label.toLowerCase() }} selected
            </p>
            <ul v-else class="space-y-3">
                <li
                    v-for="item in items"
                    :key="item.key"
                    class="flex items-start gap-3"
                >
                    <img
                        v-if="item.image_url"
                        :src="item.image_url"
                        alt=""
                        class="h-14 w-14 shrink-0 object-contain"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ item.name }}</p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ item.quantity }} × {{ item.formattedPrice }}
                        </p>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ item.status }}
                        </p>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="disabled"
                            :aria-label="`Remove ${item.name}`"
                            @click="$emit('remove', item.product_id)"
                            >Remove</Button
                        >
                    </div>
                </li>
            </ul>
        </div>
        <Button
            variant="outline"
            :disabled="disabled"
            :aria-label="`${items.length ? 'Change' : 'Select'} ${label}`"
            @click="$emit('select', $event)"
            >{{ items.length ? 'Change / add' : 'Select part' }}</Button
        >
    </section>
</template>
