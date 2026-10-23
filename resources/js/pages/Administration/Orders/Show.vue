<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
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
import OrderHandlerController from '@/actions/App/Http/Controllers/Administration/OrderHandlerController';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';
import OrderPaymentProofController from '@/actions/App/Http/Controllers/Administration/OrderPaymentProofController';
import OrderPaymentStatusController from '@/actions/App/Http/Controllers/Administration/OrderPaymentStatusController';
import OrderManualShipmentController from '@/actions/App/Http/Controllers/Administration/OrderManualShipmentController';
import OrderStatusController from '@/actions/App/Http/Controllers/Administration/OrderStatusController';
import OrderShipmentStatusController from '@/actions/App/Http/Controllers/Administration/OrderShipmentStatusController';
import OrderShipmentReferenceController from '@/actions/App/Http/Controllers/Administration/OrderShipmentReferenceController';
import InputError from '@/components/InputError.vue';
import ShipmentTracking from '@/components/ShipmentTracking.vue';
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
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/dateTime';
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
import { store as confirmCodCollection } from '@/routes/administration/orders/cod-collection';
import { store as confirmPickup } from '@/routes/administration/orders/pickup-confirmation';

const props = defineProps({
    order: { type: Object, required: true },
    handler_options: { type: Array, default: () => [] },
});

const handlerForm = useForm({
    handler_id: props.order.assigned_handler_id ?? '',
});
const claimForm = useForm({});
const page = usePage();

const selectedOrderStatus = ref('');
const orderConfirmationOpen = ref(false);
const paymentConfirmationOpen = ref(false);
const codConfirmationOpen = ref(false);
const codCollectionForm = useForm({ cod_collection_confirmed: false });
const pickupConfirmationOpen = ref(false);
const pickupConfirmationForm = useForm({
    payment_received_confirmed: false,
    products_handed_over_confirmed: false,
});
const orderStatusForm = useForm({ status: '' });
const manualShipmentDialogOpen = ref(false);
const manualShipmentForm = useForm({
    arrangement_confirmed: false,
    carrier: '',
    tracking_reference: '',
    eta_min_days: '',
    eta_max_days: '',
});
const shipmentConfirmationOpen = ref(false);
const shipmentStatusForm = useForm({ status: '', tracking_reference: null });
const shipmentReferenceForm = useForm({
    tracking_reference: props.order.shipment?.tracking_reference ?? '',
});
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
        : props.order.payment.status.value === 'verified'
          ? 'Cancelling restores reserved stock. Verified payment stays recorded; any refund requires separate manual handling.'
          : 'Cancelling restores reserved stock and keeps the order in history.',
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

function requestCodConfirmation() {
    if (
        !props.order.payment.can_confirm_cod_collection ||
        codCollectionForm.processing
    ) {
        return;
    }
    codCollectionForm.reset();
    codCollectionForm.clearErrors();
    codConfirmationOpen.value = true;
}

function submitCodCollection() {
    if (
        !props.order.payment.can_confirm_cod_collection ||
        !codCollectionForm.cod_collection_confirmed ||
        codCollectionForm.processing
    ) {
        return;
    }
    codCollectionForm.submit(confirmCodCollection(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            codConfirmationOpen.value = false;
            codCollectionForm.reset();
        },
    });
}

function requestPickupConfirmation() {
    if (
        !props.order.payment.can_confirm_pickup ||
        pickupConfirmationForm.processing
    ) {
        return;
    }
    pickupConfirmationForm.reset();
    pickupConfirmationForm.clearErrors();
    pickupConfirmationOpen.value = true;
}

function submitPickupConfirmation() {
    if (
        !props.order.payment.can_confirm_pickup ||
        !pickupConfirmationForm.payment_received_confirmed ||
        !pickupConfirmationForm.products_handed_over_confirmed ||
        pickupConfirmationForm.processing
    ) {
        return;
    }
    pickupConfirmationForm.submit(confirmPickup(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            pickupConfirmationOpen.value = false;
            pickupConfirmationForm.reset();
        },
    });
}

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

