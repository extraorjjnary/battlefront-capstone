<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Boxes,
    PackageSearch,
    Search,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
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
import { index as productIndex, show as productShow } from '@/routes/products';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    filter_options: { type: Object, required: true },
});

const categoryId = ref(String(props.filters.category_id ?? 'all'));
const brand = ref(props.filters.brand ?? 'all');
const tagId = ref(String(props.filters.tag_id ?? 'all'));

const { search, isSearching, clearSearch, cancelPendingSearch } =
    useDebouncedSearch({
        initialSearch: props.filters.q,
        currentSearch: () => props.filters.q,
        route: productIndex,
        query: selectedFilters,
    });

const hasSearch = computed(() => Boolean(props.filters.q));
const hasSearchInput = computed(() => Boolean(search.value.trim()));
const hasAppliedFilters = computed(() =>
    ['category_id', 'brand', 'tag_id'].some(
        (filter) => props.filters[filter] !== null,
    ),
);
const hasActiveQuery = computed(
    () => hasSearch.value || hasAppliedFilters.value,
);

function selectedValue(value) {
    return value === 'all' ? undefined : value;
}

function catalogPage(options) {
    return productIndex({
        query: {
            ...props.filters,
            page: options.query.page,
        },
    });
}

function appliedFilters() {
    return {
        category_id: props.filters.category_id ?? undefined,
        brand: props.filters.brand ?? undefined,
        tag_id: props.filters.tag_id ?? undefined,
    };
}

