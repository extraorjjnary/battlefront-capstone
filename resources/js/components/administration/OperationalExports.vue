<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import OperationalExportController from '@/actions/App/Http/Controllers/Administration/OperationalExportController';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
const props = defineProps({
    feature: String,
    filters: Object,
    caseId: [Number, String],
    requestId: [Number, String],
    paymentId: [Number, String],
});
const page = usePage();
const dataset = ref('customer-returns');
const selectedFeature = computed(() =>
    props.feature === 'customer-returns' ? dataset.value : props.feature,
);
const url = (format) =>
    OperationalExportController.url(
        { feature: selectedFeature.value, format },
        {
            query: {
                ...props.filters,
                case: props.caseId ?? undefined,
                request_id: props.requestId ?? undefined,
                payment_id: props.paymentId ?? undefined,
            },
        },
    );
</script>
<template>
    <section
        v-if="page.props.auth.can?.exportReports"
        class="space-y-2"
        aria-label="Print and export records"
    >
        <div class="flex flex-wrap items-center gap-2">
            <label v-if="feature === 'customer-returns'" class="text-sm"
                >Report dataset
                <select
                    v-model="dataset"
                    class="border-input bg-background rounded-md border p-2"
                >
                    <option value="customer-returns">Received returns</option>
                    <option value="customer-requests">Customer requests</option>
                </select>
            </label>
            <Button variant="outline" as-child>
                <a
                    :href="url('print')"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Print</a
                >
            </Button>
            <Button variant="outline" as-child
                ><a :href="url('csv')">Export CSV</a></Button
            >
            <Button variant="outline" as-child
                ><a :href="url('pdf')">Export PDF</a></Button
            >
        </div>
        <p
            v-if="feature === 'stock-requests'"
            class="text-muted-foreground text-sm"
        >
            Selection:
            {{
                {
                    mine: 'My requests',
                    all: 'All requests',
                    to_review: 'To review',
                }[filters?.view]
            }}
        </p>
        <p class="text-muted-foreground text-sm">
            CSV includes all matching records across pages. PDF and print
            support up to 500 records; narrow your filters for larger
            selections.
        </p>
        <InputError :message="page.props.errors?.export" />
        <p
            v-if="feature === 'customer-returns'"
            class="text-muted-foreground text-sm"
        >
            Customer requests await physical receipt. Approved refunds are
            complete only after payment confirmation.
        </p>
    </section>
</template>
