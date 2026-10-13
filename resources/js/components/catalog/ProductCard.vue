<script setup>
import { Link } from "@inertiajs/vue3";
import { ArrowRight } from "@lucide/vue";
import ProductImage from "@/components/catalog/ProductImage.vue";
import ProductPrice from "@/components/catalog/ProductPrice.vue";
import StockAvailability from "@/components/catalog/StockAvailability.vue";
import { Badge } from "@/components/ui/badge";
import { useProductPrefetch } from "@/composables/useProductPrefetch";
import { rememberCatalogVisit } from "@/lib/catalogReturn";
import { show as productShow } from "@/routes/products";

defineProps({ product: { type: Object, required: true } });
const { prefetchProduct, cancelProductPrefetch } = useProductPrefetch();
</script>

<template>
    <Link
        :href="productShow(product.id)"
        @pointerenter="
            prefetchProduct($event, productShow(product.id))
        "
        @pointerleave="cancelProductPrefetch"
        @pointercancel="cancelProductPrefetch"
        @pointerdown="cancelProductPrefetch"
        @click.capture="
            cancelProductPrefetch();
            rememberCatalogVisit($event, product.id);
        "
        class="border-border bg-card focus-visible:ring-ring group hover:border-primary/60 flex min-h-full min-w-0 flex-col overflow-hidden border transition-colors focus-visible:ring-2 focus-visible:outline-none"
    >
        <div
            class="aspect-square overflow-hidden sm:aspect-3/2"
        >
            <ProductImage
                :image-url="product.image_url"
                :product-name="product.name"
                contain
                class="transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none [&_img]:size-[98%] lg:[&_img]:size-full lg:[&_img]:object-cover"
            />
        </div>

        <article
            class="flex min-w-0 flex-1 flex-col gap-2 p-2.5 sm:gap-3 sm:p-4"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    variant="secondary"
                    class="max-w-full wrap-anywhere whitespace-normal"
                >
                    {{ product.category.name }}
                </Badge>
                <Badge v-if="product.is_featured">
                    Featured
                </Badge>
            </div>

            <div>
                <p
                    v-if="product.brand"
                    class="text-muted-foreground truncate text-xs font-semibold tracking-wide uppercase"
                >
                    {{ product.brand }}
                </p>
                <h3
                    class="mt-1 line-clamp-2 text-sm leading-snug font-bold tracking-tight wrap-anywhere sm:text-lg"
                >
                    {{ product.name }}
                </h3>
                <p
                    v-if="product.description"
                    class="text-muted-foreground mt-2 hidden text-sm leading-6 sm:line-clamp-2"
                >
                    {{ product.description }}
                </p>
            </div>

            <div class="mt-auto flex flex-col gap-3">
                <ProductPrice
                    :price="product.price"
                    :discount-price="product.discount_price"
                    compact
                />
                <div
                    class="flex flex-wrap items-end justify-between gap-2"
                >
                    <StockAvailability
                        :inventory="product.inventory"
                    />
                    <ArrowRight
                        class="text-muted-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-1 motion-reduce:transition-none sm:block"
                        aria-hidden="true"
                    />
                </div>
            </div>
        </article>
    </Link>
</template>
