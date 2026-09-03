<script setup>
import { SelectRoot, useForwardPropsEmits } from "reka-ui";
const props = defineProps(SelectRoot.props);
const emits = defineEmits(SelectRoot.emits);
const forwarded = useForwardPropsEmits(props, emits);
</script>

<template>
  <SelectRoot
    v-slot="slotProps"
    data-slot="select"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </SelectRoot>
</template>
