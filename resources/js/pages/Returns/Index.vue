<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Undo2 } from '@lucide/vue';
import CatalogPagination from '@/components/CatalogPagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { formatDateTime } from '@/lib/dateTime';
import { index as orderIndex, show as showOrder } from '@/routes/orders';
import { index } from '@/routes/returns';

defineProps({
    returnRequests: { type: Object, required: true },
});

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Returns & Refunds', href: index() }],
    },
});
</script>

<template>
    <div class="contents">
        <Head title="Returns & Refunds" />

        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
        >
            <PageHeader
                title="Returns & Refunds"
                heading-id="returns-heading"
            />

            <p class="text-muted-foreground text-sm leading-6">
                Track your return requests and confirmed refund payments here.
                Open the original order to manage a request or view payment
                details.
            </p>

            <section
                aria-labelledby="return-history-heading"
                class="min-w-0 space-y-4"
            >
                <h2
                    id="return-history-heading"
                    class="text-muted-foreground text-sm font-medium"
                >
                    {{ returnRequests.total }}
                    {{
                        returnRequests.total === 1
                            ? 'return request'
                            : 'return requests'
                    }}
                </h2>

                <div
                    v-if="returnRequests.data.length === 0"
                    class="surface-panel border-border bg-card flex min-h-80 items-center justify-center border border-dashed p-8 text-center"
                >
                    <div class="max-w-md">
                        <span
                            class="border-border bg-secondary text-muted-foreground mx-auto flex size-14 items-center justify-center border"
                        >
                            <Undo2 class="size-6" aria-hidden="true" />
                        </span>
                        <h3 class="mt-5 text-xl font-bold">
                            No return requests to show
                        </h3>
                        <p class="text-muted-foreground mt-2 text-sm leading-6">
                            Return requests appear here after you submit them
                            from an order. Visit your orders to view details and
                            available actions.
                        </p>
                        <Button as-child class="mt-6">
                            <Link :href="orderIndex()">
                                View orders
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>
                </div>

                <article
                    v-for="entry in returnRequests.data"
                    :key="entry.id"
                    class="surface-card border-border bg-card min-w-0 space-y-4 border p-4 sm:p-6"
                >
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0 space-y-2">
                            <p
                                class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                            >
                                {{ entry.order_reference }} · Return request #{{
                                    entry.id
                                }}
                            </p>
                            <h3 class="text-lg font-semibold wrap-anywhere">
                                {{ entry.product.name }}
                            </h3>
                            <p class="text-muted-foreground text-sm">
                                {{ entry.quantity }}
                                {{ entry.quantity === 1 ? 'unit' : 'units' }}
                                · {{ entry.reason_label }}
                            </p>
                            <Badge
                                variant="outline"
                                class="h-auto max-w-full text-left whitespace-normal"
                            >
                                {{ entry.status.label }}
                            </Badge>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            as-child
                            class="shrink-0"
                        >
                            <Link
                                :href="showOrder(entry.order_id)"
                                :aria-label="`View order ${entry.order_reference}`"
                            >
                                View order
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>

                    <p class="text-muted-foreground text-sm">
                        Requested {{ formatDateTime(entry.submitted_at) }}
                    </p>
                    <p
                        v-if="entry.feedback"
                        class="text-sm wrap-anywhere whitespace-pre-wrap"
                    >
                        {{ entry.feedback }}
                    </p>

                    <dl
                        v-if="entry.refund"
                        class="border-border grid gap-4 border-t pt-4 text-sm sm:grid-cols-3"
                    >
                        <div>
                            <dt class="text-muted-foreground">
                                Approved refund
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    formatCurrency(entry.refund.approved_amount)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Confirmed paid
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    formatCurrency(
                                        entry.refund.confirmed_paid_amount,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Remaining refund
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums">
                                {{
                                    formatCurrency(
                                        entry.refund.remaining_amount,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-else
                        class="text-muted-foreground border-border border-t pt-4 text-sm"
                    >
                        No monetary refund approved for this request.
                    </p>
                </article>

                <CatalogPagination
                    :current-page="returnRequests.current_page"
                    :last-page="returnRequests.last_page"
                    :route="index"
                    label="Return request pages"
                />
            </section>
        </main>
    </div>
</template>
