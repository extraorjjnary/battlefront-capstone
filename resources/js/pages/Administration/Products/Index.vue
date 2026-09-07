<script setup>
import { Form, Head, Link } from '@inertiajs/vue3';
import { PackageSearch, Pencil, Plus, RotateCcw, Star } from '@lucide/vue';
import ProductActivationController from '@/actions/App/Http/Controllers/Administration/ProductActivationController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import CatalogNavigation from '@/components/CatalogNavigation.vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import DeactivationDialog from '@/components/DeactivationDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

defineProps({
    products: { type: Object, required: true },
});

const currencyFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

function formatPrice(value) {
    return currencyFormatter.format(Number(value));
}

function hideBrokenImage(event) {
    event.currentTarget.hidden = true;
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Products',
                href: ProductController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Products" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <PackageSearch class="size-5" />
                    </span>
                    <div>
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Sagay City catalog
                        </p>
                        <h1
                            class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            Product management
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            Maintain the hardware details, pricing, categories,
                            and tags used across Battlefront catalog workflows.
                        </p>
                    </div>
                </div>
                <Button as-child class="shrink-0">
                    <Link :href="ProductController.create()">
                        <Plus />
                        Add product
                    </Link>
                </Button>
            </div>
            <CatalogNavigation />
        </section>

        <section aria-labelledby="product-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ products.total }} products
                </p>
                <h2 id="product-list-heading" class="text-xl font-semibold">
                    Catalog products
                </h2>
            </div>

            <div
                v-if="products.data.length === 0"
                class="border-border bg-card flex min-h-52 items-center justify-center border p-6 text-center"
            >
                <div>
                    <PackageSearch
                        class="text-muted-foreground mx-auto size-9"
                    />
                    <p class="mt-3 font-medium">No products available</p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Add the first Battlefront catalog product to get
                        started.
                    </p>
                    <Button as-child class="mt-5">
                        <Link :href="ProductController.create()">
                            <Plus />
                            Add product
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-else
                class="border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="product in products.data"
                    :key="product.id"
                    class="grid gap-5 p-5 lg:grid-cols-[5rem_minmax(0,1.4fr)_minmax(10rem,0.7fr)_auto] lg:items-center"
                >
                    <div
                        class="border-border bg-muted/40 relative flex aspect-square w-20 items-center justify-center overflow-hidden border"
                    >
                        <PackageSearch class="text-muted-foreground size-6" />
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="`${product.name} product image`"
                            class="absolute inset-0 size-full object-contain"
                            @error="hideBrokenImage"
                        />
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ product.name }}</h3>
                            <Badge
                                :variant="
                                    product.is_active ? 'secondary' : 'outline'
                                "
                            >
                                {{ product.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                            <Badge v-if="product.is_featured" variant="outline">
                                <Star />
                                Featured
                            </Badge>
                        </div>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ product.brand }} · {{ product.category.name }}
                            <span v-if="!product.category.is_active">
                                (Inactive category)
                            </span>
                        </p>
                        <div
                            v-if="product.tags.length > 0"
                            class="mt-3 flex flex-wrap gap-1.5"
                        >
                            <Badge
                                v-for="tag in product.tags.slice(0, 3)"
                                :key="tag.id"
                                variant="outline"
                            >
                                {{ tag.name }}
                            </Badge>
                            <span
                                v-if="product.tags.length > 3"
                                class="text-muted-foreground self-center text-xs"
                            >
                                +{{ product.tags.length - 3 }} more
                            </span>
                        </div>
                    </div>

                    <div>
                        <p class="text-muted-foreground text-xs">
                            {{
                                product.discount_price
                                    ? 'Discount price'
                                    : 'Regular price'
                            }}
                        </p>
                        <p class="mt-1 font-semibold tabular-nums">
                            {{
                                formatPrice(
                                    product.discount_price ?? product.price,
                                )
                            }}
                        </p>
                        <p
                            v-if="product.discount_price"
                            class="text-muted-foreground mt-1 text-sm tabular-nums line-through"
                        >
                            {{ formatPrice(product.price) }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 lg:justify-end"
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="ProductController.edit(product.id)">
                                <Pencil />
                                Edit
                            </Link>
                        </Button>

                        <DeactivationDialog
                            v-if="product.is_active"
                            :name="product.name"
                            :form="ProductActivationController.form(product.id)"
                            description="This product will no longer appear in customer catalog, cart, or recommendation workflows. Its record, category, tags, and inventory are preserved."
                        />

                        <Form
                            v-else
                            v-bind="
                                ProductActivationController.form(product.id)
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <input type="hidden" name="is_active" value="1" />
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                <RotateCcw v-else />
                                Reactivate
                            </Button>
                        </Form>
                    </div>
                </article>
            </div>

            <CatalogPagination
                :current-page="products.current_page"
                :last-page="products.last_page"
                :route="ProductController.index"
                label="Product pages"
            />
        </section>
    </main>
</template>
