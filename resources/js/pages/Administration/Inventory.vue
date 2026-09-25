<script setup>
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    Boxes,
    ChevronLeft,
    ChevronRight,
    CircleX,
    Save,
    Search,
    SlidersHorizontal,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import InputError from '@/components/InputError.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    filter_options: { type: Object, required: true },
    low_stock_count: { type: Number, required: true },
    out_of_stock_count: { type: Number, required: true },
});

const categoryId = ref(String(props.filters.category_id ?? 'all'));
const stock = ref(props.filters.stock);

const { search, isSearching, clearSearch, cancelPendingSearch } =
    useDebouncedSearch({
        initialSearch: props.filters.q,
        currentSearch: () => props.filters.q,
        route: InventoryController.index,
        query: selectedFilters,
    });

const hasSearchInput = computed(() => Boolean(search.value.trim()));
const hasAppliedFilters = computed(
    () => props.filters.category_id !== null || props.filters.stock !== 'all',
);
const hasActiveQuery = computed(
    () => Boolean(props.filters.q) || hasAppliedFilters.value,
);
const stockFilterSummary = computed(
    () =>
        ({
            in_stock: 'in-stock',
            low_stock: 'low-stock',
            out_of_stock: 'out-of-stock',
            not_initialized: 'not-initialized',
        })[props.filters.stock] ?? '',
);

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatLastUpdated(value) {
    return value ? dateFormatter.format(new Date(value)) : 'Not available';
}

function inventoryPage(page) {
    return InventoryController.index({
        query: {
            q: props.filters.q ?? undefined,
            category_id: props.filters.category_id ?? undefined,
            page,
            stock:
                props.filters.stock === 'all' ? undefined : props.filters.stock,
        },
    });
}

function appliedFilters() {
    return {
        category_id: props.filters.category_id ?? undefined,
        stock: props.filters.stock === 'all' ? undefined : props.filters.stock,
    };
}

function selectedFilters() {
    return {
        category_id: categoryId.value === 'all' ? undefined : categoryId.value,
        stock: stock.value === 'all' ? undefined : stock.value,
    };
}

function filtersAreCurrent() {
    const selected = selectedFilters();
    const applied = appliedFilters();

    return Object.keys(selected).every(
        (filter) =>
            String(selected[filter] ?? '') === String(applied[filter] ?? ''),
    );
}

