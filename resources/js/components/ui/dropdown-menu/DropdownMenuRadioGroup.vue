<script setup>
import { DropdownMenuRadioGroup, useForwardPropsEmits } from "reka-ui";
const props = defineProps(DropdownMenuRadioGroup.props);
const emits = defineEmits(DropdownMenuRadioGroup.emits);
const forwarded = useForwardPropsEmits(props, emits);
</script>

<template>
  <DropdownMenuRadioGroup
    data-slot="dropdown-menu-radio-group"
    v-bind="forwarded"
  >
    <slot />
  </DropdownMenuRadioGroup>
</template>
