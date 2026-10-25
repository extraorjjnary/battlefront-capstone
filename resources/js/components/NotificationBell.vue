<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { ref } from 'vue';
import ListSkeleton from '@/components/ListSkeleton.vue';
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
const markingAllRead = ref(false);
const readError = ref('');
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
                const destination = navigation.destination(notification);
                if (destination) router.visit(destination);
            },
        },
    );
}

function markAllRead() {
    if (markingAllRead.value || !summary.value.unread_count) return;
    markingAllRead.value = true;
    readError.value = '';
    router.patch(
        navigation.readAll(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                readError.value =
                    'The read state could not be saved. Please try again.';
            },
            onFinish: () => {
                markingAllRead.value = false;
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
            <div class="flex flex-wrap items-center justify-between gap-1">
                <DropdownMenuLabel
                    >Notifications ·
                    {{ summary.unread_count }} unread</DropdownMenuLabel
                >
                <DropdownMenuItem
                    v-if="summary.unread_count"
                    as-child
                    :disabled="markingAllRead"
                    @select.prevent="markAllRead"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-auto px-1.5 py-1 text-xs"
                        :disabled="markingAllRead"
                        :aria-busy="markingAllRead"
                        >Mark all as read</Button
                    >
                </DropdownMenuItem>
            </div>
            <p
                v-if="readError"
                role="alert"
                class="text-destructive px-2 py-3 text-sm"
            >
                {{ readError }}
            </p>
            <p
                v-if="error"
                role="alert"
                class="text-destructive px-2 py-3 text-sm"
            >
                {{ error }}
            </p>
            <div v-if="loading && !summary.recent.length" role="status">
                <span class="sr-only">Loading notifications…</span>
                <ListSkeleton />
            </div>
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
                        >{{
                            notification.order?.reference ??
                            'Operational update'
                        }}
                        · {{ notification.is_read ? 'Read' : 'Unread' }}</span
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