function updateFilters() {
    if (filtersAreCurrent()) {
        return;
    }

    cancelPendingSearch();

    router.visit(
        InventoryController.index({
            query: {
                q: search.value.trim() || undefined,
                ...selectedFilters(),
            },
        }),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function clearFilters() {
    cancelPendingSearch();
    categoryId.value = 'all';
    stock.value = 'all';
}

watch([categoryId, stock], updateFilters);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Inventory',
                href: InventoryController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Inventory" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <Boxes class="size-5" />
                </span>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Sagay City operation
                    </p>
                    <h1
                        class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        Inventory management
                    </h1>
                    <p
                        class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                    >
                        Review current product stock and maintain the quantity
                        and reorder level used by Battlefront operations. Stock
                        is low when it has units remaining below its reorder
                        level.
                    </p>
                </div>
            </div>
        </section>

        <section aria-labelledby="inventory-ledger-heading">
            <div
                class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p class="text-muted-foreground text-sm">
                        {{ products.total }}
                        {{ stockFilterSummary }}
                        products
                    </p>
                    <h2
                        id="inventory-ledger-heading"
                        class="text-xl font-semibold"
                    >
                        Stock ledger
                    </h2>
                </div>

                <div class="flex flex-wrap gap-3">
                    <div
                        class="border-border bg-card flex items-center gap-3 border px-4 py-3"
                    >
                        <TriangleAlert
                            class="text-primary size-4"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="text-sm font-semibold tabular-nums">
                                {{ low_stock_count }} low-stock products
                            </p>
                            <p class="text-muted-foreground text-xs">
                                Units remain below reorder level
                            </p>
                        </div>
                    </div>
                    <div
                        class="border-destructive/50 bg-destructive/5 flex items-center gap-3 border px-4 py-3"
                    >
                        <CircleX
                            class="text-destructive size-4"
                            aria-hidden="true"
                        />
                        <div>
                            <p
                                class="text-destructive text-sm font-bold tabular-nums"
                            >
                                {{ out_of_stock_count }} out-of-stock products
                            </p>
                            <p class="text-muted-foreground text-xs">
                                No units available
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <section
                class="border-border bg-card mb-5 border p-5"
                aria-labelledby="inventory-query-heading"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                    >
                        <SlidersHorizontal class="size-4" />
                    </span>
                    <div>
                        <h3 id="inventory-query-heading" class="font-semibold">
                            Find inventory records
                        </h3>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Search immediately; category and stock attention
                            update the ledger as they change.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                >
                    <div class="grid gap-2">
                        <Label for="inventory-search">Search inventory</Label>
                        <div class="relative">
                            <Search
                                class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                            />
                            <Input
                                id="inventory-search"
                                v-model="search"
                                class="pl-9"
                                maxlength="255"
                                placeholder="Product code, name, or brand"
                                autocomplete="off"
                            />
                        </div>
                        <p
                            class="text-muted-foreground text-xs"
                            aria-live="polite"
                        >
                            {{
                                isSearching
                                    ? 'Updating results...'
                                    : 'Results update automatically as you type.'
                            }}
                        </p>
                    </div>
                    <Button
                        v-if="hasSearchInput"
                        type="button"
                        variant="outline"
                        class="sm:mb-5"
                        @click="clearSearch"
                    >
                        <X />
                        Clear search
                    </Button>
                </div>

                <div
                    class="border-border mt-5 grid gap-4 border-t pt-5 md:grid-cols-2"
                >
                    <div class="grid gap-2">
                        <Label for="inventory-category">Category</Label>
                        <Select v-model="categoryId">
                            <SelectTrigger
                                id="inventory-category"
                                class="w-full"
                            >
                                <SelectValue placeholder="All categories" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all"
                                    >All categories</SelectItem
                                >
                                <SelectItem
                                    v-for="category in filter_options.categories"
                                    :key="category.id"
                                    :value="String(category.id)"
                                >
                                    {{ category.name }}
                                    {{ category.is_active ? '' : '(Inactive)' }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="inventory-stock">Stock status</Label>
                        <Select v-model="stock">
                            <SelectTrigger id="inventory-stock" class="w-full">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="in_stock"
                                    >In stock</SelectItem
                                >
                                <SelectItem value="low_stock"
                                    >Low stock</SelectItem
                                >
                                <SelectItem value="out_of_stock"
                                    >Out of stock</SelectItem
                                >
                                <SelectItem value="not_initialized"
                                    >Not initialized</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div
                        class="flex flex-wrap gap-2 md:col-span-2 md:justify-end"
                    >
                        <Button
                            v-if="hasAppliedFilters"
                            type="button"
                            variant="outline"
                            @click="clearFilters"
                        >
                            <X />
                            Clear filters
                        </Button>
                    </div>
                </div>
            </section>

            <div
                v-if="products.data.length === 0"
                class="border-border bg-card flex min-h-48 items-center justify-center border p-6 text-center"
            >
                <div>
                    <template v-if="hasActiveQuery">
                        <TriangleAlert
                            class="text-muted-foreground mx-auto size-8"
                        />
                        <p class="mt-3 font-medium">
                            No inventory records match this query
                        </p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Try another search term or clear the applied
                            filters.
                        </p>
                    </template>
                    <template v-else>
                        <Boxes class="text-muted-foreground mx-auto size-8" />
                        <p class="mt-3 font-medium">No products available</p>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Products will appear here after they are added to
                            the catalog.
                        </p>
                    </template>
                </div>
            </div>

            <div
                v-else
                class="border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="product in products.data"
                    :key="product.id"
                    class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,2fr)] xl:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate font-semibold">
                                {{ product.name }}
                            </h3>
                            <Badge
                                :variant="
                                    product.is_active ? 'secondary' : 'outline'
                                "
                            >
                                {{ product.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                            <Badge
                                v-if="product.stock_status === 'out_of_stock'"
                                variant="outline"
                                class="border-destructive/50 bg-destructive/10 text-destructive"
                            >
                                <CircleX />
                                Out of stock
                            </Badge>
                            <Badge
                                v-else-if="product.stock_status === 'low_stock'"
                                variant="outline"
                                class="border-primary/40 text-primary"
                            >
                                <TriangleAlert />
                                Low stock
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ product.product_code
                            }}<span v-if="product.brand">
                                · {{ product.brand }}</span
                            >
                            ·
                            {{ product.category }}
                        </p>
                        <p
                            v-if="product.inventory"
                            class="text-muted-foreground mt-3 text-xs"
                        >
                            Last updated
                            {{
                                formatLastUpdated(
                                    product.inventory.last_updated,
                                )
                            }}
                        </p>
                    </div>

                    <Form
                        v-if="product.inventory"
                        v-bind="
                            InventoryController.update.form(
                                product.inventory.id,
                            )
                        "
                        :options="{ preserveScroll: true }"
                        class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label :for="`quantity-${product.inventory.id}`">
                                Current quantity
                            </Label>
                            <Input
                                :id="`quantity-${product.inventory.id}`"
                                name="quantity"
                                type="number"
                                min="0"
                                max="4294967295"
                                step="1"
                                inputmode="numeric"
                                :default-value="product.inventory.quantity"
                                :aria-invalid="Boolean(errors.quantity)"
                                required
                            />
                            <InputError :message="errors.quantity" />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                :for="`reorder-level-${product.inventory.id}`"
                            >
                                Reorder level
                            </Label>
                            <Input
                                :id="`reorder-level-${product.inventory.id}`"
                                name="reorder_level"
                                type="number"
                                min="0"
                                max="4294967295"
                                step="1"
                                inputmode="numeric"
                                :default-value="product.inventory.reorder_level"
                                :aria-invalid="Boolean(errors.reorder_level)"
                                required
                            />
                            <InputError :message="errors.reorder_level" />
                        </div>

                        <Button class="sm:mt-7" :disabled="processing">
                            <Spinner v-if="processing" />
                            <Save v-else />
                            Update stock
                        </Button>
                    </Form>

                    <Form
                        v-else
                        v-bind="InventoryController.store.form(product.id)"
                        :options="{
                            preserveScroll: true,
                            preserveState: 'errors',
                        }"
                        :error-bag="`initializeInventory${product.id}`"
                        class="border-border bg-muted/40 grid gap-4 border p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                        v-slot="{ errors, processing }"
                    >
                        <div class="sm:col-span-3">
                            <Badge variant="outline">Not initialized</Badge>
                            <p class="text-muted-foreground mt-2 text-sm">
                                This product does not have an inventory record
                                yet.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`initial-quantity-${product.id}`">
                                Initial quantity
                            </Label>
                            <Input
                                :id="`initial-quantity-${product.id}`"
                                name="quantity"
                                type="number"
                                min="0"
                                max="4294967295"
                                step="1"
                                inputmode="numeric"
                                :aria-invalid="Boolean(errors.quantity)"
                                required
                            />
                            <InputError :message="errors.quantity" />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`initial-reorder-level-${product.id}`">
                                Reorder level
                            </Label>
                            <Input
                                :id="`initial-reorder-level-${product.id}`"
                                name="reorder_level"
                                type="number"
                                min="0"
                                max="4294967295"
                                step="1"
                                inputmode="numeric"
                                :aria-invalid="Boolean(errors.reorder_level)"
                                required
                            />
                            <InputError :message="errors.reorder_level" />
                        </div>

                        <Button class="sm:mt-7" :disabled="processing">
                            <Spinner v-if="processing" />
                            <Save v-else />
                            Initialize inventory
                        </Button>
                    </Form>
                </article>
            </div>

            <nav
                v-if="products.last_page > 1"
                aria-label="Inventory pages"
                class="mt-5 flex items-center justify-between gap-4"
            >
                <Button
                    v-if="products.current_page > 1"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="inventoryPage(products.current_page - 1)"
                        preserve-scroll
                    >
                        <ChevronLeft />
                        Previous
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    <ChevronLeft />
                    Previous
                </Button>
                <p class="text-muted-foreground text-sm">
                    Page {{ products.current_page }} of {{ products.last_page }}
                </p>
                <Button
                    v-if="products.current_page < products.last_page"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="inventoryPage(products.current_page + 1)"
                        preserve-scroll
                    >
                        Next
                        <ChevronRight />
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Next
                    <ChevronRight />
                </Button>
            </nav>
        </section>
    </main>
</template>
