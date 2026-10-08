<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';
import {
    CalendarRange,
    ChartNoAxesCombined,
    CircleDollarSign,
    ReceiptText,
    ShoppingBasket,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';
import SalesReportController from '@/actions/App/Http/Controllers/Administration/SalesReportController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency } from '@/lib/currency';

ChartJS.register(
    ArcElement,
    BarElement,
    CategoryScale,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Title,
    Tooltip,
);

const props = defineProps({
    filters: { type: Object, required: true },
    kpis: { type: Object, required: true },
    timeline: { type: Object, required: true },
    top_products: { type: Object, required: true },
    categories: { type: Object, required: true },
    products: { type: Object, required: true },
    active_filters: { type: Object, required: true },
    recommendation_engagement: { type: Object, required: true },
});

const page = usePage();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const period = ref(props.filters.period);
const selectedProduct = ref(props.active_filters.product);
const selectedCategory = ref(props.active_filters.category);
const isCrossFiltering = ref(false);
const numberFormatter = new Intl.NumberFormat('en-PH');
const dateFormatter = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' });
const presets = [7, 30, 90];
const hasSales = computed(() => props.kpis.total_sales > 0);
const hasCrossFilter = computed(
    () => selectedProduct.value !== null || selectedCategory.value !== null,
);

watch(
    () => props.filters,
    (filters) => {
        from.value = filters.from;
        to.value = filters.to;
        period.value = filters.period;
    },
);

watch(
    () => props.active_filters,
    (filters) => {
        selectedProduct.value = filters.product;
        selectedCategory.value = filters.category;
    },
);

const rangeLabel = computed(
    () =>
        `${dateFormatter.format(parseDate(props.filters.from))} – ${dateFormatter.format(parseDate(props.filters.to))}`,
);

const kpiCards = computed(() => [
    {
        label: 'Total sales',
        value: numberFormatter.format(props.kpis.total_sales),
        detail: 'Recorded sale transactions',
        icon: ReceiptText,
    },
    {
        label: 'Total revenue',
        value: formatCurrency(props.kpis.total_revenue),
        detail: hasCrossFilter.value
            ? 'Purchase-time item revenue in this selection'
            : 'Revenue from sale records',
        icon: CircleDollarSign,
    },
    {
        label: 'Average order value',
        value: formatCurrency(props.kpis.average_order_value),
        detail: 'Average revenue per sale',
        icon: ChartNoAxesCombined,
    },
    {
        label: 'Items sold',
        value: numberFormatter.format(props.kpis.total_items_sold),
        detail: 'Units across completed orders',
        icon: ShoppingBasket,
    },
]);

const timelineData = computed(() => ({
    labels: props.timeline.labels,
    datasets: [
        {
            label: 'Revenue',
            data: props.timeline.revenue,
            borderColor: '#EF1B1B',
            backgroundColor: 'rgba(239, 27, 27, 0.12)',
            pointBackgroundColor: '#EF1B1B',
            fill: true,
            tension: 0.25,
            yAxisID: 'revenue',
        },
        {
            label: 'Sales',
            data: props.timeline.sales,
            borderColor: '#9CA3AF',
            backgroundColor: '#9CA3AF',
            pointBackgroundColor: '#9CA3AF',
            tension: 0.25,
            yAxisID: 'sales',
        },
    ],
}));

const topProductsData = computed(() => ({
    labels: props.top_products.labels,
    datasets: [
        {
            label: 'Units sold',
            data: props.top_products.quantities,
            backgroundColor: props.top_products.ids.map((id) => {
                if (selectedProduct.value === null) {
                    return '#B91C1C';
                }

                return selectedProduct.value.id === id
                    ? '#EF1B1B'
                    : 'rgba(156, 163, 175, 0.32)';
            }),
            borderColor: props.top_products.ids.map((id) =>
                selectedProduct.value?.id === id ? '#F8FAFC' : '#2A2E36',
            ),
            borderWidth: props.top_products.ids.map((id) =>
                selectedProduct.value?.id === id ? 3 : 1,
            ),
            hoverBackgroundColor: '#EF1B1B',
        },
    ],
}));

const categoryData = computed(() => ({
    labels: props.categories.labels,
    datasets: [
        {
            label: 'Item revenue',
            data: props.categories.revenue,
            backgroundColor: props.categories.ids.map((id) => {
                if (selectedCategory.value === null) {
                    return '#B91C1C';
                }

                return selectedCategory.value.id === id
                    ? '#EF1B1B'
                    : 'rgba(156, 163, 175, 0.32)';
            }),
            borderColor: props.categories.ids.map((id) =>
                selectedCategory.value?.id === id ? '#F8FAFC' : '#111318',
            ),
            borderWidth: props.categories.ids.map((id) =>
                selectedCategory.value?.id === id ? 3 : 2,
            ),
            hoverBackgroundColor: '#EF1B1B',
        },
    ],
}));

const baseChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            labels: { color: '#9CA3AF', usePointStyle: true },
        },
    },
    scales: {
        x: {
            ticks: { color: '#9CA3AF' },
            grid: { color: 'rgba(42, 46, 54, 0.55)' },
        },
        y: {
            beginAtZero: true,
            ticks: { color: '#9CA3AF', precision: 0 },
            grid: { color: 'rgba(42, 46, 54, 0.55)' },
        },
    },
};

