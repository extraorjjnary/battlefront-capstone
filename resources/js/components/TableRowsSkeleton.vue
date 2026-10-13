<script setup>
import { Skeleton } from '@/components/ui/skeleton';

defineProps({
    rows: { type: Number, default: 3 },
    columns: { type: Number, default: 4 },
});
</script>

<template>
    <tbody
        data-slot="table-rows-skeleton"
        aria-hidden="true"
        class="divide-border divide-y"
    >
        <tr v-for="row in rows" :key="row">
            <td v-for="column in columns" :key="column" class="px-5 py-4">
                <Skeleton class="h-5 w-3/4" />
            </td>
        </tr>
    </tbody>
</template>
