<script setup>
import { TooltipProvider } from "reka-ui";
const props = defineProps({ ...TooltipProvider.props, "delayDuration": { default: 0 }, });
</script>

<template>
  <TooltipProvider v-bind="props">
    <slot />
  </TooltipProvider>
</template>
