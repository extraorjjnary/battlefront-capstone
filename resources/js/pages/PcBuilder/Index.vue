<script setup>
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import ConfirmActionDialog from '@/components/ConfirmActionDialog.vue';
import { useActionConfirmation } from '@/composables/useActionConfirmation';
import { login } from '@/routes';
import {
    cartBlocker,
    allowsMultipleComponents,
    componentDefinitions,
} from './builderActions';
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
import {
    validate as validateBuild,
    options as componentOptions,
} from '@/actions/App/Http/Controllers/PcBuilderController';
import ReplacementDialog from './ReplacementDialog.vue';
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
    strict_selection_enabled: { type: Boolean, default: false },
});

const essentialTypes = Object.keys(componentDefinitions).filter(
    (type) => componentDefinitions[type].required,
);
const componentLabels = Object.fromEntries(
    Object.entries(componentDefinitions).map(([type, definition]) => [
        type,
        definition.label,
    ]),
);
const simpleOrder = Object.keys(componentDefinitions).filter(
    (type) => componentDefinitions[type].simple,
);
const optionalTypes = Object.keys(componentDefinitions).filter(
    (type) => !componentDefinitions[type].simple,
);
const optionalOpen = ref(false);
const mode = ref('simple');
const activeType = ref('processor');
const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const savedOpen = ref(false);
const mobileSummaryOpen = ref(false);
const transferring = ref(false);
const checking = ref(false);
const selecting = ref(false);
const pickerProducts = ref([]);
const optionsLoading = ref(false);
const optionsError = ref('');
const optionsPage = ref(1);
const optionsLastPage = ref(1);
let optionsRequest = 0;
let optionsTimer;
const pickerFilters = ref({ search: '', brand: '', sort: 'name', page: 1 });
const replacement = ref(null);
const replacementKeys = ref([]);
const replacementPreview = ref(null);
const replacementLoading = ref(false);
const replacementError = ref('');
let replacementRequest = 0;
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
const selectionHttp = useHttp({ components: [] });
const optionsHttp = useHttp({
    components: [],
    component_type: 'processor',
    selection_version: 0,
    search: '',
    brand: '',
    sort: 'name',
    page: 1,
    candidate_id: null,
    quantity: null,
    candidate_configuration: null,
    remove_keys: [],
});
const replacementHttp = useHttp({
    components: [],
    component_type: 'processor',
    selection_version: 0,
    candidate_id: null,
    remove_keys: [],
});
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
const componentSections = computed(() => {
    const types = componentTypes.value;
    return [
        ...new Set(types.map((type) => componentDefinitions[type].group)),
    ].map((group) => ({
        label: group,
        types: types.filter(
            (type) => componentDefinitions[type].group === group,
        ),
    }));
});
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
        optionsHttp.cancel();
        optionsRequest += 1;
        pickerProducts.value = [];
        if (props.strict_selection_enabled && pickerOpen.value) loadOptions();
        replacementRequest += 1;
        replacementHttp.cancel();
        replacement.value = null;
        clearTimeout(validationTimer);
        validationTimer = setTimeout(runValidation, 250);
    },
    { deep: true, flush: 'sync' },
);

