<script setup>
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';
const { title = '', description = '' } = defineProps({
    title: { type: String },
    description: { type: String },
});
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <slot />
    </AuthLayout>
</template>
