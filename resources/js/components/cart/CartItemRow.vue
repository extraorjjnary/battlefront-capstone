<script setup>
import { Form, Link } from '@inertiajs/vue3';
import { CircleHelp, CircleX, Save, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/CartItemController';
import InputError from '@/components/InputError.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency } from '@/lib/currency';
import { show as productShow } from '@/routes/products';

const props = defineProps({
    item: { type: Object, required: true },
});

const removeDialogOpen = ref(false);
const enteredQuantity = ref(props.item.quantity);
const isAvailable = computed(
    () => props.item.availability.status === 'available',
);
const isAtMaximum = computed(
    () =>
        isAvailable.value &&
        enteredQuantity.value >= props.item.availability.available_quantity,
);
const canUpdate = computed(() =>
    ['available', 'insufficient_stock'].includes(
        props.item.availability.status,
    ),
);
const productIsBrowsable = computed(
    () => props.item.availability.status !== 'product_ineligible',
);

function trackEnteredQuantity(event) {
    enteredQuantity.value = event.currentTarget.valueAsNumber;
}

const availabilityCopy = computed(() => {
    const availableQuantity = props.item.availability.available_quantity;

    return {
        product_ineligible: {
            title: 'Product unavailable',
            description:
                'This product is no longer eligible for customer carts. Remove it before continuing.',
            icon: TriangleAlert,
        },
        inventory_unavailable: {
            title: 'Stock unavailable',
            description:
                'Current stock information is unavailable. Remove this item or check again later.',
            icon: CircleHelp,
        },
        out_of_stock: {
            title: 'Out of stock',
            description:
                'This product currently has no stock. Remove it or check again later.',
            icon: CircleX,
        },
        insufficient_stock: {
            title: 'Quantity exceeds stock',
            description: `Only ${availableQuantity} item(s) are currently available. Reduce the quantity to continue.`,
            icon: TriangleAlert,
        },
    }[props.item.availability.status];
});
</script>

<template>
    <article class="grid gap-5 p-5 sm:grid-cols-[9rem_minmax(0,1fr)] sm:p-6">
        <div class="border-border aspect-square overflow-hidden border">
            <ProductImage
                :image-url="item.product.image_url"
                :product-name="item.product.name"
            />
        </div>

        <div class="min-w-0">
            <div
                class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {{ item.product.category }}
                        </Badge>
                        <Badge v-if="isAvailable" variant="outline">
                            In stock
                        </Badge>
                    </div>
                    <p
                        class="text-muted-foreground mt-3 text-xs font-semibold tracking-wide uppercase"
                    >
                        {{ item.product.brand }}
                    </p>
                    <Button
                        v-if="productIsBrowsable"
                        as-child
                        variant="link"
                        class="h-auto justify-start p-0 text-lg font-bold"
                    >
                        <Link :href="productShow(item.product.id)">
                            {{ item.product.name }}
                        </Link>
                    </Button>
                    <h2 v-else class="mt-1 text-lg font-bold">
                        {{ item.product.name }}
                    </h2>
                    <ProductPrice
                        :price="item.product.price"
                        :discount-price="item.product.discount_price"
                        class="mt-3"
                    />
                </div>

                <div class="lg:text-right">
                    <p
                        class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                    >
                        Line total
                    </p>
                    <p class="mt-1 text-xl font-bold tabular-nums">
                        {{ formatCurrency(item.line_total) }}
                    </p>
                </div>
            </div>

            <Alert v-if="availabilityCopy" variant="destructive" class="mt-5">
                <component
                    :is="availabilityCopy.icon"
                    class="size-4"
                    aria-hidden="true"
                />
                <AlertTitle>{{ availabilityCopy.title }}</AlertTitle>
                <AlertDescription>
                    {{ availabilityCopy.description }}
                </AlertDescription>
            </Alert>

            <div
                class="border-border mt-5 flex flex-col gap-4 border-t pt-5 md:flex-row md:items-start md:justify-between"
            >
                <Form
                    v-bind="update.form(item.id)"
                    :error-bag="`updateCartItem${item.id}`"
                    :options="{ preserveScroll: true }"
                    class="grid gap-3 sm:grid-cols-[8rem_auto] sm:items-start"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label :for="`cart-quantity-${item.id}`">
                            Quantity
                        </Label>
                        <Input
                            :id="`cart-quantity-${item.id}`"
                            name="quantity"
                            type="number"
                            min="1"
                            :max="
                                item.availability.available_quantity ??
                                4294967295
                            "
                            step="1"
                            inputmode="numeric"
                            :default-value="item.quantity"
                            :disabled="!canUpdate || processing"
                            :aria-invalid="Boolean(errors.quantity)"
                            :aria-describedby="
                                isAtMaximum
                                    ? `cart-quantity-limit-${item.id}`
                                    : undefined
                            "
                            required
                            @input="trackEnteredQuantity"
                        />
                        <p
                            v-if="isAtMaximum"
                            :id="`cart-quantity-limit-${item.id}`"
                            class="text-muted-foreground text-xs"
                            aria-live="polite"
                        >
                            Maximum available quantity reached.
                        </p>
                        <InputError :message="errors.quantity" />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        class="sm:mt-7"
                        :disabled="!canUpdate || processing"
                    >
                        <Spinner v-if="processing" />
                        <Save v-else aria-hidden="true" />
                        {{ processing ? 'Updating...' : 'Update quantity' }}
                    </Button>
                </Form>

                <Dialog v-model:open="removeDialogOpen">
                    <DialogTrigger as-child>
                        <Button variant="ghost" class="md:mt-7">
                            <Trash2 aria-hidden="true" />
                            Remove
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="destroy.form(item.id)"
                            :options="{ preserveScroll: true }"
                            @success="removeDialogOpen = false"
                            v-slot="{ processing }"
                        >
                            <DialogHeader>
                                <DialogTitle>
                                    Remove {{ item.product.name }}?
                                </DialogTitle>
                                <DialogDescription>
                                    This removes the product from your cart. You
                                    can add it again later if it remains
                                    available.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter class="mt-6 gap-2">
                                <DialogClose as-child>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" />
                                    <Trash2 v-else aria-hidden="true" />
                                    {{ processing ? 'Removing...' : 'Remove' }}
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    </article>
</template>
