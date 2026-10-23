<script setup>
import { Search, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    isSearching: { type: Boolean, default: false },
    maxlength: { type: Number, default: 100 },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'clear']);
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <div class="relative">
            <Search
                class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                aria-hidden="true"
            />
            <Input
                :id="id"
                :model-value="modelValue"
                class="pl-9"
                :class="{ 'min-h-11': compact }"
                :maxlength="maxlength"
                :placeholder="placeholder"
                autocomplete="off"
                @update:model-value="emit('update:modelValue', $event)"
            />
        </div>
        <p
            :class="
                compact && !isSearching
                    ? 'sr-only'
                    : 'text-muted-foreground flex min-h-5 items-center gap-1 text-xs'
            "
            aria-live="polite"
        >
            <Spinner v-if="isSearching" class="size-3" />
            {{
                isSearching
                    ? 'Updating results...'
                    : compact
                      ? ''
                      : 'Results update automatically as you type.'
            }}
        </p>
        <Button
            v-if="modelValue.trim()"
            type="button"
            variant="outline"
            class="w-fit"
            @click="emit('clear')"
        >
            <X aria-hidden="true" />
            Clear search
        </Button>
    </div>
</template>
