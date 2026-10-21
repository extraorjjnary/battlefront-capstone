<script setup>
import darkLogoUrl from '../../../docs/assets/battlefront-logo-dark.webp';
import logoUrl from '../../../docs/assets/battlefront-logo.webp';
import { useAppearance } from '@/composables/useAppearance';
import { cn } from '@/lib/utils';

const props = defineProps({
    class: { type: [String, Array, Object], default: '' },
});

const { resolvedAppearance } = useAppearance();
</script>

<template>
    <img
        :src="resolvedAppearance === 'dark' ? darkLogoUrl : logoUrl"
        alt="Battlefront Computer Trading"
        width="1200"
        height="303"
        :class="cn('h-auto w-full object-contain', props.class)"
    />
</template>
