<script setup>
import { SelectValue } from "reka-ui";
const props = defineProps(SelectValue.props);
</script>

<template>
  <SelectValue
    data-slot="select-value"
    v-bind="props"
  >
    <slot />
  </SelectValue>
</template>
