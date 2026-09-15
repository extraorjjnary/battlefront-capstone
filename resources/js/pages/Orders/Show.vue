<script setup>
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    CalendarDays,
    CreditCard,
    PackageCheck,
    ReceiptText,
    Store,
    Truck,
    UserRound,
} from '@lucide/vue';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import ProductImage from '@/components/catalog/ProductImage.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { index as productIndex } from '@/routes/products';

defineProps({
    order: { type: Object, required: true },
    isConfirmation: { type: Boolean, required: true },
});

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'long',
    timeStyle: 'short',
});

function formatOrderDate(value) {
    return dateFormatter.format(new Date(value));
}
</script>

<template>
    <div class="contents">
        <Head :title="`Order ${order.reference}`" />

        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
        >
            <section
                class="border-border bg-card relative overflow-hidden border p-6 sm:p-8"
                aria-labelledby="confirmation-heading"
            >
                <div
                    class="bg-primary absolute inset-y-0 left-0 w-1"
                    aria-hidden="true"
                />
                <div
                    class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="flex items-start gap-4">
                        <span
                            class="bg-secondary text-primary flex size-12 shrink-0 items-center justify-center rounded-md"
                        >
                            <BadgeCheck class="size-6" aria-hidden="true" />
                        </span>
                        <div>
                            <p
                                class="text-primary text-xs font-semibold tracking-widest uppercase"
                            >
                                {{
                                    isConfirmation
                                        ? 'Order confirmed'
                                        : 'Customer order'
                                }}
                            </p>
                            <h1
                                id="confirmation-heading"
                                class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                            >
                                {{
                                    isConfirmation
                                        ? 'Thank you for your order'
                                        : `Order ${order.reference}`
                                }}
                            </h1>
                            <p
                                class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                            >
                                <template v-if="isConfirmation">
                                    Battlefront received your order. Keep the
                                    reference below when asking about its
                                    progress.
                                </template>
                                <template v-else>
                                    Review the latest order and payment status
                                    recorded by Battlefront.
                                </template>
                            </p>
                        </div>
                    </div>

                    <div class="sm:text-right">
                        <p
                            class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                        >
                            Order reference
                        </p>
                        <p class="mt-1 text-2xl font-bold tabular-nums">
                            {{ order.reference }}
                        </p>
                        <Badge variant="secondary" class="mt-2">
                            {{ order.status.label }}
                        </Badge>
                    </div>
                </div>

                <div
                    class="border-border text-muted-foreground mt-6 flex items-center gap-2 border-t pt-4 text-sm"
                >
                    <CalendarDays class="size-4" aria-hidden="true" />
                    Submitted {{ formatOrderDate(order.created_at) }}
                </div>
            </section>

            <div
                class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] xl:items-start"
            >
                <section aria-labelledby="ordered-items-heading">
                    <div class="mb-4">
                        <p class="text-muted-foreground text-sm">
                            {{ order.item_count }}
                            {{
                                order.item_count === 1 ? 'product' : 'products'
                            }}
                            · {{ order.total_quantity }}
                            {{ order.total_quantity === 1 ? 'unit' : 'units' }}
                        </p>
                        <h2
                            id="ordered-items-heading"
                            class="text-xl font-semibold"
                        >
                            Ordered hardware
                        </h2>
                    </div>

                    <div
                        class="border-border bg-card divide-border divide-y border"
                    >
                        <article
                            v-for="item in order.items"
                            :key="item.id"
                            class="grid gap-5 p-5 sm:grid-cols-[7rem_minmax(0,1fr)] sm:p-6"
                        >
                            <div
                                class="border-border aspect-square overflow-hidden border"
                            >
                                <ProductImage
                                    :image-url="item.product.image_url"
                                    :product-name="item.product.name"
                                />
                            </div>

                            <div
                                class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="text-primary text-xs font-semibold tracking-wide uppercase"
                                    >
                                        {{ item.product.brand }}
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold">
                                        {{ item.product.name }}
                                    </h3>
                                    <p
                                        class="text-muted-foreground mt-3 text-sm"
                                    >
                                        {{ item.quantity }} ×
                                        {{ formatCurrency(item.unit_price) }}
                                    </p>
                                </div>

                                <div class="sm:text-right">
                                    <p
                                        class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                                    >
                                        Line total
                                    </p>
                                    <p
                                        class="mt-1 text-lg font-bold tabular-nums"
                                    >
                                        {{ formatCurrency(item.line_total) }}
                                    </p>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <aside class="grid gap-6 xl:sticky xl:top-6">
                    <section
                        class="border-border bg-card border p-6"
                        aria-labelledby="order-total-heading"
                    >
                        <div class="flex items-center gap-3">
                            <ReceiptText
                                class="text-primary size-5"
                                aria-hidden="true"
                            />
                            <h2 id="order-total-heading" class="font-bold">
                                Order total
                            </h2>
                        </div>
                        <p class="mt-5 text-3xl font-bold tabular-nums">
                            {{ formatCurrency(order.total) }}
                        </p>
                        <p class="text-muted-foreground mt-2 text-xs leading-5">
                            Based on the prices recorded when this order was
                            placed.
                        </p>
                    </section>

                    <section
                        class="border-border bg-card border p-6"
                        aria-labelledby="submitted-details-heading"
                    >
                        <div class="flex items-center gap-3">
                            <PackageCheck
                                class="text-primary size-5"
                                aria-hidden="true"
                            />
                            <h2
                                id="submitted-details-heading"
                                class="font-bold"
                            >
                                Submitted details
                            </h2>
                        </div>

                        <dl class="border-border mt-5 divide-y border-y">
                            <div class="flex gap-3 py-4">
                                <UserRound
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0">
                                    <dt
                                        class="text-muted-foreground text-xs font-medium"
                                    >
                                        Recipient
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ order.recipient.name }}
                                    </dd>
                                    <dd
                                        class="text-muted-foreground mt-1 text-sm"
                                    >
                                        {{ order.recipient.contact_number }}
                                    </dd>
                                </div>
                            </div>

                            <div class="flex gap-3 py-4">
                                <Truck
                                    v-if="
                                        order.fulfillment.value === 'delivery'
                                    "
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <Store
                                    v-else
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0">
                                    <dt
                                        class="text-muted-foreground text-xs font-medium"
                                    >
                                        Fulfillment
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ order.fulfillment.label }}
                                    </dd>
                                    <dd
                                        v-if="
                                            order.fulfillment.delivery_address
                                        "
                                        class="text-muted-foreground mt-1 text-sm leading-5"
                                    >
                                        {{ order.fulfillment.delivery_address }}
                                    </dd>
                                </div>
                            </div>

                            <div class="flex gap-3 py-4">
                                <CreditCard
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0">
                                    <dt
                                        class="text-muted-foreground text-xs font-medium"
                                    >
                                        Payment
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ order.payment.method.label }}
                                    </dd>
                                    <dd class="mt-2">
                                        <Badge variant="outline">
                                            {{ order.payment.status.label }}
                                        </Badge>
                                    </dd>
                                    <dd
                                        class="text-muted-foreground mt-2 text-xs leading-5"
                                    >
                                        {{ order.payment.notice }}
                                    </dd>
                                </div>
                            </div>
                        </dl>

                        <div class="mt-6 grid gap-3">
                            <Button as-child>
                                <Link :href="OrderController.index()">
                                    View order history
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                            <Button as-child variant="outline">
                                <Link :href="productIndex()">
                                    Continue shopping
                                </Link>
                            </Button>
                        </div>
                    </section>
                </aside>
            </div>
        </main>
    </div>
</template>
