<script setup>
import { Head } from '@inertiajs/vue3';
import { PackagePlus } from '@lucide/vue';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import ProductForm from '@/components/ProductForm.vue';

defineProps({
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
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
    <Head title="Add product" />

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
                    <PackagePlus class="size-5" />
                </span>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Sagay City catalog
                    </p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Add product
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Create a catalog record. Inventory remains managed in
                        the separate inventory workspace.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-border bg-card border p-6 sm:p-8">
            <ProductForm
                :categories="categories"
                :tags="tags"
                :form="ProductController.store.form()"
                submit-label="Create product"
            />
        </section>
    </main>
</template>
