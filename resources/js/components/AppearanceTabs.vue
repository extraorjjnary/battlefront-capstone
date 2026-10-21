<script setup>
import { Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
const { appearance, updateAppearance } = useAppearance();
const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
];
</script>

<template>
    <div class="bg-muted inline-flex max-w-full flex-wrap gap-1 rounded-lg p-1">
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :aria-pressed="appearance === value"
            @click="updateAppearance(value)"
            :class="[
                'focus-visible:ring-ring flex min-h-11 items-center rounded-md px-3 py-1.5 transition-colors focus-visible:ring-2 focus-visible:outline-none md:min-h-8 md:px-3.5',
                appearance === value
                    ? 'bg-card text-foreground shadow-xs'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            ]"
        >
            <component :is="Icon" class="-ml-1 h-4 w-4" />
            <span class="ml-1.5 text-sm">{{ label }}</span>
        </button>
    </div>
</template>
