<script setup>
import { Link } from '@inertiajs/vue3';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';

defineProps({ active: { type: String, default: 'orders' } });
</script>

<template>
    <nav aria-label="Order sections" class="border-border flex gap-2 border-b">
        <Link
            v-for="section in ['orders', 'shipments']"
            :key="section"
            :href="
                OrderController.index(
                    section === 'shipments'
                        ? { query: { view: 'shipments' } }
                        : undefined,
                )
            "
            :aria-current="active === section ? 'page' : undefined"
            class="text-muted-foreground hover:text-foreground inline-flex min-h-12 items-center border-b-2 px-4 text-sm font-medium"
            :class="
                active === section
                    ? 'border-primary text-foreground'
                    : 'border-transparent'
            "
        >
            {{ section === 'orders' ? 'Orders' : 'Shipments' }}
        </Link>
    </nav>
</template>
