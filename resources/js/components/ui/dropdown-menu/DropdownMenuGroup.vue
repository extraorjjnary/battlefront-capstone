<script setup>
import { DropdownMenuGroup } from "reka-ui";
const props = defineProps(DropdownMenuGroup.props);
</script>

<template>
  <DropdownMenuGroup
    data-slot="dropdown-menu-group"
    v-bind="props"
  >
    <slot />
  </DropdownMenuGroup>
</template>
