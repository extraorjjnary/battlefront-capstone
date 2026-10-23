<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Boxes,
    ChartColumnIncreasing,
    ListTodo,
    ReceiptText,
} from '@lucide/vue';
import { computed } from 'vue';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import StaffPerformanceController from '@/actions/App/Http/Controllers/Administration/StaffPerformanceController';
import WorkQueueController from '@/actions/App/Http/Controllers/Administration/WorkQueueController';
import DeferredContent from '@/components/DeferredContent.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatSkeleton from '@/components/StatSkeleton.vue';
import { dashboard as dashboardRoute } from '@/routes';

defineProps({ dashboard: { type: Object } });

const page = usePage();
const permissions = computed(() => page.props.auth.can ?? {});
const metrics = computed(() => [
    ...(permissions.value.viewOrders
        ? [
              {
                  key: 'pending_orders',
                  title: 'Pending orders',
                  description: 'Orders awaiting review.',
              },
              {
                  key: 'pending_payment_reviews',
                  title: 'Payment checks',
                  description: 'Active orders awaiting a payment decision.',
              },
          ]
        : []),
    ...(permissions.value.viewInventory
        ? [
              {
                  key: 'low_stock_products',
                  title: 'Low-stock products',
                  description: 'At or below the reorder level.',
              },
              {
                  key: 'out_of_stock_products',
                  title: 'Out-of-stock products',
                  description: 'No units available.',
              },
          ]
        : []),
]);
const quickLinks = computed(() => [
    { title: 'Tasks', href: WorkQueueController.index(), icon: ListTodo },
    ...(permissions.value.viewOrders
        ? [
              {
                  title: 'Orders',
                  href: OrderController.index(),
                  icon: ReceiptText,
              },
          ]
        : []),
    ...(permissions.value.viewInventory
        ? [
              {
                  title: 'Inventory',
                  href: InventoryController.index(),
                  icon: Boxes,
              },
          ]
        : []),
    ...(permissions.value.viewOrders
        ? [
              {
                  title: 'My sales',
                  href: StaffPerformanceController.index(),
                  icon: ChartColumnIncreasing,
              },
          ]
        : []),
]);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboardRoute() }],
    },
});
</script>

<template>
    <div class="contents">
        <Head title="Dashboard" />
        <main
            class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
        >
            <PageHeader
                title="Staff dashboard"
                heading-id="staff-dashboard-heading"
                description="Review orders, payment checks, and stock alerts."
            />

            <section
                v-if="metrics.length"
                aria-labelledby="staff-summary-heading"
            >
                <h2
                    id="staff-summary-heading"
                    class="mb-4 text-xl font-semibold"
                >
                    Operational overview
                </h2>
                <DeferredContent
                    data="dashboard"
                    label="Loading operational overview..."
                    error-message="The operational overview could not be loaded."
                >
                    <template #fallback>
                        <div
                            aria-hidden="true"
                            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                        >
                            <StatSkeleton
                                v-for="metric in metrics"
                                :key="metric.key"
                                class="border-border bg-card border"
                            />
                        </div>
                    </template>
                    <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div
                            v-for="metric in metrics"
                            :key="metric.key"
                            class="surface-panel border-border bg-card border p-5"
                        >
                            <dt class="text-muted-foreground text-sm">
                                {{ metric.title }}
                            </dt>
                            <dd class="mt-2 text-2xl font-bold tabular-nums">
                                {{ dashboard[metric.key] }}
                            </dd>
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{ metric.description }}
                            </p>
                        </div>
                    </dl>
                </DeferredContent>
            </section>

            <section aria-labelledby="staff-tools-heading">
                <h2 id="staff-tools-heading" class="mb-4 text-xl font-semibold">
                    Quick links
                </h2>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Link
                        v-for="item in quickLinks"
                        :key="item.title"
                        :href="item.href"
                        class="surface-panel border-border bg-card hover:bg-accent focus-visible:ring-ring flex items-center gap-3 border p-5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <component
                            :is="item.icon"
                            class="text-primary-ink size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="flex-1 font-semibold">{{
                            item.title
                        }}</span>
                        <ArrowRight
                            class="text-muted-foreground size-4 shrink-0"
                            aria-hidden="true"
                        />
                    </Link>
                </div>
            </section>
        </main>
    </div>
</template>
