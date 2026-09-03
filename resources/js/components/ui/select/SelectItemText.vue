<script setup>
import { SelectItemText } from "reka-ui";
const props = defineProps(SelectItemText.props);
</script>

<template>
  <SelectItemText
    data-slot="select-item-text"
    v-bind="props"
  >
    <slot />
  </SelectItemText>
</template>
