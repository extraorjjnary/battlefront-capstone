<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps({
    currentPage: { type: Number, required: true },
    lastPage: { type: Number, required: true },
    route: { type: Function, required: true },
    label: { type: String, default: 'Catalog pages' },
});
</script>

<template>
    <nav
        v-if="lastPage > 1"
        :aria-label="label"
        class="mt-5 flex items-center justify-between gap-4"
    >
        <Button v-if="currentPage > 1" variant="outline" size="sm" as-child>
            <Link
                :href="route({ query: { page: currentPage - 1 } })"
                preserve-scroll
            >
                <ChevronLeft />
                Previous
            </Link>
        </Button>
        <Button v-else variant="outline" size="sm" disabled>
            <ChevronLeft />
            Previous
        </Button>

        <p class="text-muted-foreground text-sm">
            Page {{ currentPage }} of {{ lastPage }}
        </p>

        <Button
            v-if="currentPage < lastPage"
            variant="outline"
            size="sm"
            as-child
        >
            <Link
                :href="route({ query: { page: currentPage + 1 } })"
                preserve-scroll
            >
                Next
                <ChevronRight />
            </Link>
        </Button>
        <Button v-else variant="outline" size="sm" disabled>
            Next
            <ChevronRight />
        </Button>
    </nav>
</template>
