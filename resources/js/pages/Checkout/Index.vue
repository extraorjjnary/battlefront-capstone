<script setup>
import { Form, Head, Link } from "@inertiajs/vue3";
import {
    ArrowLeft,
    BadgeCheck,
    Clock3,
    CreditCard,
    MapPin,
    PackageCheck,
    Phone,
    ShieldCheck,
    Store,
    Truck,
    Upload,
} from "@lucide/vue";
import { computed, ref } from "vue";
import { store as storeOrder } from "@/actions/App/Http/Controllers/OrderController";
import InputError from "@/components/InputError.vue";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Spinner } from "@/components/ui/spinner";
import { Textarea } from "@/components/ui/textarea";
import { formatCurrency } from "@/lib/currency";
import { index as cartIndex } from "@/routes/cart";
import { index as checkoutIndex } from "@/routes/checkout";

const props = defineProps({
    cart: { type: Object, required: true },
    customer: { type: Object, required: true },
    pickupLocation: { type: Object, required: true },
    fulfillmentMethods: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
});

const fulfillmentMethod = ref("pickup");
const paymentMethod = ref("cash");
const paymentProofKey = ref(0);

const availablePaymentMethods = computed(() =>
    props.paymentMethods.filter((method) =>
        method.available_for.includes(fulfillmentMethod.value),
    ),
);
const selectedPaymentMethod = computed(() =>
    props.paymentMethods.find((method) => method.value === paymentMethod.value),
);
const requiresPaymentProof = computed(
    () => selectedPaymentMethod.value?.requires_proof ?? false,
);
const selectedPaymentAccount = computed(
    () => selectedPaymentMethod.value?.payment_account ?? null,
);

function chooseFulfillment(value) {
    fulfillmentMethod.value = value;

    if (
        !availablePaymentMethods.value.some(
            (method) => method.value === paymentMethod.value,
        )
    ) {
        paymentMethod.value = availablePaymentMethods.value[0].value;
    }
}

function choosePayment(value) {
    paymentMethod.value = value;
    paymentProofKey.value += 1;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Cart", href: cartIndex() },
            { title: "Checkout", href: checkoutIndex() },
        ],
    },
});
</script>

