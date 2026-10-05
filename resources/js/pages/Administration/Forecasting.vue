<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { ChartNoAxesCombined, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Line } from 'vue-chartjs';
import ForecastingController from '@/actions/App/Http/Controllers/Administration/ForecastingController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedSearch } from '@/composables/useDebouncedSearch';

ChartJS.register(
    CategoryScale,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

const props = defineProps({
    products: { type: Object, required: true },
    selected_product: { type: Object, default: null },
    current_inventory: { type: Object, default: null },
    filters: { type: Object, required: true },
    readiness: { type: Object, default: null },
    timezone: { type: String, required: true },
    forecasts: { type: Object, required: true },
});

const initialResult = usePage().flash?.forecast_result ?? null;
const result = ref(initialResult);
const isCheckingHistory = ref(false);
const form = useForm({
    product_id: props.selected_product?.id ?? null,
});
const { search, isSearching, cancelPendingSearch } = useDebouncedSearch({
    initialSearch: props.filters.q,
    currentSearch: () => props.filters.q,
    route: ForecastingController.index,
    query: () => ({
        product_id: props.filters.product_id ?? undefined,
    }),
});
const dateFormatter = computed(
    () =>
        new Intl.DateTimeFormat('en-PH', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: props.timezone,
        }),
);

watch(
    () => props.selected_product?.id,
    (id) => {
        form.product_id = id ?? null;
        result.value = null;
    },
);

function selectProduct(product) {
    cancelPendingSearch();
    router.get(
        ForecastingController.index.url(),
        {
            q: props.filters.q || undefined,
            product_id: product?.id ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                isCheckingHistory.value = true;
                result.value = null;
            },
            onFinish: () => {
                isCheckingHistory.value = false;
            },
        },
    );
}

function generate() {
    cancelPendingSearch();
    result.value = null;
    form.post(ForecastingController.store.url(), {
        preserveScroll: true,
        onSuccess: (page) => {
            result.value = page.flash?.forecast_result ?? null;
        },
    });
}

function pageRoute(options, saved = false) {
    return ForecastingController.index({
        query: {
            ...props.filters,
            page: saved ? props.products.current_page : options.query.page,
            forecast_page: saved
                ? options.query.page
                : props.forecasts.current_page,
        },
    });
}

function generatedTime(value) {
    return dateFormatter.value.format(new Date(value));
}

const chartData = computed(() => {
    const observations = result.value?.observations ?? [];
    const forecasts = result.value?.monthly_forecasts ?? [];
    return {
        labels: [
            ...observations.map((item) => item.label),
            ...forecasts.map((item) => item.label),
        ],
        datasets: [
            {
                label: 'Historical units sold',
                data: [
                    ...observations.map((item) => item.quantity_sold),
                    ...forecasts.map(() => null),
                ],
                borderColor: '#9CA3AF',
                backgroundColor: '#9CA3AF',
                pointRadius: 4,
                tension: 0,
            },
            {
                label: 'Forecast',
                data: [
                    ...observations.map((item, index) =>
                        index === observations.length - 1
                            ? item.quantity_sold
                            : null,
                    ),
                    ...forecasts.map((item) => Number(item.forecast_quantity)),
                ],
                borderColor: '#EF1B1B',
                backgroundColor: '#EF1B1B',
                borderDash: [6, 4],
                pointStyle: 'rectRot',
                pointRadius: [
                    ...observations.map(() => 0),
                    ...forecasts.map(() => 7),
                ],
                tension: 0,
            },
        ],
    };
});
const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
        legend: { labels: { color: '#9CA3AF', usePointStyle: true } },
        tooltip: {
            filter: (item) =>
                item.datasetIndex === 0 ||
                item.dataIndex >= item.chart.data.labels.length - 3,
        },
    },
    scales: {
        x: {
            ticks: { color: '#9CA3AF', autoSkip: true, maxTicksLimit: 12 },
            grid: { color: 'rgba(42, 46, 54, 0.55)' },
        },
        y: {
            beginAtZero: true,
            title: { display: true, text: 'Units', color: '#9CA3AF' },
            ticks: { color: '#9CA3AF' },
            grid: { color: 'rgba(42, 46, 54, 0.55)' },
        },
    },
};
</script>

