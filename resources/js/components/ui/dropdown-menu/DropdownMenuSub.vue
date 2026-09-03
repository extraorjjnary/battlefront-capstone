<script setup>
import { DropdownMenuSub, useForwardPropsEmits } from "reka-ui";
const props = defineProps(DropdownMenuSub.props);
const emits = defineEmits(DropdownMenuSub.emits);
const forwarded = useForwardPropsEmits(props, emits);
</script>

<template>
  <DropdownMenuSub v-slot="slotProps" data-slot="dropdown-menu-sub" v-bind="forwarded">
    <slot v-bind="slotProps" />
  </DropdownMenuSub>
</template>
