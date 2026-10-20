<script setup>
import { Head } from '@inertiajs/vue3';
import { create, index, store } from '@/actions/App/Http/Controllers/Administration/BranchController';
import BranchForm from '@/components/BranchForm.vue';
import PageHeader from '@/components/PageHeader.vue';

defineOptions({ layout: { breadcrumbs: [{ title: 'Branches', href: index() }, { title: 'Add branch', href: create() }] } });
</script>

<template>
    <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
        <Head title="Add branch" />
        <PageHeader title="Add branch" />
        <section class="surface-panel border-border bg-card border p-4 sm:p-6">
            <BranchForm :form="store.form()" submit-label="Create branch" />
        </section>
    </main>
</template>
