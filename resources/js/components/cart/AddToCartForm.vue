<script setup>
import { Form } from '@inertiajs/vue3';
import { ShoppingCart } from '@lucide/vue';
import { computed, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/CartItemController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps({
    productId: { type: Number, required: true },
    availableQuantity: { type: Number, required: true },
});

const enteredQuantity = ref(1);
const isAtMaximum = computed(
    () => enteredQuantity.value >= props.availableQuantity,
);

function trackEnteredQuantity(event) {
    enteredQuantity.value = event.currentTarget.valueAsNumber;
}
</script>

<template>
    <Form
        v-bind="store.form()"
        :error-bag="`addCartItem${productId}`"
        :options="{ preserveScroll: true }"
        class="border-border bg-secondary/40 grid gap-4 border p-4 sm:grid-cols-[minmax(8rem,0.45fr)_minmax(0,1fr)] sm:items-end"
        v-slot="{ errors, processing }"
    >
        <input type="hidden" name="product_id" :value="productId" />

        <div class="grid gap-2">
            <Label :for="`add-cart-quantity-${productId}`">Quantity</Label>
            <Input
                :id="`add-cart-quantity-${productId}`"
                name="quantity"
                type="number"
                min="1"
                :max="availableQuantity"
                step="1"
                inputmode="numeric"
                :default-value="1"
                :aria-invalid="Boolean(errors.quantity)"
                :aria-describedby="
                    isAtMaximum
                        ? `add-cart-quantity-limit-${productId}`
                        : undefined
                "
                required
                @input="trackEnteredQuantity"
            />
        </div>

        <Button type="submit" :disabled="processing">
            <Spinner v-if="processing" />
            <ShoppingCart v-else aria-hidden="true" />
            {{ processing ? 'Adding...' : 'Add to cart' }}
        </Button>

        <div class="min-h-8 sm:col-span-2" aria-live="polite">
            <p
                v-if="isAtMaximum"
                :id="`add-cart-quantity-limit-${productId}`"
                class="text-muted-foreground text-xs"
            >
                Maximum available quantity reached.
            </p>
        </div>

        <InputError
            v-if="errors.product_id"
            class="sm:col-span-2"
            :message="errors.product_id"
        />
        <InputError
            v-if="errors.quantity"
            class="sm:col-span-2"
            :message="errors.quantity"
        />
    </Form>
</template>
