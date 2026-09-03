<script setup>
import { DropdownMenuTrigger, useForwardProps } from "reka-ui";
const props = defineProps(DropdownMenuTrigger.props);
const forwardedProps = useForwardProps(props);
</script>

<template>
  <DropdownMenuTrigger
    data-slot="dropdown-menu-trigger"
    v-bind="forwardedProps"
  >
    <slot />
  </DropdownMenuTrigger>
</template>