const timelineOptions = {
    ...baseChartOptions,
    interaction: { intersect: false, mode: 'index' },
    plugins: {
        ...baseChartOptions.plugins,
        tooltip: {
            callbacks: {
                label(context) {
                    return context.dataset.yAxisID === 'revenue'
                        ? `Revenue: ${formatCurrency(context.raw)}`
                        : `Sales: ${numberFormatter.format(context.raw)}`;
                },
            },
        },
    },
    scales: {
        x: baseChartOptions.scales.x,
        revenue: {
            beginAtZero: true,
            position: 'left',
            ticks: {
                color: '#9CA3AF',
                callback: (value) => formatCurrency(value),
            },
            grid: { color: 'rgba(42, 46, 54, 0.55)' },
        },
        sales: {
            beginAtZero: true,
            position: 'right',
            ticks: { color: '#9CA3AF', precision: 0 },
            grid: { drawOnChartArea: false },
        },
    },
};

const topProductsOptions = computed(() => ({
    ...baseChartOptions,
    onClick(_event, elements) {
        if (elements.length === 0 || isCrossFiltering.value) {
            return;
        }

        const index = elements[0].index;
        toggleProduct({
            id: props.top_products.ids[index],
            name: props.top_products.labels[index],
        });
    },
    onHover(event, elements) {
        if (event.native?.target) {
            event.native.target.style.cursor =
                elements.length > 0 ? 'pointer' : 'default';
        }
    },
}));

const categoryOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    onClick(_event, elements) {
        if (elements.length === 0 || isCrossFiltering.value) {
            return;
        }

        const index = elements[0].index;
        toggleCategory({
            id: props.categories.ids[index],
            name: props.categories.labels[index],
        });
    },
    onHover(event, elements) {
        if (event.native?.target) {
            event.native.target.style.cursor =
                elements.length > 0 ? 'pointer' : 'default';
        }
    },
    plugins: {
        legend: {
            position: 'bottom',
            labels: { color: '#9CA3AF', usePointStyle: true },
        },
        tooltip: {
            callbacks: {
                label: (context) =>
                    `${context.label}: ${formatCurrency(context.raw)}`,
            },
        },
    },
}));

function parseDate(value) {
    return new Date(`${value}T00:00:00`);
}

function formatDateInput(date) {
    const localDate = new Date(
        date.getTime() - date.getTimezoneOffset() * 60_000,
    );

    return localDate.toISOString().slice(0, 10);
}

