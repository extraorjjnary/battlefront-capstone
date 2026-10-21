<script setup>
import { cn } from "@/lib/utils";
const props = defineProps({
    "class": {},
});
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground surface-card flex flex-col gap-6 border py-6',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
