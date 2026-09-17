<script setup>
import { Head } from '@inertiajs/vue3';
import { PackageSearch } from '@lucide/vue';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import ProductForm from '@/components/ProductForm.vue';
import { Badge } from '@/components/ui/badge';

defineProps({
    product: { type: Object, required: true },
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Products', href: ProductController.index() },
            { title: 'Edit product', href: ProductController.index() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${product.name}`" />

    <main
        class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <PackageSearch class="size-5" />
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Sagay City catalog
                        </p>
                        <Badge
                            :variant="
                                product.is_active ? 'secondary' : 'outline'
                            "
                        >
                            {{ product.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Edit {{ product.name }}
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Update catalog details and relationships. Product
                        activation is managed from the product list.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-border bg-card border p-6 sm:p-8">
            <ProductForm
                :product="product"
                :categories="categories"
                :tags="tags"
                :form="{
                    action: ProductController.update.url(product.id),
                    method: 'post',
                }"
                method-override="put"
                submit-label="Save changes"
            />
        </section>
    </main>
</template>
