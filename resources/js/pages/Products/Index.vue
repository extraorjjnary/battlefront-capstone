<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Boxes, PackageSearch } from '@lucide/vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Badge } from '@/components/ui/badge';
import { index as productIndex, show as productShow } from '@/routes/products';

defineProps({
    products: { type: Object, required: true },
});
</script>

<template>
    <div class="dark bg-background text-foreground min-h-screen">
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
                        Products available to browse
                    </p>
                    <p class="mt-1 text-3xl font-bold">{{ products.total }}</p>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Stock information reflects the current catalog record.
                    </p>
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
                        No products are currently available to browse.
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Please check again later for catalog updates.
                    </p>
                </div>

                <CatalogPagination
                    :current-page="products.current_page"
                    :last-page="products.last_page"
                    :route="productIndex"
                    label="Product catalog pages"
                />
            </section>
        </main>
    </div>
</template>
