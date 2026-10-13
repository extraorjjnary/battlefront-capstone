<script setup>
import { Skeleton } from '@/components/ui/skeleton';

defineProps({ rows: { type: Number, default: 3 } });
</script>

<template>
    <div data-slot="list-skeleton" aria-hidden="true">
        <div v-for="row in rows" :key="row" class="grid gap-1 px-2 py-3">
            <Skeleton class="h-5 w-3/4" />
            <Skeleton class="h-4 w-1/2" />
        </div>
    </div>
</template>
