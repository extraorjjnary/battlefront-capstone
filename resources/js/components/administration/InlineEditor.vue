<script setup>
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

defineProps({
    label: { type: String, required: true },
    triggerLabel: { type: String, default: null },
});

const open = ref(false);
function reveal() {
    open.value = true;
}
</script>

<template>
    <Collapsible
        v-model:open="open"
        class="min-w-0"
        :class="{ 'w-full': open }"
    >
        <CollapsibleTrigger as-child>
            <Button
                type="button"
                variant="outline"
                class="min-h-11"
                :aria-label="triggerLabel ?? label"
            >
                {{ label }}
                <ChevronDown
                    class="size-4 transition-transform motion-reduce:transition-none"
                    :class="{ 'rotate-180': open }"
                    aria-hidden="true"
                />
            </Button>
        </CollapsibleTrigger>
        <CollapsibleContent
            force-mount
            :hidden="!open"
            class="border-border mt-3 border-t pt-4 data-[state=closed]:hidden"
            @invalid.capture="reveal"
        >
            <slot :reveal="reveal" />
        </CollapsibleContent>
    </Collapsible>
</template>