function selectedFilters() {
    return {
        category_id: selectedValue(categoryId.value),
        brand: selectedValue(brand.value),
        tag_id: selectedValue(tagId.value),
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
        productIndex({
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
    brand.value = 'all';
    tagId.value = 'all';
}

watch([categoryId, brand, tagId], updateFilters);
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Products">
            <meta
                head-key="description"
                name="description"
                content="Browse available computer hardware from Battlefront Computer Trading."
            />
        </Head>

        <StorefrontHeader active-section="products" />

        <main class="mx-auto max-w-7xl px-5 py-10 sm:px-8 sm:py-14">
            <section
                class="border-border bg-card relative overflow-hidden border px-6 py-10 sm:px-10 lg:grid lg:grid-cols-[1.45fr_0.55fr] lg:items-end lg:gap-12 lg:px-14 lg:py-14"
                aria-labelledby="catalog-heading"
            >
                <div
                    class="bg-primary absolute inset-y-0 left-0 w-1.5"
                    aria-hidden="true"
                />
                <div>
                    <div
                        class="border-primary/30 bg-primary/10 text-primary mb-5 flex size-11 items-center justify-center border"
                    >
                        <PackageSearch class="size-5" aria-hidden="true" />
                    </div>
                    <p
                        class="text-primary text-xs font-bold tracking-[0.2em] uppercase"
                    >
                        Customer catalog
                    </p>
                    <h1
                        id="catalog-heading"
                        class="mt-3 max-w-3xl text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl"
                    >
                        Computer hardware, clearly presented
                    </h1>
                    <p
                        class="text-muted-foreground mt-5 max-w-2xl text-base leading-7 sm:text-lg"
                    >
                        Review current product details, pricing, and stock
                        availability before choosing the right hardware for your
                        setup.
                    </p>
                </div>

                <div
                    class="border-border mt-8 border-t pt-6 lg:mt-0 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-10"
                >
                    <p class="text-muted-foreground text-sm font-medium">
                        {{
                            hasActiveQuery
                                ? 'Products matching your catalog query'
                                : 'Products available to browse'
                        }}
                    </p>
                    <p class="mt-1 text-3xl font-bold">{{ products.total }}</p>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Stock information reflects the current catalog record.
                    </p>
                </div>
            </section>

            <section
                class="border-border bg-card mt-8 border p-5 sm:p-6"
                aria-labelledby="catalog-filters-heading"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center"
                    >
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 id="catalog-filters-heading" class="font-bold">
                            Find the right hardware
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Search product details or narrow the catalog by
                            category, brand, and tag.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-6 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                >
                    <div class="grid gap-2">
                        <Label for="catalog-search">Search products</Label>
                        <div class="relative">
                            <Search
                                class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                                aria-hidden="true"
                            />
                            <Input
                                id="catalog-search"
                                v-model="search"
                                class="pl-9"
                                maxlength="255"
                                placeholder="Name, brand, or description"
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
                        <X aria-hidden="true" />
                        Clear search
                    </Button>
                </div>

                <div class="border-border mt-6 border-t pt-6">
                    <div>
                        <h3 class="font-semibold">Filter products</h3>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Category, brand, and tag selections update results
                            automatically and remain separate from search.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="catalog-category">Category</Label>
                            <Select v-model="categoryId">
                                <SelectTrigger
                                    id="catalog-category"
                                    class="w-full"
                                >
                                    <SelectValue placeholder="All categories" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All categories
                                    </SelectItem>
                                    <SelectItem
                                        v-for="category in filter_options.categories"
                                        :key="category.id"
                                        :value="String(category.id)"
                                    >
                                        {{ category.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="catalog-brand">Brand</Label>
                            <Select v-model="brand">
                                <SelectTrigger
                                    id="catalog-brand"
                                    class="w-full"
                                >
                                    <SelectValue placeholder="All brands" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All brands
                                    </SelectItem>
                                    <SelectItem
                                        v-for="brandOption in filter_options.brands"
                                        :key="brandOption"
                                        :value="brandOption"
                                    >
                                        {{ brandOption }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="catalog-tag">Tag</Label>
                            <Select v-model="tagId">
                                <SelectTrigger id="catalog-tag" class="w-full">
                                    <SelectValue placeholder="All tags" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >All tags</SelectItem
                                    >
                                    <SelectItem
                                        v-for="tag in filter_options.tags"
                                        :key="tag.id"
                                        :value="String(tag.id)"
                                    >
                                        {{ tag.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="flex flex-wrap gap-2 md:col-span-3 md:justify-end"
                        >
                            <Button
                                v-if="hasAppliedFilters"
                                type="button"
                                variant="outline"
                                @click="clearFilters"
                            >
                                <X aria-hidden="true" />
                                Clear filters
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-12" aria-labelledby="product-list-heading">
                <div class="mb-6 flex items-end justify-between gap-6">
                    <div>
                        <p
                            class="text-primary text-xs font-bold tracking-[0.18em] uppercase"
                        >
                            Product lineup
                        </p>
                        <h2
                            id="product-list-heading"
                            class="mt-2 text-2xl font-bold tracking-tight"
                        >
                            Browse the catalog
                        </h2>
                    </div>
                    <p
                        v-if="products.total"
                        class="text-muted-foreground hidden text-sm sm:block"
                    >
                        Showing {{ products.from }} to {{ products.to }} of
                        {{ products.total }}
                    </p>
                </div>

                <div
                    v-if="products.data.length"
                    class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <Link
                        v-for="product in products.data"
                        :key="product.id"
                        :href="productShow(product.id)"
                        prefetch
                        class="border-border bg-card focus-visible:ring-ring group hover:border-primary/60 flex min-h-full flex-col overflow-hidden border transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <div class="aspect-4/3 overflow-hidden">
                            <ProductImage
                                :image-url="product.image_url"
                                :product-name="product.name"
                                class="transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none"
                            />
                        </div>

                        <article class="flex flex-1 flex-col gap-4 p-5">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge variant="secondary">
                                    {{ product.category.name }}
                                </Badge>
                                <Badge v-if="product.is_featured">
                                    Featured
                                </Badge>
                            </div>

                            <div>
                                <p
                                    v-if="product.brand"
                                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                                >
                                    {{ product.brand }}
                                </p>
                                <h3
                                    class="mt-1 text-lg leading-snug font-bold tracking-tight"
                                >
                                    {{ product.name }}
                                </h3>
                                <p
                                    v-if="product.description"
                                    class="text-muted-foreground mt-2 line-clamp-2 text-sm leading-6"
                                >
                                    {{ product.description }}
                                </p>
                            </div>

                            <div class="mt-auto flex flex-col gap-4">
                                <ProductPrice
                                    :price="product.price"
                                    :discount-price="product.discount_price"
                                />
                                <div
                                    class="flex items-end justify-between gap-3"
                                >
                                    <StockAvailability
                                        :inventory="product.inventory"
                                    />
                                    <ArrowRight
                                        class="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-1 motion-reduce:transition-none"
                                        aria-hidden="true"
                                    />
                                </div>
                            </div>
                        </article>
                    </Link>
                </div>

                <div
                    v-else
                    class="border-border bg-muted/30 border border-dashed px-6 py-16 text-center"
                >
                    <Boxes
                        class="text-muted-foreground mx-auto size-8"
                        aria-hidden="true"
                    />
                    <p class="mt-4 font-semibold">
                        {{
                            hasActiveQuery
                                ? 'No products match your search and filters.'
                                : 'No products are currently available to browse.'
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{
                            hasActiveQuery
                                ? 'Try another search term or clear the applied filters.'
                                : 'Please check again later for catalog updates.'
                        }}
                    </p>
                    <div
                        v-if="hasActiveQuery"
                        class="mt-5 flex flex-wrap justify-center gap-2"
                    >
                        <Button
                            v-if="hasSearch"
                            type="button"
                            variant="outline"
                            @click="clearSearch"
                        >
                            Clear search
                        </Button>
                        <Button
                            v-if="hasAppliedFilters"
                            type="button"
                            variant="outline"
                            @click="clearFilters"
                        >
                            Clear filters
                        </Button>
                    </div>
                </div>

                <CatalogPagination
                    :current-page="products.current_page"
                    :last-page="products.last_page"
                    :route="catalogPage"
                    label="Product catalog pages"
                />
            </section>
        </main>
    </div>
</template>
