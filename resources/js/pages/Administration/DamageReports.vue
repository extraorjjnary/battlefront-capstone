<script setup>
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import AdministrationEvidenceController from '@/actions/App/Http/Controllers/Administration/AdministrationEvidenceController';
import InputError from '@/components/InputError.vue';
import SearchableProductSelect from '@/components/administration/SearchableProductSelect.vue';
import SearchField from '@/components/SearchField.vue';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { watch } from 'vue';

const props = defineProps({
    reports: Object,
    products: Array,
    suppliers: Array,
    evidence: Object,
    selected_case: { type: Number, default: null },
    filters: Object,
});
const page = usePage();
const { search, isSearching, clearSearch } = useDebouncedSearch({
    initialSearch: props.filters?.q,
    currentSearch: () => props.filters?.q,
    route: DamageReportController.index,
    query: () => ({ case: props.selected_case ?? undefined }),
});
watch(() => props.filters?.q, (query) => { search.value = query ?? ''; });
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Damage reports', href: DamageReportController.index() },
        ],
    },
});
</script>

<template>
    <Head title="Damage reports" />
    <main class="mx-auto flex w-full max-w-5xl flex-col gap-7 p-6 lg:p-10">
        <p v-if="selected_case" class="text-sm">
            Showing damage report #{{ selected_case }} ·
            <Link
                :href="DamageReportController.index()"
                class="text-primary underline"
                >Show all</Link
            >
        </p>
        <div>
            <p
                class="text-primary text-xs font-semibold tracking-widest uppercase"
            >
                Inventory control
            </p>
            <h1 class="mt-2 text-2xl font-bold">Damaged stock</h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Reported units leave sellable stock immediately and remain in
                quarantine until disposition.
            </p>
        </div>
        <Form
            v-if="page.props.auth.can?.requestReturns"
            v-bind="DamageReportController.store.form()"
            v-slot="{ errors, processing }"
            class="border-border bg-card grid gap-4 border p-5 sm:grid-cols-2"
        >
            <div class="space-y-2">
                <Label for="damage-product">Product</Label
                ><SearchableProductSelect
                    id="damage-product"
                    :products="products"
                /><InputError :message="errors.product_id" />
            </div>
            <div class="space-y-2">
                <Label for="damage-quantity">Damaged units</Label
                ><Input
                    id="damage-quantity"
                    name="quantity"
                    type="number"
                    min="1"
                    required
                /><InputError :message="errors.quantity" />
            </div>
            <div class="space-y-2 sm:col-span-2">
                <Label for="damage-reason">Reason</Label
                ><Input
                    id="damage-reason"
                    name="reason"
                    required
                    minlength="5"
                    maxlength="2000"
                /><InputError :message="errors.reason" />
            </div>
            <Button type="submit" :disabled="processing"
                >Quarantine and report</Button
            >
        </Form>
        <section class="space-y-3" aria-label="Damage cases">
            <SearchField
                id="damage-search"
                v-model="search"
                class="mb-3 max-w-xl"
                label="Search damage reports"
                placeholder="Product, requester, or reason"
                :is-searching="isSearching"
                @clear="clearSearch"
            />
            <p
                v-if="reports.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No damage reports.
            </p>
            <article
                v-for="report in reports.data"
                :key="report.id"
                class="border-border bg-card space-y-3 border p-5"
            >
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">
                            {{ report.product_name }} ·
                            {{ report.quantity }} units
                        </h2>
                        <p class="text-muted-foreground text-sm">
                            Reported by {{ report.requester_name }}:
                            {{ report.reason }}
                        </p>
                    </div>
                    <span class="text-sm capitalize">{{
                        report.disposition ?? report.status
                    }}</span>
                </div>
                <p class="text-sm">
                    Held in quarantine for this case:
                    {{ report.held_quantity }} units
                </p>
                <p v-if="report.decision_reason" class="text-sm">
                    Decision reason: {{ report.decision_reason }}
                </p>
                <div
                    v-if="evidence[report.id]?.length"
                    class="flex flex-wrap gap-3 text-sm"
                >
                    <a
                        v-for="file in evidence[report.id]"
                        :key="file.id"
                        :href="
                            AdministrationEvidenceController.show(file.id).url
                        "
                        class="text-primary underline"
                        >{{ file.original_name }}</a
                    >
                </div>
                <Form
                    v-if="
                        page.props.auth.can?.requestReturns &&
                        (evidence[report.id]?.length ?? 0) < 5
                    "
                    v-bind="
                        AdministrationEvidenceController.store.form({
                            subjectType: 'damage_report',
                            subjectId: report.id,
                        })
                    "
                    v-slot="{ errors, processing }"
                    class="flex flex-wrap items-end gap-2"
                >
                    <label class="text-sm"
                        >Inspection evidence<input
                            name="files[]"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                            class="mt-1 block" /></label
                    ><Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        >Add evidence</Button
                    ><InputError :message="errors.files || errors['files.0']" />
                </Form>
                <Form
                    v-if="
                        report.status === 'pending' &&
                        report.requester_id !== page.props.auth.user.id &&
                        page.props.auth.can?.approveReturns
                    "
                    v-bind="DamageReportController.decide.form(report.id)"
                    v-slot="{ errors, processing }"
                    class="grid gap-3 sm:grid-cols-3"
                >
                    <select
                        name="disposition"
                        required
                        class="border-border bg-background border p-2"
                    >
                        <option value="release">Release to sale</option>
                        <option value="write_off">Write off</option>
                        <option value="supplier_return">
                            Return to supplier
                        </option>
                    </select>
                    <select
                        name="supplier_id"
                        class="border-border bg-background border p-2"
                    >
                        <option value="">Select supplier if returning</option>
                        <option
                            v-for="supplier in suppliers"
                            :key="supplier.id"
                            :value="supplier.id"
                        >
                            {{ supplier.name }}
                        </option>
                    </select>
                    <Input
                        name="reason"
                        required
                        minlength="5"
                        maxlength="2000"
                        placeholder="Decision reason"
                    />
                    <Button type="submit" :disabled="processing"
                        >Record decision</Button
                    >
                    <InputError
                        :message="
                            errors.decision ||
                            errors.disposition ||
                            errors.supplier_id ||
                            errors.reason
                        "
                    />
                </Form>
            </article>
        </section>
        <div v-if="reports.last_page > 1" class="flex justify-between text-sm">
            <Link
                v-if="reports.prev_page_url"
                :href="reports.prev_page_url"
                class="text-primary underline"
                >Previous</Link
            ><span v-else></span
            ><Link
                v-if="reports.next_page_url"
                :href="reports.next_page_url"
                class="text-primary underline"
                >Next</Link
            >
        </div>
    </main>
</template>
