<script setup>
import { computed } from 'vue';
import { show } from '@/actions/App/Http/Controllers/ProductCatalogController';
import TextLink from '@/components/TextLink.vue';

const props = defineProps({
    content: { type: String, required: true },
    products: { type: Array, default: () => [] },
});

const paragraphs = computed(() => {
    const products = props.products
        .filter(
            (product) =>
                Number.isSafeInteger(product?.id) &&
                product.id > 0 &&
                typeof product.name === 'string' &&
                product.name.trim() !== '',
        )
        .toSorted((left, right) => right.name.length - left.name.length);
    const pattern = products.length
        ? new RegExp(
              `(?<![\\p{L}\\p{N}_])(?:${products.map((product) => `(${product.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`).join('|')})(?![\\p{L}\\p{N}_])`,
              'giu',
          )
        : null;

    return props.content.split(/\n\s*\n/).map((paragraph) => {
        if (!pattern) return [{ text: paragraph }];

        const segments = [];
        let offset = 0;
        for (const match of paragraph.matchAll(pattern)) {
            if (match.index > offset) {
                segments.push({ text: paragraph.slice(offset, match.index) });
            }
            const product =
                products[
                    match.slice(1).findIndex((name) => name !== undefined)
                ];
            segments.push({ text: match[0], id: product.id });
            offset = match.index + match[0].length;
        }
        if (offset < paragraph.length) {
            segments.push({ text: paragraph.slice(offset) });
        }
        return segments;
    });
});
</script>

<template>
    <div class="mt-1 space-y-3 text-sm leading-6 [overflow-wrap:anywhere]">
        <p
            v-for="(paragraph, index) in paragraphs"
            :key="index"
            class="whitespace-pre-wrap"
        >
            <template
                v-for="(segment, segmentIndex) in paragraph"
                :key="segmentIndex"
            >
                <TextLink v-if="segment.id" :href="show.url(segment.id)">{{
                    segment.text
                }}</TextLink
                ><template v-else>{{ segment.text }}</template>
            </template>
        </p>
    </div>
</template>
