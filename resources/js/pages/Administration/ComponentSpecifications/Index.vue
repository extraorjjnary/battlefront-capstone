<script setup>
import { Head, router, useHttp } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import ComponentSpecificationController from '@/actions/App/Http/Controllers/Administration/ComponentSpecificationController';
import PageHeader from '@/components/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import { useActionConfirmation } from '@/composables/useActionConfirmation';
import { useUnsavedEditor } from '@/composables/useUnsavedEditor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SpecificationField from './SpecificationField.vue';
import {
    componentLabels,
    fieldsFor,
    parseSpecifications,
} from './specificationFields';

const props = defineProps({
    products: Array,
    profiles: Array,
    candidates: { type: Array, default: () => [] },
});
const { confirmation, confirm, answer } = useActionConfirmation();
const search = ref('');
const status = ref('all');
const selected = ref(null);
const productId = ref('');
const componentType = ref('processor');
const partNumber = ref('');
const hardwareRevision = ref('');
const specifications = ref({});
const evidence = ref([]);
const message = ref('');
const advancedText = ref('');
const advancedOpen = ref(false);
const advancedError = ref('');
const reviewOpen = ref(false);
const http = useHttp({
    product_id: null,
    component_type: '',
    manufacturer_part_number: null,
    hardware_revision: null,
    edit_version: null,
    specifications: {},
    evidence: [],
});
const snapshot = () =>
    JSON.stringify([
        productId.value,
        componentType.value,
        partNumber.value,
        hardwareRevision.value,
        specifications.value,
        evidence.value,
    ]);
