<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Mail } from '@lucide/vue';
import CustomerController from '@/actions/App/Http/Controllers/Administration/CustomerController';
import UserInfo from '@/components/UserInfo.vue';
import { Button } from '@/components/ui/button';

defineProps({
    customer: { type: Object, required: true },
});

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'long',
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
            {
                title: 'Customer record',
                href: CustomerController.index(),
            },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
    >
        <Head :title="customer.name" />
        <PageHeader
            :title="customer.name"
            description="Account details are read-only."
        >
            <template #actions>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="CustomerController.index()">
                        <ArrowLeft />
                        Back to customers
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <section aria-labelledby="account-details-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">Customer account</p>
                <h2 id="account-details-heading" class="text-xl font-semibold">
                    Account details
                </h2>
            </div>

            <div
                class="surface-panel border-border bg-card divide-border divide-y border"
            >
                <div class="flex items-center gap-3 p-5">
                    <UserInfo :user="customer" show-email />
                </div>

                <dl
                    class="divide-border grid grid-cols-1 md:grid-cols-2 md:divide-x"
                >
                    <div class="border-border flex gap-3 p-5 max-md:border-b">
                        <Mail
                            class="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                        <div class="min-w-0">
                            <dt class="text-muted-foreground text-sm">
                                Email address
                            </dt>
                            <dd class="mt-1 font-medium wrap-anywhere">
                                {{ customer.email }}
                            </dd>
                        </div>
                    </div>

                    <div class="flex gap-3 p-5">
                        <CalendarDays
                            class="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                        <div>
                            <dt class="text-muted-foreground text-sm">
                                Customer since
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ formatJoinedDate(customer.created_at) }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>
        </section>
    </main>
</template>
