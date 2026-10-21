<script setup>
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
defineProps({
    breadcrumbs: { type: Array, default: () => [] },
});
</script>

<template>
    <header
        class="bg-card border-sidebar-border/70 flex h-16 min-w-0 shrink-0 items-center gap-2 border-b px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12"
    >
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <SidebarTrigger class="shrink-0" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <AppearanceToggle />
            <NotificationBell />
        </div>
    </header>
</template>
