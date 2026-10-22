<script setup>
import { Head } from '@inertiajs/vue3';
import {
    create,
    index,
    store,
} from '@/actions/App/Http/Controllers/Administration/BranchController';
import BranchForm from '@/components/BranchForm.vue';
import PageHeader from '@/components/PageHeader.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stores', href: index() },
            { title: 'Add store', href: create() },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Add store" />
        <PageHeader title="Add store" />
        <section class="surface-panel border-border bg-card border p-4 sm:p-6">
            <BranchForm :form="store.form()" submit-label="Add store" />
        </section>
    </main>
</template>
