<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Mail, UserRound } from '@lucide/vue';
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
    <Head :title="customer.name" />

    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 p-6 lg:p-10"
    >
        <section class="border-border bg-card relative overflow-hidden border">
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div
                class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex min-w-0 items-start gap-4">
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <UserRound class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p
                            class="text-primary text-xs font-semibold tracking-widest uppercase"
                        >
                            Read-only customer record
                        </p>
                        <h1
                            class="mt-2 truncate text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            {{ customer.name }}
                        </h1>
                        <p
                            class="text-muted-foreground mt-2 max-w-2xl text-sm leading-6"
                        >
                            View the approved account details used to identify
                            this Battlefront customer.
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="CustomerController.index()">
                        <ArrowLeft />
                        Back to customers
                    </Link>
                </Button>
            </div>
        </section>

        <section aria-labelledby="account-details-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">Customer account</p>
                <h2 id="account-details-heading" class="text-xl font-semibold">
                    Account details
                </h2>
            </div>

            <div class="border-border bg-card divide-border divide-y border">
                <div class="flex items-center gap-3 p-5">
                    <UserInfo :user="customer" show-email />
                </div>

                <dl class="divide-border grid md:grid-cols-2 md:divide-x">
                    <div class="border-border flex gap-3 p-5 max-md:border-b">
                        <Mail
                            class="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                        <div class="min-w-0">
                            <dt class="text-muted-foreground text-sm">
                                Email address
                            </dt>
                            <dd class="mt-1 truncate font-medium">
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
