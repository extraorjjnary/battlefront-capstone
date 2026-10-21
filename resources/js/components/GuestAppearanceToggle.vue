<script setup>
import { onMounted, ref } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});
</script>

<template>
    <AppearanceToggle v-if="isMounted" />
    <span
        v-else
        class="inline-flex h-11 w-12 shrink-0 md:h-9"
        aria-hidden="true"
    />
</template>
