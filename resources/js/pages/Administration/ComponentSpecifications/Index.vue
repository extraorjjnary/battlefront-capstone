<script setup>
import { Head, router, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ComponentSpecificationController from '@/actions/App/Http/Controllers/Administration/ComponentSpecificationController';
import PageHeader from '@/components/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    products: { type: Array, required: true },
    profiles: { type: Array, required: true },
    candidates: { type: Array, default: () => [] },
});

const candidateSearch = ref('');
const candidateStatus = ref('needs_identity');
const candidateLabels = {
    needs_identity: 'Identity / evidence needed',
    needs_review: 'Draft needs review',
    reviewed: 'Specifications reviewed',
    not_core_component: 'Other products / manual classification',
};
const visibleCandidates = computed(() =>
    props.candidates.filter(
        (candidate) =>
            candidate.status === candidateStatus.value &&
            `${candidate.name} ${candidate.product_code}`
                .toLowerCase()
                .includes(candidateSearch.value.trim().toLowerCase()),
    ),
);
function prepareCandidate(candidate) {
    const profile = props.profiles.find(
        (item) => item.id === candidate.profile_id,
    );
    if (profile) editProfile(profile);
    else {
        startNew();
        productId.value = String(candidate.product_id);
        componentType.value = candidate.suggested_type ?? 'processor';
    }
    document.getElementById('spec-product')?.focus();
}

const componentTypes = [
    ['processor', 'Processor (CPU)'],
    ['motherboard', 'Motherboard'],
    ['memory', 'Memory (RAM)'],
    ['storage', 'Storage'],
    ['power_supply', 'Power Supply (PSU)'],
    ['case', 'PC Case'],
    ['graphics_card', 'Graphics Card (GPU)'],
    ['cooler', 'CPU Cooler'],
    ['fan', 'Case Fan'],
    ['network_adapter', 'Network Adapter'],
    ['monitor', 'Monitor'],
    ['keyboard', 'Keyboard'],
    ['mouse', 'Mouse'],
    ['headset', 'Headset'],
    ['speakers', 'Speakers'],
    ['webcam', 'Webcam'],
    ['ups_avr', 'UPS / AVR'],
];
const selectedProfileId = ref('');
const productId = ref('');
const componentType = ref('processor');
const partNumber = ref('');
const hardwareRevision = ref('');
const editVersion = ref(null);
const specificationsText = ref('{}');
const evidenceText = ref(
    '[\n  {\n    "url": "https://manufacturer.example/specifications",\n    "title": "Manufacturer product specification",\n    "section": "Specifications",\n    "verified_at": "2026-10-27",\n    "variant": "EXACT-MODEL-OR-PART-NUMBER"\n  }\n]',
);
const message = ref('');
const http = useHttp({});
const selectedProfile = computed(
    () =>
        props.profiles.find(
            (profile) => String(profile.id) === selectedProfileId.value,
        ) ?? null,
);
const productsWithProfile = computed(
    () => new Set(props.profiles.map((profile) => profile.product_id)),
);

function startNew() {
    selectedProfileId.value = '';
    productId.value = '';
    componentType.value = 'processor';
    partNumber.value = '';
    hardwareRevision.value = '';
    editVersion.value = null;
    specificationsText.value = '{}';
    evidenceText.value = '[]';
    message.value = '';
}

function editProfile(profile) {
    selectedProfileId.value = String(profile.id);
    productId.value = String(profile.product_id);
    componentType.value = profile.component_type;
    partNumber.value = profile.manufacturer_part_number ?? '';
    hardwareRevision.value = profile.hardware_revision ?? '';
    editVersion.value = profile.edit_version;
    specificationsText.value = JSON.stringify(profile.specifications, null, 2);
    evidenceText.value = JSON.stringify(profile.evidence, null, 2);
    message.value = '';
}

function parseJson(text, label) {
    try {
        return JSON.parse(text);
    } catch {
        throw new Error(`${label} must contain valid JSON.`);
    }
}

