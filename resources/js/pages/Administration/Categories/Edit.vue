<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';

import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import CategoryForm from '@/components/CategoryForm.vue';
import { Badge } from '@/components/ui/badge';

defineProps({
    category: { type: Object, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Categories', href: CategoryController.index() },
            { title: 'Edit category', href: CategoryController.index() },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head :title="`Edit ${category.name}`" />
        <PageHeader :title="`Edit ${category.name}`">
            <template #metadata>
                <Badge :variant="category.is_active ? 'secondary' : 'outline'">
                    {{ category.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </template>
        </PageHeader>

        <section class="surface-panel border-border bg-card border p-6 sm:p-8">
            <CategoryForm
                :category="category"
                :form="CategoryController.update.form(category.id)"
                submit-label="Save changes"
            />
        </section>
    </main>
</template>
