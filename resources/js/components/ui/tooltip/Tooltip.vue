<script setup>
import { TooltipRoot, useForwardPropsEmits } from "reka-ui";
const props = defineProps(TooltipRoot.props);
const emits = defineEmits(TooltipRoot.emits);
const forwarded = useForwardPropsEmits(props, emits);
</script>

<template>
  <TooltipRoot
    v-slot="slotProps"
    data-slot="tooltip"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </TooltipRoot>
</template>
