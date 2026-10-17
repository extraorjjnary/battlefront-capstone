<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuditLogController from '@/actions/App/Http/Controllers/Administration/AuditLogController';

const props = defineProps({ entries: { type: Object, required: true }, filters: { type: Object, required: true } });
const action = ref(props.filters.action ?? '');
const actor = ref(props.filters.actor_id ?? '');
const page = usePage();
function applyFilters() { router.get(AuditLogController.index(), { action: action.value || undefined, actor_id: actor.value || undefined }); }
defineOptions({ layout: { breadcrumbs: [{ title: 'Audit log', href: AuditLogController.index() }] } });
</script>

<template>
    <Head title="Audit log" />
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 lg:p-10">
        <div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-primary text-xs font-semibold tracking-widest uppercase">Accountability</p><h1 class="mt-2 text-2xl font-bold">Audit log</h1></div><a v-if="page.props.auth.user?.administration_role_id && page.props.auth.can?.exportAudit" :href="AuditLogController.export({ query: props.filters }).url" class="text-primary text-sm underline">Export CSV</a></div>
        <form class="border-border bg-card flex flex-wrap items-end gap-3 border p-4" @submit.prevent="applyFilters"><label class="text-sm">Action<input v-model="action" class="border-input bg-background mt-1 block border p-2" /></label><label class="text-sm">Actor ID<input v-model="actor" type="number" class="border-input bg-background mt-1 block border p-2" /></label><button type="submit" class="bg-primary text-primary-foreground px-4 py-2 text-sm font-semibold">Filter</button></form>
        <div class="space-y-3"><article v-for="entry in entries.data" :key="entry.id" class="border-border bg-card border p-4 text-sm"><div class="flex flex-wrap justify-between gap-2"><strong>{{ entry.action }}</strong><time class="text-muted-foreground">{{ entry.created_at }}</time></div><p class="mt-1">{{ entry.actor_name ?? 'System' }} · {{ entry.subject_type }} #{{ entry.subject_id }}</p><p v-if="entry.reason" class="mt-2">{{ entry.reason }}</p><pre v-if="entry.changes" class="bg-secondary mt-2 overflow-x-auto p-2 text-xs">{{ entry.changes }}</pre></article><p v-if="entries.data.length === 0" class="text-muted-foreground p-6">No audit entries found.</p></div>
        <div v-if="entries.last_page > 1" class="flex justify-between text-sm"><Link v-if="entries.prev_page_url" :href="entries.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="entries.next_page_url" :href="entries.next_page_url" class="text-primary underline">Next</Link></div>
    </main>
</template>
