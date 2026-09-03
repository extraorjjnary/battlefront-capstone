<script setup>
import { CollapsibleContent } from "reka-ui";
const props = defineProps(CollapsibleContent.props);
</script>

<template>
  <CollapsibleContent
    data-slot="collapsible-content"
    v-bind="props"
  >
    <slot />
  </CollapsibleContent>
</template>
