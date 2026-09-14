<script setup>
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    PackageOpen,
    ShoppingCart,
    TriangleAlert,
} from '@lucide/vue';
import CartItemRow from '@/components/cart/CartItemRow.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { index as cartIndex } from '@/routes/cart';
import { index as checkoutIndex } from '@/routes/checkout';
import { index as productIndex } from '@/routes/products';

defineProps({
    cart: { type: Object, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Cart',
                href: cartIndex(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Cart" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6 sm:p-8"
            aria-labelledby="cart-heading"
        >
            <div
                class="bg-primary absolute inset-y-0 left-0 w-1"
                aria-hidden="true"
            />
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <ShoppingCart class="size-5" aria-hidden="true" />
                </span>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Customer cart
                    </p>
                    <h1
                        id="cart-heading"
                        class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        Your hardware selection
                    </h1>
                    <p
                        class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                    >
                        Review current Battlefront pricing, stock, and
                        quantities before continuing to checkout.
                    </p>
                </div>
            </div>
        </section>

        <section
            v-if="cart.items.length === 0"
            class="border-border bg-card flex min-h-80 items-center justify-center border border-dashed p-8 text-center"
            aria-labelledby="empty-cart-heading"
        >
            <div class="max-w-md">
                <span
                    class="border-border bg-secondary text-muted-foreground mx-auto flex size-14 items-center justify-center border"
                >
                    <PackageOpen class="size-6" aria-hidden="true" />
                </span>
                <h2 id="empty-cart-heading" class="mt-5 text-xl font-bold">
                    Your cart is empty
                </h2>
                <p class="text-muted-foreground mt-2 text-sm leading-6">
                    Browse the catalog to compare current hardware details and
                    add available products to your cart.
                </p>
                <Button as-child class="mt-6">
                    <Link :href="productIndex()">
                        Browse products
                        <ArrowRight aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </section>

        <template v-else>
            <Alert v-if="cart.conflict_count" variant="destructive">
                <TriangleAlert class="size-4" aria-hidden="true" />
                <AlertTitle>
                    {{ cart.conflict_count }}
                    {{
                        cart.conflict_count === 1 ? 'item needs' : 'items need'
                    }}
                    attention
                </AlertTitle>
                <AlertDescription>
                    Review the marked stock conflicts. Update an eligible
                    quantity or remove an unavailable item before continuing.
                </AlertDescription>
            </Alert>

            <div
                class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start"
            >
                <section aria-labelledby="cart-items-heading">
                    <div class="mb-4">
                        <p class="text-muted-foreground text-sm">
                            {{ cart.item_count }}
                            {{ cart.item_count === 1 ? 'product' : 'products' }}
                        </p>
                        <h2
                            id="cart-items-heading"
                            class="text-xl font-semibold"
                        >
                            Cart items
                        </h2>
                    </div>

                    <div
                        class="border-border bg-card divide-border divide-y border"
                    >
                        <CartItemRow
                            v-for="item in cart.items"
                            :key="item.id"
                            :item="item"
                        />
                    </div>
                </section>

                <aside
                    class="border-border bg-card border p-6 xl:sticky xl:top-6"
                    aria-labelledby="cart-summary-heading"
                >
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Current totals
                    </p>
                    <h2
                        id="cart-summary-heading"
                        class="mt-2 text-xl font-bold"
                    >
                        Cart summary
                    </h2>

                    <dl class="border-border mt-6 grid gap-4 border-y py-5">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted-foreground text-sm">
                                Products
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ cart.item_count }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted-foreground text-sm">Units</dt>
                            <dd class="font-semibold tabular-nums">
                                {{ cart.total_quantity }}
                            </dd>
                        </div>
                        <div
                            class="border-border flex items-end justify-between gap-4 border-t pt-4"
                        >
                            <dt class="font-semibold">Cart total</dt>
                            <dd class="text-2xl font-bold tabular-nums">
                                {{ formatCurrency(cart.total) }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="cart.conflict_count"
                        class="text-destructive mt-4 text-sm leading-6"
                    >
                        Totals include items with availability conflicts until
                        they are updated or removed.
                    </p>
                    <p
                        v-else
                        class="text-muted-foreground mt-4 text-sm leading-6"
                    >
                        Prices and quantities shown here are refreshed from the
                        server after every cart change.
                    </p>

                    <Button
                        v-if="!cart.conflict_count"
                        as-child
                        class="mt-6 w-full"
                    >
                        <Link :href="checkoutIndex()">
                            Proceed to checkout
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                    <Button
                        v-else
                        class="mt-6 w-full"
                        disabled
                        aria-describedby="checkout-conflict-help"
                    >
                        Resolve cart issues first
                    </Button>
                    <p
                        v-if="cart.conflict_count"
                        id="checkout-conflict-help"
                        class="text-muted-foreground mt-2 text-center text-xs"
                    >
                        Checkout becomes available after all marked items are
                        updated or removed.
                    </p>

                    <Button as-child variant="outline" class="mt-3 w-full">
                        <Link :href="productIndex()">
                            Continue shopping
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </aside>
            </div>
        </template>
    </main>
</template>