function requestShipmentUpdate() {
    const next = props.order.shipment_actions?.next_status;
    if (!next || shipmentStatusForm.processing) {
        return;
    }
    shipmentStatusForm.status = next.value;
    if (next.value === 'delivered') {
        shipmentConfirmationOpen.value = true;
        return;
    }
    submitShipmentStatus();
}

function submitShipmentStatus() {
    shipmentStatusForm.submit(
        OrderShipmentStatusController.update(props.order.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                shipmentConfirmationOpen.value = false;
                shipmentStatusForm.reset();
                shipmentReferenceForm.tracking_reference =
                    props.order.shipment?.tracking_reference ?? '';
            },
            onError: () => {
                shipmentConfirmationOpen.value = false;
            },
        },
    );
}

function submitShipmentReference() {
    shipmentReferenceForm.submit(
        OrderShipmentReferenceController.update(props.order.id),
        {
            preserveScroll: true,
        },
    );
}

function requestManualShipment() {
    if (
        !props.order.can_create_manual_shipment ||
        manualShipmentForm.processing
    ) {
        return;
    }

    manualShipmentForm.reset();
    manualShipmentForm.clearErrors();
    manualShipmentDialogOpen.value = true;
}

function submitManualShipment() {
    if (
        !props.order.can_create_manual_shipment ||
        !manualShipmentForm.arrangement_confirmed ||
        !manualShipmentForm.carrier ||
        manualShipmentForm.processing
    ) {
        return;
    }

    manualShipmentForm.post(
        OrderManualShipmentController.store(props.order.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                manualShipmentDialogOpen.value = false;
                manualShipmentForm.reset();
            },
        },
    );
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
                            {{ formatDateTime(order.created_at, 'long') }}
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

        <section
            class="border-border bg-card flex flex-wrap items-end gap-4 border p-5"
        >
            <div class="min-w-48 flex-1">
                <p class="font-semibold">Assigned sales handler</p>
                <p class="text-muted-foreground mt-1 text-sm">
                    {{ order.assigned_handler_name ?? 'Unassigned' }}
                </p>
                <p
                    v-if="order.handler_required && !order.assigned_handler_id"
                    class="text-destructive mt-1 text-xs"
                >
                    Assign a handler before completing this order.
                </p>
            </div>
            <form
                v-if="
                    page.props.auth.can?.processOrders &&
                    !order.assigned_handler_id &&
                    !['completed', 'cancelled'].includes(order.status.value)
                "
                @submit.prevent="
                    claimForm.post(OrderHandlerController.claim(order.id))
                "
            >
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="claimForm.processing"
                    >Claim order</Button
                >
            </form>
            <form
                v-if="
                    handler_options.length && page.props.auth.can?.assignOrders
                "
                class="flex items-end gap-2"
                @submit.prevent="
                    handlerForm.patch(OrderHandlerController.assign(order.id))
                "
            >
                <div>
                    <Label for="assigned-handler">Assign handler</Label>
                    <select
                        id="assigned-handler"
                        v-model="handlerForm.handler_id"
                        class="border-input bg-background mt-1 block h-9 min-w-44 border px-2 text-sm"
                    >
                        <option value="">Select staff</option>
                        <option
                            v-for="handler in handler_options"
                            :key="handler.id"
                            :value="handler.id"
                        >
                            {{ handler.name }}
                        </option>
                    </select>
                </div>
                <Button
                    type="submit"
                    :disabled="
                        !handlerForm.handler_id || handlerForm.processing
                    "
                    >Assign</Button
                >
            </form>
            <Link
                v-if="order.status.value === 'completed'"
                :href="
                    CustomerReturnController.index({
                        query: { order_id: order.id },
                    })
                "
                class="text-primary text-sm underline"
                >Record return</Link
            >
        </section>

        <div
            class="grid gap-8 xl:grid-cols-[minmax(0,1.6fr)_minmax(20rem,0.8fr)]"
        >
            <div class="flex min-w-0 flex-col gap-8">
                <ShipmentTracking
                    v-if="order.shipment && page.props.auth.can?.processOrders"
                    :shipment="order.shipment"
                    :quote="order.delivery_quote"
                />
                <div
                    v-if="
                        order.delivery_quote?.pricing_configured === false &&
                        !order.shipment
                    "
                    class="border-border bg-secondary/40 grid gap-3 border p-5 text-sm"
                    role="status"
                >
                    <p class="font-semibold">No configured delivery rate</p>
                    <p class="text-muted-foreground leading-6">
                        This order remains at its saved product subtotal with no
                        delivery fee. No LBC quote, booking, or estimate is
                        implied. Recording a manually arranged LBC shipment will
                        not change the saved order amount or add a fee.
                    </p>
                    <p class="text-muted-foreground text-xs leading-5">
                        {{ order.delivery_quote.assumption_label }}
                    </p>
                    <div
                        v-if="
                            order.can_create_manual_shipment &&
                            page.props.auth.can?.processOrders
                        "
                    >
                        <Button type="button" @click="requestManualShipment">
                            <Truck />
                            Create Manual Shipment
                        </Button>
                        <Dialog v-model:open="manualShipmentDialogOpen">
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        Create Manual Shipment
                                    </DialogTitle>
                                    <DialogDescription>
                                        Record an LBC arrangement confirmed
                                        outside checkout. This does not add a
                                        delivery fee or change the order total.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-4">
                                    <div class="grid gap-2">
                                        <Label for="manual-shipment-carrier">
                                            Carrier
                                        </Label>
                                        <Select
                                            :model-value="
                                                manualShipmentForm.carrier
                                            "
                                            @update:model-value="
                                                manualShipmentForm.carrier =
                                                    $event
                                            "
                                        >
                                            <SelectTrigger
                                                id="manual-shipment-carrier"
                                                class="w-full"
                                            >
                                                <SelectValue
                                                    placeholder="Select confirmed carrier"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="lbc">
                                                    LBC
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            :message="
                                                manualShipmentForm.errors
                                                    .carrier
                                            "
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="manual-tracking-reference">
                                            LBC tracking / reference (optional)
                                        </Label>
                                        <Input
                                            id="manual-tracking-reference"
                                            v-model="
                                                manualShipmentForm.tracking_reference
                                            "
                                            maxlength="255"
                                            placeholder="Enter only a reference actually supplied by LBC"
                                        />
                                        <InputError
                                            :message="
                                                manualShipmentForm.errors
                                                    .tracking_reference
                                            "
                                        />
                                    </div>
                                    <fieldset class="grid gap-2">
                                        <legend class="text-sm font-medium">
                                            Estimated delivery from preparation
                                            (optional)
                                        </legend>
                                        <p
                                            class="text-muted-foreground text-xs leading-5"
                                        >
                                            Enter both day values only when an
                                            estimate is available. Leave both
                                            blank if no estimate has been
                                            supplied.
                                        </p>
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div class="grid gap-2">
                                                <Label for="manual-eta-min">
                                                    Minimum days
                                                </Label>
                                                <Input
                                                    id="manual-eta-min"
                                                    v-model="
                                                        manualShipmentForm.eta_min_days
                                                    "
                                                    type="number"
                                                    min="0"
                                                    max="3650"
                                                    step="1"
                                                />
                                                <InputError
                                                    :message="
                                                        manualShipmentForm
                                                            .errors.eta_min_days
                                                    "
                                                />
                                            </div>
                                            <div class="grid gap-2">
                                                <Label for="manual-eta-max">
                                                    Maximum days
                                                </Label>
                                                <Input
                                                    id="manual-eta-max"
                                                    v-model="
                                                        manualShipmentForm.eta_max_days
                                                    "
                                                    type="number"
                                                    min="0"
                                                    max="3650"
                                                    step="1"
                                                />
                                                <InputError
                                                    :message="
                                                        manualShipmentForm
                                                            .errors.eta_max_days
                                                    "
                                                />
                                            </div>
                                        </div>
                                    </fieldset>
                                    <div class="grid gap-2">
                                        <div class="flex items-start gap-3">
                                            <Checkbox
                                                id="manual-arrangement-confirmed"
                                                v-model="
                                                    manualShipmentForm.arrangement_confirmed
                                                "
                                            />
                                            <Label
                                                for="manual-arrangement-confirmed"
                                                class="text-sm leading-5 font-normal"
                                            >
                                                I confirm LBC has accepted this
                                                delivery arrangement. I
                                                understand no delivery fee or
                                                estimate is added unless
                                                explicitly entered above.
                                            </Label>
                                        </div>
                                        <InputError
                                            :message="
                                                manualShipmentForm.errors
                                                    .arrangement_confirmed
                                            "
                                        />
                                    </div>
                                </div>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline">
                                            Keep unconfigured
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        :disabled="
                                            !manualShipmentForm.arrangement_confirmed ||
                                            !manualShipmentForm.carrier ||
                                            manualShipmentForm.processing
                                        "
                                        @click="submitManualShipment"
                                    >
                                        <Spinner
                                            v-if="manualShipmentForm.processing"
                                        />
                                        Create Manual Shipment
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                    <p v-else class="text-muted-foreground text-sm">
                        Manual shipment setup is unavailable for this order's
                        current status or payment state.
                    </p>
                </div>
                <section aria-labelledby="processing-details-heading">
                    <div class="mb-4">
                        <p class="text-muted-foreground text-sm">
                            Order details at purchase
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
                                        Recipient details at purchase
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
                                    <p
                                        v-if="
                                            order.fulfillment.value ===
                                                'delivery' &&
                                            order.fulfillment
                                                .delivery_destination
                                        "
                                        class="text-muted-foreground mt-1 text-sm"
                                    >
                                        {{
                                            order.fulfillment
                                                .delivery_destination
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="order-items-heading">
                    <div
                        class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                    >
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
                        <dl
                            class="grid grid-cols-3 gap-4 text-sm sm:text-right"
                        >
                            <div>
                                <dt class="text-muted-foreground">
                                    Product subtotal
                                </dt>
                                <dd class="mt-1 font-semibold tabular-nums">
                                    {{
                                        order.product_subtotal === null
                                            ? 'Not recorded'
                                            : formatCurrency(
                                                  order.product_subtotal,
                                              )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Delivery fee
                                </dt>
                                <dd class="mt-1 font-semibold tabular-nums">
                                    {{ formatCurrency(order.delivery_fee) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Final total
                                </dt>
                                <dd class="mt-1 font-semibold tabular-nums">
                                    {{ formatCurrency(order.total) }}
                                </dd>
                            </div>
                        </dl>
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
                    <dl
                        v-if="order.profitability"
                        class="border-border mt-4 grid gap-3 border p-4 sm:grid-cols-3"
                    >
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Cost of goods sold
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    formatCurrency(
                                        order.profitability.cost_of_goods_sold,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Sales returns
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    formatCurrency(
                                        order.profitability.sales_returns,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Gross profit
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    order.profitability.available
                                        ? formatCurrency(
                                              order.profitability.gross_profit,
                                          )
                                        : 'Unavailable'
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <aside class="flex flex-col gap-6" aria-label="Order actions">
                <section
                    v-if="order.shipment"
                    class="border-border bg-card grid gap-4 border p-5"
                    aria-label="Shipment actions"
                >
                    <h2 class="font-semibold">Manual shipment actions</h2>
                    <template v-if="order.shipment_actions?.next_status">
                        <div
                            v-if="
                                order.shipment_actions.next_status.value ===
                                'handed_to_lbc'
                            "
                            class="grid gap-2"
                        >
                            <Label for="handoff-reference"
                                >Real LBC tracking / reference (optional)</Label
                            >
                            <Input
                                id="handoff-reference"
                                v-model="shipmentStatusForm.tracking_reference"
                                maxlength="255"
                            />
                            <p class="text-muted-foreground text-xs">
                                Leave blank unless LBC has supplied a real
                                value.
                            </p>
                            <InputError
                                :message="
                                    shipmentStatusForm.errors.tracking_reference
                                "
                            />
                        </div>
                        <Button
                            type="button"
                            :disabled="
                                shipmentStatusForm.processing ||
                                shipmentReferenceForm.processing
                            "
                            @click="requestShipmentUpdate"
                        >
                            <Spinner v-if="shipmentStatusForm.processing" />
                            Mark {{ order.shipment_actions.next_status.label }}
                        </Button>
                    </template>
                    <p
                        v-else-if="
                            ['pending', 'processing'].includes(
                                order.status.value,
                            ) &&
                            !['delivered', 'cancelled'].includes(
                                order.shipment.status.value,
                            )
                        "
                        class="text-muted-foreground text-sm leading-5"
                    >
                        {{
                            order.payment.method.value === 'cod'
                                ? 'Move the COD order to Processing before advancing shipment milestones. Payment remains Pending until collection is confirmed after delivery.'
                                : 'Verify payment and move the order to Processing before advancing shipment milestones.'
                        }}
                    </p>
                    <p v-else class="text-muted-foreground text-sm">
                        Shipment progression is closed for this order.
                    </p>
                    <InputError :message="shipmentStatusForm.errors.status" />

                    <form
                        v-if="order.shipment_actions?.can_update_reference"
                        class="border-border grid gap-3 border-t pt-4"
                        @submit.prevent="submitShipmentReference"
                    >
                        <Label for="shipment-reference"
                            >LBC tracking / reference</Label
                        >
                        <Input
                            id="shipment-reference"
                            v-model="shipmentReferenceForm.tracking_reference"
                            maxlength="255"
                        />
                        <p class="text-muted-foreground text-xs">
                            Enter only a supplied reference. Clear the field to
                            remove an incorrect value.
                        </p>
                        <InputError
                            :message="
                                shipmentReferenceForm.errors.tracking_reference
                            "
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="
                                shipmentReferenceForm.processing ||
                                shipmentStatusForm.processing
                            "
                        >
                            <Spinner v-if="shipmentReferenceForm.processing" />
                            Save reference
                        </Button>
                    </form>

                    <Dialog v-model:open="shipmentConfirmationOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle
                                    >Mark
                                    {{ order.reference }}
                                    delivered?</DialogTitle
                                >
                                <DialogDescription>{{
                                    order.payment.method.value === 'cod'
                                        ? 'Confirm the delivery has finished. Payment remains Pending and the order remains Processing until COD collection is manually confirmed. No sale is recorded yet.'
                                        : 'Confirm the delivery has finished. This completes the order and records its sale.'
                                }}</DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child
                                    ><Button type="button" variant="outline"
                                        >Keep out for delivery</Button
                                    ></DialogClose
                                >
                                <Button
                                    type="button"
                                    :disabled="shipmentStatusForm.processing"
                                    @click="submitShipmentStatus"
                                >
                                    <Spinner
                                        v-if="shipmentStatusForm.processing"
                                    />
                                    Confirm delivered
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </section>
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

                    <p
                        v-if="
                            order.cancellation_blocked_reason &&
                            ['pending', 'processing'].includes(
                                order.status.value,
                            )
                        "
                        class="text-muted-foreground mt-4 text-sm leading-5"
                    >
                        {{ order.cancellation_blocked_reason }}
                    </p>
                    <div
                        v-if="
                            page.props.auth.can?.processOrders &&
                            order.allowed_status_transitions.length
                        "
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
                                    order.payment.method.value !== 'cod' &&
                                    !order.payment.pay_at_pickup &&
                                    order.payment.status.value !== 'verified'
                                "
                                class="text-muted-foreground text-xs leading-5"
                            >
                                Verify payment before moving this order to
                                {{
                                    order.status.value === 'pending'
                                        ? 'Processing'
                                        : 'Completed'
                                }}.<template
                                    v-if="
                                        order.allowed_status_transitions.some(
                                            (transition) =>
                                                transition.value ===
                                                'cancelled',
                                        )
                                    "
                                >
                                    Cancellation remains available.</template
                                >
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
                        <template
                            v-if="
                                ['completed', 'cancelled'].includes(
                                    order.status.value,
                                )
                            "
                        >
                            This order is in a terminal state and cannot be
                            changed.
                        </template>
                        <template
                            v-else-if="order.payment.can_confirm_cod_collection"
                        >
                            Delivery is recorded. Confirm COD Collection below
                            to complete the order and record its sale.
                        </template>
                        <template v-else-if="order.payment.pay_at_pickup">
                            Confirm payment and product handover below to
                            complete pickup.
                        </template>
                        <template v-else>
                            Continue delivery using the shipment milestones
                            above.
                        </template>
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
                        v-if="order.payment.gateway"
                        class="border-border mt-5 border-t pt-5"
                    >
                        <p class="text-sm font-medium">Online payment</p>
                        <dl class="text-muted-foreground mt-3 grid gap-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt>Provider flow</dt>
                                <dd class="text-foreground text-right">
                                    {{ order.payment.gateway.channel === 'hosted_checkout' ? 'Hosted Checkout' : 'Direct Online Banking' }}
                                </dd>
                            </div>
                            <div v-if="order.payment.gateway.bank" class="flex justify-between gap-4">
                                <dt>Bank</dt>
                                <dd class="text-foreground uppercase">{{ order.payment.gateway.bank }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Reconciliation status</dt>
                                <dd class="text-foreground">{{ order.payment.gateway.status.replaceAll('_', ' ') }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Merchant cutoff</dt>
                                <dd class="text-foreground text-right">{{ formatDateTime(order.payment.gateway.merchant_cutoff_at) }}</dd>
                            </div>
                        </dl>
                        <p
                            v-if="['closing', 'failed', 'retrying', 'reconciliation_required'].includes(order.payment.gateway.status)"
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            Provider confirmation is still required. Keep this order pending and inventory reserved until reconciliation completes.
                        </p>
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
                        v-if="
                            page.props.auth.can?.processOrders &&
                            order.payment.pay_at_pickup &&
                            !['completed', 'cancelled'].includes(
                                order.status.value,
                            )
                        "
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <p class="font-semibold">
                            Payment and pickup confirmation
                        </p>
                        <p class="text-muted-foreground text-sm leading-6">
                            {{ order.payment.method.description }}
                            Move the order to Processing to prepare it. Confirm
                            payment and product handover together when the
                            customer collects it. This completes pickup and
                            records the sale once.
                        </p>
                        <p
                            v-if="order.payment.status.value === 'verified'"
                            class="text-muted-foreground text-sm"
                        >
                            Payment is already verified. Confirm product
                            handover to complete pickup.
                        </p>
                        <Button
                            v-if="
                                page.props.auth.can?.processOrders &&
                                order.payment.can_confirm_pickup
                            "
                            type="button"
                            class="w-full whitespace-normal"
                            :disabled="pickupConfirmationForm.processing"
                            @click="requestPickupConfirmation"
                        >
                            Confirm Payment &amp; Complete Pickup
                        </Button>
                        <p v-else class="text-muted-foreground text-sm">
                            Confirmation requires an eligible Processing pickup
                            order.
                        </p>
                        <InputError
                            :message="
                                pickupConfirmationForm.errors
                                    .payment_received_confirmed
                            "
                        />
                        <InputError
                            :message="
                                pickupConfirmationForm.errors
                                    .products_handed_over_confirmed
                            "
                        />
                        <Dialog v-model:open="pickupConfirmationOpen">
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle
                                        >Confirm Payment &amp; Complete
                                        Pickup</DialogTitle
                                    >
                                    <DialogDescription>
                                        Confirm payment of
                                        {{ formatCurrency(order.total) }} and
                                        product handover for
                                        {{ order.reference }}. This verifies
                                        payment, completes pickup, and records
                                        its sale.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="pickup-payment-confirmed"
                                        v-model="
                                            pickupConfirmationForm.payment_received_confirmed
                                        "
                                    />
                                    <Label
                                        for="pickup-payment-confirmed"
                                        class="text-sm leading-5 font-normal"
                                    >
                                        {{
                                            order.payment.method.value ===
                                            'cash'
                                                ? 'I confirm that the full cash payment was received.'
                                                : 'I confirm that the physical debit/credit card terminal transaction succeeded for the full amount.'
                                        }}
                                    </Label>
                                </div>
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="pickup-handover-confirmed"
                                        v-model="
                                            pickupConfirmationForm.products_handed_over_confirmed
                                        "
                                    />
                                    <Label
                                        for="pickup-handover-confirmed"
                                        class="text-sm leading-5 font-normal"
                                    >
                                        I confirm that the products were handed
                                        over to the customer.
                                    </Label>
                                </div>
                                <InputError
                                    :message="
                                        pickupConfirmationForm.errors
                                            .payment_received_confirmed
                                    "
                                />
                                <InputError
                                    :message="
                                        pickupConfirmationForm.errors
                                            .products_handed_over_confirmed
                                    "
                                />
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline"
                                            >Keep preparing pickup</Button
                                        >
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        class="whitespace-normal"
                                        :disabled="
                                            !pickupConfirmationForm.payment_received_confirmed ||
                                            !pickupConfirmationForm.products_handed_over_confirmed ||
                                            pickupConfirmationForm.processing
                                        "
                                        @click="submitPickupConfirmation"
                                    >
                                        <Spinner
                                            v-if="
                                                pickupConfirmationForm.processing
                                            "
                                        />
                                        Confirm Payment &amp; Complete Pickup
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                    <div
                        v-else-if="
                            page.props.auth.can?.processOrders &&
                            order.payment.method.value === 'cod' &&
                            order.payment.status.value === 'pending' &&
                            order.status.value !== 'cancelled'
                        "
                        class="border-border mt-5 grid gap-4 border-t pt-5"
                    >
                        <p class="font-semibold">COD — Awaiting Collection</p>
                        <p class="text-muted-foreground text-sm leading-6">
                            Payment is collected upon delivery. Marking the
                            shipment Delivered does not verify payment or record
                            a sale. Confirm collection only after manually
                            verifying that LBC collected the total COD amount of
                            {{ formatCurrency(order.total) }}.
                        </p>
                        <Button
                            v-if="
                                page.props.auth.can?.processOrders &&
                                order.payment.can_confirm_cod_collection
                            "
                            type="button"
                            :disabled="codCollectionForm.processing"
                            @click="requestCodConfirmation"
                        >
                            Confirm COD Collection
                        </Button>
                        <p v-else class="text-muted-foreground text-sm">
                            Collection confirmation becomes available after the
                            shipment reaches Delivered.
                        </p>
                        <p class="text-muted-foreground text-xs leading-5">
                            Handle failed delivery, unpaid orders, and returns
                            manually. No automatic cancellation, refund, return,
                            or stock restoration occurs. No live LBC settlement
                            is verified.
                        </p>
                        <Dialog v-model:open="codConfirmationOpen">
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        Confirm COD Collection for
                                        {{ order.reference }}?
                                    </DialogTitle>
                                    <DialogDescription>
                                        Confirm that you manually verified LBC
                                        collected
                                        {{ formatCurrency(order.total) }}. This
                                        verifies payment, completes the order,
                                        and records its sale.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="flex items-start gap-3">
                                    <Checkbox
                                        id="cod-collection-confirmed"
                                        v-model="
                                            codCollectionForm.cod_collection_confirmed
                                        "
                                    />
                                    <Label
                                        for="cod-collection-confirmed"
                                        class="text-sm leading-5 font-normal"
                                    >
                                        I manually verified that LBC collected
                                        the full COD payment.
                                    </Label>
                                </div>
                                <InputError
                                    :message="
                                        codCollectionForm.errors
                                            .cod_collection_confirmed
                                    "
                                />
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline">
                                            Keep awaiting collection
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        :disabled="
                                            !codCollectionForm.cod_collection_confirmed ||
                                            codCollectionForm.processing
                                        "
                                        @click="submitCodCollection"
                                    >
                                        <Spinner
                                            v-if="codCollectionForm.processing"
                                        />
                                        Confirm COD Collection
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                    <div
                        v-else-if="
                            page.props.auth.can?.processOrders &&
                            !order.payment.pay_at_pickup &&
                            order.payment.status.value === 'pending' &&
                            order.status.value !== 'cancelled'
                        "
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
                            <template v-if="order.status.value === 'cancelled'">
                                Cancelled orders cannot accept payment
                                decisions. Any recorded payment remains
                                unchanged.
                            </template>
                            <template v-else>
                                This payment decision is final for this review.
                            </template>
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
