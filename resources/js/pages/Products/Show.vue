<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, PackageOpen, Tag } from '@lucide/vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as productIndex } from '@/routes/products';

defineProps({
    product: { type: Object, required: true },
});
</script>

<template>
    <div class="dark bg-background text-foreground min-h-screen">
        <Head :title="product.name">
            <meta
                head-key="description"
                name="description"
                :content="
                    product.description ??
                    `View ${product.name} product details and stock availability.`
                "
            />
        </Head>

        <StorefrontHeader active-section="products" />

        <main class="mx-auto max-w-7xl px-5 py-8 sm:px-8 sm:py-12">
            <Button as-child variant="ghost" class="-ml-3">
                <Link :href="productIndex()">
                    <ArrowLeft aria-hidden="true" />
                    Back to products
                </Link>
            </Button>

            <article
                class="border-border bg-card mt-6 grid overflow-hidden border lg:grid-cols-[minmax(0,1.05fr)_minmax(24rem,0.95fr)]"
            >
                <div
                    class="border-border aspect-square overflow-hidden border-b lg:aspect-auto lg:min-h-155 lg:border-r lg:border-b-0"
                >
                    <ProductImage
                        :image-url="product.image_url"
                        :product-name="product.name"
                    />
                </div>

                <div class="flex flex-col p-6 sm:p-9 lg:p-12">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {{ product.category.name }}
                        </Badge>
                        <Badge v-if="product.is_featured">Featured</Badge>
                    </div>

                    <p
                        class="text-primary mt-7 text-xs font-bold tracking-[0.18em] uppercase"
                    >
                        {{ product.brand }}
                    </p>
                    <h1
                        class="mt-2 text-3xl leading-tight font-bold tracking-tight sm:text-4xl"
                    >
                        {{ product.name }}
                    </h1>

                    <ProductPrice
                        :price="product.price"
                        :discount-price="product.discount_price"
                        class="mt-6"
                    />

                    <div class="border-border mt-7 border-y py-6">
                        <p
                            class="text-muted-foreground mb-3 text-xs font-bold tracking-wide uppercase"
                        >
                            Current availability
                        </p>
                        <StockAvailability :inventory="product.inventory" />
                        <p
                            v-if="product.inventory.status === 'out_of_stock'"
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            This product remains in the catalog but is not
                            currently in stock.
                        </p>
                        <p
                            v-else-if="
                                product.inventory.status === 'unavailable'
                            "
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            Current stock information is not available. Contact
                            Battlefront before visiting a branch.
                        </p>
                    </div>

                    <section class="mt-7" aria-labelledby="description-heading">
                        <h2 id="description-heading" class="font-semibold">
                            Product description
                        </h2>
                        <p
                            v-if="product.description"
                            class="text-muted-foreground mt-3 text-sm leading-7 whitespace-pre-line"
                        >
                            {{ product.description }}
                        </p>
                        <div
                            v-else
                            class="border-border bg-muted/30 mt-3 flex gap-3 border border-dashed p-4"
                        >
                            <PackageOpen
                                class="text-muted-foreground mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <p class="text-muted-foreground text-sm leading-6">
                                A product description is not currently
                                available.
                            </p>
                        </div>
                    </section>

                    <section class="mt-8" aria-labelledby="tags-heading">
                        <h2
                            id="tags-heading"
                            class="flex items-center gap-2 font-semibold"
                        >
                            <Tag
                                class="text-primary size-4"
                                aria-hidden="true"
                            />
                            Product tags
                        </h2>
                        <div
                            v-if="product.tags.length"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <Badge
                                v-for="tag in product.tags"
                                :key="tag.id"
                                variant="outline"
                            >
                                {{ tag.name }}
                            </Badge>
                        </div>
                        <p
                            v-else
                            class="text-muted-foreground mt-3 text-sm leading-6"
                        >
                            No tags are currently assigned to this product.
                        </p>
                    </section>
                </div>
            </article>
        </main>
    </div>
</template>
