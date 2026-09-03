<script setup>
import { DialogRoot, useForwardPropsEmits } from "reka-ui";
const props = defineProps(DialogRoot.props);
const emits = defineEmits(DialogRoot.emits);
const forwarded = useForwardPropsEmits(props, emits);
</script>

<template>
  <DialogRoot
    v-slot="slotProps"
    data-slot="dialog"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </DialogRoot>
</template>