function submitFilters() {
    router.visit(
        SalesReportController.index({
            query: {
                from: from.value,
                to: to.value,
                period: period.value,
                product_id: selectedProduct.value?.id,
                category_id: selectedCategory.value?.id,
            },
        }),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function toggleProduct(product) {
    const nextProduct =
        selectedProduct.value?.id === product.id ? null : product;

    updateCrossFilters(nextProduct, selectedCategory.value);
}

function toggleCategory(category) {
    const nextCategory =
        selectedCategory.value?.id === category.id ? null : category;

    updateCrossFilters(selectedProduct.value, nextCategory);
}

function clearCrossFilters() {
    updateCrossFilters(null, null);
}

function updateCrossFilters(product, category) {
    const previousProduct = selectedProduct.value;
    const previousCategory = selectedCategory.value;
    selectedProduct.value = product;
    selectedCategory.value = category;

    router.visit(
        SalesReportController.index({
            query: {
                from: props.filters.from,
                to: props.filters.to,
                period: props.filters.period,
                product_id: product?.id,
                category_id: category?.id,
            },
        }),
        {
            only: [
                'filters',
                'active_filters',
                'kpis',
                'timeline',
                'top_products',
                'categories',
                'products',
            ],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                isCrossFiltering.value = true;
            },
            onError: () => {
                selectedProduct.value = previousProduct;
                selectedCategory.value = previousCategory;
            },
            onFinish: () => {
                isCrossFiltering.value = false;
            },
        },
    );
}

function applyPreset(days) {
    const end = new Date();
    const start = new Date(end);
    start.setDate(start.getDate() - (days - 1));
    from.value = formatDateInput(start);
    to.value = formatDateInput(end);
    submitFilters();
}

function productPageRoute(options) {
    return SalesReportController.index({
        query: {
            from: props.filters.from,
            to: props.filters.to,
            period: props.filters.period,
            product_id: selectedProduct.value?.id,
            category_id: selectedCategory.value?.id,
            page: options.query.page,
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Sales reports',
                href: SalesReportController.index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Sales reports" />

    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6 sm:p-8"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
            >
                <div class="max-w-2xl">
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Administration · Sagay operations
                    </p>
                    <h1
                        class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        Sales dashboard
                    </h1>
                    <p class="text-muted-foreground mt-3 leading-7">
                        Monitor sales, product performance, and recommendation
                        engagement for {{ rangeLabel }}.
                    </p>
                </div>
                <div
                    class="text-muted-foreground flex items-center gap-2 text-sm"
                >
                    <CalendarRange class="text-primary size-4" />
                    Inclusive date range
                </div>
            </div>
        </section>

        <section
            class="border-border bg-card border p-5"
            aria-labelledby="sales-filter-heading"
        >
            <div class="flex items-start gap-3">
                <span
                    class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                >
                    <CalendarRange class="size-4" />
                </span>
                <div>
                    <h2 id="sales-filter-heading" class="font-semibold">
                        Reporting period
                    </h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Choose inclusive dates and how the timeline groups
                        recorded sales.
                    </p>
                </div>
            </div>

            <form
                class="mt-5 grid gap-4 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end"
                @submit.prevent="submitFilters"
            >
                <div class="grid gap-2">
                    <Label for="sales-report-from">Start date</Label>
                    <Input id="sales-report-from" v-model="from" type="date" />
                    <p
                        v-if="page.props.errors.from"
                        class="text-destructive text-xs"
                    >
                        {{ page.props.errors.from }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="sales-report-to">End date</Label>
                    <Input id="sales-report-to" v-model="to" type="date" />
                    <p
                        v-if="page.props.errors.to"
                        class="text-destructive text-xs"
                    >
                        {{ page.props.errors.to }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="sales-report-period">Group timeline by</Label>
                    <Select v-model="period">
                        <SelectTrigger id="sales-report-period" class="w-full">
                            <SelectValue placeholder="Select a period" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="day">Day</SelectItem>
                            <SelectItem value="week">Week</SelectItem>
                            <SelectItem value="month">Month</SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="page.props.errors.period"
                        class="text-destructive text-xs"
                    >
                        {{ page.props.errors.period }}
                    </p>
                </div>
                <Button type="submit" :disabled="isCrossFiltering">
                    Apply range
                </Button>
            </form>

            <div
                class="border-border mt-5 flex flex-wrap items-center gap-2 border-t pt-4"
            >
                <span
                    class="text-muted-foreground mr-1 text-xs font-medium tracking-wide uppercase"
                >
                    Quick ranges
                </span>
                <Button
                    v-for="days in presets"
                    :key="days"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="isCrossFiltering"
                    @click="applyPreset(days)"
                >
                    Last {{ days }} days
                </Button>
            </div>
        </section>

        <section
            v-if="hasCrossFilter"
            class="border-border bg-card flex flex-wrap items-center gap-2 border px-4 py-3"
            aria-label="Active sales report filters"
        >
            <span
                class="text-muted-foreground text-xs font-medium tracking-wide uppercase"
            >
                Filtered by
            </span>
            <Button
                v-if="selectedProduct"
                type="button"
                variant="outline"
                size="sm"
                :disabled="isCrossFiltering"
                @click="toggleProduct(selectedProduct)"
            >
                Product: {{ selectedProduct.name }}
                <X />
            </Button>
            <Button
                v-if="selectedCategory"
                type="button"
                variant="outline"
                size="sm"
                :disabled="isCrossFiltering"
                @click="toggleCategory(selectedCategory)"
            >
                Category: {{ selectedCategory.name }}
                <X />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                :disabled="isCrossFiltering"
                @click="clearCrossFilters"
            >
                Clear filters
            </Button>
            <span
                v-if="isCrossFiltering"
                class="text-muted-foreground text-xs"
                role="status"
                aria-live="polite"
            >
                Updating dashboard…
            </span>
        </section>

        <section aria-labelledby="sales-kpis-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">{{ rangeLabel }}</p>
                <h2 id="sales-kpis-heading" class="text-xl font-semibold">
                    Sales summary
                </h2>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="card in kpiCards"
                    :key="card.label"
                    class="border-border bg-card border p-5"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-muted-foreground text-sm">
                                {{ card.label }}
                            </p>
                            <p class="mt-2 text-2xl font-bold tracking-tight">
                                {{ card.value }}
                            </p>
                        </div>
                        <span
                            class="bg-secondary text-primary flex size-10 shrink-0 items-center justify-center rounded-md"
                        >
                            <component :is="card.icon" class="size-5" />
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-4 text-xs">
                        {{ card.detail }}
                    </p>
                </article>
            </div>
        </section>

        <section
            v-if="!hasSales"
            class="border-border bg-card flex min-h-52 items-center justify-center border p-6 text-center"
        >
            <div class="max-w-md">
                <ChartNoAxesCombined
                    class="text-muted-foreground mx-auto size-9"
                />
                <h2 class="mt-3 font-semibold">
                    {{
                        hasCrossFilter
                            ? 'No recorded sales match these filters'
                            : 'No recorded sales in this range'
                    }}
                </h2>
                <p class="text-muted-foreground mt-2 text-sm leading-6">
                    The summary remains at zero. Clear a chart filter or select
                    another date range to review other recorded sales.
                </p>
            </div>
        </section>

        <template v-else>
            <section
                class="border-border bg-card border p-5"
                aria-labelledby="sales-trend-heading"
            >
                <div>
                    <p class="text-muted-foreground text-sm">
                        Backend aggregates
                    </p>
                    <h2 id="sales-trend-heading" class="text-xl font-semibold">
                        Revenue and sales over time
                    </h2>
                </div>
                <div class="mt-6 h-80">
                    <Line :data="timelineData" :options="timelineOptions" />
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <section
                    class="border-border bg-card border p-5"
                    aria-labelledby="top-products-heading"
                >
                    <p class="text-muted-foreground text-sm">
                        Ranked by units · Select a bar to filter
                    </p>
                    <h2 id="top-products-heading" class="text-xl font-semibold">
                        Top-selling products
                    </h2>
                    <div class="mt-6 h-80">
                        <Bar
                            :data="topProductsData"
                            :options="topProductsOptions"
                            aria-label="Top-selling products. Select a product bar to filter the dashboard."
                            role="img"
                        >
                            Product chart unavailable.
                        </Bar>
                    </div>
                </section>

                <section
                    class="border-border bg-card border p-5"
                    aria-labelledby="category-sales-heading"
                >
                    <p class="text-muted-foreground text-sm">
                        Historical item revenue · Select a slice to filter
                    </p>
                    <h2
                        id="category-sales-heading"
                        class="text-xl font-semibold"
                    >
                        Sales by current category
                    </h2>
                    <div class="mt-6 h-80">
                        <Doughnut
                            :data="categoryData"
                            :options="categoryOptions"
                            aria-label="Sales by current category. Select a category slice to filter the dashboard."
                            role="img"
                        >
                            Category chart unavailable.
                        </Doughnut>
                    </div>
                </section>
            </div>
        </template>

        <section aria-labelledby="product-report-heading">
            <div
                class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p class="text-muted-foreground text-sm">
                        {{ products.total }}
                        {{ products.total === 1 ? 'product' : 'products' }}
                    </p>
                    <h2
                        id="product-report-heading"
                        class="text-xl font-semibold"
                    >
                        Product performance report
                    </h2>
                </div>
                <p class="text-muted-foreground text-xs">
                    Revenue uses purchase-time item prices.
                </p>
            </div>

            <div
                v-if="products.data.length === 0"
                class="border-border bg-card border p-8 text-center"
            >
                <ShoppingBasket class="text-muted-foreground mx-auto size-8" />
                <p class="mt-3 font-medium">No product sales to report</p>
                <p class="text-muted-foreground mt-1 text-sm">
                    Product aggregates will appear when this range contains
                    sales.
                </p>
            </div>

            <div v-else class="border-border bg-card overflow-x-auto border">
                <table class="w-full min-w-3xl text-left text-sm">
                    <thead
                        class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Product</th>
                            <th class="px-5 py-3 font-medium">
                                Current category
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Sales
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Units sold
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Item revenue
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr v-for="product in products.data" :key="product.id">
                            <td class="px-5 py-4 font-medium">
                                <button
                                    type="button"
                                    class="hover:text-primary focus-visible:ring-ring text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                    :aria-pressed="
                                        selectedProduct?.id === product.id
                                    "
                                    :disabled="isCrossFiltering"
                                    @click="toggleProduct(product)"
                                >
                                    {{ product.name }}
                                </button>
                            </td>
                            <td class="text-muted-foreground px-5 py-4">
                                {{ product.category }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(product.sales_count)
                                }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(
                                        product.quantity_sold,
                                    )
                                }}
                            </td>
                            <td class="px-5 py-4 text-right font-medium">
                                {{ formatCurrency(product.item_revenue) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <CatalogPagination
                :current-page="products.current_page"
                :last-page="products.last_page"
                :route="productPageRoute"
                label="Product report pages"
            />
        </section>

        <section aria-labelledby="category-report-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    Current product classification
                </p>
                <h2 id="category-report-heading" class="text-xl font-semibold">
                    Category report
                </h2>
            </div>
            <div
                v-if="categories.rows.length === 0"
                class="border-border bg-card border p-8 text-center"
            >
                <p class="font-medium">No category sales to report</p>
            </div>
            <div v-else class="border-border bg-card overflow-x-auto border">
                <table class="w-full min-w-2xl text-left text-sm">
                    <thead
                        class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Category</th>
                            <th class="px-5 py-3 text-right font-medium">
                                Sales
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Units sold
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Item revenue
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr
                            v-for="category in categories.rows"
                            :key="category.id"
                        >
                            <td class="px-5 py-4 font-medium">
                                <button
                                    type="button"
                                    class="hover:text-primary focus-visible:ring-ring text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                    :aria-pressed="
                                        selectedCategory?.id === category.id
                                    "
                                    :disabled="isCrossFiltering"
                                    @click="toggleCategory(category)"
                                >
                                    {{ category.name }}
                                </button>
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(category.sales_count)
                                }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                {{
                                    numberFormatter.format(
                                        category.quantity_sold,
                                    )
                                }}
                            </td>
                            <td class="px-5 py-4 text-right font-medium">
                                {{ formatCurrency(category.item_revenue) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="recommendation-engagement-heading">
            <div
                class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p
                        class="text-primary text-xs font-semibold tracking-widest uppercase"
                    >
                        Anonymous web events
                    </p>
                    <h2
                        id="recommendation-engagement-heading"
                        class="mt-1 text-xl font-semibold"
                    >
                        Recommendation engagement
                    </h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Events for {{ rangeLabel }}. Views, clicks, hides, and
                        reports count separately; history is retained for up to
                        90 days.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="border-border bg-card border p-5">
                    <p class="text-muted-foreground text-sm">Impressions</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums">
                        {{
                            numberFormatter.format(
                                recommendation_engagement.summary.impressions,
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Cards at least half visible for one second
                    </p>
                </div>
                <div class="border-border bg-card border p-5">
                    <p class="text-muted-foreground text-sm">Product clicks</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums">
                        {{
                            numberFormatter.format(
                                recommendation_engagement.summary.clicks,
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Clicks from a recommendation card
                    </p>
                </div>
                <div class="border-border bg-card border p-5">
                    <p class="text-muted-foreground text-sm">Hidden</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums">
                        {{
                            numberFormatter.format(
                                recommendation_engagement.summary.dismissals,
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Recommendations shoppers chose to hide
                    </p>
                </div>
                <div class="border-border bg-card border p-5">
                    <p class="text-muted-foreground text-sm">
                        Reported as a problem
                    </p>
                    <p class="mt-2 text-3xl font-bold tabular-nums">
                        {{
                            numberFormatter.format(
                                recommendation_engagement.summary.wrong_reports,
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Anonymous product suggestion reports
                    </p>
                </div>
            </div>

            <div
                v-if="
                    Object.values(recommendation_engagement.summary).every(
                        (count) => count === 0,
                    )
                "
                class="border-border bg-card mt-4 border p-8 text-center"
                role="status"
            >
                <p class="font-medium">
                    No recommendation activity in this range
                </p>
                <p class="text-muted-foreground mt-1 text-sm">
                    Anonymous web event counts will appear here as customers
                    view, select, hide, or report recommendations.
                </p>
            </div>

            <div v-else class="mt-4 grid gap-4 xl:grid-cols-2">
                <div class="border-border bg-card overflow-x-auto border">
                    <div class="border-border border-b p-5">
                        <h3 class="font-semibold">By page placement</h3>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Where recommendation cards were shown.
                        </p>
                    </div>
                    <table class="w-full min-w-2xl text-left text-sm">
                        <thead
                            class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Placement</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Impressions
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Clicks
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Hidden
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Reports
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-border divide-y">
                            <tr
                                v-for="placement in recommendation_engagement.placements"
                                :key="placement.placement"
                            >
                                <td class="px-5 py-4 font-medium">
                                    {{ placement.label }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(
                                            placement.impressions,
                                        )
                                    }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(placement.clicks)
                                    }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(
                                            placement.dismissals,
                                        )
                                    }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(
                                            placement.wrong_reports,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="border-border bg-card overflow-x-auto border">
                    <div class="border-border border-b p-5">
                        <h3 class="font-semibold">By recommendation reason</h3>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Which recommendation signals were attached to the
                            cards.
                        </p>
                    </div>
                    <table class="w-full min-w-2xl text-left text-sm">
                        <thead
                            class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Reason</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Impressions
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Clicks
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Hidden
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Reports
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-border divide-y">
                            <tr
                                v-for="reason in recommendation_engagement.reasons"
                                :key="reason.reason_code"
                            >
                                <td class="px-5 py-4 font-medium">
                                    {{ reason.label }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(
                                            reason.impressions,
                                        )
                                    }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{ numberFormatter.format(reason.clicks) }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(
                                            reason.dismissals,
                                        )
                                    }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(
                                            reason.wrong_reports,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="border-border bg-card overflow-x-auto border xl:col-span-2"
                >
                    <div class="border-border border-b p-5">
                        <h3 class="font-semibold">Most clicked products</h3>
                        <p class="text-muted-foreground mt-1 text-xs">
                            Products selected from a recommendation card in this
                            date range.
                        </p>
                    </div>
                    <table class="w-full min-w-lg text-left text-sm">
                        <thead
                            class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Product</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Impressions
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Clicks
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-border divide-y">
                            <tr
                                v-if="
                                    recommendation_engagement
                                        .top_clicked_products.length === 0
                                "
                            >
                                <td
                                    colspan="3"
                                    class="text-muted-foreground px-5 py-4"
                                >
                                    No product clicks in this range.
                                </td>
                            </tr>
                            <tr
                                v-for="product in recommendation_engagement.top_clicked_products"
                                :key="product.product_id"
                            >
                                <td class="px-5 py-4 font-medium">
                                    {{ product.product_name }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{
                                        numberFormatter.format(
                                            product.impressions,
                                        )
                                    }}
                                </td>
                                <td class="px-5 py-4 text-right tabular-nums">
                                    {{ numberFormatter.format(product.clicks) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</template>
