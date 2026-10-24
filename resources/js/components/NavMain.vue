<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
defineProps({
    items: { type: Array, required: true },
    label: { type: String, default: 'Navigation' },
});
const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
const { state, isMobile } = useSidebar();
const isCollapsed = computed(
    () => state.value === 'collapsed' && !isMobile.value,
);
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
                    :tooltip="
                        item.pendingCount > 0
                            ? `${item.title}, ${item.pendingCount} pending actions`
                            : item.title
                    "
                >
                    <Link
                        :href="item.href"
                        :aria-label="
                            item.badgeCount !== undefined
                                ? `${item.title}, ${item.badgeCount} ${item.badgeCount === 1 ? 'unit' : 'units'} in cart`
                                : item.pendingCount > 0
                                  ? `${item.title}, ${item.pendingCount} pending ${item.pendingCount === 1 ? 'action' : 'actions'}`
                                  : undefined
                        "
                    >
                        <span
                            v-if="
                                isCollapsed &&
                                (item.badgeCount !== undefined ||
                                    item.pendingCount > 0)
                            "
                            class="relative size-4 shrink-0"
                        >
                            <component
                                :is="item.icon"
                                class="size-4"
                                aria-hidden="true"
                            />
                            <span
                                v-if="
                                    (item.badgeCount ?? item.pendingCount) > 0
                                "
                                :data-slot="
                                    item.badgeCount !== undefined
                                        ? 'cart-quantity-badge'
                                        : 'pending-actions-badge'
                                "
                                aria-hidden="true"
                                class="bg-destructive-action text-destructive-action-foreground ring-sidebar pointer-events-none absolute -top-1.5 -right-1.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full px-1 text-[9px] leading-none font-semibold tabular-nums ring-1"
                            >
                                {{
                                    (item.badgeCount ?? item.pendingCount) > 99
                                        ? '99+'
                                        : (item.badgeCount ?? item.pendingCount)
                                }}
                            </span>
                        </span>
                        <component :is="item.icon" v-else aria-hidden="true" />
                        <span class="min-w-0 truncate">{{ item.title }}</span>
                        <span
                            v-if="
                                !isCollapsed &&
                                (item.badgeCount ?? item.pendingCount) > 0
                            "
                            :data-slot="
                                item.badgeCount !== undefined
                                    ? 'cart-quantity-badge'
                                    : 'pending-actions-badge'
                            "
                            aria-hidden="true"
                            class="bg-destructive-action text-destructive-action-foreground pointer-events-none ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[10px] font-semibold tabular-nums"
                            >{{
                                (item.badgeCount ?? item.pendingCount) > 99
                                    ? '99+'
                                    : (item.badgeCount ?? item.pendingCount)
                            }}</span
                        >
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
