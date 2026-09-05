<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, ShieldCheck, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const page = usePage();
const user = computed(() => page.props.auth.user);
const isAdministrator = computed(
    () => page.props.auth.can.accessAdministration,
);
const workspaceName = computed(() =>
    isAdministrator.value ? 'Administration workspace' : 'Customer workspace',
);
const description = computed(() =>
    isAdministrator.value
        ? 'Your Battlefront operations workspace for the Sagay City branch.'
        : 'Your starting point for shopping with Battlefront Computer Trading.',
);
const accountLinks = [
    {
        title: 'Profile details',
        description: 'Review and update your name and email address.',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Account security',
        description: 'Manage your password and security options.',
        href: editSecurity(),
        icon: ShieldCheck,
    },
];

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-10 p-6 lg:p-10"
    >
        <section
            class="border-border bg-card relative overflow-hidden border p-6 sm:p-8"
        >
            <div class="bg-primary absolute inset-y-0 left-0 w-1"></div>
            <div class="max-w-2xl">
                <p
                    class="text-primary text-xs font-semibold tracking-widest uppercase"
                >
                    {{ workspaceName }}
                </p>
                <h1 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
                    Welcome, {{ user.name }}
                </h1>
                <p class="text-muted-foreground mt-3 text-base leading-7">
                    {{ description }} Only tools available to your account are
                    shown in navigation.
                </p>
            </div>
        </section>

        <section aria-labelledby="available-now-heading">
            <div class="mb-4">
                <p class="text-muted-foreground text-sm">Available now</p>
                <h2 id="available-now-heading" class="text-xl font-semibold">
                    Manage your Battlefront account
                </h2>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <Link
                    v-for="item in accountLinks"
                    :key="item.title"
                    :href="item.href"
                    class="border-border bg-card hover:bg-accent focus-visible:ring-ring group flex min-h-32 items-start gap-4 border p-5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span
                        class="bg-secondary text-primary flex size-11 shrink-0 items-center justify-center rounded-md"
                    >
                        <component :is="item.icon" class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="font-semibold">{{ item.title }}</span>
                        <span
                            class="text-muted-foreground mt-1 block text-sm leading-6"
                        >
                            {{ item.description }}
                        </span>
                    </span>
                    <ArrowRight
                        class="text-muted-foreground mt-1 size-4 shrink-0 transition-transform group-hover:translate-x-1"
                    />
                </Link>
            </div>
        </section>
    </main>
</template>
