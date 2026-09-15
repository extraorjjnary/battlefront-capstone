<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    Banknote,
    CircleAlert,
    CircleX,
    ExternalLink,
    MapPin,
    PackageCheck,
    ReceiptText,
    ShieldCheck,
    Truck,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import OrderPaymentProofController from '@/actions/App/Http/Controllers/Administration/OrderPaymentProofController';
import OrderPaymentStatusController from '@/actions/App/Http/Controllers/Administration/OrderPaymentStatusController';
import OrderStatusController from '@/actions/App/Http/Controllers/Administration/OrderStatusController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/currency';
import { orderStatusBadgeClass } from '@/lib/orderStatus';

const props = defineProps({
    order: { type: Object, required: true },
});

const selectedOrderStatus = ref('');
const orderConfirmationOpen = ref(false);
const paymentConfirmationOpen = ref(false);
const orderStatusForm = useForm({ status: '' });
const paymentStatusForm = useForm({
    payment_status: '',
    manual_verification_confirmed: false,
    rejection_reason: '',
    rejection_note: '',
});

const selectedOrderTransition = computed(() =>
    props.order.allowed_status_transitions.find(
        (transition) => transition.value === selectedOrderStatus.value,
    ),
);
const orderConfirmationTitle = computed(() =>
    selectedOrderStatus.value === 'completed'
        ? `Mark ${props.order.reference} completed?`
        : `Cancel ${props.order.reference}?`,
);
const orderConfirmationDescription = computed(() =>
    selectedOrderStatus.value === 'completed'
        ? 'Confirm that fulfillment is finished before completing this order.'
        : 'Confirm that this order should be cancelled.',
);
const paymentConfirmationTitle = computed(() =>
    paymentStatusForm.payment_status === 'verified'
        ? `Mark payment for ${props.order.reference} verified?`
        : `Reject payment for ${props.order.reference}?`,
);
const paymentConfirmationDescription = computed(() =>
    paymentStatusForm.payment_status === 'verified'
        ? 'Confirm that the payment was manually cross-checked in the selected payment platform or account.'
        : 'Confirm that the payment could not be verified and should be rejected.',
);
const rejectingWalletPayment = computed(
    () =>
        paymentStatusForm.payment_status === 'rejected' &&
        props.order.payment.method.requires_proof,
);

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'long',
    timeStyle: 'short',
});

function selectOrderStatus(status) {
    selectedOrderStatus.value = status;
    orderStatusForm.clearErrors();
}

function requestOrderStatusUpdate() {
    if (!selectedOrderTransition.value) {
        return;
    }

    orderStatusForm.status = selectedOrderStatus.value;

    if (['completed', 'cancelled'].includes(selectedOrderStatus.value)) {
        orderConfirmationOpen.value = true;

        return;
    }

    submitOrderStatus();
}

function submitOrderStatus() {
    orderStatusForm.submit(OrderStatusController.update(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            orderConfirmationOpen.value = false;
            selectedOrderStatus.value = '';
            orderStatusForm.reset();
        },
        onError: () => {
            orderConfirmationOpen.value = false;
        },
    });
}

function selectPaymentStatus(status) {
    paymentStatusForm.payment_status = status;
    paymentStatusForm.clearErrors();

    if (status !== 'verified') {
        paymentStatusForm.manual_verification_confirmed = false;
    }

    if (status !== 'rejected') {
        paymentStatusForm.rejection_reason = '';
        paymentStatusForm.rejection_note = '';
    }
}

function requestPaymentStatusUpdate() {
    if (!paymentStatusForm.payment_status) {
        return;
    }

    paymentConfirmationOpen.value = true;
}

