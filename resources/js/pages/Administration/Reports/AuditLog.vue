<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuditLogController from '@/actions/App/Http/Controllers/Administration/AuditLogController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    actors: { type: Array, required: true },
    actions: { type: Array, required: true },
    subjectTypes: { type: Array, required: true },
});
const page = usePage();
const action = ref(props.filters.action ?? '');
const actorId = ref(String(props.filters.actor_id ?? ''));
const subjectType = ref(props.filters.subject_type ?? '');
const subjectId = ref(props.filters.subject_id ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const dateFormatter = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

function applyFilters() {
    router.get(AuditLogController.index(), {
        action: action.value || undefined,
        actor_id: actorId.value || undefined,
        subject_type: subjectType.value || undefined,
        subject_id: subjectId.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    });
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Audit log', href: AuditLogController.index() }] } });
</script>

<template>
    <div class="contents">
        <Head title="Audit log" />
        <main class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 lg:p-10">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-primary text-xs font-semibold tracking-widest uppercase">Accountability</p>
                    <h1 class="mt-2 text-2xl font-bold">Audit log</h1>
                    <p class="text-muted-foreground mt-2 text-sm">Review who changed a record, when it happened, and the values recorded at the time.</p>
                </div>
                <a v-if="page.props.auth.can?.exportAudit" :href="AuditLogController.export({ query: props.filters }).url" class="text-primary text-sm font-semibold underline">Export filtered CSV</a>
            </header>

            <form class="border-border bg-card grid gap-4 border p-5 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="applyFilters">
                <div class="space-y-1"><Label for="audit-from">From</Label><Input id="audit-from" v-model="from" type="date" /><p v-if="page.props.errors?.from" class="text-destructive text-xs">{{ page.props.errors.from }}</p></div>
                <div class="space-y-1"><Label for="audit-to">To</Label><Input id="audit-to" v-model="to" type="date" /><p v-if="page.props.errors?.to" class="text-destructive text-xs">{{ page.props.errors.to }}</p></div>
                <div class="space-y-1"><Label for="audit-actor">Actor</Label><select id="audit-actor" v-model="actorId" class="border-input bg-background w-full border p-2"><option value="">All actors</option><option v-for="actor in actors" :key="actor.id" :value="String(actor.id)">{{ actor.name }} (#{{ actor.id }})</option></select></div>
                <div class="space-y-1"><Label for="audit-action">Action</Label><Input id="audit-action" v-model="action" list="audit-action-options" maxlength="100" placeholder="All actions" /><datalist id="audit-action-options"><option v-for="option in actions" :key="option" :value="option" /></datalist></div>
                <div class="space-y-1"><Label for="audit-subject-type">Record type</Label><Input id="audit-subject-type" v-model="subjectType" list="audit-subject-options" maxlength="100" placeholder="All record types" /><datalist id="audit-subject-options"><option v-for="option in subjectTypes" :key="option" :value="option" /></datalist></div>
                <div class="space-y-1"><Label for="audit-subject-id">Record ID</Label><Input id="audit-subject-id" v-model="subjectId" type="number" min="1" placeholder="Any ID" /></div>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-3"><Button type="submit">Apply filters</Button><Link :href="AuditLogController.index()" class="text-primary text-sm underline">Clear filters</Link></div>
            </form>

            <p class="text-muted-foreground text-sm">{{ entries.total }} matching events, newest first</p>
            <section class="grid gap-3" aria-label="Audit events">
                <article v-for="entry in entries.data" :key="entry.id" class="border-border bg-card border p-5 text-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-semibold">{{ entry.action_label }}</h2><p class="text-muted-foreground mt-1">{{ entry.actor_name }}<span v-if="entry.actor_id"> (#{{ entry.actor_id }})</span> · <time :datetime="entry.created_at">{{ dateFormatter.format(new Date(entry.created_at)) }}</time></p></div><span class="text-muted-foreground text-xs">Event #{{ entry.id }}</span></div>
                    <p class="mt-3"><Link v-if="entry.subject_url" :href="entry.subject_url" class="text-primary font-medium underline">{{ entry.subject_label }} #{{ entry.subject_id }}</Link><span v-else>{{ entry.subject_label }}<template v-if="entry.subject_id"> #{{ entry.subject_id }}</template></span></p>
                    <p v-if="entry.reason" class="mt-2">Reason: {{ entry.reason }}</p>
                    <details v-if="entry.details.length" class="border-border mt-3 border-t pt-3"><summary class="text-primary cursor-pointer font-medium">View recorded changes</summary><dl class="mt-3 grid gap-2"><div v-for="(detail, index) in entry.details" :key="index" class="bg-secondary grid gap-1 p-2 sm:grid-cols-[10rem_1fr]"><dt class="font-medium">{{ detail.label }}</dt><dd class="break-words"><span v-if="detail.before !== null" class="text-muted-foreground">{{ detail.before }} → </span>{{ detail.after }}</dd></div></dl></details>
                    <p v-else-if="!entry.details_visible && entry.has_changes" class="text-muted-foreground mt-2 text-xs">Change details are restricted for this role.</p>
                </article>
                <p v-if="entries.data.length === 0" class="border-border bg-card border p-6 text-sm">No audit events match these filters.</p>
            </section>
            <nav v-if="entries.last_page > 1" class="flex justify-between text-sm" aria-label="Audit log pages"><Link v-if="entries.prev_page_url" :href="entries.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="entries.next_page_url" :href="entries.next_page_url" class="text-primary underline">Next</Link></nav>
        </main>
    </div>
</template>
