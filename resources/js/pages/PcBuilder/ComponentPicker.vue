<script setup>
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { allowsMultipleComponents } from './builderActions';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import WishlistButton from '@/components/catalog/WishlistButton.vue';

const props = defineProps({
    open: Boolean,
    label: String,
    products: Array,
    selectedIds: Array,
    canWishlist: Boolean,
    returnFocus: Object,
    strict: Boolean,
    loading: Boolean,
    busy: Boolean,
    error: String,
    page: { type: Number, default: 1 },
    lastPage: { type: Number, default: 1 },
    availableBrands: { type: Array, default: () => [] },
    componentType: String,
    mountingPositions: { type: Array, default: () => [] },
});
const emit = defineEmits([
    'update:open',
    'select',
    'remove',
    'resolve',
    'filters',
    'retry',
]);
const search = ref('');
const brand = ref('');
const sort = ref('name');
const showUnavailable = ref(false);
const fanMount = ref('');
const brands = computed(() =>
    props.availableBrands?.length
        ? props.availableBrands
        : [
              ...new Set(
                  props.products
                      .map((product) => product.brand)
                      .filter(Boolean),
              ),
          ].sort(),
);
const visible = computed(() =>
    props.products
        .filter(
            (product) =>
                `${product.name} ${product.brand ?? ''}`
                    .toLowerCase()
                    .includes(search.value.trim().toLowerCase()) &&
                (!brand.value || product.brand === brand.value) &&
                (props.strict ||
                    showUnavailable.value ||
                    Number(product.stock_quantity) > 0),
        )
        .sort(
            (a, b) =>
                (props.strict
                    ? Number(b.selectable) - Number(a.selectable)
                    : 0) ||
                (sort.value === 'name'
                    ? a.name.localeCompare(b.name)
                    : (Number(a.price) - Number(b.price)) *
                      (sort.value === 'price_desc' ? -1 : 1)),
        ),
);
watch([search, brand, sort], () => {
    if (props.strict)
        emit('filters', {
            search: search.value,
            brand: brand.value,
            sort: sort.value,
            page: 1,
        });
});
watch(fanMount, (position) => {
    if (props.strict && props.componentType === 'fan')
        emit('filters', {
            candidate_configuration: position
                ? { mounting_position: position }
                : null,
            page: 1,
        });
});
watch(
    () => props.label,
    () => {
        search.value = '';
        brand.value = '';
        fanMount.value = '';
    },
);
function money(value) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value));
}
function restoreFocus(event) {
    event.preventDefault();
    props.returnFocus?.focus();
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="flex max-h-[90dvh] flex-col sm:max-w-3xl"
            @close-auto-focus="restoreFocus"
        >
            <DialogHeader>
                <DialogTitle>Select {{ label }}</DialogTitle>
                <DialogDescription>{{
                    strict
                        ? 'Only verified parts compatible with your selected build can be added.'
                        : 'Choose a catalog part. Compatibility is checked after selection.'
                }}</DialogDescription>
            </DialogHeader>
            <label
                v-if="
                    strict &&
                    componentType === 'fan' &&
                    mountingPositions.length
                "
                class="text-sm font-medium"
            >
                Case mounting position
                <select
                    v-model="fanMount"
                    class="border-input bg-background mt-2 block w-full rounded-md border p-2"
                >
                    <option value="">Choose a position to check fan fit</option>
                    <option
                        v-for="position in mountingPositions"
                        :key="position"
                        :value="position"
                    >
                        {{ position }}
                    </option>
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                <div>
                    <label for="part-search" class="sr-only"
                        >Search components</label
                    ><Input
                        id="part-search"
                        v-model="search"
                        placeholder="Search product or brand"
                    />
                </div>
                <select
                    v-model="brand"
                    aria-label="Filter brand"
                    class="border-input bg-background rounded-md border p-2 text-sm"
                >
                    <option value="">All brands</option>
                    <option v-for="value in brands" :key="value">
                        {{ value }}
                    </option>
                </select>
                <select
                    v-model="sort"
                    aria-label="Sort components"
                    class="border-input bg-background rounded-md border p-2 text-sm"
                >
                    <option value="name">Name</option>
                    <option value="price">Price: low to high</option>
                    <option value="price_desc">Price: high to low</option>
                </select>
            </div>
            <p
                v-if="loading"
                class="text-muted-foreground py-3 text-sm"
                role="status"
            >
                Checking available compatible parts…
            </p>
            <p v-if="error" class="text-destructive py-3 text-sm" role="alert">
                {{ error }}
                <Button variant="outline" size="sm" @click="emit('retry')"
                    >Try again</Button
                >
            </p>
            <label class="flex items-center gap-2 text-sm"
                ><input v-model="showUnavailable" type="checkbox" /> Show
                out-of-stock parts</label
            >
            <div class="min-h-0 overflow-y-auto" aria-live="polite">
                <p
                    v-if="!products.length && !loading && !error"
                    class="text-muted-foreground py-12 text-center"
                >
                    No parts have been prepared for this category yet.
                </p>
                <p
                    v-else-if="!visible.length"
                    class="text-muted-foreground py-12 text-center"
                >
                    No matching available parts. Change your filters or show
                    out-of-stock parts.
                </p>
                <article
                    v-for="product in visible"
                    :key="product.id"
                    class="border-border flex gap-4 border-b py-4"
                >
                    <img
                        v-if="product.image_url"
                        :src="product.image_url"
                        :alt="product.name"
                        class="h-20 w-20 shrink-0 object-contain sm:h-24 sm:w-24"
                        loading="lazy"
                    />
                    <div class="min-w-0 flex-1">
                        <h3 class="font-semibold">{{ product.name }}</h3>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ product.brand }} ·
                            {{
                                Number(product.stock_quantity) > 0
                                    ? `${product.stock_quantity} in stock`
                                    : 'Out of stock'
                            }}
                        </p>
                        <p class="text-muted-foreground mt-2 text-sm">
                            {{ product.summary }}
                        </p>
                        <p class="mt-2 text-xs">
                            {{
                                product.specifications_reviewed
                                    ? 'Reviewed specifications · select to check fit'
                                    : 'Specifications not verified · pending review'
                            }}
                        </p>
                        <p
                            v-if="product.manufacturer_part_number"
                            class="text-muted-foreground mt-1 text-xs"
                        >
                            Model: {{ product.manufacturer_part_number
                            }}{{
                                product.hardware_revision
                                    ? ` · Revision ${product.hardware_revision}`
                                    : ''
                            }}
                        </p>
                        <details
                            v-if="product.evidence?.length"
                            class="mt-2 text-xs"
                        >
                            <summary class="cursor-pointer">
                                Specification sources
                            </summary>
                            <a
                                v-for="source in product.evidence"
                                :key="source.url"
                                :href="source.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-primary-ink mt-2 block underline"
                                >{{ source.title }} ·
                                {{ source.verified_at }}</a
                            >
                        </details>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <ul
                                v-if="strict && !product.selectable"
                                class="text-muted-foreground w-full space-y-1 text-xs"
                            >
                                <li
                                    v-for="reason in product.selection_reasons.slice(
                                        0,
                                        2,
                                    )"
                                    :key="reason"
                                >
                                    {{ reason }}
                                </li>
                            </ul>
                            <strong class="text-primary-ink mr-auto">{{
                                money(product.price)
                            }}</strong>
                            <WishlistButton
                                :product-id="product.id"
                                :product-name="product.name"
                                :is-wishlisted="product.is_wishlisted"
                                :can-wishlist="canWishlist"
                                compact
                            />
                            <Button
                                :disabled="
                                    busy ||
                                    loading ||
                                    Number(product.stock_quantity) < 1 ||
                                    (strict && !product.selectable)
                                "
                                @click="emit('select', product)"
                                >{{
                                    selectedIds.includes(product.id)
                                        ? allowsMultipleComponents(
                                              product.component_type,
                                          )
                                            ? 'Add one more'
                                            : 'Keep this part'
                                        : 'Select part'
                                }}</Button
                            >
                            <Button
                                v-if="selectedIds.includes(product.id)"
                                variant="outline"
                                :disabled="busy"
                                :aria-label="`Remove ${product.name}`"
                                @click="emit('remove', product.id)"
                                >Remove</Button
                            >
                            <Button
                                v-if="
                                    strict &&
                                    product.can_resolve &&
                                    !product.selectable
                                "
                                variant="outline"
                                :disabled="busy || loading"
                                @click="emit('resolve', product)"
                                >Replace and resolve conflicts</Button
                            >
                        </div>
                    </div>
                </article>
            </div>
            <div
                v-if="strict && lastPage > 1"
                class="flex items-center justify-between gap-3 text-sm"
            >
                <Button
                    variant="outline"
                    :disabled="busy || loading || page <= 1"
                    @click="emit('filters', { page: page - 1 })"
                    >Previous</Button
                >
                <span>Page {{ page }} of {{ lastPage }}</span>
                <Button
                    variant="outline"
                    :disabled="busy || loading || page >= lastPage"
                    @click="emit('filters', { page: page + 1 })"
                    >Next</Button
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
