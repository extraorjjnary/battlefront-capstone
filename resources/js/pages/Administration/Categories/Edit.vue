<script setup>
import { Head } from '@inertiajs/vue3';
import { FolderPen } from '@lucide/vue';
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
    <Head :title="`Edit ${category.name}`" />

    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="flex items-start gap-4">
                <span
                    class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                >
                    <FolderPen class="size-5" />
                </span>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Sagay City catalog
                        </p>
                        <Badge
                            :variant="
                                category.is_active ? 'secondary' : 'outline'
                            "
                        >
                            {{ category.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                    </div>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Edit {{ category.name }}
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Update the category label and customer-facing
                        description. Activation is managed from the category
                        list.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-border bg-card border p-6 sm:p-8">
            <CategoryForm
                :category="category"
                :form="CategoryController.update.form(category.id)"
                submit-label="Save changes"
            />
        </section>
    </main>
</template>
