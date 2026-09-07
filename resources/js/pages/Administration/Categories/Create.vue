<script setup>
import { Head } from '@inertiajs/vue3';
import { FolderPlus } from '@lucide/vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import CategoryForm from '@/components/CategoryForm.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Categories', href: CategoryController.index() },
            { title: 'Add category', href: CategoryController.create() },
        ],
    },
});
</script>

<template>
    <Head title="Add category" />

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
                    <FolderPlus class="size-5" />
                </span>
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Sagay City catalog
                    </p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">
                        Add category
                    </h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Create a clear product group for Battlefront hardware
                        and peripherals.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-border bg-card border p-6 sm:p-8">
            <CategoryForm
                :form="CategoryController.store.form()"
                submit-label="Create category"
            />
        </section>
    </main>
</template>