async function saveDraft() {
    message.value = '';
    try {
        http.product_id = Number(productId.value);
        http.component_type = componentType.value;
        http.manufacturer_part_number = partNumber.value || null;
        http.hardware_revision = hardwareRevision.value || null;
        http.edit_version = editVersion.value;
        http.specifications = parseJson(
            specificationsText.value,
            'Specifications',
        );
        http.evidence = parseJson(evidenceText.value, 'Evidence');
        const profile = await http.post(
            ComponentSpecificationController.store.url(),
        );
        selectedProfileId.value = String(profile.id);
        productId.value = String(profile.product_id);
        componentType.value = profile.component_type;
        partNumber.value = profile.manufacturer_part_number ?? '';
        hardwareRevision.value = profile.hardware_revision ?? '';
        editVersion.value = profile.edit_version;
        message.value = `Saved draft revision ${profile.revision}. Compatibility stays unverified until an administrator approves it.`;
        router.reload({ only: ['profiles', 'candidates'] });
    } catch (error) {
        message.value = error.message?.includes('valid JSON')
            ? error.message
            : 'Could not save this specification draft. Check the fields and try again.';
    }
}

async function approveProfile(profile) {
    try {
        await http.post(
            ComponentSpecificationController.approve.url({
                componentSpecification: profile.id,
            }),
            {
                data: { edit_version: profile.edit_version },
            },
        );
        message.value = 'A new reviewed specification revision was approved.';
        router.reload({ only: ['profiles', 'candidates'] });
    } catch {
        message.value =
            'Could not approve this revision. Confirm exact-variant evidence and reload the profile.';
    }
}
</script>

