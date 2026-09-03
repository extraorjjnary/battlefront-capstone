<script setup>
import { reactiveOmit } from "@vueuse/core";
import { NavigationMenuItem } from "reka-ui";
import { cn } from "@/lib/utils";
const props = defineProps({ ...NavigationMenuItem.props, "class": {}, });
const delegatedProps = reactiveOmit(props, "class");
</script>

<template>
  <NavigationMenuItem
    data-slot="navigation-menu-item"
    v-bind="delegatedProps"
    :class="cn('relative', props.class)"
  >
    <slot />
  </NavigationMenuItem>
</template>