onBeforeUnmount(() => {
    selectionVersion += 1;
    clearTimeout(validationTimer);
    http.cancel();
    optionsHttp.cancel();
    selectionHttp.cancel();
    replacementHttp.cancel();
    optionsRequest += 1;
    replacementRequest += 1;
    clearTimeout(optionsTimer);
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
    if (
        transferring.value ||
        selecting.value ||
        Number(product.stock_quantity) < 1
    )
        return;
    if (props.strict_selection_enabled) {
        if (!product.selectable || optionsLoading.value) return;
        return commitSelection(product.proposed_components);
    }
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
    if (props.strict_selection_enabled) {
        return commitSelection(
            requestComponents().map((part) =>
                part.product_id === productId ? { ...part, quantity } : part,
            ),
        );
    }
    item.quantity = Math.min(quantity, Number(product.stock_quantity ?? 99));
}

function removeProduct(productId) {
    if (!transferring.value && !selecting.value)
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
    if (selecting.value || transferring.value) return;
    clearTimeout(optionsTimer);
    activeType.value = type;
    pickerTrigger.value = event?.currentTarget ?? null;
    pickerOpen.value = true;
    validationError.value = '';
    pickerFilters.value = { search: '', brand: '', sort: 'name', page: 1 };
    optionsHttp.candidate_configuration = null;
    if (props.strict_selection_enabled) loadOptions();
}

function queueOptions(filters = {}) {
    pickerFilters.value = { ...pickerFilters.value, ...filters };
    clearTimeout(optionsTimer);
    optionsHttp.cancel();
    optionsRequest += 1;
    optionsLoading.value = true;
    optionsTimer = setTimeout(() => loadOptions(), 200);
}

async function loadOptions(filters = {}) {
    if (!props.strict_selection_enabled || !pickerOpen.value) return;
    pickerFilters.value = { ...pickerFilters.value, ...filters };
    const request = ++optionsRequest;
    const version = selectionVersion;
    const type = activeType.value;
    optionsHttp.cancel();
    optionsLoading.value = true;
    optionsError.value = '';
    pickerProducts.value = [];
    Object.assign(optionsHttp, pickerFilters.value, {
        components: requestComponents(),
        component_type: type,
        selection_version: version,
    });
    try {
        const result = await optionsHttp.post(componentOptions.url());
        if (
            request !== optionsRequest ||
            version !== selectionVersion ||
            type !== activeType.value ||
            !pickerOpen.value
        )
            return;
        if (result.selection_version !== version) return;
        pickerProducts.value = result.products;
        optionsPage.value = result.page;
        optionsLastPage.value = result.last_page;
    } catch {
        if (request === optionsRequest)
            optionsError.value =
                'Compatible parts could not be loaded. Try again.';
    } finally {
        if (request === optionsRequest) optionsLoading.value = false;
    }
}

async function commitSelection(parts) {
    if (selecting.value || transferring.value) return false;
    const version = selectionVersion;
    selecting.value = true;
    validationError.value = '';
    try {
        selectionHttp.components = parts;
        const result = await selectionHttp.post(validateBuild.url());
        if (version !== selectionVersion) return false;
        if (!result.can_purchase || !result.selection_compatible) {
            validationError.value =
                result.selection_blockers?.join(' ') ||
                'These parts cannot be used together. Choose another part.';
            if (pickerOpen.value) loadOptions();
            return false;
        }
        pickerOpen.value = false;
        selections.value = parts.map((part) => ({
            ...part,
            component_type: findProduct(part.product_id)?.component_type,
            configuration: part.configuration ?? {},
        }));
        validation.value = result;
        return true;
    } catch (error) {
        validationError.value =
            error?.response?.data?.message ??
            'The selection could not be verified. Refresh the choices and try again.';
        if (pickerOpen.value) loadOptions();
        return false;
    } finally {
        selecting.value = false;
    }
}

function resolveReplacement(product) {
    if (selecting.value || transferring.value || !product.can_resolve) return;
    pickerOpen.value = false;
    replacement.value = product;
    replacementKeys.value = [];
    replacementPreview.value = null;
    replacementError.value = '';
    previewReplacement();
}

async function previewReplacement() {
    if (!replacement.value) return;
    const request = ++replacementRequest;
    const version = selectionVersion;
    const product = replacement.value;
    replacementHttp.cancel();
    replacementLoading.value = true;
    replacementPreview.value = null;
    replacementError.value = '';
    Object.assign(replacementHttp, {
        components: requestComponents(),
        component_type: product.component_type,
        selection_version: version,
        candidate_id: product.id,
        remove_keys: [...replacementKeys.value],
    });
    try {
        const result = await replacementHttp.post(componentOptions.url());
        if (
            request !== replacementRequest ||
            version !== selectionVersion ||
            replacement.value?.id !== product.id ||
            result.selection_version !== version
        )
            return;
        replacementPreview.value = result.products[0] ?? null;
        if (!replacementPreview.value)
            replacementError.value = 'This replacement is no longer available.';
    } catch {
        if (request === replacementRequest)
            replacementError.value =
                'The replacement could not be verified. Try again.';
    } finally {
        if (request === replacementRequest) replacementLoading.value = false;
    }
}

function toggleReplacementKey(key) {
    replacementKeys.value = replacementKeys.value.includes(key)
        ? replacementKeys.value.filter((value) => value !== key)
        : [...replacementKeys.value, key];
    previewReplacement();
}

function cancelReplacement() {
    if (selecting.value) return;
    replacementRequest += 1;
    replacementHttp.cancel();
    replacement.value = null;
    replacementPreview.value = null;
    replacementKeys.value = [];
}

async function confirmReplacement() {
    if (replacementLoading.value || !replacementPreview.value?.selectable)
        return;
    if (await commitSelection(replacementPreview.value.proposed_components))
        cancelReplacement();
    else if (replacement.value) previewReplacement();
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
        !validation.value?.can_purchase ||
        selections.value.length === 0
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
    if (props.strict_selection_enabled) {
        return commitSelection(
            requestComponents().map((part) =>
                part.key === item.key
                    ? {
                          ...part,
                          configuration: {
                              ...part.configuration,
                              mounting_position: position,
                          },
                      }
                    : part,
            ),
        );
    }
    item.configuration = { ...item.configuration, mounting_position: position };
}
</script>

<template>
    <Head title="Custom PC Builder" />
    <div class="bg-background text-foreground min-h-screen">
        <StorefrontHeader active-section="pc-builder" />
        <main
            class="mx-auto max-w-7xl px-4 py-8 pb-28 sm:px-6 sm:pb-[calc(7rem+var(--mobile-support-height))] lg:px-8 lg:pb-8"
        >
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
                    <div
                        v-for="section in componentSections"
                        :key="section.label"
                    >
                        <h3
                            v-if="mode === 'advanced'"
                            class="text-muted-foreground mt-5 text-xs font-semibold uppercase"
                        >
                            {{ section.label }}
                        </h3>
                        <ComponentRow
                            v-for="type in section.types"
                            :key="type"
                            :label="componentLabels[type]"
                            :required="requiredTypes.includes(type)"
                            :multiple="allowsMultipleComponents(type)"
                            :items="rowItems(type)"
                            :disabled="transferring"
                            :available="
                                (components[type] ?? []).some(
                                    (product) =>
                                        Number(product.stock_quantity) > 0,
                                )
                            "
                            @select="openPicker(type, $event)"
                            @remove="removeProduct"
                        />
                    </div>
                    <div v-if="mode === 'simple'" class="mt-5">
                        <Button
                            variant="outline"
                            :aria-expanded="optionalOpen"
                            aria-controls="optional-builder-parts"
                            @click="optionalOpen = !optionalOpen"
                            >{{
                                optionalOpen
                                    ? 'Hide optional extras'
                                    : 'Add optional extras'
                            }}</Button
                        >
                        <div v-if="optionalOpen" id="optional-builder-parts">
                            <ComponentRow
                                v-for="type in optionalTypes"
                                :key="type"
                                :label="componentLabels[type]"
                                :items="rowItems(type)"
                                :multiple="allowsMultipleComponents(type)"
                                :disabled="transferring"
                                :available="
                                    (components[type] ?? []).some(
                                        (product) =>
                                            Number(product.stock_quantity) > 0,
                                    )
                                "
                                @select="openPicker(type, $event)"
                                @remove="removeProduct"
                            />
                        </div>
                    </div>
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
                                        v-if="
                                            mode === 'advanced' &&
                                            item.component_type === 'fan'
                                        "
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
                                    (checking ||
                                        !validation?.can_purchase ||
                                        selections.length === 0))
                            "
                            @click="addCurrentBuildToCart"
                        >
                            {{
                                transferring
                                    ? 'Saving and adding...'
                                    : !page.props.auth?.user
                                      ? 'Sign in to add to cart'
                                      : validation?.complete
                                        ? 'Add build to cart'
                                        : 'Add selected parts to cart'
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
                :strict="strict_selection_enabled"
                :component-type="activeType"
                :mounting-positions="fanMountingPositions()"
                :loading="optionsLoading"
                :busy="selecting || transferring"
                :error="optionsError || validationError"
                :page="optionsPage"
                :last-page="optionsLastPage"
                :available-brands="
                    [
                        ...new Set(
                            (components[activeType] ?? [])
                                .map((product) => product.brand)
                                .filter(Boolean),
                        ),
                    ].sort()
                "
                :label="componentLabels[activeType]"
                :products="
                    (strict_selection_enabled
                        ? pickerProducts
                        : (components[activeType] ?? [])
                    ).map((product) => ({
                        ...product,
                        summary: specificationSummary(product),
                    }))
                "
                :selected-ids="selections.map((item) => item.product_id)"
                :can-wishlist="Boolean(page.props.auth?.can?.useCustomerCart)"
                :return-focus="pickerTrigger"
                @select="addProduct"
                @remove="removeProduct"
                @resolve="resolveReplacement"
                @filters="queueOptions"
                @retry="loadOptions"
            />
            <ReplacementDialog
                :product="replacement"
                :items="
                    selections
                        .filter(
                            (item) =>
                                item.component_type !==
                                replacement?.component_type,
                        )
                        .map((item) => ({
                            ...item,
                            name:
                                findProduct(item.product_id)?.name ??
                                'Unavailable product',
                        }))
                "
                :selected-keys="replacementKeys"
                :preview="replacementPreview"
                :loading="replacementLoading"
                :busy="selecting"
                :error="replacementError"
                @cancel="cancelReplacement"
                @toggle="toggleReplacementKey"
                @confirm="confirmReplacement"
                @retry="previewReplacement"
            />
            <div
                class="border-border bg-card fixed inset-x-0 bottom-(--mobile-support-height) z-30 flex items-center justify-between gap-3 border-t p-4 lg:hidden"
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
