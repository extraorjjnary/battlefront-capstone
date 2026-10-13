<script setup>
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div data-slot="stat-skeleton" aria-hidden="true" class="min-w-0 p-5">
        <Skeleton class="h-5 w-3/4" />
        <Skeleton class="mt-2 h-8 w-1/2" />
        <Skeleton class="mt-1 h-4 w-full" />
    </div>
</template>
