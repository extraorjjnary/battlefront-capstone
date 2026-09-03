<script setup>
import { DialogTrigger } from "reka-ui";
const props = defineProps(DialogTrigger.props);
</script>

<template>
  <DialogTrigger
    data-slot="dialog-trigger"
    v-bind="props"
  >
    <slot />
  </DialogTrigger>
</template>
