<script setup>
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
defineProps({
    breadcrumbs: { type: Array, required: true },
});
</script>

<template>
    <Breadcrumb class="min-w-0">
        <BreadcrumbList class="flex-nowrap">
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem
                    class="min-w-0"
                    :class="index < breadcrumbs.length - 1 && 'hidden sm:inline-flex'"
                >
                    <template v-if="index === breadcrumbs.length - 1">
                        <BreadcrumbPage class="truncate">{{ item.title }}</BreadcrumbPage>
                    </template>
                    <template v-else>
                        <BreadcrumbLink as-child>
                            <Link :href="item.href">{{ item.title }}</Link>
                        </BreadcrumbLink>
                    </template>
                </BreadcrumbItem>
                <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" class="hidden shrink-0 sm:block" />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
