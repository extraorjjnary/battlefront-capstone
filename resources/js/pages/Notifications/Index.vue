<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, Check, ArrowRight } from '@lucide/vue';
import { ref } from 'vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import { Button } from '@/components/ui/button';
import { useNotificationNavigation } from '@/composables/useNotificationNavigation';

defineProps({ notifications: { type: Object, required: true } });
const navigation = useNotificationNavigation();
const { summary, isAdministrator } = navigation;
const busy = ref(false);
const error = ref('');
const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function markRead(id = null) {
    error.value = '';
    router.patch(
        id ? navigation.read(id) : navigation.readAll(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onError: () => {
                error.value =
                    'The read state could not be saved. Please try again.';
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="contents">
        <Head title="Notifications" />
        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-6 lg:p-10"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        {{
                            isAdministrator
                                ? 'Order operations'
                                : 'Your Battlefront orders'
                        }}
                    </p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Notifications
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm">
                        {{ summary.unread_count }} unread ·
                        {{
                            isAdministrator
                                ? 'New orders and payment evidence needing review.'
                                : 'Payment and shipment updates, shared across your devices.'
                        }}
                    </p>
                </div>
                <Button
                    variant="outline"
                    :disabled="busy || !summary.unread_count"
                    @click="markRead()"
                >
                    <Check class="size-4" aria-hidden="true" /> Mark all as read
                </Button>
            </header>
            <p v-if="error" class="text-destructive text-sm" role="alert">
                {{ error }}
            </p>
            <section aria-label="Notification history" :aria-busy="busy">
                <div
                    v-if="!notifications.data.length"
                    class="border-border bg-card border border-dashed px-6 py-14 text-center"
                >
                    <Bell
                        class="text-muted-foreground mx-auto size-8"
                        aria-hidden="true"
                    />
                    <h2 class="mt-4 text-lg font-semibold">
                        No notifications yet
                    </h2>
                    <p class="text-muted-foreground mt-2 text-sm">
                        {{
                            isAdministrator
                                ? 'New orders and submitted wallet proofs will appear here.'
                                : 'Updates will appear here when payment or shipment milestones are recorded.'
                        }}
                    </p>
                </div>
                <div v-else class="border-border divide-border divide-y border">
                    <article
                        v-for="notification in notifications.data"
                        :key="notification.id"
                        class="bg-card border-l-2 p-5"
                        :class="
                            notification.is_read
                                ? 'border-l-transparent'
                                : 'border-l-primary'
                        "
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div>
                                <p class="text-muted-foreground text-xs">
                                    <span>{{
                                        notification.is_read ? 'Read' : 'Unread'
                                    }}</span>
                                    ·
                                    <time
                                        :datetime="notification.occurred_at"
                                        >{{
                                            dateFormatter.format(
                                                new Date(
                                                    notification.occurred_at,
                                                ),
                                            )
                                        }}</time
                                    >
                                </p>
                                <h2 class="mt-2 font-semibold">
                                    {{ notification.title }}
                                </h2>
                                <p
                                    class="text-muted-foreground mt-1 text-sm leading-6"
                                >
                                    {{ notification.body }}
                                </p>
                            </div>
                            <Button
                                v-if="!notification.is_read"
                                variant="ghost"
                                size="sm"
                                :disabled="busy"
                                @click="markRead(notification.id)"
                                >Mark as read</Button
                            >
                        </div>
                        <Link
                            v-if="notification.order"
                            :href="navigation.order(notification.order.id)"
                            class="text-primary focus-visible:ring-ring mt-3 inline-flex items-center gap-2 rounded-sm text-sm font-medium focus-visible:ring-2"
                        >
                            View {{ notification.order.reference }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </article>
                </div>
                <CatalogPagination
                    :current-page="notifications.current_page"
                    :last-page="notifications.last_page"
                    :route="navigation.index"
                    label="Notification pages"
                />
            </section>
        </main>
    </div>
</template>
