<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Clock3, Mail, MapPin, Navigation, Phone } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import BranchMap from '@/components/BranchMap.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard, home, login } from '@/routes';
import { register } from '@/routes';

defineProps({
    branches: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

const availableValue = (value) => value ?? 'Not currently available';
const phoneUrl = (phoneNumber) => `tel:${phoneNumber.replaceAll(' ', '')}`;
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Branches" />

        <header
            class="border-border bg-background/95 sticky top-0 z-20 border-b backdrop-blur"
        >
            <div
                class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-2 px-4 sm:gap-6 sm:px-8"
            >
                <Link :href="home()" class="w-28 sm:w-52">
                    <AppLogo />
                </Link>

                <nav class="flex items-center gap-2" aria-label="Primary">
                    <span
                        class="border-primary hidden border-b-2 px-2 py-2 text-sm font-semibold sm:inline"
                    >
                        Branches
                    </span>
                    <Button
                        v-if="isAuthenticated"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <Link :href="dashboard()">Dashboard</Link>
                    </Button>
                    <template v-else>
                        <Button as-child size="sm" variant="ghost">
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button as-child size="sm">
                            <Link :href="register()">Register</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-5 py-10 sm:px-8 sm:py-14">
            <section
                class="border-border bg-card relative overflow-hidden border px-6 py-10 shadow-sm sm:px-10 lg:grid lg:grid-cols-[1.5fr_0.7fr] lg:items-end lg:gap-12 lg:px-14 lg:py-14"
            >
                <div
                    class="bg-primary absolute inset-y-0 left-0 w-1.5"
                    aria-hidden="true"
                />
                <div>
                    <div
                        class="border-primary/30 bg-primary/10 text-primary mb-5 flex size-11 items-center justify-center border"
                    >
                        <Navigation class="size-5" aria-hidden="true" />
                    </div>
                    <p
                        class="text-primary mb-3 text-xs font-bold tracking-[0.2em] uppercase"
                    >
                        Battlefront locations
                    </p>
                    <h1
                        class="max-w-3xl text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl"
                    >
                        Find a Battlefront branch near you
                    </h1>
                    <p
                        class="text-muted-foreground mt-5 max-w-2xl text-base leading-7 sm:text-lg"
                    >
                        Review confirmed contact and location details before
                        visiting a Battlefront branch.
                    </p>
                </div>

                <div
                    class="border-border mt-8 border-t pt-6 lg:mt-0 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-10"
                >
                    <p class="text-muted-foreground text-sm font-medium">
                        Before you visit
                    </p>
                    <p class="mt-1 text-2xl font-bold">Check branch details</p>
                    <p class="text-muted-foreground mt-3 text-sm leading-6">
                        Contact the branch before visiting to confirm its
                        current availability.
                    </p>
                </div>
            </section>

            <BranchMap :branches="branches" class="mt-12" />

            <section class="mt-12" aria-labelledby="branch-list-heading">
                <div class="mb-6 flex items-end justify-between gap-6">
                    <div>
                        <p
                            class="text-primary text-xs font-bold tracking-[0.18em] uppercase"
                        >
                            Branch directory
                        </p>
                        <h2
                            id="branch-list-heading"
                            class="mt-2 text-2xl font-bold tracking-tight"
                        >
                            Confirmed branch information
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
                        class="bg-card border p-6 shadow-sm sm:p-8"
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
                            class="border-border mt-7 grid gap-x-8 gap-y-6 border-t pt-7 md:grid-cols-2"
                        >
                            <div class="flex gap-3">
                                <MapPin
                                    class="text-primary mt-0.5 size-5 shrink-0"
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
                                    class="text-primary mt-0.5 size-5 shrink-0"
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
                                    class="text-primary mt-0.5 size-5 shrink-0"
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
                                    class="text-primary mt-0.5 size-5 shrink-0"
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
