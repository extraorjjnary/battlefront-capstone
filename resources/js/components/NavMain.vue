<script setup>
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
defineProps({
    items: { type: Array, required: true },
    label: { type: String, default: 'Navigation' },
});
const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="
                        item.activeRoutes
                            ? item.activeRoutes.some((route) =>
                                  isCurrentOrParentUrl(route),
                              )
                            : isCurrentUrl(item.href)
                    "
                    :tooltip="item.title"
                >
                    <Link
                        :href="item.href"
                        :aria-label="
                            item.badgeCount !== undefined
                                ? `${item.title}, ${item.badgeCount} ${item.badgeCount === 1 ? 'unit' : 'units'} in cart`
                                : undefined
                        "
                    >
                        <span
                            v-if="item.badgeCount !== undefined"
                            class="relative size-4 shrink-0"
                        >
                            <component :is="item.icon" class="size-4" />
                            <span
                                v-if="item.badgeCount > 0"
                                data-slot="cart-quantity-badge"
                                aria-hidden="true"
                                class="bg-destructive-action text-destructive-action-foreground ring-sidebar pointer-events-none absolute -top-1.5 -right-1.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full px-1 text-[9px] leading-none font-semibold tabular-nums ring-1"
                            >
                                {{
                                    item.badgeCount > 99
                                        ? '99+'
                                        : item.badgeCount
                                }}
                            </span>
                        </span>
                        <component :is="item.icon" v-else />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