<template>
    <Head title="Component specifications" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <PageHeader
            title="PC component specifications"
            heading-id="component-specifications-heading"
        />
        <p class="text-muted-foreground max-w-3xl text-sm leading-6">
            Reuse existing catalog products. Record exact hardware facts and
            source evidence. Compatibility uses only the latest approved
            revision; missing attributes remain unknown.
        </p>

        <section
            class="border-border bg-card rounded-lg border p-5"
            aria-labelledby="readiness-heading"
        >
            <h2 id="readiness-heading" class="font-semibold">
                Catalog readiness
            </h2>
            <p class="text-muted-foreground mt-1 text-sm">
                Category suggestions are a review queue, not proof of
                compatibility. Profiles require exact-model evidence.
            </p>
            <div class="my-4 flex flex-wrap gap-3">
                <Input
                    v-model="candidateSearch"
                    aria-label="Search readiness queue"
                    placeholder="Search product or code"
                    class="max-w-sm"
                />
                <select
                    v-model="candidateStatus"
                    aria-label="Readiness status"
                    class="border-input bg-background rounded-md border p-2 text-sm"
                >
                    <option
                        v-for="(label, status) in candidateLabels"
                        :key="status"
                        :value="status"
                    >
                        {{ label }} ({{
                            candidates.filter((item) => item.status === status)
                                .length
                        }})
                    </option>
                </select>
            </div>
            <ul class="divide-border max-h-80 divide-y overflow-y-auto">
                <li
                    v-for="candidate in visibleCandidates"
                    :key="candidate.product_id"
                    class="flex items-center justify-between gap-4 py-3"
                >
                    <div>
                        <p class="text-sm font-medium">{{ candidate.name }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ candidate.category }} ·
                            {{ candidate.stock_quantity }} in stock ·
                            {{ candidate.reason }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="prepareCandidate(candidate)"
                        >{{
                            candidate.profile_id ? 'Review' : 'Prepare'
                        }}</Button
                    >
                </li>
                <li
                    v-if="!visibleCandidates.length"
                    class="text-muted-foreground py-6 text-sm"
                >
                    No products match this readiness filter.
                </li>
            </ul>
        </section>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <section
                class="surface-panel border-border bg-card border"
                aria-label="Component specification profiles"
            >
                <div
                    class="border-border flex items-center justify-between gap-3 border-b p-5"
                >
                    <div>
                        <h2 class="font-semibold">Specification profiles</h2>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ profiles.length }} catalog products configured
                        </p>
                    </div>
                    <Button variant="outline" @click="startNew"
                        >New profile</Button
                    >
                </div>
                <ul class="divide-border divide-y">
                    <li
                        v-for="profile in profiles"
                        :key="profile.id"
                        class="flex flex-wrap items-center justify-between gap-4 p-5"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold">
                                {{ profile.product_name }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{ profile.product_code }} ·
                                {{ profile.component_type }} · revision
                                {{ profile.revision ?? 'none' }}
                            </p>
                            <p
                                v-if="profile.incomplete_attributes.length"
                                class="mt-2 text-xs text-amber-600"
                            >
                                Missing:
                                {{ profile.incomplete_attributes.join(', ') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Badge
                                :variant="
                                    profile.review_status === 'approved'
                                        ? 'default'
                                        : 'outline'
                                "
                                >{{ profile.review_status }}</Badge
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="editProfile(profile)"
                                >Edit</Button
                            >
                            <Button
                                v-if="profile.review_status !== 'approved'"
                                size="sm"
                                :disabled="http.processing"
                                @click="approveProfile(profile)"
                                >Approve</Button
                            >
                        </div>
                    </li>
                    <li
                        v-if="profiles.length === 0"
                        class="text-muted-foreground p-8 text-center"
                    >
                        No component profiles yet. Select an existing product to
                        begin.
                    </li>
                </ul>
            </section>

            <section
                class="surface-panel border-border bg-card h-fit border p-5"
                aria-labelledby="specification-editor-heading"
            >
                <h2
                    id="specification-editor-heading"
                    class="text-lg font-semibold"
                >
                    {{
                        selectedProfile
                            ? 'Create a new revision'
                            : 'Add a component profile'
                    }}
                </h2>
                <form class="mt-5 grid gap-4" @submit.prevent="saveDraft">
                    <div class="grid gap-2">
                        <Label for="spec-product"
                            >Existing catalog product</Label
                        >
                        <select
                            id="spec-product"
                            v-model="productId"
                            required
                            class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            :disabled="Boolean(selectedProfile)"
                        >
                            <option value="" disabled>Select a product</option>
                            <option
                                v-for="product in products"
                                :key="product.id"
                                :value="String(product.id)"
                            >
                                {{ product.name }} · {{ product.product_code
                                }}{{
                                    productsWithProfile.has(product.id)
                                        ? ' · profile exists'
                                        : ''
                                }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="spec-component-type"
                            >Component category</Label
                        >
                        <select
                            id="spec-component-type"
                            v-model="componentType"
                            required
                            class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            :disabled="Boolean(selectedProfile)"
                        >
                            <option
                                v-for="[value, label] in componentTypes"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="spec-part-number"
                            >Manufacturer part number</Label
                        >
                        <Input
                            id="spec-part-number"
                            v-model="partNumber"
                            maxlength="128"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="spec-hardware-revision"
                            >Hardware revision (if applicable)</Label
                        >
                        <Input
                            id="spec-hardware-revision"
                            v-model="hardwareRevision"
                            maxlength="80"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="spec-facts"
                            >Technical specifications (JSON)</Label
                        >
                        <textarea
                            id="spec-facts"
                            v-model="specificationsText"
                            rows="12"
                            spellcheck="false"
                            class="border-input bg-background rounded-md border p-3 font-mono text-xs"
                            required
                        ></textarea>
                    </div>
                    <div class="grid gap-2">
                        <Label for="spec-evidence"
                            >Manufacturer evidence (JSON)</Label
                        >
                        <textarea
                            id="spec-evidence"
                            v-model="evidenceText"
                            rows="10"
                            spellcheck="false"
                            class="border-input bg-background rounded-md border p-3 font-mono text-xs"
                            required
                        ></textarea>
                        <p class="text-muted-foreground text-xs leading-5">
                            Each source records a URL, page/document title,
                            section, verification date, and exact variant
                            matching the part number.
                        </p>
                    </div>
                    <InputError :message="http.errors.specifications" />
                    <p v-if="message" class="text-sm" role="status">
                        {{ message }}
                    </p>
                    <Button type="submit" :disabled="http.processing"
                        >Save draft revision</Button
                    >
                </form>
            </section>
        </div>
    </main>
</template>
