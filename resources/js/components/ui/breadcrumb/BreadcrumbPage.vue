<script setup>
import { cn } from "@/lib/utils";
const props = defineProps({
    "class": {},
});
</script>

<template>
  <span
    data-slot="breadcrumb-page"
    role="link"
    aria-disabled="true"
    aria-current="page"
    :class="cn('text-foreground font-normal', props.class)"
  >
    <slot />
  </span>
</template>
