<script setup>
import { Head } from '@inertiajs/vue3';
import { index, update } from '@/actions/App/Http/Controllers/Administration/BranchController';
import BranchForm from '@/components/BranchForm.vue';
import PageHeader from '@/components/PageHeader.vue';

defineProps({ branch: { type: Object, required: true } });
defineOptions({ layout: { breadcrumbs: [{ title: 'Branches', href: index() }, { title: 'Edit branch', href: index() }] } });
</script>

<template>
    <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
        <Head :title="`Edit ${branch.city}`" />
        <PageHeader title="Edit branch" :description="branch.city" />
        <section class="surface-panel border-border bg-card border p-4 sm:p-6">
            <BranchForm :key="branch.id" :branch="branch" :form="update.form(branch.id)" submit-label="Save changes" />
        </section>
    </main>
</template>