function submitPaymentStatus() {
    paymentStatusForm.submit(
        OrderPaymentStatusController.update(props.order.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                paymentConfirmationOpen.value = false;
                paymentStatusForm.reset();
            },
            onError: () => {
                paymentConfirmationOpen.value = false;
            },
        },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Orders',
                href: OrderController.index(),
            },
            {
                title: 'Order record',
                href: OrderController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="order.reference" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
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
                        <ReceiptText class="size-5" />
                    </span>
                    <div>
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Administrator order record
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <h1
                                class="text-2xl font-bold tracking-tight tabular-nums sm:text-3xl"
                            >
                                {{ order.reference }}
                            </h1>
                            <Badge
                                variant="outline"
                                :class="
                                    orderStatusBadgeClass(order.status.value)
                                "
                            >
                                {{ order.status.label }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-2 text-sm">
                            Placed
                            {{
                                dateFormatter.format(new Date(order.created_at))
                            }}
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="OrderController.index()">
                        <ArrowLeft />
                        Back to orders
                    </Link>
                </Button>
            </div>
        </section>

        <div
            class="grid gap-8 xl:grid-cols-[minmax(0,1.6fr)_minmax(20rem,0.8fr)]"
        >
            <div class="flex min-w-0 flex-col gap-8">
                <section aria-labelledby="processing-details-heading">
                    <div class="mb-4">
                        <p class="text-muted-foreground text-sm">
                            Order snapshot
                        </p>
                        <h2
                            id="processing-details-heading"
                            class="text-xl font-semibold"
                        >
                            Processing details
                        </h2>
                    </div>

                    <div
                        class="border-border bg-card divide-border divide-y border"
                    >
                        <div class="grid md:grid-cols-2 md:divide-x">
                            <div class="flex gap-3 p-5">
                                <UserRound
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div class="min-w-0">
                                    <p class="text-muted-foreground text-sm">
                                        Customer account
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{ order.customer.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground truncate text-sm"
                                    >
                                        {{ order.customer.email }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-3 p-5">
                                <Truck
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p class="text-muted-foreground text-sm">
                                        Fulfillment
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{ order.fulfillment.label }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="grid md:grid-cols-2 md:divide-x">
                            <div class="flex gap-3 p-5">
                                <PackageCheck
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p class="text-muted-foreground text-sm">
                                        Recipient snapshot
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{ order.recipient.name }}
                                    </p>
                                    <p class="text-muted-foreground text-sm">
                                        {{ order.recipient.contact_number }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-3 p-5">
                                <MapPin
                                    class="text-primary mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p class="text-muted-foreground text-sm">
                                        Delivery address
                                    </p>
                                    <p class="mt-1 font-medium">
                                        {{
                                            order.fulfillment
                                                .delivery_address ??
                                            'Not applicable for store pickup'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="order-items-heading">
                    <div class="mb-4 flex items-end justify-between gap-4">
                        <div>
                            <p class="text-muted-foreground text-sm">
                                {{ order.total_quantity }} units across
                                {{ order.item_count }} lines
                            </p>
                            <h2
                                id="order-items-heading"
                                class="text-xl font-semibold"
                            >
                                Purchase-time item ledger
                            </h2>
                        </div>
                        <p class="font-semibold tabular-nums">
                            {{ formatCurrency(order.total) }}
                        </p>
                    </div>

                    <div
                        class="border-border bg-card divide-border divide-y border"
                    >
                        <article
                            v-for="item in order.items"
                            :key="item.id"
                            class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                        >
                            <div class="flex min-w-0 items-center gap-4">
                                <div
                                    class="bg-secondary size-14 shrink-0 overflow-hidden rounded-md"
                                >
                                    <img
                                        v-if="item.product.image_url"
                                        :src="item.product.image_url"
                                        :alt="item.product.name"
                                        class="size-full object-cover"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="truncate font-semibold">
                                        {{ item.product.name }}
                                    </h3>
                                    <p class="text-muted-foreground text-sm">
                                        {{ item.product.brand }}
                                    </p>
                                    <p
                                        class="text-muted-foreground mt-1 text-xs"
                                    >
                                        {{ item.quantity }} ×
                                        {{ formatCurrency(item.unit_price) }}
                                    </p>
                                </div>
                            </div>
                            <p class="font-semibold tabular-nums sm:text-right">
                                {{ formatCurrency(item.line_total) }}
                            </p>
                        </article>
                    </div>
                </section>
            </div>

            <aside class="flex flex-col gap-6" aria-label="Order actions">
                <section class="border-border bg-card border p-5">
                    <div class="flex items-start gap-3">
                        <ShieldCheck
                            class="text-primary mt-0.5 size-5 shrink-0"
                        />
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Current order status
                            </p>
                            <p class="font-semibold">
                                {{ order.status.label }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="order.allowed_status_transitions.length"
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <div class="grid gap-2">
                            <Label for="order-status">Move order to</Label>
                            <Select
                                :model-value="selectedOrderStatus"
                                @update:model-value="selectOrderStatus"
                            >
                                <SelectTrigger id="order-status" class="w-full">
                                    <SelectValue
                                        placeholder="Select next status"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="transition in order.allowed_status_transitions"
                                        :key="transition.value"
                                        :value="transition.value"
                                        :disabled="
                                            [
                                                'processing',
                                                'completed',
                                            ].includes(transition.value) &&
                                            order.payment.status.value !==
                                                'verified'
                                        "
                                    >
                                        {{ transition.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="
                                    ['pending', 'processing'].includes(
                                        order.status.value,
                                    ) &&
                                    order.payment.status.value !== 'verified'
                                "
                                class="text-muted-foreground text-xs leading-5"
                            >
                                Verify payment before moving this order to
                                {{
                                    order.status.value === 'pending'
                                        ? 'Processing'
                                        : 'Completed'
                                }}. Cancellation remains available.
                            </p>
                            <InputError
                                :message="orderStatusForm.errors.status"
                            />
                        </div>
                        <Button
                            type="button"
                            class="w-full"
                            :disabled="
                                !selectedOrderStatus ||
                                orderStatusForm.processing
                            "
                            @click="requestOrderStatusUpdate"
                        >
                            <Spinner v-if="orderStatusForm.processing" />
                            Update order status
                        </Button>
                    </div>
                    <p v-else class="text-muted-foreground mt-4 text-sm">
                        This order is in a terminal state and cannot be changed.
                    </p>

                    <Dialog v-model:open="orderConfirmationOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {{ orderConfirmationTitle }}
                                </DialogTitle>
                                <DialogDescription>
                                    {{ orderConfirmationDescription }}
                                </DialogDescription>
                            </DialogHeader>
                            <div
                                class="flex items-start gap-3 border p-3 text-sm"
                                :class="
                                    selectedOrderStatus === 'completed'
                                        ? 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200'
                                        : 'border-destructive/40 bg-destructive/10 text-destructive'
                                "
                            >
                                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                                <p class="leading-5 font-medium">
                                    {{
                                        selectedOrderStatus === 'completed'
                                            ? 'Completed is a terminal order status and cannot be undone.'
                                            : 'Cancelled is a terminal order status and cannot be undone.'
                                    }}
                                </p>
                            </div>
                            <DialogFooter class="mt-6 gap-2">
                                <DialogClose as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="hover:bg-muted hover:text-foreground"
                                    >
                                        Keep current status
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    :variant="
                                        selectedOrderStatus === 'cancelled'
                                            ? 'destructive'
                                            : 'default'
                                    "
                                    :class="
                                        selectedOrderStatus === 'completed'
                                            ? 'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-500/40 dark:bg-emerald-600 dark:hover:bg-emerald-500'
                                            : 'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600/40 dark:bg-red-700 dark:hover:bg-red-600'
                                    "
                                    :disabled="orderStatusForm.processing"
                                    @click="submitOrderStatus"
                                >
                                    <Spinner
                                        v-if="orderStatusForm.processing"
                                    />
                                    Confirm {{ selectedOrderTransition?.label }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>

                <section class="border-border bg-card border p-5">
                    <div class="flex items-start gap-3">
                        <Banknote class="text-primary mt-0.5 size-5 shrink-0" />
                        <div>
                            <p class="text-muted-foreground text-sm">
                                Payment method
                            </p>
                            <p class="font-semibold">
                                {{ order.payment.method.label }}
                            </p>
                            <Badge
                                variant="outline"
                                class="mt-2"
                                :class="
                                    orderStatusBadgeClass(
                                        order.payment.status.value,
                                    )
                                "
                            >
                                {{ order.payment.status.label }}
                            </Badge>
                        </div>
                    </div>

                    <div
                        v-if="order.payment.method.requires_proof"
                        class="border-border mt-5 border-t pt-5"
                    >
                        <p class="text-sm font-medium">Submitted evidence</p>
                        <template v-if="order.payment.proof_available">
                            <a
                                :href="
                                    OrderPaymentProofController(order.id).url
                                "
                                target="_blank"
                                rel="noopener noreferrer"
                                class="border-border bg-secondary mt-3 block overflow-hidden border"
                            >
                                <img
                                    :src="
                                        OrderPaymentProofController(order.id)
                                            .url
                                    "
                                    :alt="`Payment proof for ${order.reference}`"
                                    class="max-h-80 w-full object-contain"
                                />
                            </a>
                            <Button
                                variant="outline"
                                size="sm"
                                class="mt-3"
                                as-child
                            >
                                <a
                                    :href="
                                        OrderPaymentProofController(order.id)
                                            .url
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Open full proof
                                    <ExternalLink />
                                </a>
                            </Button>
                        </template>
                        <div
                            v-else
                            class="border-destructive/40 text-destructive mt-3 flex gap-2 border p-3 text-sm"
                        >
                            <CircleAlert class="mt-0.5 size-4 shrink-0" />
                            <p>
                                {{
                                    order.payment.proof_submitted
                                        ? 'The recorded proof file is unavailable.'
                                        : 'No payment proof was submitted.'
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="order.payment.status.value === 'pending'"
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <div class="text-muted-foreground text-sm leading-6">
                            <p v-if="order.payment.method.requires_proof">
                                Proof is evidence only. Cross-check the
                                transaction in the selected
                                {{ order.payment.method.label }} platform and
                                Battlefront account before verification.
                            </p>
                            <p v-else>
                                Confirm this
                                {{ order.payment.method.label.toLowerCase() }}
                                payment manually at the store before
                                verification.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="payment-status">Payment decision</Label>
                            <Select
                                :model-value="paymentStatusForm.payment_status"
                                @update:model-value="selectPaymentStatus"
                            >
                                <SelectTrigger
                                    id="payment-status"
                                    class="w-full"
                                >
                                    <SelectValue
                                        placeholder="Select decision"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        value="verified"
                                        :disabled="
                                            order.payment.method
                                                .requires_proof &&
                                            !order.payment.proof_available
                                        "
                                    >
                                        Verified
                                    </SelectItem>
                                    <SelectItem
                                        value="rejected"
                                        :disabled="
                                            order.status.value === 'completed'
                                        "
                                    >
                                        Rejected
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="
                                    paymentStatusForm.errors.payment_status
                                "
                            />
                        </div>

                        <div
                            v-if="
                                paymentStatusForm.payment_status === 'verified'
                            "
                            class="grid gap-3"
                        >
                            <div class="flex items-start gap-3">
                                <Checkbox
                                    id="manual-verification-confirmed"
                                    v-model="
                                        paymentStatusForm.manual_verification_confirmed
                                    "
                                />
                                <Label
                                    for="manual-verification-confirmed"
                                    class="text-sm leading-5 font-normal"
                                >
                                    I manually cross-checked and confirmed this
                                    payment.
                                </Label>
                            </div>
                            <InputError
                                :message="
                                    paymentStatusForm.errors
                                        .manual_verification_confirmed
                                "
                            />
                        </div>

                        <div v-if="rejectingWalletPayment" class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="rejection-reason">
                                    Rejection reason
                                </Label>
                                <Select
                                    v-model="paymentStatusForm.rejection_reason"
                                >
                                    <SelectTrigger
                                        id="rejection-reason"
                                        class="w-full"
                                    >
                                        <SelectValue
                                            placeholder="Select reason"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="reason in order.payment
                                                .rejection_reasons"
                                            :key="reason.value"
                                            :value="reason.value"
                                        >
                                            {{ reason.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="
                                        paymentStatusForm.errors
                                            .rejection_reason
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="rejection-note">
                                    Additional note
                                    <span
                                        v-if="
                                            paymentStatusForm.rejection_reason !==
                                            'other'
                                        "
                                        class="text-muted-foreground font-normal"
                                    >
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="rejection-note"
                                    v-model="paymentStatusForm.rejection_note"
                                    maxlength="255"
                                    rows="3"
                                    placeholder="Add short context for the customer"
                                />
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <InputError
                                        :message="
                                            paymentStatusForm.errors
                                                .rejection_note
                                        "
                                    />
                                    <span
                                        class="text-muted-foreground ml-auto text-xs tabular-nums"
                                    >
                                        {{
                                            paymentStatusForm.rejection_note
                                                .length
                                        }}/255
                                    </span>
                                </div>
                            </div>
                        </div>

                        <Button
                            type="button"
                            class="w-full"
                            :disabled="
                                !paymentStatusForm.payment_status ||
                                paymentStatusForm.processing ||
                                (paymentStatusForm.payment_status ===
                                    'verified' &&
                                    (!paymentStatusForm.manual_verification_confirmed ||
                                        (order.payment.method.requires_proof &&
                                            !order.payment.proof_available))) ||
                                (rejectingWalletPayment &&
                                    (!paymentStatusForm.rejection_reason ||
                                        (paymentStatusForm.rejection_reason ===
                                            'other' &&
                                            !paymentStatusForm.rejection_note.trim())))
                            "
                            @click="requestPaymentStatusUpdate"
                        >
                            Apply payment decision
                        </Button>
                    </div>
                    <div v-else class="mt-4 grid gap-3">
                        <p class="text-muted-foreground text-sm">
                            This payment decision is final for this review.
                        </p>
                        <div
                            v-if="
                                order.payment.status.value === 'rejected' &&
                                order.payment.method.requires_proof
                            "
                            class="border-destructive/40 bg-destructive/10 text-destructive border p-3 text-sm"
                        >
                            <p class="font-semibold">
                                {{
                                    order.payment.rejection?.reason ??
                                    'No rejection reason was recorded.'
                                }}
                            </p>
                            <p
                                v-if="order.payment.rejection?.note"
                                class="mt-1 leading-5"
                            >
                                {{ order.payment.rejection.note }}
                            </p>
                        </div>
                    </div>

                    <Dialog v-model:open="paymentConfirmationOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {{ paymentConfirmationTitle }}
                                </DialogTitle>
                                <DialogDescription>
                                    {{ paymentConfirmationDescription }}
                                </DialogDescription>
                            </DialogHeader>
                            <div
                                class="flex items-start gap-3 border p-3 text-sm"
                                :class="
                                    paymentStatusForm.payment_status ===
                                    'verified'
                                        ? 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200'
                                        : 'border-destructive/40 bg-destructive/10 text-destructive'
                                "
                            >
                                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                                <p class="leading-5 font-medium">
                                    {{
                                        paymentStatusForm.payment_status ===
                                        'verified'
                                            ? 'Verification is a final payment decision and cannot be undone.'
                                            : 'Rejection is final and does not cancel the order.'
                                    }}
                                </p>
                            </div>
                            <DialogFooter class="mt-6 gap-2">
                                <DialogClose as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="hover:bg-muted hover:text-foreground"
                                    >
                                        Keep payment pending
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="button"
                                    :variant="
                                        paymentStatusForm.payment_status ===
                                        'rejected'
                                            ? 'destructive'
                                            : 'default'
                                    "
                                    :class="
                                        paymentStatusForm.payment_status ===
                                        'verified'
                                            ? 'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-500/40 dark:bg-emerald-600 dark:hover:bg-emerald-500'
                                            : 'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600/40 dark:bg-red-700 dark:hover:bg-red-600'
                                    "
                                    :disabled="paymentStatusForm.processing"
                                    @click="submitPaymentStatus"
                                >
                                    <Spinner
                                        v-if="paymentStatusForm.processing"
                                    />
                                    <BadgeCheck
                                        v-else-if="
                                            paymentStatusForm.payment_status ===
                                            'verified'
                                        "
                                    />
                                    <CircleX v-else />
                                    Confirm
                                    {{
                                        paymentStatusForm.payment_status ===
                                        'verified'
                                            ? 'verification'
                                            : 'rejection'
                                    }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>
            </aside>
        </div>
    </main>
</template>
