<script setup>
import { CollapsibleRoot, useForwardPropsEmits } from "reka-ui";
const props = defineProps(CollapsibleRoot.props);
const emits = defineEmits(CollapsibleRoot.emits);
const forwarded = useForwardPropsEmits(props, emits);
</script>

<template>
  <CollapsibleRoot
    v-slot="slotProps"
    data-slot="collapsible"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </CollapsibleRoot>
</template>
