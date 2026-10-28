<script setup>
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import { useActionConfirmation } from '@/composables/useActionConfirmation';
import { login } from '@/routes';
import { cartBlocker, allowsMultipleComponents } from './builderActions';
import { Button } from '@/components/ui/button';
import BuildSummary from './BuildSummary.vue';
import SavedBuilds from './SavedBuilds.vue';
import ComponentRow from './ComponentRow.vue';
import ComponentPicker from './ComponentPicker.vue';
import { Input } from '@/components/ui/input';
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onMounted,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue';
import { validate as validateBuild } from '@/actions/App/Http/Controllers/PcBuilderController';
import pcBuildRoutes, {
    store as storeBuild,
    update as updateBuild,
    duplicate as duplicateBuild,
    destroy as deleteBuild,
    share as shareBuild,
} from '@/routes/pc-builds';
import { index as cartIndex } from '@/routes/cart';

const props = defineProps({
    components: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    ruleset_version: { type: Number, required: true },
    saved_builds: { type: Array, default: () => [] },
});

const essentialTypes = [
    'processor',
    'motherboard',
    'memory',
    'storage',
    'power_supply',
    'case',
];
const componentLabels = {
    processor: 'Processor (CPU)',
    motherboard: 'Motherboard',
    memory: 'Memory (RAM)',
    storage: 'Storage',
    power_supply: 'Power Supply (PSU)',
    case: 'PC Case',
    graphics_card: 'Graphics Card (GPU)',
    cooler: 'CPU Cooler',
    fan: 'Case Fans',
    network_adapter: 'Wi-Fi / Network Adapter',
    monitor: 'Monitor',
    keyboard: 'Keyboard',
    mouse: 'Mouse',
    headset: 'Headset',
    speakers: 'Speakers',
    webcam: 'Webcam',
    ups_avr: 'UPS / AVR',
};
const simpleOrder = [...essentialTypes, 'graphics_card', 'cooler', 'fan'];
const mode = ref('simple');
const activeType = ref('processor');
const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const savedOpen = ref(false);
const mobileSummaryOpen = ref(false);
const transferring = ref(false);
const checking = ref(false);
let selectionVersion = 0;
const budget = ref('');
const selections = ref([]);
const validation = ref(null);
const validationError = ref('');
const savedBuilds = ref([...props.saved_builds]);
const compareUuids = ref([]);
const buildName = ref('My PC Build');
const editingUuid = ref(null);
const shareMessage = ref('');
const http = useHttp({ components: [] });
const saveHttp = useHttp({
    name: 'My PC Build',
    mode: 'simple',
    budget: null,
    edit_version: 1,
    components: [],
});
const page = usePage();
const { confirmation, confirm, answer } = useActionConfirmation();
const cartExplanation = computed(() =>
    cartBlocker({
        checking: checking.value,
        error: validationError.value,
        missing: requiredMissing.value.map((type) => componentLabels[type]),
        validation: validation.value,
    }),
);
let validationTimer;

const componentTypes = computed(() =>
    mode.value === 'simple' ? simpleOrder : Object.keys(componentLabels),
);
const selectedTotal = computed(() =>
    selections.value.reduce((total, item) => {
        const product = findProduct(item.product_id);
        return total + Number(product?.price ?? 0) * item.quantity;
    }, 0),
);
const requiredTypes = computed(() => {
    const processor = selections.value.find(
        (item) => item.component_type === 'processor',
    );
    const specifications = processor
        ? findProduct(processor.product_id)?.specifications
        : null;
    return [
        ...essentialTypes,
        ...(specifications?.integrated_graphics === false
            ? ['graphics_card']
            : []),
        ...(specifications?.included_cooler === false ? ['cooler'] : []),
    ];
});
const requiredMissing = computed(() => [
    ...new Set([
        ...requiredTypes.value.filter(
            (type) =>
                !selections.value.some((item) => item.component_type === type),
        ),
        ...(validation.value?.required_components ?? []),
    ]),
]);
const completion = computed(() =>
    Math.max(
        0,
        Math.min(
            100,
            Math.round(
                ((requiredTypes.value.length - requiredMissing.value.length) /
                    requiredTypes.value.length) *
                    100,
            ),
        ),
    ),
);
const budgetExceeded = computed(
    () => budget.value !== '' && selectedTotal.value > Number(budget.value),
);
const comparedBuilds = computed(() =>
    savedBuilds.value.filter((build) =>
        compareUuids.value.includes(build.uuid),
    ),
);

