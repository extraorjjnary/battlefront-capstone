<script setup>
import { Primitive } from "reka-ui";
import { cn } from "@/lib/utils";
import { sidebarMenuButtonVariants } from ".";
const props = defineProps({ ...Primitive.props, "variant": { default: "default" },
    "size": { default: "default" },
    "isActive": { type: Boolean },
    "class": null,
    "as": { default: "button" }, });
</script>

<template>
  <Primitive
    data-slot="sidebar-menu-button"
    data-sidebar="menu-button"
    :data-size="size"
    :data-active="isActive"
    :class="cn(sidebarMenuButtonVariants({ variant, size }), props.class)"
    :as="as"
    :as-child="asChild"
    v-bind="$attrs"
  >
    <slot />
  </Primitive>
</template>
