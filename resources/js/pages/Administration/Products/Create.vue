<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';

import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import ProductForm from '@/components/ProductForm.vue';

defineProps({
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    shipping_profiles: { type: Array, required: true },
    package_estimates: { type: Object, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Products', href: ProductController.index() },
            { title: 'Add product', href: ProductController.create() },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Add product" />
        <PageHeader
            title="Add product"
            description="Manage stock separately in Inventory."
        />

        <section class="surface-panel border-border bg-card border p-6 sm:p-8">
            <ProductForm
                :categories="categories"
                :tags="tags"
                :shipping-profiles="shipping_profiles"
                :package-estimates="package_estimates"
                :show-preparation="false"
                :form="ProductController.store.form()"
                submit-label="Create product"
            />
        </section>
    </main>
</template>
