<script setup>
import { Button } from '@/components/ui/button';
defineProps({
    completion: Number,
    total: String,
    budget: [String, Number],
    budgetExceeded: Boolean,
    difference: String,
    missing: Array,
    checking: Boolean,
    validation: Object,
    error: String,
    statusLabel: Function,
});
defineEmits(['retry']);
</script>
<template>
    <div>
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">Build summary</h2>
            <span class="text-muted-foreground text-sm"
                >{{ completion }}% selected</span
            >
        </div>
        <div
            class="bg-muted mt-3 h-2 overflow-hidden rounded"
            role="progressbar"
            :aria-valuenow="completion"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="Required component progress"
        >
            <div
                class="bg-primary h-full transition-all motion-reduce:transition-none"
                :style="{ width: `${completion}%` }"
            ></div>
        </div>
        <p v-if="missing.length" class="text-muted-foreground mt-4 text-sm">
            Still needed: {{ missing.join(', ') }}
        </p>
        <div class="border-border my-5 border-y py-4">
            <p class="text-muted-foreground text-xs">Estimated total</p>
            <p class="mt-1 text-3xl font-bold tracking-tight">{{ total }}</p>
            <p v-if="budget !== '' && budget !== null" class="mt-2 text-sm">
                {{ difference }}
                {{ budgetExceeded ? 'over budget' : 'remaining in budget' }}
            </p>
        </div>
        <div aria-live="polite" :aria-busy="checking" class="mb-5">
            <p v-if="checking" class="text-muted-foreground text-sm">
                Checking compatibility…
            </p>
            <div v-else-if="error">
                <p class="text-destructive text-sm">{{ error }}</p>
                <Button
                    size="sm"
                    variant="outline"
                    class="mt-2"
                    @click="$emit('retry')"
                    >Check again</Button
                >
            </div>
            <template v-else-if="validation">
                <p class="font-semibold">
                    {{ statusLabel(validation.status) }}
                </p>
                <p
                    v-if="validation.estimated_power_w !== null"
                    class="text-muted-foreground mt-1 text-sm"
                >
                    Estimated component draw:
                    {{ validation.estimated_power_w }} W
                </p>
                <details v-if="validation.findings.length" class="mt-3 text-sm">
                    <summary class="cursor-pointer font-medium">
                        View compatibility details
                    </summary>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="finding in validation.findings"
                            :key="`${finding.rule}-${finding.component_keys.join('-')}`"
                        >
                            <strong>{{ statusLabel(finding.status) }}:</strong>
                            {{ finding.message }}
                        </li>
                    </ul>
                </details>
            </template>
            <p v-else class="text-muted-foreground text-sm">
                Select components to check compatibility.
            </p>
        </div>
        <p class="text-muted-foreground text-xs">
            Saving does not reserve stock. Prices, availability and
            compatibility are checked again before checkout.
        </p>
        <slot />
    </div>
</template>
