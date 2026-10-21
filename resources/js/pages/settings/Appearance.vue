<script setup>
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';
defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <div class="space-y-6">
        <Head title="Appearance settings" />
        <Heading variant="small" title="Appearance" />
        <AppearanceTabs />
    </div>
</template>
