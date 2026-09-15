<script setup>
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    PackageCheck,
    ReceiptText,
    UserRound,
} from '@lucide/vue';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';

defineProps({
    orders: { type: Object, required: true },
});

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value) {
    return dateFormatter.format(new Date(value));
}

function statusClass(status) {
    return {
        pending:
            'border-amber-500/50 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        processing:
            'border-sky-500/50 bg-sky-500/10 text-sky-700 dark:text-sky-300',
        completed:
            'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        cancelled: 'border-destructive/50 bg-destructive/10 text-destructive',
        verified:
            'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        rejected: 'border-destructive/50 bg-destructive/10 text-destructive',
    }[status];
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Orders',
                href: OrderController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Orders" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="flex items-start gap-4 p-6">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <ReceiptText class="size-5" />
                </span>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Sagay City order desk
                    </p>
                    <h1
                        class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        Order processing
                    </h1>
                    <p
                        class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                    >
                        Review customer orders, verify manual payments, and move
                        fulfillment through approved processing states.
                    </p>
                </div>
            </div>
        </section>

        <section aria-labelledby="order-queue-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ orders.total }} orders
                </p>
                <h2 id="order-queue-heading" class="text-xl font-semibold">
                    Processing queue
                </h2>
            </div>

            <div
                v-if="orders.data.length === 0"
                class="border-border bg-card flex min-h-48 items-center justify-center border p-6 text-center"
            >
                <div>
                    <PackageCheck
                        class="text-muted-foreground mx-auto size-8"
                    />
                    <p class="mt-3 font-medium">No orders to review</p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        New customer orders will appear here.
                    </p>
                </div>
            </div>

            <div
                v-else
                class="border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="order in orders.data"
                    :key="order.id"
                    class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)_auto] lg:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold tabular-nums">
                                {{ order.reference }}
                            </h3>
                            <Badge
                                variant="outline"
                                :class="statusClass(order.status.value)"
                            >
                                {{ order.status.label }}
                            </Badge>
                        </div>
                        <div
                            class="text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <UserRound class="size-3.5" />
                                {{ order.customer.name }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <CalendarDays class="size-3.5" />
                                {{ formatDate(order.created_at) }}
                            </span>
                        </div>
                        <p class="text-muted-foreground mt-2 text-xs">
                            Recipient: {{ order.recipient_name }} ·
                            {{ order.fulfillment.label }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-muted-foreground">Payment</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="font-medium">
                                    {{ order.payment.method.label }}
                                </span>
                                <Badge
                                    variant="outline"
                                    :class="
                                        statusClass(order.payment.status.value)
                                    "
                                >
                                    {{ order.payment.status.label }}
                                </Badge>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-muted-foreground">
                                {{ order.total_quantity }} items
                            </p>
                            <p class="mt-1 font-semibold tabular-nums">
                                {{ formatCurrency(order.total) }}
                            </p>
                        </div>
                    </div>

                    <Button variant="outline" size="sm" as-child>
                        <Link :href="OrderController.show(order.id)">
                            Review order
                            <ArrowRight />
                        </Link>
                    </Button>
                </article>
            </div>

            <CatalogPagination
                :current-page="orders.current_page"
                :last-page="orders.last_page"
                :route="OrderController.index"
                label="Order pages"
            />
        </section>
    </main>
</template>
