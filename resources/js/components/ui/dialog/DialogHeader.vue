<script setup>
import { cn } from "@/lib/utils";
const props = defineProps({
    "class": {},
});
</script>

<template>
  <div
    data-slot="dialog-header"
    :class="cn('flex flex-col gap-2 pr-8 text-center sm:text-left', props.class)"
  >
    <slot />
  </div>
</template>