const baseline = ref(snapshot());
const dirty = computed(
    () => baseline.value !== snapshot() || Boolean(advancedError.value),
);
const fields = computed(() => fieldsFor(componentType.value));
const statusLabels = {
    needs_identity: 'Needs specifications',
    needs_review: 'Draft needs review',
    reviewed: 'Reviewed',
    not_core_component: 'Manual classification',
};
const queue = computed(() =>
    [
        ...props.candidates,
        ...props.profiles
            .filter(
                (profile) =>
                    !props.candidates.some(
                        (candidate) =>
                            candidate.product_id === profile.product_id,
                    ),
            )
            .map((profile) => ({
                product_id: profile.product_id,
                profile_id: profile.id,
                name: profile.product_name,
                product_code: profile.product_code,
                status:
                    profile.review_status === 'approved'
                        ? 'reviewed'
                        : 'needs_review',
                stock_quantity: null,
                reason: 'Existing profile outside the current customer catalog.',
            })),
    ].filter(
        (candidate) =>
            (status.value === 'all' || candidate.status === status.value) &&
            `${candidate.name} ${candidate.product_code}`
                .toLowerCase()
                .includes(search.value.trim().toLowerCase()),
    ),
);
const availableProducts = computed(() =>
    props.products.filter(
        (product) =>
            !props.profiles.some(
                (profile) => profile.product_id === product.id,
            ) || product.id === Number(productId.value),
    ),
);
const errorEntries = computed(() => Object.entries(http.errors));
const { mayDiscard } = useUnsavedEditor(dirty, confirm);
async function openEditor(candidate = null, force = false) {
    if (http.processing || (!force && !(await mayDiscard()))) return;
    const profile = props.profiles.find(
        (item) => item.id === candidate?.profile_id,
    );
    selected.value = profile ? { ...profile } : null;
    productId.value = String(
        profile?.product_id ?? candidate?.product_id ?? '',
    );
    componentType.value =
        profile?.component_type ?? candidate?.suggested_type ?? 'processor';
    partNumber.value = profile?.manufacturer_part_number ?? '';
    hardwareRevision.value = profile?.hardware_revision ?? '';
    specifications.value = JSON.parse(
        JSON.stringify(profile?.specifications ?? {}),
    );
    if (Array.isArray(specifications.value)) specifications.value = {};
    evidence.value = JSON.parse(JSON.stringify(profile?.evidence ?? []));
    message.value = '';
    advancedError.value = '';
    advancedOpen.value = false;
    reviewOpen.value = false;
    http.clearErrors();
    baseline.value = snapshot();
    await nextTick();
    document.getElementById('specification-editor-heading')?.focus();
}
function toggleAdvanced() {
    if (!advancedOpen.value)
        advancedText.value = JSON.stringify(specifications.value, null, 2);
    advancedOpen.value = !advancedOpen.value;
}
function updateAdvanced(value) {
    advancedText.value = value;
    try {
        specifications.value = parseSpecifications(value);
        advancedError.value = '';
    } catch {
        advancedError.value = 'Enter a valid JSON object before saving.';
    }
}
async function saveDraft() {
    if (http.processing || advancedError.value) return;
    message.value = '';
    http.product_id = Number(productId.value);
    http.component_type = componentType.value;
    http.manufacturer_part_number = partNumber.value || null;
    http.hardware_revision = hardwareRevision.value || null;
    http.edit_version = selected.value?.edit_version ?? null;
    http.specifications = specifications.value;
    http.evidence = evidence.value;
    try {
        const result = await http.post(
            ComponentSpecificationController.store.url(),
        );
        selected.value = {
            ...selected.value,
            ...result,
            specifications: JSON.parse(JSON.stringify(specifications.value)),
            evidence: JSON.parse(JSON.stringify(evidence.value)),
        };
        baseline.value = snapshot();
        message.value =
            'Draft saved. Review the saved specifications and source evidence before approving.';
        router.reload({ only: ['profiles', 'candidates'] });
    } catch {
        message.value =
            'The draft was not saved. Resolve the errors below and try again.';
    }
}
async function approve() {
    if (dirty.value || !selected.value || http.processing) return;
    try {
        const result = await http.post(
            ComponentSpecificationController.approve.url({
                componentSpecification: selected.value.id,
            }),
            { data: { edit_version: selected.value.edit_version } },
        );
        selected.value = { ...selected.value, ...result };
        reviewOpen.value = false;
        message.value =
            'Specifications approved. Missing facts still require verification.';
        router.reload({ only: ['profiles', 'candidates'] });
    } catch {
        message.value =
            'Approval did not complete. Check the review errors below.';
    }
}
async function reloadRevision() {
    if (!(await mayDiscard())) return;
    router.reload({
        only: ['profiles', 'candidates'],
        onSuccess: () => openEditor({ profile_id: selected.value?.id }, true),
    });
}
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="PC component specifications" />
        <PageHeader
            title="PC component specifications"
            heading-id="component-specifications-heading"
        />
        <p class="text-muted-foreground max-w-3xl text-sm leading-6">
            Select a catalog product, record its verified hardware facts, then
            review its source evidence. Unknown facts remain unverified.
        </p>
        <div class="grid items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
            <section
                class="border-border bg-card rounded-lg border p-4"
                aria-label="Component review queue"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">Products to review</h2>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="http.processing"
                        @click="openEditor()"
                        >Add specifications</Button
                    >
                </div>
                <div class="my-4 grid gap-3">
                    <Label for="queue-search">Find a product</Label
                    ><Input
                        id="queue-search"
                        v-model="search"
                        placeholder="Product name or code"
                    />
                    <Label for="queue-status">Review status</Label>
                    <select
                        id="queue-status"
                        v-model="status"
                        class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                    >
                        <option value="all">All products</option>
                        <option
                            v-for="(label, value) in statusLabels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
                <p class="text-muted-foreground mb-2 text-xs">
                    {{ queue.length }} matching products
                </p>
                <ul
                    class="divide-border max-h-[36rem] divide-y overflow-y-auto"
                >
                    <li
                        v-for="candidate in queue"
                        :key="candidate.product_id"
                        class="grid gap-2 py-4"
                    >
                        <p class="text-sm font-semibold">
                            {{ candidate.name }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ candidate.product_code
                            }}<template
                                v-if="candidate.stock_quantity !== null"
                            >
                                · {{ candidate.stock_quantity }} in
                                stock</template
                            >
                        </p>
                        <Badge variant="outline" class="w-fit">{{
                            statusLabels[candidate.status]
                        }}</Badge>
                        <p class="text-muted-foreground text-xs">
                            {{ candidate.reason }}
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="http.processing"
                            @click="openEditor(candidate)"
                            >{{
                                candidate.profile_id
                                    ? candidate.status === 'reviewed'
                                        ? 'Edit specifications'
                                        : 'Review draft'
                                    : 'Add specifications'
                            }}</Button
                        >
                    </li>
                    <li
                        v-if="!queue.length"
                        class="text-muted-foreground py-6 text-sm"
                    >
                        No products match. Try another name or review status.
                    </li>
                </ul>
            </section>
            <section
                class="border-border bg-card min-w-0 rounded-lg border p-5 sm:p-6"
                aria-labelledby="specification-editor-heading"
            >
                <h2
                    id="specification-editor-heading"
                    tabindex="-1"
                    class="text-lg font-semibold"
                >
                    {{
                        selected ? 'Edit specifications' : 'Add specifications'
                    }}
                </h2>
                <p v-if="selected" class="text-muted-foreground mt-1 text-sm">
                    Saved revision {{ selected.revision }} ·
                    {{
                        selected.review_status === 'approved'
                            ? 'Reviewed'
                            : 'Draft'
                    }}
                </p>
                <form class="mt-6 grid gap-6" @submit.prevent="saveDraft">
                    <fieldset
                        :disabled="http.processing"
                        class="grid min-w-0 gap-4"
                    >
                        <legend class="mb-4 font-semibold">
                            Product identity
                        </legend>
                        <div class="grid gap-2">
                            <Label for="spec-product">Catalog product</Label
                            ><select
                                id="spec-product"
                                v-model="productId"
                                required
                                :disabled="Boolean(selected)"
                                class="border-input bg-background h-10 w-full min-w-0 rounded-md border px-3 text-sm"
                            >
                                <option value="" disabled>
                                    Select a product
                                </option>
                                <option
                                    v-for="product in availableProducts"
                                    :key="product.id"
                                    :value="String(product.id)"
                                >
                                    {{ product.name }} ·
                                    {{ product.product_code }}
                                </option></select
                            ><InputError :message="http.errors.product_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="spec-type">Component category</Label
                            ><select
                                id="spec-type"
                                v-model="componentType"
                                :disabled="Boolean(selected)"
                                class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            >
                                <option
                                    v-for="(label, value) in componentLabels"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option></select
                            ><InputError
                                :message="http.errors.component_type"
                            />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="spec-part"
                                    >Manufacturer part number</Label
                                ><Input
                                    id="spec-part"
                                    v-model="partNumber"
                                    maxlength="128"
                                /><InputError
                                    :message="
                                        http.errors.manufacturer_part_number
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="spec-revision"
                                    >Hardware revision (if applicable)</Label
                                ><Input
                                    id="spec-revision"
                                    v-model="hardwareRevision"
                                    maxlength="80"
                                /><InputError
                                    :message="http.errors.hardware_revision"
                                />
                            </div>
                        </div>
                    </fieldset>
                    <fieldset
                        :disabled="http.processing"
                        class="border-border grid min-w-0 gap-4 border-t pt-5"
                    >
                        <legend class="font-semibold">
                            Technical specifications
                        </legend>
                        <p class="text-muted-foreground text-sm">
                            Use exact source names and units. Leave unverified
                            values unknown; use “Confirm none” only when the
                            source verifies that there are none.
                        </p>
                        <template v-if="!advancedOpen"
                            ><div
                                v-for="field in fields"
                                :key="field.key"
                                class="border-border border-b pb-4 last:border-0"
                            >
                                <SpecificationField
                                    :id="'spec-' + field.key"
                                    :field="field"
                                    :model-value="specifications[field.key]"
                                    @update:model-value="
                                        specifications = {
                                            ...specifications,
                                            [field.key]: $event,
                                        }
                                    "
                                /><InputError
                                    :message="
                                        http.errors[
                                            'specifications.' + field.key
                                        ]
                                    "
                                /></div
                        ></template>
                        <Button
                            type="button"
                            variant="ghost"
                            :aria-expanded="advancedOpen"
                            aria-controls="advanced-specifications-panel"
                            :disabled="Boolean(advancedError)"
                            @click="toggleAdvanced"
                            >{{
                                advancedOpen
                                    ? 'Return to guided fields'
                                    : 'Advanced attributes (JSON)'
                            }}</Button
                        >
                        <div
                            v-if="advancedOpen"
                            id="advanced-specifications-panel"
                        >
                            <p class="text-muted-foreground mb-2 text-sm">
                                Includes all saved attributes. Uncommon fields
                                are preserved by the guided form.
                            </p>
                            <Label for="advanced-specifications"
                                >All technical attributes</Label
                            ><textarea
                                id="advanced-specifications"
                                :value="advancedText"
                                rows="10"
                                spellcheck="false"
                                class="border-input bg-background mt-2 w-full rounded-md border p-3 font-mono text-xs"
                                @input="updateAdvanced($event.target.value)"
                            ></textarea
                            ><InputError :message="advancedError" />
                        </div>
                    </fieldset>
                    <fieldset
                        :disabled="http.processing"
                        class="border-border grid min-w-0 gap-4 border-t pt-5"
                    >
                        <legend class="font-semibold">Source evidence</legend>
                        <p class="text-muted-foreground text-sm">
                            At least one source must identify the exact
                            manufacturer part number before approval.
                        </p>
                        <div
                            v-for="(source, index) in evidence"
                            :key="index"
                            class="border-border grid gap-3 rounded-md border p-4"
                        >
                            <h3 class="text-sm font-semibold">
                                Source {{ index + 1 }}
                            </h3>
                            <div
                                v-for="(label, key) in {
                                    url: 'Source URL',
                                    title: 'Page or document title',
                                    section: 'Section',
                                    verified_at: 'Verification date',
                                    variant: 'Exact model / part number',
                                }"
                                :key="key"
                                class="grid gap-2"
                            >
                                <Label :for="'source-' + index + '-' + key">{{
                                    label
                                }}</Label
                                ><Input
                                    :id="'source-' + index + '-' + key"
                                    v-model="source[key]"
                                    :type="
                                        key === 'url'
                                            ? 'url'
                                            : key === 'verified_at'
                                              ? 'date'
                                              : 'text'
                                    "
                                    required
                                /><InputError
                                    :message="
                                        http.errors[
                                            'evidence.' + index + '.' + key
                                        ]
                                    "
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="evidence.splice(index, 1)"
                                >Remove source {{ index + 1 }}</Button
                            >
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="evidence.length >= 20"
                            @click="
                                evidence.push({
                                    url: '',
                                    title: '',
                                    section: '',
                                    verified_at: '',
                                    variant: '',
                                })
                            "
                            >Add source evidence</Button
                        >
                    </fieldset>
                    <div
                        v-if="errorEntries.length"
                        role="alert"
                        class="border-destructive text-destructive rounded-md border p-4 text-sm"
                    >
                        <p class="font-semibold">Please resolve these issues</p>
                        <ul class="mt-2 list-inside list-disc">
                            <li v-for="[key, error] in errorEntries" :key="key">
                                {{ error }}
                            </li>
                        </ul>
                        <Button
                            v-if="http.errors.edit_version"
                            type="button"
                            variant="outline"
                            class="mt-3"
                            @click="reloadRevision"
                            >Reload saved revision</Button
                        >
                    </div>
                    <p v-if="message" role="status" class="text-sm">
                        {{ message }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            :variant="
                                reviewOpen && !dirty ? 'outline' : 'default'
                            "
                            :disabled="
                                http.processing || Boolean(advancedError)
                            "
                            >{{
                                http.processing ? 'Working…' : 'Save draft'
                            }}</Button
                        ><Button
                            v-if="selected?.review_status === 'draft'"
                            type="button"
                            variant="outline"
                            :disabled="dirty || http.processing"
                            @click="reviewOpen = !reviewOpen"
                            >Review saved revision</Button
                        >
                    </div>
                    <p
                        v-if="dirty && selected"
                        class="text-muted-foreground text-xs"
                    >
                        Save your changes before reviewing this revision for
                        approval.
                    </p>
                </form>
                <section
                    v-if="reviewOpen && !dirty"
                    class="border-border mt-6 grid gap-4 rounded-md border p-4"
                    aria-label="Review saved specifications"
                >
                    <h3 class="font-semibold">
                        Review revision {{ selected.revision }}
                    </h3>
                    <p class="text-sm">
                        Part number:
                        {{
                            selected.manufacturer_part_number || 'Not supplied'
                        }}. Check the recorded facts above against the saved
                        sources before approving. Approval does not make missing
                        facts compatible.
                    </p>
                    <ul class="grid gap-2 text-sm">
                        <li
                            v-for="(source, index) in selected.evidence"
                            :key="index"
                        >
                            <span class="font-medium">{{ source.title }}</span>
                            · {{ source.section }} · {{ source.variant }} ·
                            {{ source.verified_at }}
                            <p class="text-muted-foreground break-all">
                                {{ source.url }}
                            </p>
                        </li>
                    </ul>
                    <Button :disabled="http.processing" @click="approve"
                        >Approve specifications</Button
                    >
                </section>
            </section>
        </div>
        <ConfirmActionDialog :confirmation="confirmation" @answer="answer" />
    </main>
</template>
