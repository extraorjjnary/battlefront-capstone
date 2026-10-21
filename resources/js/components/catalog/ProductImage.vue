<script setup>
import { ImageOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    imageUrl: { type: String, default: null },
    productName: { type: String, required: true },
    contain: { type: Boolean, default: true },
    desktopCover: { type: Boolean, default: false },
});

const failedToLoad = ref(false);
const hasImage = computed(() => Boolean(props.imageUrl) && !failedToLoad.value);
const isDemoImage = computed(() =>
    props.imageUrl?.includes('/images/demo-products/'),
);

watch(
    () => props.imageUrl,
    () => {
        failedToLoad.value = false;
    },
);
</script>

<template>
    <div class="bg-muted relative flex size-full items-center justify-center">
        <img
            v-if="hasImage"
            :src="imageUrl"
            :alt="`${productName} product image`"
            loading="lazy"
            decoding="async"
            class="size-full"
            :class="[
                contain ? 'object-contain' : 'object-cover',
                { 'lg:object-cover': desktopCover },
            ]"
            @error="failedToLoad = true"
        />
        <div
            v-else
            class="text-muted-foreground flex min-w-0 flex-col items-center gap-2 px-2 text-center sm:gap-3 sm:px-6"
        >
            <span
                class="border-border bg-background/50 flex size-10 items-center justify-center border sm:size-14"
            >
                <ImageOff class="size-6" aria-hidden="true" />
            </span>
            <span class="text-xs font-medium wrap-anywhere sm:text-sm"
                >Image unavailable</span
            >
        </div>
        <span
            v-if="hasImage && isDemoImage"
            class="border-border bg-background/90 text-muted-foreground absolute right-3 bottom-3 border px-2 py-1 text-[0.65rem] font-bold tracking-wider uppercase"
        >
            Demo image
        </span>
    </div>
</template>