onMounted(() => {
    try {
        const draft = JSON.parse(
            localStorage.getItem('battlefront_pc_build_draft') ?? 'null',
        );
        if (draft && Array.isArray(draft.selections)) {
            selections.value = draft.selections.filter((item) =>
                findProduct(item.product_id),
            );
            budget.value = typeof draft.budget === 'string' ? draft.budget : '';
            mode.value = draft.mode === 'advanced' ? 'advanced' : 'simple';
            buildName.value =
                typeof draft.name === 'string' ? draft.name : 'My PC Build';
        }
    } catch {
        try {
            localStorage.removeItem('battlefront_pc_build_draft');
        } catch {
            /* Storage is unavailable. */
        }
    }
});

watch(
    [selections, budget, mode, buildName],
    () => {
        try {
            localStorage.setItem(
                'battlefront_pc_build_draft',
                JSON.stringify({
                    selections: selections.value,
                    budget: budget.value,
                    mode: mode.value,
                    name: buildName.value,
                }),
            );
        } catch {
            /* Storage may be disabled; keep the in-memory draft. */
        }
    },
    { deep: true },
);

watch(
    selections,
    () => {
        selectionVersion += 1;
        validation.value = null;
        validationError.value = '';
        checking.value = true;
        http.cancel();
        clearTimeout(validationTimer);
        validationTimer = setTimeout(runValidation, 250);
    },
    { deep: true, flush: 'sync' },
);

onBeforeUnmount(() => {
    selectionVersion += 1;
    clearTimeout(validationTimer);
    http.cancel();
});

function findProduct(productId) {
    const product = Object.values(props.components)
        .flat()
        .find((item) => item.id === productId);
    if (product) return product;
    return (
        savedBuilds.value
            .flatMap((build) => build.items ?? [])
            .find((item) => item.product_id === productId) ?? null
    );
}

function addProduct(product) {
    if (transferring.value || Number(product.stock_quantity) < 1) return;
    pickerOpen.value = false;
    const existing = selections.value.find(
        (item) => item.product_id === product.id,
    );
    const allowsMultiple = allowsMultipleComponents(product.component_type);
    if (existing && allowsMultiple) {
        existing.quantity = Math.min(
            existing.quantity + 1,
            Number(product.stock_quantity ?? 99),
        );
    } else if (!existing) {
        if (!allowsMultiple) {
            selections.value = selections.value.filter(
                (item) => item.component_type !== product.component_type,
            );
        }
        selections.value.push({
            key: `${product.component_type}-${product.id}`,
            product_id: product.id,
            component_type: product.component_type,
            quantity: 1,
            configuration: {},
        });
    }
    validationError.value = '';
}

function changeQuantity(productId, quantity) {
    const item = selections.value.find(
        (selected) => selected.product_id === productId,
    );
    const product = findProduct(productId);
    if (
        !item ||
        !product ||
        transferring.value ||
        !Number.isInteger(Number(quantity))
    )
        return;
    if (quantity < 1) {
        removeProduct(productId);
        return;
    }
    item.quantity = Math.min(quantity, Number(product.stock_quantity ?? 99));
}

function removeProduct(productId) {
    if (!transferring.value)
        selections.value = selections.value.filter(
            (item) => item.product_id !== productId,
        );
}

async function runValidation() {
    const version = selectionVersion;
    checking.value = true;
    validationError.value = '';
    try {
        http.components = requestComponents();
        const result = await http.post(validateBuild.url());
        if (version === selectionVersion) validation.value = result;
    } catch (error) {
        if (version === selectionVersion) {
            validation.value = null;
            validationError.value =
                error?.response?.data?.message ??
                'Build compatibility could not be checked. Please try again.';
        }
    } finally {
        if (version === selectionVersion) checking.value = false;
    }
}

function openPicker(type, event) {
    activeType.value = type;
    pickerTrigger.value = event?.currentTarget ?? null;
    pickerOpen.value = true;
}

