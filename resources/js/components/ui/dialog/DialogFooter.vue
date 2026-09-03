<script setup>
import { DialogClose } from "reka-ui";
import { cn } from "@/lib/utils";
import { Button } from '@/components/ui/button';
const props = defineProps({
    "class": null,
    "showCloseButton": { type: Boolean, default: false },
});
</script>

<template>
  <div
    data-slot="dialog-footer"
    :class="cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', props.class)"
  >
    <slot />
    <DialogClose v-if="showCloseButton" as-child>
      <Button variant="outline">
        Close
      </Button>
    </DialogClose>
  </div>
</template>
