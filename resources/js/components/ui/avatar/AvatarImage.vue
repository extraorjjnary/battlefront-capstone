<script setup>
import { AvatarImage } from "reka-ui";
const props = defineProps(AvatarImage.props);
</script>

<template>
  <AvatarImage
    data-slot="avatar-image"
    v-bind="props"
    class="aspect-square size-full"
  >
    <slot />
  </AvatarImage>
</template>