<template>
    <Head title="Checkout" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6 sm:p-8"
            aria-labelledby="checkout-heading"
        >
            <div
                class="bg-primary absolute inset-y-0 left-0 w-1"
                aria-hidden="true"
            />
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <PackageCheck class="size-5" aria-hidden="true" />
                </span>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Customer checkout
                    </p>
                    <h1
                        id="checkout-heading"
                        class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        Confirm fulfillment and payment
                    </h1>
                    <p
                        class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                    >
                        Review your current hardware selection and validate the
                        details Battlefront will need for your order.
                    </p>
                </div>
            </div>
        </section>

        <Form
            v-bind="storeOrder.form()"
            :options="{ preserveScroll: true }"
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start"
            v-slot="{ errors, processing, progress }"
        >
            <div class="grid gap-6">
                <Alert v-if="errors.cart" variant="destructive">
                    <AlertTitle>Your cart changed</AlertTitle>
                    <AlertDescription>{{ errors.cart }}</AlertDescription>
                </Alert>

                <section
                    class="border-border bg-card grid gap-6 border p-6"
                    aria-labelledby="recipient-heading"
                >
                    <div class="flex items-center gap-3">
                        <ShieldCheck
                            class="text-primary size-5"
                            aria-hidden="true"
                        />
                        <div>
                            <p
                                class="text-muted-foreground text-xs font-semibold tracking-widest uppercase"
                            >
                                Step 1
                            </p>
                            <h2 id="recipient-heading" class="font-bold">
                                Recipient details
                            </h2>
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="recipient-name">Recipient name</Label>
                            <Input
                                id="recipient-name"
                                name="recipient_name"
                                type="text"
                                maxlength="255"
                                autocomplete="name"
                                :default-value="customer.name"
                                :aria-invalid="Boolean(errors.recipient_name)"
                                required
                            />
                            <InputError :message="errors.recipient_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="contact-number">Contact number</Label>
                            <Input
                                id="contact-number"
                                name="contact_number"
                                type="tel"
                                maxlength="20"
                                autocomplete="tel"
                                placeholder="e.g. 0917 123 4567"
                                :aria-invalid="Boolean(errors.contact_number)"
                                required
                            />
                            <InputError :message="errors.contact_number" />
                        </div>
                    </div>
                </section>

                <section
                    class="border-border bg-card grid gap-6 border p-6"
                    aria-labelledby="fulfillment-heading"
                >
                    <div class="flex items-center gap-3">
                        <MapPin
                            class="text-primary size-5"
                            aria-hidden="true"
                        />
                        <div>
                            <p
                                class="text-muted-foreground text-xs font-semibold tracking-widest uppercase"
                            >
                                Step 2
                            </p>
                            <h2 id="fulfillment-heading" class="font-bold">
                                Fulfillment
                            </h2>
                        </div>
                    </div>

                    <div
                        class="grid gap-3 sm:grid-cols-2"
                        role="radiogroup"
                        aria-labelledby="fulfillment-heading"
                    >
                        <label
                            v-for="method in fulfillmentMethods"
                            :key="method.value"
                            class="border-border has-checked:border-primary has-checked:bg-primary/5 focus-within:ring-ring/50 grid cursor-pointer grid-cols-[auto_1fr] gap-3 border p-4 transition-colors focus-within:ring-3"
                        >
                            <input
                                class="accent-primary mt-1 size-4"
                                type="radio"
                                name="fulfillment_method"
                                :value="method.value"
                                :checked="fulfillmentMethod === method.value"
                                @change="chooseFulfillment(method.value)"
                            />
                            <span>
                                <span
                                    class="flex items-center gap-2 font-semibold"
                                >
                                    <Store
                                        v-if="method.value === 'pickup'"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <Truck
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ method.label }}
                                </span>
                                <span
                                    class="text-muted-foreground mt-1 block text-xs leading-5"
                                >
                                    {{
                                        method.value === "pickup"
                                            ? "Collect your order from Battlefront."
                                            : "Send the order to your supplied address."
                                    }}
                                </span>
                            </span>
                        </label>
                    </div>
                    <InputError :message="errors.fulfillment_method" />

                    <div
                        v-if="fulfillmentMethod === 'pickup'"
                        class="border-border bg-secondary/40 grid gap-4 border p-4"
                    >
                        <div class="flex items-start gap-3">
                            <Store
                                class="text-primary mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <p
                                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                                >
                                    Pickup location
                                </p>
                                <p class="mt-1 font-semibold">
                                    {{ pickupLocation.name }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="border-border bg-background grid gap-3 border p-4 text-sm"
                        >
                            <div class="flex items-start gap-3">
                                <MapPin
                                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <p class="leading-6">
                                    {{ pickupLocation.address }}
                                </p>
                            </div>
                            <div
                                v-if="
                                    pickupLocation.contact_number ||
                                    pickupLocation.operating_hours
                                "
                                class="border-border grid gap-3 border-t pt-3 sm:grid-cols-2"
                            >
                                <div
                                    v-if="pickupLocation.contact_number"
                                    class="flex items-center gap-3"
                                >
                                    <Phone
                                        class="text-muted-foreground size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="tabular-nums">
                                        {{ pickupLocation.contact_number }}
                                    </span>
                                </div>
                                <div
                                    v-if="pickupLocation.operating_hours"
                                    class="flex items-center gap-3"
                                >
                                    <Clock3
                                        class="text-muted-foreground size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span>
                                        {{ pickupLocation.operating_hours }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="fulfillmentMethod === 'delivery'"
                        class="grid gap-2"
                    >
                        <Label for="delivery-address">Delivery address</Label>
                        <Textarea
                            id="delivery-address"
                            name="delivery_address"
                            maxlength="255"
                            autocomplete="street-address"
                            :default-value="
                                customer.default_delivery_address ?? ''
                            "
                            placeholder="House or building, street, barangay, city, and province"
                            :aria-invalid="Boolean(errors.delivery_address)"
                            required
                        />
                        <p
                            v-if="customer.default_delivery_address"
                            class="text-muted-foreground text-xs leading-5"
                        >
                            Pre-filled from your profile. Changes here apply only
                            to this order.
                        </p>
                        <InputError :message="errors.delivery_address" />
                    </div>
                </section>

                <section
                    class="border-border bg-card grid gap-6 border p-6"
                    aria-labelledby="payment-heading"
                >
                    <div class="flex items-center gap-3">
                        <CreditCard
                            class="text-primary size-5"
                            aria-hidden="true"
                        />
                        <div>
                            <p
                                class="text-muted-foreground text-xs font-semibold tracking-widest uppercase"
                            >
                                Step 3
                            </p>
                            <h2 id="payment-heading" class="font-bold">
                                Payment method
                            </h2>
                        </div>
                    </div>

                    <div
                        class="grid gap-3 sm:grid-cols-2"
                        role="radiogroup"
                        aria-labelledby="payment-heading"
                    >
                        <label
                            v-for="method in paymentMethods"
                            :key="method.value"
                            class="border-border has-checked:border-primary has-checked:bg-primary/5 focus-within:ring-ring/50 grid cursor-pointer grid-cols-[auto_1fr] gap-3 border p-4 transition-colors focus-within:ring-3 has-disabled:cursor-not-allowed has-disabled:opacity-50"
                        >
                            <input
                                class="accent-primary mt-1 size-4"
                                type="radio"
                                name="payment_method"
                                :value="method.value"
                                :checked="paymentMethod === method.value"
                                :disabled="
                                    !method.available_for.includes(
                                        fulfillmentMethod,
                                    )
                                "
                                @change="choosePayment(method.value)"
                            />
                            <span>
                                <span class="font-semibold">{{
                                    method.label
                                }}</span>
                                <span
                                    class="text-muted-foreground mt-1 block text-xs leading-5"
                                >
                                    {{
                                        method.requires_proof
                                            ? "Manual e-wallet payment with proof."
                                            : fulfillmentMethod === "delivery"
                                              ? "Available for pickup only."
                                              : "Pay when you collect your order."
                                    }}
                                </span>
                            </span>
                        </label>
                    </div>
                    <InputError :message="errors.payment_method" />

                    <div
                        v-if="requiresPaymentProof"
                        class="border-border bg-secondary/40 grid gap-4 border p-4"
                    >
                        <div class="grid gap-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-semibold">
                                        {{ selectedPaymentMethod.label }}
                                        receiving account
                                    </p>
                                    <p
                                        class="text-muted-foreground mt-1 text-sm"
                                    >
                                        Send payment only to the account shown
                                        for this selected wallet.
                                    </p>
                                </div>
                                <span
                                    v-if="selectedPaymentAccount.is_demo"
                                    class="border-primary/40 bg-primary/10 text-primary shrink-0 border px-2 py-1 text-xs font-semibold tracking-wide uppercase"
                                >
                                    Demo details
                                </span>
                            </div>

                            <dl
                                class="border-border bg-background grid gap-3 border p-4 sm:grid-cols-2"
                            >
                                <div class="grid gap-1">
                                    <dt
                                        class="text-muted-foreground text-xs font-medium"
                                    >
                                        Account name
                                    </dt>
                                    <dd class="font-semibold">
                                        {{
                                            selectedPaymentAccount.account_name
                                        }}
                                    </dd>
                                </div>
                                <div class="grid gap-1">
                                    <dt
                                        class="text-muted-foreground text-xs font-medium"
                                    >
                                        Account/mobile number
                                    </dt>
                                    <dd
                                        class="font-semibold tracking-wide tabular-nums"
                                    >
                                        {{
                                            selectedPaymentAccount.account_number
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div class="border-border flex gap-3 border-t pt-4">
                            <Upload
                                class="text-primary mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <p class="font-semibold">
                                    Pay first, then capture proof
                                </p>
                                <p
                                    class="text-muted-foreground mt-1 text-sm leading-6"
                                >
                                    Complete payment to the account above first.
                                    After
                                    {{ selectedPaymentMethod.label }} shows a
                                    successful transaction, take a screenshot or
                                    snapshot and upload it below. Your proof
                                    remains pending manual admin verification
                                    and is not treated as verified.
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="payment-proof">Payment proof</Label>
                            <Input
                                :key="paymentProofKey"
                                id="payment-proof"
                                name="payment_proof"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                :aria-invalid="Boolean(errors.payment_proof)"
                                required
                            />
                            <p class="text-muted-foreground text-xs">
                                JPEG, PNG, or WebP up to 5 MB. Proof is handled
                                as private evidence.
                            </p>
                            <InputError :message="errors.payment_proof" />
                        </div>
                    </div>
                </section>
            </div>

            <aside
                class="border-border bg-card border p-6 xl:sticky xl:top-6"
                aria-labelledby="checkout-summary-heading"
            >
                <p
                    class="text-primary text-xs font-semibold tracking-widest uppercase"
                >
                    Current cart
                </p>
                <h2
                    id="checkout-summary-heading"
                    class="mt-2 text-xl font-bold"
                >
                    Hardware summary
                </h2>

                <ul class="border-border mt-6 divide-y border-y">
                    <li
                        v-for="item in cart.items"
                        :key="item.id"
                        class="grid grid-cols-[1fr_auto] gap-4 py-4"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">
                                {{ item.product.name }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                <template v-if="item.product.brand">
                                    {{ item.product.brand }} ·
                                </template>
                                Qty
                                {{ item.quantity }}
                            </p>
                        </div>
                        <p class="text-sm font-semibold tabular-nums">
                            {{ formatCurrency(item.line_total) }}
                        </p>
                    </li>
                </ul>

                <dl class="grid gap-3 py-5">
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-muted-foreground">Products</dt>
                        <dd class="font-semibold tabular-nums">
                            {{ cart.item_count }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-muted-foreground">Units</dt>
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

                <div v-if="progress" class="mb-4 grid gap-2" aria-live="polite">
                    <div class="flex justify-between gap-4 text-xs font-medium">
                        <span>Uploading payment proof</span>
                        <span>{{ progress.percentage }}%</span>
                    </div>
                    <progress
                        class="accent-primary h-2 w-full"
                        :value="progress.percentage"
                        max="100"
                    />
                </div>

                <Button type="submit" class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    <BadgeCheck v-else aria-hidden="true" />
                    {{ processing ? "Placing order..." : "Place order" }}
                </Button>

                <p
                    class="text-muted-foreground mt-3 text-center text-xs leading-5"
                >
                    Stock is deducted and your cart is cleared only after the
                    complete order succeeds.
                </p>

                <Button as-child variant="outline" class="mt-5 w-full">
                    <Link :href="cartIndex()">
                        <ArrowLeft aria-hidden="true" />
                        Back to cart
                    </Link>
                </Button>
            </aside>
        </Form>
    </main>
</template>
