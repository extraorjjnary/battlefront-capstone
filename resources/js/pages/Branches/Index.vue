<script setup>
import PageHeader from '@/components/PageHeader.vue';
import { Head } from '@inertiajs/vue3';
import { Clock3, Mail, MapPin, Phone } from '@lucide/vue';
import BranchMap from '@/components/BranchMap.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Badge } from '@/components/ui/badge';

defineProps({
    branches: {
        type: Array,
        required: true,
    },
});

const availableValue = (value) => value ?? 'Not currently available';
const phoneUrl = (phoneNumber) => `tel:${phoneNumber.replaceAll(' ', '')}`;
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Our stores" />

        <StorefrontHeader active-section="branches" />

        <main class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <PageHeader
                title="Our stores"
                description="Contact the store to confirm availability before visiting."
            />

            <BranchMap :branches="branches" />

            <section aria-labelledby="branch-list-heading">
                <div class="mb-6 flex items-end justify-between gap-6">
                    <div>

                        <h2
                            id="branch-list-heading"
                            class="text-xl font-semibold tracking-tight"
                        >
                            Store details
                        </h2>
                    </div>
                    <p class="text-muted-foreground hidden text-sm sm:block">
                        {{ branches.length }} locations listed
                    </p>
                </div>

                <div v-if="branches.length" class="grid gap-5 lg:grid-cols-2">
                    <article
                        v-for="branch in branches"
                        :key="branch.id"
                        class="surface-card bg-card border p-4 sm:p-6"
                        :class="
                            branch.is_operational
                                ? 'border-primary/50 lg:col-span-2'
                                : 'border-border'
                        "
                    >
                        <div>
                            <div>
                                <Badge
                                    v-if="branch.is_operational"
                                    variant="default"
                                    class="rounded-sm px-2.5 py-1"
                                >
                                    <Clock3 aria-hidden="true" />
                                    Open · {{ branch.operating_hours }}
                                </Badge>
                                <h3
                                    class="text-2xl font-bold tracking-tight"
                                    :class="branch.is_operational ? 'mt-4' : ''"
                                >
                                    {{ branch.city }}
                                </h3>
                                <p class="text-muted-foreground mt-1 text-sm">
                                    {{ branch.name }}
                                </p>
                            </div>
                        </div>

                        <dl
                            class="border-border mt-4 grid gap-x-6 gap-y-4 border-t pt-4 md:grid-cols-2"
                        >
                            <div class="flex gap-3">
                                <MapPin
                                    class="text-primary-ink mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <dt
                                        class="text-muted-foreground text-xs font-bold tracking-wide uppercase"
                                    >
                                        Address
                                    </dt>
                                    <dd class="mt-1 text-sm leading-6">
                                        {{ availableValue(branch.address) }}
                                    </dd>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <Phone
                                    class="text-primary-ink mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <dt
                                        class="text-muted-foreground text-xs font-bold tracking-wide uppercase"
                                    >
                                        Contact number
                                    </dt>
                                    <dd class="mt-1 text-sm leading-6">
                                        <a
                                            v-if="branch.contact_number"
                                            :href="
                                                phoneUrl(branch.contact_number)
                                            "
                                            class="font-medium underline-offset-4 hover:underline"
                                        >
                                            {{ branch.contact_number }}
                                        </a>
                                        <span v-else
                                            >Not currently available</span
                                        >
                                    </dd>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <Mail
                                    class="text-primary-ink mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0">
                                    <dt
                                        class="text-muted-foreground text-xs font-bold tracking-wide uppercase"
                                    >
                                        Email
                                    </dt>
                                    <dd class="mt-1 text-sm leading-6">
                                        <a
                                            v-if="branch.email"
                                            :href="`mailto:${branch.email}`"
                                            class="font-medium break-all underline-offset-4 hover:underline"
                                        >
                                            {{ branch.email }}
                                        </a>
                                        <span v-else
                                            >Not currently available</span
                                        >
                                    </dd>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <Clock3
                                    class="text-primary-ink mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <div>
                                    <dt
                                        class="text-muted-foreground text-xs font-bold tracking-wide uppercase"
                                    >
                                        Operating hours
                                    </dt>
                                    <dd class="mt-1 text-sm leading-6">
                                        {{
                                            availableValue(
                                                branch.operating_hours,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </div>
                        </dl>
                        <p v-if="branch.operational_notice" class="text-muted-foreground mt-4 text-sm leading-6">{{ branch.operational_notice }}</p>
                    </article>
                </div>

                <div
                    v-else
                    class="border-border bg-muted/30 border border-dashed px-6 py-14 text-center"
                >
                    <MapPin
                        class="text-muted-foreground mx-auto size-7"
                        aria-hidden="true"
                    />
                    <p class="mt-4 font-semibold">
                        Branch information is not currently available.
                    </p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Please check again later.
                    </p>
                </div>
            </section>
        </main>
    </div>
</template>
