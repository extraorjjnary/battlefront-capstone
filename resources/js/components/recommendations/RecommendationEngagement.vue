<script setup>
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

const props = defineProps({
    engagement: { type: Object, required: true },
    rangeLabel: { type: String, required: true },
});

const detailsOpen = ref(false);
const numberFormatter = new Intl.NumberFormat('en-PH');
const metrics = [
    {
        key: 'impressions',
        label: 'Impressions',
        columnLabel: 'Impressions',
        definition:
            'Client-reported cards at least half visible for one second',
    },
    {
        key: 'clicks',
        label: 'Product clicks',
        columnLabel: 'Clicks',
        definition: 'Clicks from a recommendation card',
    },
    {
        key: 'dismissals',
        label: 'Hidden',
        columnLabel: 'Hidden',
        definition: 'Recommendations shoppers chose to hide',
    },
    {
        key: 'wrong_reports',
        label: 'Reported as a problem',
        columnLabel: 'Reports',
        definition: 'Anonymous product suggestion reports',
    },
];
const hasActivity = computed(() =>
    metrics.some((metric) => props.engagement.summary[metric.key] > 0),
);
</script>

<template>
    <section
        class="border-border min-w-0 space-y-4 border-t pt-6"
        aria-labelledby="recommendation-engagement-heading"
    >
        <div>
            <p
                class="text-primary text-xs font-semibold tracking-widest uppercase"
            >
                Anonymous web and mobile events
            </p>
            <h2
                id="recommendation-engagement-heading"
                class="mt-1 text-xl font-semibold"
            >
                Recommendation engagement
            </h2>
            <p class="text-muted-foreground mt-1 text-sm">
                Events for {{ rangeLabel }}. Views, clicks, hides, and reports
                count separately; history is retained for up to 90 days.
            </p>
        </div>

        <dl
            class="border-border bg-border grid grid-cols-2 gap-px border md:grid-cols-4"
        >
            <div
                v-for="metric in metrics"
                :key="metric.key"
                class="bg-card min-w-0 px-4 py-3"
            >
                <dt class="text-muted-foreground text-sm">
                    {{ metric.label }}
                </dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums">
                    {{ numberFormatter.format(engagement.summary[metric.key]) }}
                </dd>
            </div>
        </dl>

        <p
            v-if="!hasActivity"
            class="text-muted-foreground text-sm"
            role="status"
        >
            No recommendation activity in this range.
        </p>

        <div class="border-border bg-card min-w-0 border">
            <h3
                id="engagement-placements-heading"
                class="border-border border-b px-4 py-3 text-sm font-semibold"
            >
                By page placement
            </h3>
            <div
                class="focus-visible:ring-ring overflow-x-auto focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset"
                role="region"
                aria-labelledby="engagement-placements-heading"
                tabindex="0"
            >
                <table
                    class="w-full min-w-[30rem] text-left text-sm sm:min-w-0"
                    aria-labelledby="engagement-placements-heading"
                >
                    <thead
                        class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                    >
                        <tr>
                            <th
                                scope="col"
                                class="px-3 py-2.5 font-medium sm:px-4"
                            >
                                Placement
                            </th>
                            <th
                                v-for="metric in metrics"
                                :key="metric.key"
                                scope="col"
                                class="px-3 py-2.5 text-right font-medium sm:px-4"
                            >
                                {{ metric.columnLabel }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-border divide-y">
                        <tr v-if="engagement.placements.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-4 py-3"
                            >
                                No placement activity in this range.
                            </td>
                        </tr>
                        <tr
                            v-for="placement in engagement.placements"
                            :key="placement.placement"
                        >
                            <th
                                scope="row"
                                class="px-3 py-2.5 font-medium wrap-anywhere sm:px-4"
                            >
                                {{ placement.label }}
                            </th>
                            <td
                                v-for="metric in metrics"
                                :key="metric.key"
                                class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums sm:px-4"
                            >
                                {{
                                    numberFormatter.format(
                                        placement[metric.key],
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Collapsible
            v-model:open="detailsOpen"
            class="border-border bg-card min-w-0 border"
        >
            <CollapsibleTrigger as-child>
                <button
                    type="button"
                    class="hover:bg-secondary/60 focus-visible:ring-ring flex min-h-11 w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset"
                >
                    {{
                        detailsOpen
                            ? 'Hide engagement details'
                            : 'View engagement details'
                    }}
                    <ChevronDown
                        class="size-4 shrink-0"
                        :class="{ 'rotate-180': detailsOpen }"
                        aria-hidden="true"
                    />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent class="border-border space-y-4 border-t p-4">
                <div>
                    <h3 class="text-sm font-semibold">Metric definitions</h3>
                    <dl class="mt-2 grid gap-3 text-xs sm:grid-cols-2">
                        <div v-for="metric in metrics" :key="metric.key">
                            <dt class="font-medium">{{ metric.label }}</dt>
                            <dd class="text-muted-foreground mt-1">
                                {{ metric.definition }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div>
                    <h3
                        id="engagement-reasons-heading"
                        class="text-sm font-semibold"
                    >
                        By recommendation reason
                    </h3>
                    <p class="text-muted-foreground mt-1 text-xs">
                        The primary displayed reason submitted with each event.
                    </p>
                    <div
                        class="focus-visible:ring-ring mt-2 overflow-x-auto focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset"
                        role="region"
                        aria-labelledby="engagement-reasons-heading"
                        tabindex="0"
                    >
                        <table
                            class="w-full min-w-[30rem] text-left text-sm sm:min-w-0"
                            aria-labelledby="engagement-reasons-heading"
                        >
                            <thead
                                class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                            >
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-3 py-2.5 font-medium sm:px-4"
                                    >
                                        Reason
                                    </th>
                                    <th
                                        v-for="metric in metrics"
                                        :key="metric.key"
                                        scope="col"
                                        class="px-3 py-2.5 text-right font-medium sm:px-4"
                                    >
                                        {{ metric.columnLabel }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-border divide-y">
                                <tr v-if="engagement.reasons.length === 0">
                                    <td
                                        colspan="5"
                                        class="text-muted-foreground px-4 py-3"
                                    >
                                        No recommendation reason activity in
                                        this range.
                                    </td>
                                </tr>
                                <tr
                                    v-for="reason in engagement.reasons"
                                    :key="reason.reason_code"
                                >
                                    <th
                                        scope="row"
                                        class="px-3 py-2.5 font-medium wrap-anywhere sm:px-4"
                                    >
                                        {{ reason.label }}
                                    </th>
                                    <td
                                        v-for="metric in metrics"
                                        :key="metric.key"
                                        class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums sm:px-4"
                                    >
                                        {{
                                            numberFormatter.format(
                                                reason[metric.key],
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h3
                        id="engagement-products-heading"
                        class="text-sm font-semibold"
                    >
                        Most clicked products
                    </h3>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Products selected from a recommendation card in this
                        date range.
                    </p>
                    <div
                        class="focus-visible:ring-ring mt-2 overflow-x-auto focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset"
                        role="region"
                        aria-labelledby="engagement-products-heading"
                        tabindex="0"
                    >
                        <table
                            class="w-full min-w-[22rem] text-left text-sm sm:min-w-0"
                            aria-labelledby="engagement-products-heading"
                        >
                            <thead
                                class="bg-secondary/60 text-muted-foreground text-xs uppercase"
                            >
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-3 py-2.5 font-medium sm:px-4"
                                    >
                                        Product
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2.5 text-right font-medium sm:px-4"
                                    >
                                        Impressions
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2.5 text-right font-medium sm:px-4"
                                    >
                                        Clicks
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-border divide-y">
                                <tr
                                    v-if="
                                        engagement.top_clicked_products
                                            .length === 0
                                    "
                                >
                                    <td
                                        colspan="3"
                                        class="text-muted-foreground px-4 py-3"
                                    >
                                        No product clicks in this range.
                                    </td>
                                </tr>
                                <tr
                                    v-for="product in engagement.top_clicked_products"
                                    :key="product.product_id"
                                >
                                    <th
                                        scope="row"
                                        class="px-3 py-2.5 font-medium wrap-anywhere sm:px-4"
                                    >
                                        {{ product.product_name }}
                                    </th>
                                    <td
                                        class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums sm:px-4"
                                    >
                                        {{
                                            numberFormatter.format(
                                                product.impressions,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums sm:px-4"
                                    >
                                        {{
                                            numberFormatter.format(
                                                product.clicks,
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    </section>
</template>
