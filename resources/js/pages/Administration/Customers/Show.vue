<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Mail, UserRound } from '@lucide/vue';
import CustomerController from '@/actions/App/Http/Controllers/Administration/CustomerController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';
import UserInfo from '@/components/UserInfo.vue';
import { Button } from '@/components/ui/button';
import { formatTimestampDate } from '@/lib/dateTime';

defineProps({
    customer: { type: Object, required: true },
    orders: { type: Object, required: true },
    order_status_filter: { type: String, required: true },
});

function formatJoinedDate(value) {
    return value ? formatTimestampDate(value, 'long') : 'Not available';
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Customers',
                href: CustomerController.index(),
            },
            {
                title: 'Customer record',
                href: CustomerController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="customer.name" />

    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex min-w-0 items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <UserRound class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Read-only customer record
                        </p>
                        <h1
                            class="mt-2 truncate text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            {{ customer.name }}
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            View the approved account details used to identify
                            this Battlefront customer.
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="CustomerController.index()">
                        <ArrowLeft />
                        Back to customers
                    </Link>
                </Button>
            </div>
        </section>

        <section aria-labelledby="purchases-heading" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-muted-foreground text-sm">Order history</p>
                    <h2 id="purchases-heading" class="text-xl font-semibold">Purchases</h2>
                </div>
                <Link :href="CustomerController.show(customer.id, { query: { status: order_status_filter === 'completed' ? 'all' : 'completed' } })" class="text-primary text-sm underline">
                    {{ order_status_filter === 'completed' ? 'Show all orders' : 'Show completed only' }}
                </Link>
            </div>
            <div v-if="orders.data.length === 0" class="border-border bg-card border p-6 text-sm text-muted-foreground">
                No orders match this view.
            </div>
            <div v-for="order in orders.data" :key="order.id" class="border-border bg-card space-y-3 border p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-3"><Link :href="OrderController.show(order.id)" class="text-primary font-semibold underline">{{ order.reference }}</Link><Link v-if="order.status === 'completed'" :href="CustomerReturnController.index({ query: { order_id: order.id } })" class="text-primary text-sm underline">Record return</Link></div>
                    <span class="text-muted-foreground text-sm">{{ formatJoinedDate(order.created_at) }} · {{ order.status }} · {{ order.payment_status }}</span>
                </div>
                <ul class="space-y-1 text-sm">
                    <li v-for="item in order.items" :key="item.name">{{ item.name }} × {{ item.quantity }} · ₱{{ item.price_at_time }}</li>
                </ul>
                <p class="text-muted-foreground text-sm">Products ₱{{ order.product_subtotal ?? order.total_amount }} · Delivery ₱{{ order.delivery_fee }} · Order total ₱{{ order.total_amount }}</p>
            </div>
            <div class="flex justify-between text-sm" v-if="orders.last_page > 1">
                <Link v-if="orders.prev_page_url" :href="orders.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span>
                <Link v-if="orders.next_page_url" :href="orders.next_page_url" class="text-primary underline">Next</Link>
            </div>
        </section>

        <section aria-labelledby="account-details-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">Customer account</p>
                <h2 id="account-details-heading" class="text-xl font-semibold">
                    Account details
                </h2>
            </div>

            <div class="border-border bg-card divide-border divide-y border">
                <div class="flex items-center gap-3 p-5">
                    <UserInfo :user="customer" show-email />
                </div>

                <dl class="divide-border grid grid-cols-1 md:grid-cols-2 md:divide-x">
                    <div class="border-border flex gap-3 p-5 max-md:border-b">
                        <Mail
                            class="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                        <div class="min-w-0">
                            <dt class="text-muted-foreground text-sm">
                                Email address
                            </dt>
                            <dd class="mt-1 font-medium wrap-anywhere">
                                {{ customer.email }}
                            </dd>
                        </div>
                    </div>

                    <div class="flex gap-3 p-5">
                        <CalendarDays
                            class="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Customer since
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ formatJoinedDate(customer.created_at) }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>
        </section>
    </main>
</template>
