<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppHeaderLayout from '@/layouts/app/AppHeaderLayout.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

const { breadcrumbs = [] } = defineProps({
    breadcrumbs: { type: Array },
});

const page = usePage();
const layout = computed(() =>
    page.props.auth?.can?.accessAdministration ? AppLayout : AppHeaderLayout,
);
</script>

<template>
    <component :is="layout" :breadcrumbs="breadcrumbs">
        <slot />
    </component>
</template>
