<script setup>
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
const { breadcrumbs = [] } = defineProps({
    breadcrumbs: { type: Array },
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
