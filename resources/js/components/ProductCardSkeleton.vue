<script setup>
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div
        data-slot="product-card-skeleton"
        aria-hidden="true"
        class="surface-card border-border bg-card flex min-w-0 flex-col overflow-hidden border"
    >
        <Skeleton class="aspect-square w-full rounded-none sm:aspect-3/2" />
        <div class="product-card-content">
            <Skeleton class="h-5 w-20 max-w-full" />
            <div class="grid gap-2">
                <Skeleton class="h-3 w-1/3" />
                <Skeleton class="h-4 w-full sm:h-5" />
                <Skeleton class="h-4 w-3/4 sm:h-5" />
                <Skeleton class="mt-1 hidden h-12 w-full sm:block" />
            </div>
            <div class="mt-auto grid gap-3 pt-1">
                <Skeleton class="h-6 w-2/3" />
                <Skeleton class="h-4 w-1/2" />
            </div>
        </div>
    </div>
</template>
