<script setup>
import { Moon, Sun } from "@lucide/vue";
import { computed } from "vue";
import { Button } from "@/components/ui/button";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/components/ui/tooltip";
import { useAppearance } from "@/composables/useAppearance";

const { resolvedAppearance, updateAppearance } = useAppearance();
const label = computed(() =>
    resolvedAppearance.value === "dark"
        ? "Switch to light mode"
        : "Switch to dark mode",
);

function toggleAppearance() {
    updateAppearance(resolvedAppearance.value === "dark" ? "light" : "dark");
}
</script>

<template>
    <TooltipProvider :delay-duration="0">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    type="button"
                    role="switch"
                    variant="ghost"
                    size="icon"
                    class="appearance-toggle group h-9 w-12 rounded-full p-0 hover:bg-transparent focus-visible:ring-ring dark:hover:bg-transparent motion-reduce:transition-none cursor-pointer"
                    aria-label="Dark mode"
                    :aria-checked="resolvedAppearance === 'dark'"
                    @click="toggleAppearance"
                >
                    <span
                        class="relative h-6 w-10 rounded-full border border-input bg-muted transition-colors duration-200 group-hover:border-ring motion-reduce:transition-none"
                        aria-hidden="true"
                    >
                        <span
                            class="absolute top-1/2 left-0.5 flex size-4.5 -translate-y-1/2 items-center justify-center rounded-full bg-background text-foreground transition-transform duration-200 ease-in-out motion-reduce:transition-none"
                            :class="
                                resolvedAppearance === 'dark'
                                    ? 'translate-x-4'
                                    : 'translate-x-0'
                            "
                        >
                            <Moon
                                v-if="resolvedAppearance === 'dark'"
                                class="size-3"
                            />
                            <Sun v-else class="size-3" />
                        </span>
                    </span>
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{ label }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