<template>
    <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
        <Head title="Forecasting" />
        <header>
            <div class="flex items-center gap-3">
                <ChartNoAxesCombined class="text-primary size-6" />
                <h1 class="text-2xl font-semibold tracking-tight">
                    Forecasting
                </h1>
            </div>
            <p class="text-muted-foreground mt-2 text-sm">
                Estimate quarterly product demand from completed monthly sales
                to support stock planning.
            </p>
        </header>

        <div class="grid gap-6 xl:grid-cols-2">
            <section
                class="border-border bg-card rounded-xl border p-5"
                aria-labelledby="product-heading"
            >
                <h2 id="product-heading" class="text-lg font-semibold">
                    1. Select a product
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Inactive products remain available for historical
                    forecasting.
                </p>
                <Label for="product-search" class="mt-4 mb-2 block"
                    >Product name or code</Label
                >
                <div class="relative">
                    <Search
                        class="text-muted-foreground absolute top-3 left-3 size-4"
                    />
                    <Input
                        id="product-search"
                        v-model="search"
                        :disabled="form.processing || isCheckingHistory"
                        class="pl-9"
                        placeholder="Search products"
                    />
                </div>
                <p
                    v-if="isSearching"
                    class="text-muted-foreground mt-2 text-sm"
                    role="status"
                >
                    Searching…
                </p>
                <ul
                    class="divide-border mt-4 max-h-80 divide-y overflow-y-auto"
                >
                    <li v-for="product in products.data" :key="product.id">
                        <button
                            type="button"
                            class="hover:bg-muted focus-visible:ring-ring flex w-full items-center justify-between gap-3 rounded-md p-3 text-left focus-visible:ring-2 focus-visible:outline-none"
                            :class="{
                                'bg-muted': form.product_id === product.id,
                            }"
                            :aria-pressed="form.product_id === product.id"
                            :disabled="form.processing || isCheckingHistory"
                            @click="selectProduct(product)"
                        >
                            <span>
                                <span class="block font-medium">{{
                                    product.name
                                }}</span>
                                <span class="text-muted-foreground text-xs"
                                    >{{ product.product_code }} ·
                                    {{ product.category }}</span
                                >
                                <span
                                    v-if="product.is_synthetic"
                                    class="text-muted-foreground block text-xs"
                                    >Synthetic development history</span
                                >
                            </span>
                            <Badge v-if="!product.is_active" variant="secondary"
                                >Inactive</Badge
                            >
                        </button>
                    </li>
                </ul>
                <p
                    v-if="products.data.length === 0"
                    class="text-muted-foreground py-6 text-sm"
                >
                    {{
                        filters.q
                            ? 'No products match this search.'
                            : 'No products are available.'
                    }}
                </p>
                <CatalogPagination
                    :current-page="products.current_page"
                    :last-page="products.last_page"
                    :route="pageRoute"
                    label="Product selection pages"
                />
            </section>

            <section
                class="border-border bg-card rounded-xl border p-5"
                aria-labelledby="generate-heading"
            >
                <h2 id="generate-heading" class="text-lg font-semibold">
                    2. Generate a forecast
                </h2>
                <p v-if="selected_product" class="mt-3 font-medium">
                    {{ selected_product.name }}
                    <span class="text-muted-foreground text-sm"
                        >· {{ selected_product.product_code }}</span
                    >
                </p>
                <p v-else class="text-muted-foreground mt-3 text-sm">
                    Select a product to begin.
                </p>
                <p
                    v-if="selected_product?.is_synthetic"
                    class="text-muted-foreground mt-2 text-sm"
                >
                    Synthetic development history; not actual Battlefront client
                    sales.
                </p>
                <div
                    v-if="isCheckingHistory"
                    class="text-muted-foreground mt-5 flex items-center gap-2 text-sm"
                    role="status"
                >
                    <Spinner /> Checking sales history…
                </div>
                <div
                    v-else-if="readiness"
                    class="mt-5 space-y-3"
                    aria-live="polite"
                >
                    <Badge
                        :variant="
                            readiness.status === 'ready'
                                ? 'secondary'
                                : 'outline'
                        "
                    >
                        {{
                            readiness.status === 'ready'
                                ? 'Ready'
                                : readiness.status === 'insufficient_history'
                                  ? 'Insufficient history'
                                  : readiness.status === 'history_unsuitable'
                                    ? 'History unsuitable'
                                    : 'History unavailable'
                        }}
                    </Badge>
                    <p class="text-sm">{{ readiness.message }}</p>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">
                                Required source months
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ readiness.source_label }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Target quarter
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ readiness.target_label }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="readiness.sales_scope_label"
                        class="text-muted-foreground text-xs"
                    >
                        Sales basis: {{ readiness.sales_scope_label }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Additive Holt–Winters uses 36 completed months to
                        estimate the full target quarter; current-quarter sales
                        are excluded. Dates use {{ readiness.timezone }}.
                    </p>
                </div>
                <div
                    v-if="selected_product"
                    class="text-muted-foreground mt-4 text-xs"
                >
                    Current inventory — planning context:
                    <template v-if="current_inventory">
                        {{ current_inventory.quantity }} units · Updated
                        {{ generatedTime(current_inventory.last_updated) }}
                    </template>
                    <template v-else>Unavailable</template>. Inventory does not
                    affect this calculation.
                </div>
                <form class="mt-5 space-y-3" @submit.prevent="generate">
                    <InputError :message="form.errors.product_id" />
                    <InputError :message="form.errors.forecast" />
                    <Button
                        type="submit"
                        :disabled="
                            !form.product_id ||
                            readiness?.status !== 'ready' ||
                            isCheckingHistory ||
                            isSearching ||
                            form.processing
                        "
                    >
                        <Spinner v-if="form.processing" />
                        {{
                            form.processing
                                ? 'Generating…'
                                : 'Generate forecast'
                        }}
                    </Button>
                </form>
                <p class="text-muted-foreground mt-4 text-xs">
                    Generating again replaces the saved result for the same
                    product, method, and target quarter.
                </p>
            </section>
        </div>

        <section
            v-if="result"
            class="border-border bg-card rounded-xl border p-5"
            aria-labelledby="result-heading"
            aria-live="polite"
        >
            <h2 id="result-heading" class="text-lg font-semibold">
                {{
                    result.status === 'ready'
                        ? 'Generated forecast'
                        : 'Forecast unavailable'
                }}
            </h2>
            <p class="mt-2 text-sm">{{ result.message }}</p>
            <template v-if="result.status === 'ready'">
                <p v-if="result.is_synthetic" class="mt-2 text-sm font-medium">
                    Synthetic development history
                </p>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground text-xs">Product</dt>
                        <dd class="mt-1 font-medium">
                            {{ result.product.name }} ·
                            {{ result.product.product_code }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Method</dt>
                        <dd class="mt-1 font-medium">Additive Holt–Winters</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Estimated quarterly demand
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ result.forecast_quantity }}
                            <span class="text-sm font-normal">units</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Target quarter
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ result.target_label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Source period
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ result.source_label }} ·
                            {{ result.observations.length }} months
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Generated</dt>
                        <dd class="mt-1 font-medium">
                            {{ generatedTime(result.generated_at) }} ({{
                                result.timezone
                            }})
                        </dd>
                    </div>
                </dl>
                <p class="text-muted-foreground mt-5 text-sm">
                    {{ result.guidance }}
                </p>
                <p class="text-muted-foreground mt-2 text-sm">
                    Sales basis: {{ result.sales_scope_label }}
                </p>
                <dl
                    class="mt-5 grid gap-4 sm:grid-cols-3"
                    aria-label="Monthly demand estimates"
                >
                    <div
                        v-for="month in result.monthly_forecasts"
                        :key="month.start"
                    >
                        <dt class="text-muted-foreground text-sm">
                            {{ month.label }}
                        </dt>
                        <dd class="mt-1 font-semibold tabular-nums">
                            {{ month.forecast_quantity }} units
                        </dd>
                    </div>
                </dl>
                <p class="text-muted-foreground mt-3 text-xs">
                    {{ result.rounding_note }}
                </p>
                <div class="mt-6 h-80 min-w-0">
                    <Line
                        :data="chartData"
                        :options="chartOptions"
                        role="img"
                        aria-label="Completed monthly sales and three target-quarter monthly demand estimates. Historical values are available in the table below."
                    />
                </div>
                <details class="mt-4">
                    <summary
                        class="focus-visible:ring-ring cursor-pointer text-sm font-medium focus-visible:ring-2"
                    >
                        View monthly history
                    </summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                Completed monthly sales used for this generation
                            </caption>
                            <thead>
                                <tr class="border-border border-b">
                                    <th scope="col" class="p-2">Month</th>
                                    <th scope="col" class="p-2 text-right">
                                        Units
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="observation in result.observations"
                                    :key="observation.start"
                                    class="border-border border-b"
                                >
                                    <th scope="row" class="p-2 font-normal">
                                        {{ observation.label }}
                                    </th>
                                    <td class="p-2 text-right tabular-nums">
                                        {{ observation.quantity_sold }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
                <p class="text-muted-foreground mt-4 text-xs">
                    Monthly estimates and history are generation-time context.
                    They are not retained with saved forecasts after this view
                    is refreshed or lost.
                </p>
            </template>
        </section>

        <section
            class="border-border bg-card rounded-xl border p-5"
            aria-labelledby="saved-heading"
        >
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 id="saved-heading" class="text-lg font-semibold">
                        Saved forecasts
                    </h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{
                            selected_product
                                ? 'Results for the selected product.'
                                : 'Results for all products.'
                        }}
                        Estimates support planning; actual demand may differ.
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <Button
                        v-if="selected_product"
                        variant="outline"
                        :disabled="form.processing"
                        @click="selectProduct(null)"
                        >Show all products</Button
                    >
                </div>
            </div>
            <p class="text-muted-foreground mt-3 text-xs">
                Saved quarterly totals are read as stored. Original monthly
                estimates, observations, and model parameters are not retained.
                Holt–Winters and moving-average source dates are inferred from
                their fixed windows; legacy linear-trend source dates are
                unavailable.
            </p>
            <div v-if="forecasts.data.length" class="mt-5 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Persisted product demand forecasts
                    </caption>
                    <thead>
                        <tr
                            class="border-border text-muted-foreground border-b"
                        >
                            <th scope="col" class="p-3">Product</th>
                            <th scope="col" class="p-3">Method</th>
                            <th scope="col" class="p-3 text-right">
                                Forecast units
                            </th>
                            <th scope="col" class="p-3">Target</th>
                            <th scope="col" class="p-3">Source period</th>
                            <th scope="col" class="p-3">
                                Generated ({{ timezone }})
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="forecast in forecasts.data"
                            :key="forecast.id"
                            class="border-border border-b"
                        >
                            <th scope="row" class="p-3 font-medium">
                                {{ forecast.product.name
                                }}<span
                                    class="text-muted-foreground block text-xs"
                                    >{{ forecast.product.product_code }}</span
                                ><span
                                    v-if="forecast.product.is_synthetic"
                                    class="text-muted-foreground block text-xs"
                                    >Synthetic development history</span
                                >
                            </th>
                            <td class="p-3">
                                {{ forecast.method_label }}
                            </td>
                            <td
                                class="p-3 text-right font-semibold tabular-nums"
                            >
                                {{ forecast.forecast_quantity }}
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                {{ forecast.target_label }}
                            </td>
                            <td class="p-3">{{ forecast.source_label }}</td>
                            <td class="p-3 whitespace-nowrap">
                                {{ generatedTime(forecast.generated_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-muted-foreground py-8 text-sm">
                No saved forecasts{{
                    selected_product ? ' match this selection' : ' yet'
                }}.
            </p>
            <CatalogPagination
                :current-page="forecasts.current_page"
                :last-page="forecasts.last_page"
                :route="(options) => pageRoute(options, true)"
                label="Saved forecast pages"
            />
        </section>
    </div>
</template>
