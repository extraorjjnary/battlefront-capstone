<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';

import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import ProductForm from '@/components/ProductForm.vue';
import { Badge } from '@/components/ui/badge';

defineProps({
    product: { type: Object, required: true },
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    shipping_profiles: { type: Array, required: true },
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
    <main
        class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head :title="`Edit ${product.name}`" />
        <PageHeader :title="`Edit ${product.name}`">
            <template #metadata>
                <Badge :variant="product.is_active ? 'secondary' : 'outline'">
                    {{ product.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </template>
        </PageHeader>

        <section class="surface-panel border-border bg-card border p-6 sm:p-8">
            <ProductForm
                :product="product"
                :categories="categories"
                :tags="tags"
                :shipping-profiles="shipping_profiles"
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
