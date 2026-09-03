<script setup>
import { TooltipTrigger } from "reka-ui";
const props = defineProps(TooltipTrigger.props);
</script>

<template>
  <TooltipTrigger
    data-slot="tooltip-trigger"
    v-bind="props"
  >
    <slot />
  </TooltipTrigger>
</template>
