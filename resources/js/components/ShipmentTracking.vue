<script setup>
import { Truck } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { formatCurrency } from '@/lib/currency';
import { orderStatusBadgeClass } from '@/lib/orderStatus';

defineProps({
    shipment: { type: Object, required: true },
    quote: { type: Object, default: null },
});

const timestampFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const civilDateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeZone: 'UTC',
});

function formatTimestamp(value) {
    return timestampFormatter.format(new Date(value));
}

function formatCivilDate(value) {
    return civilDateFormatter.format(new Date(`${value}T00:00:00Z`));
}
</script>

<template>
    <section
        class="border-border bg-card border p-5 sm:p-6"
        aria-label="Shipment tracking"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <Truck
                    class="text-primary mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <div>
                    <p class="text-muted-foreground text-sm">
                        {{ shipment.carrier.toUpperCase() }} delivery
                    </p>
                    <h2 class="font-semibold">Shipment progress</h2>
                </div>
            </div>
            <Badge
                variant="outline"
                :class="
                    orderStatusBadgeClass(
                        shipment.status.value === 'delivered'
                            ? 'completed'
                            : shipment.status.value,
                    )
                "
            >
                {{ shipment.status.label }}
            </Badge>
        </div>

        <p class="text-muted-foreground mt-3 text-xs leading-5">
            {{ shipment.notice }}
        </p>
        <p
            v-if="shipment.history_notice"
            class="text-muted-foreground mt-2 text-sm"
        >
            {{ shipment.history_notice }}
        </p>

        <div class="mt-5 grid gap-6 md:grid-cols-2">
            <dl class="grid content-start gap-4 text-sm">
                <div v-if="quote">
                    <dt class="text-muted-foreground">Delivery destination</dt>
                    <dd class="mt-1 font-medium">{{ quote.destination }}</dd>
                </div>
                <div v-if="quote">
                    <dt class="text-muted-foreground">Delivery fee</dt>
                    <dd class="mt-1 font-medium tabular-nums">
                        {{ formatCurrency(quote.delivery_fee) }}
                    </dd>
                    <dd class="text-muted-foreground mt-1 text-xs">
                        Base {{ formatCurrency(quote.base_fee) }} + handling
                        {{ formatCurrency(quote.handling_surcharge) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        {{
                            shipment.status.value === 'cancelled'
                                ? 'Original delivery estimate'
                                : 'Estimated delivery'
                        }}
                    </dt>
                    <template v-if="shipment.eta.estimated_delivery_start">
                        <dd class="mt-1 font-medium">
                            {{
                                formatCivilDate(
                                    shipment.eta.estimated_delivery_start,
                                )
                            }}
                            <template
                                v-if="
                                    shipment.eta.estimated_delivery_end !==
                                    shipment.eta.estimated_delivery_start
                                "
                            >
                                –
                                {{
                                    formatCivilDate(
                                        shipment.eta.estimated_delivery_end,
                                    )
                                }}
                            </template>
                        </dd>
                        <dd
                            class="text-muted-foreground mt-1 text-xs leading-5"
                        >
                            {{ shipment.eta.notice }} Dates use
                            {{ shipment.eta.timezone }}.
                        </dd>
                    </template>
                    <template v-else>
                        <dd v-if="quote" class="mt-1 font-medium">
                            {{ quote.eta_min_days
                            }}<template
                                v-if="quote.eta_max_days !== quote.eta_min_days"
                                >–{{ quote.eta_max_days }}</template
                            >
                            calendar days from the start of preparation.
                        </dd>
                        <dd class="text-muted-foreground mt-1 text-xs">
                            Calendar dates are not recorded until preparation
                            starts.
                        </dd>
                    </template>
                </div>
                <div v-if="shipment.tracking_reference">
                    <dt class="text-muted-foreground">
                        LBC tracking / reference
                    </dt>
                    <dd class="mt-1 font-medium break-all">
                        {{ shipment.tracking_reference }}
                    </dd>
                </div>
                <div v-if="quote">
                    <dt class="text-muted-foreground">Packing</dt>
                    <dd class="mt-1">{{ quote.packing_expectation }}</dd>
                    <dd
                        v-if="quote.is_demo"
                        class="text-muted-foreground mt-2 text-xs leading-5"
                    >
                        {{ quote.assumption_label }}
                    </dd>
                </div>
            </dl>

            <div>
                <h3 class="text-sm font-semibold">Recorded milestones</h3>
                <ol class="border-border mt-3 grid gap-4 border-l pl-4">
                    <li
                        v-for="milestone in shipment.timeline"
                        :key="milestone.status"
                        class="grid gap-1 text-sm"
                    >
                        <p class="font-medium">{{ milestone.label }}</p>
                        <time
                            :datetime="milestone.occurred_at"
                            class="text-muted-foreground text-xs"
                        >
                            {{ formatTimestamp(milestone.occurred_at) }}
                        </time>
                    </li>
                </ol>
                <p
                    v-if="!shipment.timeline.length"
                    class="text-muted-foreground mt-3 text-sm"
                >
                    No milestone timestamps are recorded.
                </p>
            </div>
        </div>
    </section>
</template>
