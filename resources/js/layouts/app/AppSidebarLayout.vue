<script setup>
import { usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
defineProps({
    breadcrumbs: { type: Array, default: () => [] },
});
const page = usePage();
const isCompactScreen = useMediaQuery('(width < 64rem)');
const useCustomerHeader = computed(
    () =>
        isCompactScreen.value &&
        page.props.auth?.can?.useCustomerCart === true &&
        page.props.auth?.can?.accessAdministration !== true,
);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar v-if="!useCustomerHeader" />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppHeader v-if="useCustomerHeader" :breadcrumbs="breadcrumbs" />
            <AppSidebarHeader v-else :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
