<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useNotificationNavigation } from '@/composables/useNotificationNavigation';
import { useNotificationPolling } from '@/composables/useNotificationPolling';

const navigation = useNotificationNavigation();
const { summary } = navigation;
const {
    loading,
    error,
    refresh: refreshSummary,
} = useNotificationPolling(navigation);

function refresh(open) {
    if (!open) return;
    refreshSummary();
}

function openNotification(notification) {
    router.patch(
        navigation.read(notification.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                if (notification.order)
                    router.visit(navigation.order(notification.order.id));
            },
        },
    );
}
</script>

<template>
    <DropdownMenu @update:open="refresh">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="`Notifications, ${summary.unread_count} unread`"
            >
                <Bell class="size-5" aria-hidden="true" />
                <span
                    v-if="summary.unread_count"
                    class="bg-primary text-primary-foreground absolute -top-1 -right-1 min-w-5 rounded-full px-1 text-xs font-semibold"
                >
                    {{
                        summary.unread_count > 99 ? '99+' : summary.unread_count
                    }}
                </span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80 max-w-[calc(100vw-2rem)]">
            <DropdownMenuLabel
                >Notifications ·
                {{ summary.unread_count }} unread</DropdownMenuLabel
            >
            <p
                v-if="error"
                role="alert"
                class="text-destructive px-2 py-3 text-sm"
            >
                {{ error }}
            </p>
            <p
                v-if="loading && !summary.recent.length"
                class="text-muted-foreground animate-pulse px-2 py-4 text-sm"
            >
                Loading notifications…
            </p>
            <p
                v-else-if="!summary.recent.length"
                class="text-muted-foreground px-2 py-4 text-sm"
            >
                No order updates yet.
            </p>
            <DropdownMenuItem
                v-for="notification in summary.recent"
                :key="notification.id"
                class="items-start gap-2 py-3"
                @select="openNotification(notification)"
            >
                <span
                    class="mt-1.5 size-2 shrink-0 rounded-full"
                    :class="notification.is_read ? 'bg-muted' : 'bg-primary'"
                    aria-hidden="true"
                />
                <span class="min-w-0">
                    <span
                        class="block text-sm"
                        :class="!notification.is_read && 'font-semibold'"
                        >{{ notification.title }}</span
                    >
                    <span class="text-muted-foreground block text-xs"
                        >{{ notification.order?.reference ?? 'Order update' }} ·
                        {{ notification.is_read ? 'Read' : 'Unread' }}</span
                    >
                </span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link :href="navigation.index()">View all notifications</Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