function rowItems(type) {
    return selections.value
        .filter((item) => item.component_type === type)
        .map((item) => {
            const product = findProduct(item.product_id);
            return {
                ...product,
                ...item,
                name: product?.name ?? 'Unavailable product',
                formattedPrice: formatPrice(product?.price),
                status: product
                    ? selectedProductStatus(product)
                    : 'Unavailable',
            };
        });
}

async function addCurrentBuildToCart() {
    if (!page.props.auth?.user) {
        router.visit(login.url());
        return;
    }
    if (
        transferring.value ||
        saveHttp.processing ||
        checking.value ||
        !validation.value?.complete
    )
        return;
    transferring.value = true;
    try {
        const saved = await saveCurrentBuild();
        if (saved) await addBuildToCart(saved);
    } finally {
        transferring.value = false;
    }
}

async function toggleMobileSummary() {
    mobileSummaryOpen.value = !mobileSummaryOpen.value;
    if (mobileSummaryOpen.value) {
        await nextTick();
        document.getElementById('build-summary')?.focus();
    }
}

function addSavedBuildToCart(build) {
    if (transferring.value || saveHttp.processing) return;
    return build.uuid === editingUuid.value
        ? addCurrentBuildToCart()
        : addBuildToCart(build);
}

function requestComponents() {
    return selections.value.map(
        ({ key, product_id, quantity, configuration }) => ({
            key,
            product_id,
            quantity,
            configuration,
        }),
    );
}

async function saveCurrentBuild() {
    if (!page.props.auth?.user) {
        router.visit(login.url());
        return;
    }
    saveHttp.name = buildName.value.trim() || 'My PC Build';
    saveHttp.mode = mode.value;
    saveHttp.budget = budget.value || null;
    saveHttp.components = requestComponents();
    shareMessage.value = '';
    try {
        const saved = editingUuid.value
            ? await saveHttp.patch(updateBuild.url({ uuid: editingUuid.value }))
            : await saveHttp.post(storeBuild.url());
        const index = savedBuilds.value.findIndex(
            (build) => build.uuid === saved.uuid,
        );
        if (index < 0) savedBuilds.value.unshift(saved);
        else savedBuilds.value[index] = saved;
        editingUuid.value = saved.uuid;
        saveHttp.edit_version = saved.edit_version;
        buildName.value = saved.name;
        shareMessage.value =
            'Build saved. Stock is checked again when you add it to cart.';
        return saved;
    } catch {
        validationError.value =
            'This build could not be saved. Refresh and try again.';
    }
}

async function loadBuild(build) {
    if (transferring.value) return;
    if (!(await mayReplaceBuild())) return;
    selections.value = build.items.map((item) => ({
        key: item.key,
        product_id: item.product_id,
        component_type: item.component_type,
        quantity: item.quantity,
        configuration: JSON.parse(JSON.stringify({ ...item.configuration })),
    }));
    mode.value = build.mode;
    budget.value = build.budget ?? '';
    buildName.value = build.name;
    editingUuid.value = build.uuid;
    saveHttp.edit_version = build.edit_version;
    activeType.value = build.items[0]?.component_type ?? 'processor';
    savedOpen.value = false;
}

async function startNewBuild() {
    if (transferring.value) return;
    if (!(await mayReplaceBuild())) return;
    selections.value = [];
    mode.value = 'simple';
    budget.value = '';
    buildName.value = 'My PC Build';
    editingUuid.value = null;
    saveHttp.edit_version = 1;
}

async function loadTemplate(template) {
    if (transferring.value) return;
    if (!(await mayReplaceBuild())) return;
    selections.value = JSON.parse(JSON.stringify(template.components)).map(
        (item) => ({ ...item, configuration: { ...item.configuration } }),
    );
    budget.value = template.budget ?? '';
    buildName.value = template.name;
    editingUuid.value = null;
    mode.value = 'simple';
    activeType.value = template.components[0]?.component_type ?? 'processor';
    saveHttp.edit_version = 1;
}

async function mayReplaceBuild() {
    return (
        selections.value.length === 0 ||
        (await confirm({
            title: 'Replace the current build?',
            description:
                'Your current selections will be replaced. Save this build first if you want to keep it.',
            label: 'Replace build',
            destructive: true,
        }))
    );
}

