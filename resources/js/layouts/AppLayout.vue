<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CustomerChatAssistant from '@/components/chatbot/CustomerChatAssistant.vue';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';

const { breadcrumbs = [] } = defineProps({
    breadcrumbs: { type: Array },
});
const page = usePage();
const customerId = computed(() =>
    page.props.auth?.can?.useCustomerCart === true
        ? page.props.auth.user?.id
        : null,
);
</script>

<template>
    <AppSidebarLayout :breadcrumbs="breadcrumbs">
        <slot />
        <CustomerChatAssistant v-if="customerId" :key="customerId" />
    </AppSidebarLayout>
</template>
