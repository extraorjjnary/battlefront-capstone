<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import WorkQueueController from '@/actions/App/Http/Controllers/Administration/WorkQueueController';
import { formatDateTime } from '@/lib/dateTime';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
});
const kind = ref(props.filters.kind ?? 'all');
const responsible = ref(props.filters.responsible ?? '');
const olderThan = ref(String(props.filters.older_than ?? 'all'));
watch(
    () => props.filters,
    (filters) => {
        kind.value = filters.kind ?? 'all';
        responsible.value = filters.responsible ?? '';
        olderThan.value = String(filters.older_than ?? 'all');
    },
);
function applyFilters() {
    router.get(
        WorkQueueController.index(),
        {
            kind: kind.value === 'all' ? undefined : kind.value,
            responsible: responsible.value.trim() || undefined,
            older_than: olderThan.value === 'all' ? undefined : olderThan.value,
        },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Work queue', href: WorkQueueController.index() },
        ],
    },
});
</script>

<template>
    <Head title="Admin work queue" />
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 lg:p-10">
        <header class="border-border bg-card border p-6">
            <p
                class="text-primary text-xs font-semibold tracking-widest uppercase"
            >
                Operations
            </p>
            <h1 class="mt-2 text-2xl font-bold">Work queue</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Open work you can act on, oldest first. Age is informational; no
                overdue threshold is applied.
            </p>
        </header>
        <form
            class="border-border bg-card grid gap-3 border p-4 sm:grid-cols-2 lg:grid-cols-4"
            @submit.prevent="applyFilters"
        >
            <label class="grid gap-1 text-sm"
                >Task type<select
                    v-model="kind"
                    class="border-input bg-background border p-2"
                >
                    <option value="all">All tasks</option>
                    <option value="stock_adjustment">Stock adjustment</option>
                    <option value="damage">Damage</option>
                    <option value="supplier_return">Supplier return</option>
                    <option value="customer_return">Customer return</option>
                    <option value="customer_handover">Customer handover</option>
                    <option value="refund">Refund payment</option>
                    <option value="refund_reconciliation">
                        Refund reconciliation
                    </option>
                    <option value="order">Order</option>
                </select></label
            >
            <label class="grid gap-1 text-sm"
                >Responsible person<input
                    v-model="responsible"
                    class="border-input bg-background border p-2"
                    maxlength="255"
                    placeholder="Search name"
            /></label>
            <label class="grid gap-1 text-sm"
                >Age<select
                    v-model="olderThan"
                    class="border-input bg-background border p-2"
                >
                    <option value="all">Any age</option>
                    <option value="1">Over 1 day</option>
                    <option value="7">Over 7 days</option>
                    <option value="30">Over 30 days</option>
                </select></label
            >
            <button
                class="bg-primary text-primary-foreground self-end px-4 py-2 text-sm font-semibold"
                type="submit"
            >
                Apply filters
            </button>
        </form>
        <p class="text-muted-foreground text-sm">
            {{ entries.total }} open items visible to your permissions
        </p>
        <div class="grid gap-3">
            <article
                v-for="entry in entries.data"
                :key="`${entry.kind}-${entry.id}`"
                class="border-border bg-card grid gap-3 border p-4 sm:grid-cols-[1fr_auto] sm:items-center"
            >
                <div>
                    <p class="text-primary text-xs font-semibold uppercase">
                        {{ entry.kind.replaceAll('_', ' ') }}
                    </p>
                    <h2 class="mt-1 font-semibold">{{ entry.reference }}</h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ entry.required_action }} · Responsible:
                        {{ entry.responsible_name }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Opened {{ formatDateTime(entry.created_at) }} ·
                        {{ entry.age_days }} days old
                    </p>
                </div>
                <Link
                    :href="entry.href"
                    class="text-primary text-sm font-semibold underline"
                    >Open record</Link
                >
            </article>
            <p
                v-if="entries.data.length === 0"
                class="border-border bg-card border p-6 text-sm"
            >
                No work requires an action available to your role.
            </p>
        </div>
        <nav
            v-if="entries.last_page > 1"
            class="flex justify-between text-sm"
            aria-label="Work queue pages"
        >
            <Link
                v-if="entries.prev_page_url"
                :href="entries.prev_page_url"
                class="text-primary underline"
                >Previous</Link
            ><span v-else></span>
            <Link
                v-if="entries.next_page_url"
                :href="entries.next_page_url"
                class="text-primary underline"
                >Next</Link
            >
        </nav>
    </main>
</template>