function toggleCompare(build) {
    if (compareUuids.value.includes(build.uuid)) {
        compareUuids.value = compareUuids.value.filter(
            (uuid) => uuid !== build.uuid,
        );
        return;
    }
    if (compareUuids.value.length >= 3) {
        validationError.value = 'Compare up to three builds at a time.';
        return;
    }
    compareUuids.value.push(build.uuid);
}

function buildPrice(build) {
    return build.items.reduce(
        (total, item) =>
            total +
            Number(item.price ?? findProduct(item.product_id)?.price ?? 0) *
                item.quantity,
        0,
    );
}

async function duplicateSavedBuild(build) {
    try {
        const duplicate = await saveHttp.post(
            duplicateBuild.url({ uuid: build.uuid }),
        );
        savedBuilds.value.unshift(duplicate);
    } catch {
        validationError.value = 'This build could not be duplicated.';
    }
}

async function deleteSavedBuild(build) {
    if (
        !(await confirm({
            title: `Delete ${build.name}?`,
            description:
                'This removes the saved build. Current selections remain in the builder.',
            label: 'Delete build',
            destructive: true,
        }))
    )
        return;
    try {
        await saveHttp.delete(deleteBuild.url({ uuid: build.uuid }));
        savedBuilds.value = savedBuilds.value.filter(
            (saved) => saved.uuid !== build.uuid,
        );
        if (editingUuid.value === build.uuid) {
            editingUuid.value = null;
            saveHttp.edit_version = 1;
        }
    } catch {
        validationError.value = 'This build could not be deleted.';
    }
}

async function toggleShare(build) {
    try {
        if (build.share_enabled) {
            await saveHttp.delete(
                pcBuildRoutes.share.destroy.url({ uuid: build.uuid }),
            );
            build.share_enabled = false;
            shareMessage.value = 'Sharing has been turned off.';
        } else {
            const result = await saveHttp.post(
                shareBuild.url({ uuid: build.uuid }),
            );
            build.share_enabled = true;
            await navigator.clipboard.writeText(
                new URL(result.url, window.location.origin).toString(),
            );
            shareMessage.value =
                'A private share link was copied. Anyone with it can view this build.';
        }
    } catch {
        validationError.value = 'The share link could not be updated.';
    }
}

async function addBuildToCart(build) {
    if (saveHttp.processing) return;
    try {
        await saveHttp.post(pcBuildRoutes.cart.store.url({ uuid: build.uuid }));
        router.visit(cartIndex.url());
    } catch {
        validationError.value =
            'The build could not be added. Check current compatibility and stock.';
    }
}

function formatPrice(price) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(price));
}

function statusLabel(status) {
    return (
        {
            compatible: 'Compatible',
            warning: 'Warning',
            incompatible: 'Incompatible',
            unknown: 'Needs verification',
        }[status] ?? 'Not checked'
    );
}

function specificationSummary(product) {
    const fields =
        {
            processor: ['socket', 'generation', 'maximum_power_w'],
            motherboard: [
                'socket',
                'chipset',
                'form_factor',
                'memory_generations',
            ],
            memory: [
                'memory_generation',
                'capacity_gb',
                'modules_per_package',
                'speed_mts',
            ],
            storage: ['interface', 'form_factor', 'capacity_gb'],
            power_supply: ['continuous_power_w', 'form_factor'],
            case: ['motherboard_form_factors', 'maximum_gpu_length_mm'],
            graphics_card: [
                'length_mm',
                'board_power_w',
                'required_power_connectors',
            ],
            cooler: ['mounting_sockets', 'height_mm', 'radiator_size_mm'],
            fan: ['size_mm', 'header_type'],
        }[product.component_type] ?? [];

    return fields
        .filter((field) => product.specifications?.[field] !== undefined)
        .map((field) => {
            const value = product.specifications[field];
            const display = Array.isArray(value)
                ? value.join(', ')
                : value !== null && typeof value === 'object'
                  ? Object.entries(value)
                        .map(([key, count]) => `${key} × ${count}`)
                        .join(', ')
                  : value;
            return `${field.replaceAll('_', ' ')}: ${display}`;
        })
        .join(' · ');
}

