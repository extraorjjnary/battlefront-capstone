<script setup>
import { Deferred, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps({
    data: { type: [String, Array], required: true },
    label: { type: String, default: 'Loading content…' },
    errorMessage: {
        type: String,
        default: 'This content could not be loaded.',
    },
});
const keys = computed(() =>
    Array.isArray(props.data) ? props.data : [props.data],
);

function retry() {
    router.reload({ only: keys.value });
}
</script>

<template>
    <div class="contents">
        <Deferred :data="data">
            <template #fallback>
                <div class="contents" role="status">
                    <span class="sr-only">{{ label }}</span>
                    <slot name="fallback" />
                </div>
            </template>
            <template #rescue="{ reloading }">
                <div class="rounded-card border-border bg-card border p-5">
                    <p role="alert" class="text-destructive text-sm">
                        {{ errorMessage }}
                    </p>
                    <Button
                        class="mt-3"
                        variant="outline"
                        size="sm"
                        :disabled="reloading"
                        @click="retry"
                    >
                        {{ reloading ? 'Retrying…' : 'Retry' }}
                    </Button>
                </div>
            </template>
            <template #default="state">
                <slot v-bind="state" />
            </template>
        </Deferred>
    </div>
</template>
