<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Boxes,
    ChartNoAxesCombined,
    CircleAlert,
    Clock3,
    PackageSearch,
    ReceiptText,
    WalletCards,
} from '@lucide/vue';
import { computed } from 'vue';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import SalesReportController from '@/actions/App/Http/Controllers/Administration/SalesReportController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { orderStatusBadgeClass } from '@/lib/orderStatus';
import { dashboard as dashboardRoute } from '@/routes';

const props = defineProps({
    dashboard: { type: Object, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const quickLinks = [
    {
        title: 'Orders',
        description: 'Review payments and process active orders.',
        href: OrderController.index(),
        icon: ReceiptText,
    },
    {
        title: 'Inventory',
        description: 'Check stock levels and update quantities.',
        href: InventoryController.index(),
        icon: Boxes,
    },
    {
        title: 'Catalog',
        description: 'Maintain products and categories.',
        href: ProductController.index(),
        icon: PackageSearch,
    },
    {
        title: 'Sales reports',
        description: 'Open detailed revenue and product reports.',
        href: SalesReportController.index(),
        icon: ChartNoAxesCombined,
    },
];
const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value) {
    return dateFormatter.format(new Date(value));
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboardRoute(),
            },
        ],
    },
});
</script>

<template>
    <div class="contents">
        <Head title="Administration dashboard" />

        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
        >
            <section
                class="border-border bg-card relative overflow-hidden border px-6 py-5"
                aria-labelledby="admin-dashboard-heading"
            >
                <div
                    class="bg-primary absolute inset-y-0 left-0 w-1"
                    aria-hidden="true"
                />
                <p
                    class="text-primary text-xs font-semibold tracking-widest uppercase"
                >
                    Sagay City operations
                </p>
                <h1
                    id="admin-dashboard-heading"
                    class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                >
                    Welcome, {{ user.name }}
                </h1>
                <p class="text-muted-foreground mt-2 text-sm leading-6">
                    Review today’s operational priorities and continue where
                    attention is needed.
                </p>
            </section>

            <section aria-labelledby="operations-summary-heading">
                <h2 id="operations-summary-heading" class="sr-only">
                    Operations summary
                </h2>
                <dl
                    class="border-border bg-card grid border sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div
                        class="border-border border-b p-5 sm:border-r lg:border-b-0"
                    >
                        <dt class="text-muted-foreground text-sm">
                            Recorded revenue
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{
                                formatCurrency(dashboard.kpis.recorded_revenue)
                            }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{ dashboard.kpis.recorded_sales }}
                            {{
                                dashboard.kpis.recorded_sales === 1
                                    ? 'recorded sale'
                                    : 'recorded sales'
                            }}
                        </p>
                    </div>
                    <div
                        class="border-border border-b p-5 sm:border-r-0 lg:border-r lg:border-b-0"
                    >
                        <dt class="text-muted-foreground text-sm">
                            Pending orders
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{ dashboard.kpis.pending_orders }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Awaiting review or payment verification
                        </p>
                    </div>
                    <div
                        class="border-border border-b p-5 sm:border-r sm:border-b-0"
                    >
                        <dt class="text-muted-foreground text-sm">
                            Processing orders
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{ dashboard.kpis.processing_orders }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Currently being fulfilled
                        </p>
                    </div>
                    <div class="p-5">
                        <dt class="text-muted-foreground text-sm">
                            Low-stock products
                        </dt>
                        <dd class="mt-2 text-2xl font-bold tabular-nums">
                            {{ dashboard.kpis.low_stock_products }}
                        </dd>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Below their reorder level
                        </p>
                    </div>
                </dl>
            </section>

            <section
                class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(22rem,0.85fr)]"
                aria-labelledby="needs-attention-heading"
            >
                <div>
                    <div class="mb-4 flex items-end justify-between gap-4">
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Operational queue
                            </p>
                            <h2
                                id="needs-attention-heading"
                                class="text-xl font-semibold"
                            >
                                Needs attention
                            </h2>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="OrderController.index()">
                                View orders
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>

                    <div class="border-border bg-card border">
                        <div class="border-border border-b p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex gap-3">
                                    <span
                                        class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                                    >
                                        <WalletCards
                                            class="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div>
                                        <h3 class="font-semibold">
                                            Pending payment checks
                                        </h3>
                                        <p
                                            class="text-muted-foreground mt-1 text-sm"
                                        >
                                            {{
                                                dashboard.needs_attention
                                                    .pending_payment_reviews
                                            }}
                                            active
                                            {{
                                                dashboard.needs_attention
                                                    .pending_payment_reviews ===
                                                1
                                                    ? 'order needs'
                                                    : 'orders need'
                                            }}
                                            a payment decision.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="
                                    dashboard.needs_attention.payment_orders
                                        .length === 0
                                "
                                class="text-muted-foreground mt-5 flex items-center gap-2 border-t pt-4 text-sm"
                            >
                                <CircleAlert
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                No active payment checks are waiting.
                            </div>
                            <div v-else class="mt-5 grid gap-1">
                                <Link
                                    v-for="order in dashboard.needs_attention
                                        .payment_orders"
                                    :key="order.id"
                                    :href="OrderController.show(order.id)"
                                    class="hover:bg-accent focus-visible:ring-ring grid gap-2 px-3 py-3 transition-colors focus-visible:ring-2 focus-visible:outline-none sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold">
                                            {{ order.reference }} ·
                                            {{ order.customer_name }}
                                        </p>
                                        <p
                                            class="text-muted-foreground mt-1 text-xs"
                                        >
                                            {{ order.payment_method }} ·
                                            {{ formatDate(order.created_at) }}
                                        </p>
                                    </div>
                                    <p class="font-semibold tabular-nums">
                                        {{ formatCurrency(order.total) }}
                                    </p>
                                </Link>
                            </div>
                        </div>

                        <div class="p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex gap-3">
                                    <span
                                        class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                                    >
                                        <Boxes
                                            class="size-5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div>
                                        <h3 class="font-semibold">
                                            Low-stock inventory
                                        </h3>
                                        <p
                                            class="text-muted-foreground mt-1 text-sm"
                                        >
                                            Products below their reorder
                                            threshold.
                                        </p>
                                    </div>
                                </div>
                                <Button variant="ghost" size="sm" as-child>
                                    <Link
                                        :href="
                                            InventoryController.index({
                                                query: { stock: 'low' },
                                            })
                                        "
                                    >
                                        Inventory
                                        <ArrowRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            </div>

                            <div
                                v-if="
                                    dashboard.needs_attention.low_stock_products
                                        .length === 0
                                "
                                class="text-muted-foreground mt-5 flex items-center gap-2 border-t pt-4 text-sm"
                            >
                                <CircleAlert
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                No products are below their reorder level.
                            </div>
                            <div v-else class="mt-5 divide-y">
                                <div
                                    v-for="product in dashboard.needs_attention
                                        .low_stock_products"
                                    :key="product.id"
                                    class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold">
                                            {{ product.name }}
                                        </p>
                                        <p
                                            class="text-muted-foreground mt-1 text-xs"
                                        >
                                            {{ product.brand }}
                                            <span v-if="!product.is_active">
                                                · Inactive
                                            </span>
                                        </p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p
                                            class="text-destructive font-bold tabular-nums"
                                        >
                                            {{ product.quantity }} in stock
                                        </p>
                                        <p
                                            class="text-muted-foreground mt-1 text-xs tabular-nums"
                                        >
                                            Reorder at
                                            {{ product.reorder_level }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="mb-4">
                        <p class="text-muted-foreground text-sm">
                            Latest activity
                        </p>
                        <h2 class="text-xl font-semibold">Recent orders</h2>
                    </div>
                    <div class="border-border bg-card border">
                        <div
                            v-if="props.dashboard.recent_orders.length === 0"
                            class="text-muted-foreground flex min-h-64 flex-col items-center justify-center gap-3 p-8 text-center text-sm"
                        >
                            <ReceiptText class="size-7" aria-hidden="true" />
                            No orders have been placed yet.
                        </div>
                        <div v-else class="divide-border divide-y">
                            <Link
                                v-for="order in props.dashboard.recent_orders"
                                :key="order.id"
                                :href="OrderController.show(order.id)"
                                class="hover:bg-accent focus-visible:ring-ring block p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <div
                                    class="flex items-start justify-between gap-4"
                                >
                                    <div class="min-w-0">
                                        <p class="font-semibold tabular-nums">
                                            {{ order.reference }}
                                        </p>
                                        <p
                                            class="text-muted-foreground mt-1 truncate text-sm"
                                        >
                                            {{ order.customer_name }} ·
                                            {{ order.total_quantity }}
                                            {{
                                                order.total_quantity === 1
                                                    ? 'unit'
                                                    : 'units'
                                            }}
                                        </p>
                                    </div>
                                    <p
                                        class="shrink-0 font-semibold tabular-nums"
                                    >
                                        {{ formatCurrency(order.total) }}
                                    </p>
                                </div>
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-2"
                                >
                                    <Badge
                                        variant="outline"
                                        :class="
                                            orderStatusBadgeClass(
                                                order.status.value,
                                            )
                                        "
                                    >
                                        {{ order.status.label }}
                                    </Badge>
                                    <Badge
                                        variant="outline"
                                        :class="
                                            orderStatusBadgeClass(
                                                order.payment_status.value,
                                            )
                                        "
                                    >
                                        Payment
                                        {{ order.payment_status.label }}
                                    </Badge>
                                    <span
                                        class="text-muted-foreground ml-auto flex items-center gap-1 text-xs"
                                    >
                                        <Clock3
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{ formatDate(order.created_at) }}
                                    </span>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <section aria-labelledby="quick-links-heading">
                <div class="mb-4">
                    <p class="text-muted-foreground text-sm">Shortcuts</p>
                    <h2 id="quick-links-heading" class="text-xl font-semibold">
                        Administration tools
                    </h2>
                </div>
                <div
                    class="border-border bg-card grid border md:grid-cols-2 xl:grid-cols-4"
                >
                    <Link
                        v-for="item in quickLinks"
                        :key="item.title"
                        :href="item.href"
                        class="border-border hover:bg-accent focus-visible:ring-ring group flex gap-3 border-b p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none md:odd:border-r xl:border-b-0 xl:not-last:border-r"
                    >
                        <component
                            :is="item.icon"
                            class="text-primary mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="font-semibold">{{ item.title }}</span>
                            <span
                                class="text-muted-foreground mt-1 block text-xs leading-5"
                            >
                                {{ item.description }}
                            </span>
                        </span>
                        <ArrowRight
                            class="text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform group-hover:translate-x-1"
                            aria-hidden="true"
                        />
                    </Link>
                </div>
            </section>
        </main>
    </div>
</template>