function selectedProductStatus(product) {
    const selected = selections.value.find(
        (item) => item.product_id === product.id,
    );
    if (!selected) return 'Select to check fit';
    const statuses =
        validation.value?.findings
            .filter((finding) => finding.component_keys.includes(selected.key))
            .map((finding) => finding.status) ?? [];
    if (statuses.includes('incompatible')) return statusLabel('incompatible');
    if (statuses.includes('unknown') || statuses.length === 0)
        return statusLabel('unknown');
    if (statuses.includes('warning')) return statusLabel('warning');
    return statusLabel('compatible');
}

function fanMountingPositions() {
    const selectedCase = selections.value.find(
        (item) => item.component_type === 'case',
    );
    const caseProduct = selectedCase
        ? findProduct(selectedCase.product_id)
        : null;
    return caseProduct?.specifications?.fan_mounting_positions ?? [];
}

function setFanMount(item, position) {
    if (transferring.value) return;
    item.configuration = { ...item.configuration, mounting_position: position };
}
</script>

<template>
    <Head title="Custom PC Builder" />
    <div class="bg-background text-foreground min-h-screen">
        <StorefrontHeader active-section="pc-builder" />
        <main class="mx-auto max-w-7xl px-4 py-8 pb-28 sm:px-6 lg:px-8 lg:pb-8">
            <div
                class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p
                        class="text-primary-ink text-sm font-semibold tracking-[0.2em] uppercase"
                    >
                        Battlefront Workshop
                    </p>
                    <h1
                        class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        Build your PC
                    </h1>
                    <p class="text-muted-foreground mt-2 max-w-2xl">
                        Choose parts from Battlefront's catalog. Compatibility
                        checks use reviewed component specifications.
                    </p>
                </div>
                <div class="flex gap-2" aria-label="Builder mode">
                    <Button
                        :variant="mode === 'simple' ? 'default' : 'outline'"
                        :disabled="transferring"
                        @click="mode = 'simple'"
                        >Simple</Button
                    >
                    <Button
                        :variant="mode === 'advanced' ? 'default' : 'outline'"
                        :disabled="transferring"
                        @click="mode = 'advanced'"
                        >Advanced</Button
                    >
                </div>
            </div>

            <section
                v-if="templates.length"
                class="border-border bg-card mb-6 rounded-xl border p-4"
                aria-labelledby="starter-templates-heading"
            >
                <h2 id="starter-templates-heading" class="font-semibold">
                    Start with a Battlefront template
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Templates use reviewed catalog parts. Stock and
                    compatibility are checked again when you add a complete
                    build to your cart.
                </p>
                <ul class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <li
                        v-for="template in templates"
                        :key="template.uuid"
                        class="border-border bg-background rounded-lg border p-4"
                    >
                        <h3 class="font-medium">{{ template.name }}</h3>
                        <p
                            v-if="template.description"
                            class="text-muted-foreground mt-1 text-sm"
                        >
                            {{ template.description }}
                        </p>
                        <p
                            v-if="template.budget"
                            class="text-muted-foreground mt-2 text-xs"
                        >
                            Suggested budget {{ formatPrice(template.budget) }}
                        </p>
                        <Button
                            class="mt-3"
                            size="sm"
                            variant="outline"
                            @click="loadTemplate(template)"
                            >Customize this build</Button
                        >
                    </li>
                </ul>
            </section>

            <div
                class="border-border mb-6 flex flex-wrap items-center gap-2 border-y py-3"
            >
                <Button
                    variant="outline"
                    :disabled="transferring"
                    @click="
                        page.props.auth?.user
                            ? (savedOpen = !savedOpen)
                            : router.visit(login.url())
                    "
                    >{{
                        savedOpen
                            ? 'Close saved builds'
                            : page.props.auth?.user
                              ? 'Saved builds'
                              : 'Sign in to view saved builds'
                    }}</Button
                >
                <Button
                    v-if="editingUuid"
                    variant="outline"
                    :disabled="saveHttp.processing || transferring"
                    @click="
                        toggleShare(
                            savedBuilds.find(
                                (build) => build.uuid === editingUuid,
                            ),
                        )
                    "
                    >Share saved build</Button
                >
                <Button
                    variant="ghost"
                    :disabled="transferring"
                    @click="startNewBuild"
                    >Start new build</Button
                >
                <p
                    v-if="!page.props.auth?.user"
                    class="text-muted-foreground text-sm"
                >
                    Sign in to save, share and add a build to cart. Your draft
                    stays in this browser.
                </p>
                <p v-if="shareMessage" class="text-sm" role="status">
                    {{ shareMessage }}
                </p>
            </div>
            <SavedBuilds
                :open="savedOpen && Boolean(page.props.auth?.user)"
                :saved-builds="savedBuilds"
                :compared-builds="comparedBuilds"
                :compare-uuids="compareUuids"
                :component-labels="componentLabels"
                :share-message="shareMessage"
                :start-new-build="startNewBuild"
                :load-build="loadBuild"
                :add-build-to-cart="addSavedBuildToCart"
                :duplicate-saved-build="duplicateSavedBuild"
                :toggle-compare="toggleCompare"
                :toggle-share="toggleShare"
                :delete-saved-build="deleteSavedBuild"
                :find-product="findProduct"
                :format-price="formatPrice"
                :build-price="buildPrice"
            />
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <section
                    class="border-border bg-card min-w-0 rounded-xl border p-4 sm:p-6"
                    aria-label="Select PC components"
                >
                    <div class="border-border border-b pb-4">
                        <h2 class="text-lg font-semibold">
                            Choose your components
                        </h2>
                        <p class="text-muted-foreground mt-1 text-sm">
                            Start with a processor, then work through your
                            build. * Required part
                        </p>
                    </div>
                    <ComponentRow
                        v-for="type in componentTypes"
                        :key="type"
                        :label="componentLabels[type]"
                        :required="requiredTypes.includes(type)"
                        :multiple="allowsMultipleComponents(type)"
                        :items="rowItems(type)"
                        :disabled="transferring"
                        @select="openPicker(type, $event)"
                        @remove="removeProduct"
                    />
                </section>
                <aside
                    id="build-summary"
                    tabindex="-1"
                    :class="mobileSummaryOpen ? 'block' : 'hidden lg:block'"
                    class="border-border bg-card h-fit rounded-xl border p-5 lg:sticky lg:top-6"
                    aria-label="Build summary"
                >
                    <BuildSummary
                        :completion="completion"
                        :total="formatPrice(selectedTotal)"
                        :budget="budget"
                        :budget-exceeded="budgetExceeded"
                        :difference="
                            formatPrice(
                                Math.abs(Number(budget) - selectedTotal),
                            )
                        "
                        :missing="
                            requiredMissing.map((type) => componentLabels[type])
                        "
                        :checking="checking"
                        :validation="validation"
                        :error="validationError"
                        :status-label="statusLabel"
                        @retry="runValidation"
                    >
                        <label
                            for="build-budget"
                            class="mt-5 block text-sm font-medium"
                            >Optional budget (PHP)</label
                        >
                        <Input
                            id="build-budget"
                            v-model="budget"
                            :disabled="transferring"
                            type="number"
                            min="0"
                            step="100"
                            class="border-input bg-background mt-2"
                            placeholder="Set a budget"
                        />
                        <label
                            for="build-name"
                            class="mt-4 block text-sm font-medium"
                            >Build name</label
                        >
                        <Input
                            id="build-name"
                            v-model="buildName"
                            :disabled="transferring"
                            maxlength="120"
                            class="border-input bg-background mt-2"
                        />

                        <ul class="mt-5 space-y-3">
                            <li
                                v-for="item in selections"
                                :key="item.key"
                                class="border-border flex items-start justify-between gap-3 border-b pb-3 text-sm"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">
                                        {{ findProduct(item.product_id)?.name }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        {{
                                            componentLabels[item.component_type]
                                        }}
                                        ·
                                        {{
                                            formatPrice(
                                                findProduct(item.product_id)
                                                    ?.price,
                                            )
                                        }}
                                    </p>
                                    <div
                                        v-if="item.component_type === 'fan'"
                                        class="mt-2 grid gap-1"
                                    >
                                        <label
                                            :for="`fan-mount-${item.product_id}`"
                                            class="text-muted-foreground text-xs"
                                            >Case mounting position</label
                                        >
                                        <select
                                            v-if="fanMountingPositions().length"
                                            :id="`fan-mount-${item.product_id}`"
                                            :value="
                                                item.configuration
                                                    .mounting_position ?? ''
                                            "
                                            class="border-input bg-background rounded-md border px-2 py-1 text-xs"
                                            @change="
                                                setFanMount(
                                                    item,
                                                    $event.target.value,
                                                )
                                            "
                                        >
                                            <option value="" disabled>
                                                Select a case position
                                            </option>
                                            <option
                                                v-for="position in fanMountingPositions()"
                                                :key="position"
                                                :value="position"
                                            >
                                                {{ position }}
                                            </option>
                                        </select>
                                        <p
                                            v-else
                                            class="text-xs text-amber-700 dark:text-amber-300"
                                        >
                                            Selected case fan positions need
                                            verification.
                                        </p>
                                    </div>
                                    <div
                                        v-if="
                                            [
                                                'memory',
                                                'storage',
                                                'fan',
                                                'network_adapter',
                                            ].includes(item.component_type)
                                        "
                                        class="mt-2 flex items-center gap-2"
                                    >
                                        <label
                                            :for="`quantity-${item.product_id}`"
                                            class="text-muted-foreground text-xs"
                                            >Quantity</label
                                        >
                                        <Input
                                            :id="`quantity-${item.product_id}`"
                                            :model-value="item.quantity"
                                            :disabled="transferring"
                                            type="number"
                                            min="1"
                                            :max="
                                                findProduct(item.product_id)
                                                    ?.stock_quantity
                                            "
                                            class="border-input bg-background h-8 w-20"
                                            @update:model-value="
                                                changeQuantity(
                                                    item.product_id,
                                                    Number($event),
                                                )
                                            "
                                        />
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="text-primary-ink hover:text-destructive text-xs"
                                    :aria-label="`Remove ${findProduct(item.product_id)?.name}`"
                                    @click="removeProduct(item.product_id)"
                                >
                                    Remove
                                </button>
                            </li>
                        </ul>

                        <Button
                            class="mt-4 w-full"
                            variant="outline"
                            :disabled="saveHttp.processing || transferring"
                            @click="saveCurrentBuild"
                        >
                            {{
                                !page.props.auth?.user
                                    ? 'Sign in to save build'
                                    : editingUuid
                                      ? 'Save changes'
                                      : 'Save build'
                            }}
                        </Button>
                        <Button
                            class="mt-2 w-full"
                            :disabled="
                                saveHttp.processing ||
                                transferring ||
                                (Boolean(page.props.auth?.user) &&
                                    (checking || !validation?.complete))
                            "
                            @click="addCurrentBuildToCart"
                        >
                            {{
                                transferring
                                    ? 'Saving and adding...'
                                    : !page.props.auth?.user
                                      ? 'Sign in to add to cart'
                                      : 'Add build to cart'
                            }}
                        </Button>
                        <p
                            v-if="cartExplanation"
                            class="text-muted-foreground mt-3 text-sm"
                            role="status"
                        >
                            {{ cartExplanation }}
                        </p>
                    </BuildSummary>
                </aside>
            </div>
            <ComponentPicker
                v-model:open="pickerOpen"
                :label="componentLabels[activeType]"
                :products="
                    (components[activeType] ?? []).map((product) => ({
                        ...product,
                        summary: specificationSummary(product),
                    }))
                "
                :selected-ids="selections.map((item) => item.product_id)"
                :can-wishlist="Boolean(page.props.auth?.can?.useCustomerCart)"
                :return-focus="pickerTrigger"
                @select="addProduct"
            />
            <div
                class="border-border bg-card fixed inset-x-0 bottom-0 z-30 flex items-center justify-between gap-3 border-t p-4 lg:hidden"
            >
                <div>
                    <p class="text-muted-foreground text-xs">Build total</p>
                    <strong>{{ formatPrice(selectedTotal) }}</strong>
                </div>
                <Button
                    variant="outline"
                    :aria-expanded="mobileSummaryOpen"
                    aria-controls="build-summary"
                    @click="toggleMobileSummary"
                    >{{
                        mobileSummaryOpen ? 'Hide summary' : 'View summary'
                    }}</Button
                >
            </div>
        </main>
        <ConfirmActionDialog :confirmation="confirmation" @answer="answer" />
    </div>
</template>
