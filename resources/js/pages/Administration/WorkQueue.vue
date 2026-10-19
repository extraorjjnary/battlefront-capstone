<script setup>
import { Head, Link } from '@inertiajs/vue3';
import WorkQueueController from '@/actions/App/Http/Controllers/Administration/WorkQueueController';

defineProps({ entries: { type: Object, required: true } });

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

defineOptions({
    layout: { breadcrumbs: [{ title: 'Work queue', href: WorkQueueController.index() }] },
});
</script>

<template>
    <Head title="Admin work queue" />
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 lg:p-10">
        <header class="border-border bg-card border p-6">
            <p class="text-primary text-xs font-semibold tracking-widest uppercase">Operations</p>
            <h1 class="mt-2 text-2xl font-bold">Work queue</h1>
            <p class="text-muted-foreground mt-2 text-sm">Open work you can act on, oldest first. Age is informational; no overdue threshold is applied.</p>
        </header>
        <p class="text-muted-foreground text-sm">{{ entries.total }} open items visible to your permissions</p>
        <div class="grid gap-3">
            <article v-for="entry in entries.data" :key="`${entry.kind}-${entry.id}`" class="border-border bg-card grid gap-3 border p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                <div>
                    <p class="text-primary text-xs font-semibold uppercase">{{ entry.kind.replaceAll('_', ' ') }}</p>
                    <h2 class="mt-1 font-semibold">{{ entry.reference }}</h2>
                    <p class="text-muted-foreground mt-1 text-sm">{{ entry.required_action }} · Responsible: {{ entry.responsible_name }}</p>
                    <p class="text-muted-foreground mt-1 text-xs">Opened {{ dateFormatter.format(new Date(entry.created_at)) }} · {{ entry.age_days }} days old</p>
                </div>
                <Link :href="entry.href" class="text-primary text-sm font-semibold underline">Open record</Link>
            </article>
            <p v-if="entries.data.length === 0" class="border-border bg-card border p-6 text-sm">No work requires an action available to your role.</p>
        </div>
        <nav v-if="entries.last_page > 1" class="flex justify-between text-sm" aria-label="Work queue pages">
            <Link v-if="entries.prev_page_url" :href="entries.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span>
            <Link v-if="entries.next_page_url" :href="entries.next_page_url" class="text-primary underline">Next</Link>
        </nav>
    </main>
</template>
