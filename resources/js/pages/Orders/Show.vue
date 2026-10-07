<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    CalendarDays,
    CircleAlert,
    CreditCard,
    PackageCheck,
    ReceiptText,
    Store,
    Truck,
    Upload,
    UserRound,
} from '@lucide/vue';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import OrderPaymentProofController from '@/actions/App/Http/Controllers/OrderPaymentProofController';
import InputError from '@/components/InputError.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency } from '@/lib/currency';
import { orderStatusBadgeClass } from '@/lib/orderStatus';
import { index as productIndex } from '@/routes/products';

const props = defineProps({
    order: { type: Object, required: true },
    isConfirmation: { type: Boolean, required: true },
});

const replacementProofForm = useForm({
    payment_proof: null,
});

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'long',
    timeStyle: 'short',
});

function formatOrderDate(value) {
    return dateFormatter.format(new Date(value));
}

function selectReplacementProof(event) {
    replacementProofForm.payment_proof = event.target.files?.[0] ?? null;
    replacementProofForm.clearErrors('payment_proof');
}

function submitReplacementProof() {
    replacementProofForm.submit(OrderPaymentProofController(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            replacementProofForm.reset();
        },
    });
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
                        <Badge
                            variant="outline"
                            class="mt-2"
                            :class="orderStatusBadgeClass(order.status.value)"
                        >
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
                                        v-if="item.product.brand"
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
                        <dl v-if="order.product_subtotal !== null" class="mt-5 grid gap-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Product subtotal</dt>
                                <dd class="font-semibold tabular-nums">{{ formatCurrency(order.product_subtotal) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Delivery fee</dt>
                                <dd class="font-semibold tabular-nums">{{ formatCurrency(order.delivery_fee) }}</dd>
                            </div>
                        </dl>
                        <p class="mt-5 text-3xl font-bold tabular-nums">
                            {{ formatCurrency(order.total) }}
                        </p>
                        <p class="text-muted-foreground mt-2 text-xs leading-5">
                            Based on the prices recorded when this order was
                            placed.
                        </p>
                        <div v-if="order.delivery_quote" class="border-border mt-5 grid gap-2 border-t pt-4 text-sm">
                            <p class="font-semibold">{{ order.delivery_quote.carrier.toUpperCase() }} · {{ order.delivery_quote.destination }}</p>
                            <p>{{ order.delivery_quote.packing_expectation }}</p>
                            <p>Estimated preparation and transit: {{ order.delivery_quote.eta_min_days }}<template v-if="order.delivery_quote.eta_min_days !== order.delivery_quote.eta_max_days">–{{ order.delivery_quote.eta_max_days }}</template> calendar days.</p>
                            <p class="text-muted-foreground text-xs leading-5">{{ order.delivery_quote.notice }}</p>
                            <p v-if="order.delivery_quote.is_demo" class="text-muted-foreground text-xs leading-5">{{ order.delivery_quote.assumption_label }}</p>
                        </div>
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
                                        <Badge
                                            variant="outline"
                                            :class="
                                                orderStatusBadgeClass(
                                                    order.payment.status.value,
                                                )
                                            "
                                        >
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

                        <div
                            v-if="order.payment.rejection"
                            class="border-destructive/40 bg-destructive/10 mt-5 border p-4"
                        >
                            <div
                                class="text-destructive flex items-start gap-3"
                            >
                                <CircleAlert
                                    class="mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0">
                                    <p class="font-semibold">
                                        Payment proof rejected
                                    </p>
                                    <p class="mt-2 text-sm font-medium">
                                        {{ order.payment.rejection.reason }}
                                    </p>
                                    <p
                                        v-if="order.payment.rejection.note"
                                        class="mt-1 text-sm leading-5"
                                    >
                                        {{ order.payment.rejection.note }}
                                    </p>
                                </div>
                            </div>

                            <form
                                v-if="order.payment.can_resubmit_proof"
                                class="border-destructive/30 mt-4 grid gap-3 border-t pt-4"
                                @submit.prevent="submitReplacementProof"
                            >
                                <div class="grid gap-2">
                                    <label
                                        for="replacement-payment-proof"
                                        class="text-sm font-semibold"
                                    >
                                        Replacement proof
                                    </label>
                                    <Input
                                        id="replacement-payment-proof"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        :disabled="
                                            replacementProofForm.processing
                                        "
                                        @change="selectReplacementProof"
                                    />
                                    <p
                                        class="text-muted-foreground text-xs leading-5"
                                    >
                                        Upload a clear JPEG, PNG, or WebP image
                                        up to 5 MB. Battlefront will review it
                                        again manually.
                                    </p>
                                    <InputError
                                        :message="
                                            replacementProofForm.errors
                                                .payment_proof
                                        "
                                    />
                                </div>

                                <progress
                                    v-if="replacementProofForm.progress"
                                    class="h-2 w-full accent-red-700"
                                    :value="
                                        replacementProofForm.progress.percentage
                                    "
                                    max="100"
                                >
                                    {{
                                        replacementProofForm.progress
                                            .percentage
                                    }}%
                                </progress>

                                <Button
                                    type="submit"
                                    class="w-full bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600/40 dark:bg-red-700 dark:hover:bg-red-600"
                                    :disabled="
                                        replacementProofForm.processing ||
                                        !replacementProofForm.payment_proof
                                    "
                                >
                                    <Spinner
                                        v-if="replacementProofForm.processing"
                                    />
                                    <Upload v-else aria-hidden="true" />
                                    {{
                                        replacementProofForm.processing
                                            ? 'Submitting proof...'
                                            : 'Submit replacement proof'
                                    }}
                                </Button>
                            </form>
                        </div>

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
