<script setup>
import { reactiveOmit } from "@vueuse/core";
import { DialogDescription } from "reka-ui";
import { cn } from "@/lib/utils";
const props = defineProps({ ...DialogDescription.props, "class": {}, });
const delegatedProps = reactiveOmit(props, "class");
</script>

<template>
  <DialogDescription
    data-slot="sheet-description"
    :class="cn('text-muted-foreground text-sm', props.class)"
    v-bind="delegatedProps"
  >
    <slot />
  </DialogDescription>
</template>
