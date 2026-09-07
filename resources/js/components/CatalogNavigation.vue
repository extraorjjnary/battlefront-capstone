<script setup>
import { Link } from '@inertiajs/vue3';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const { isCurrentUrl } = useCurrentUrl();
const items = [
    { title: 'Products', href: ProductController.index() },
    { title: 'Categories', href: CategoryController.index() },
];
</script>

<template>
    <nav aria-label="Catalog sections" class="border-border flex border-b">
        <Link
            v-for="item in items"
            :key="item.title"
            :href="item.href"
            class="focus-visible:ring-ring relative px-4 py-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
            :class="
                isCurrentUrl(item.href)
                    ? 'text-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
        >
            {{ item.title }}
            <span
                v-if="isCurrentUrl(item.href)"
                class="bg-primary absolute inset-x-3 bottom-0 h-0.5"
            ></span>
        </Link>
    </nav>
</template>
