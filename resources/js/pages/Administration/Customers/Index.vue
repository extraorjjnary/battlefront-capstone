<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, UsersRound } from '@lucide/vue';
import CustomerController from '@/actions/App/Http/Controllers/Administration/CustomerController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import UserInfo from '@/components/UserInfo.vue';
import { Button } from '@/components/ui/button';

defineProps({
    customers: { type: Object, required: true },
});

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
});

function formatJoinedDate(value) {
    return value ? dateFormatter.format(new Date(value)) : 'Not available';
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Customers',
                href: CustomerController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head title="Customers" />
        <PageHeader
                title="Customers"
                description="Account details are read-only."
            />

        <section aria-labelledby="customer-list-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">
                    {{ customers.total }} customers
                </p>
                <h2 id="customer-list-heading" class="text-xl font-semibold">
                    Account directory
                </h2>
            </div>

            <div
                v-if="customers.data.length === 0"
                class="surface-panel border-border bg-card flex min-h-48 items-center justify-center border p-6 text-center"
            >
                <div>
                    <UsersRound class="text-muted-foreground mx-auto size-8" />
                    <p class="mt-3 font-medium">No customer accounts</p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Registered customer accounts will appear here.
                    </p>
                </div>
            </div>

            <div
                v-else
                class="surface-panel border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="customer in customers.data"
                    :key="customer.id"
                    class="grid gap-5 p-5 md:grid-cols-[minmax(0,1fr)_auto_auto] md:items-center"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <UserInfo :user="customer" show-email />
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-3 md:justify-end"
                    >
                        <span class="text-muted-foreground text-sm">
                            Joined {{ formatJoinedDate(customer.created_at) }}
                        </span>
                    </div>

                    <Button variant="outline" size="sm" as-child>
                        <Link :href="CustomerController.show(customer.id)">
                            View record
                            <ArrowRight />
                        </Link>
                    </Button>
                </article>
            </div>

            <CatalogPagination
                :current-page="customers.current_page"
                :last-page="customers.last_page"
                :route="CustomerController.index"
                label="Customer account pages"
            />
        </section>
    </main>
</template>
