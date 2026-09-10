<script setup>
import { Head, Link } from "@inertiajs/vue3";
import {
    ArrowRight,
    Cpu,
    HardDrive,
    Headphones,
    Keyboard,
    MapPinned,
    Monitor,
    PackageCheck,
    Search,
    SlidersHorizontal,
} from "@lucide/vue";
import AppLogo from "@/components/AppLogo.vue";
import { Button } from "@/components/ui/button";
import { dashboard, home, login, register } from "@/routes";
import { index as branchIndex } from "@/routes/branches";

const hardwareAreas = [
    { name: "Processors", icon: Cpu },
    { name: "Storage", icon: HardDrive },
    { name: "Displays", icon: Monitor },
    { name: "Peripherals", icon: Keyboard },
];

const customerHighlights = [
    {
        title: "Find the right hardware",
        description:
            "Explore computer products by category, brand, and the requirements that matter to your setup.",
        icon: Search,
    },
    {
        title: "Choose with confidence",
        description:
            "Use guided, requirement-based recommendations to narrow down suitable products for your needs.",
        icon: SlidersHorizontal,
    },
    {
        title: "Keep track of your order",
        description:
            "Use your Battlefront account to manage purchases and follow order progress in one place.",
        icon: PackageCheck,
    },
];
</script>

<template>
    <div class="dark min-h-screen bg-background text-foreground">
        <Head title="Home">
            <meta
                head-key="description"
                name="description"
                content="Battlefront Computer Trading provides computer hardware, guided product discovery, and branch information."
            />
        </Head>

        <header
            class="sticky top-0 z-30 border-b border-border bg-background/95 backdrop-blur"
        >
            <div
                class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-2 px-4 sm:gap-6 sm:px-8"
            >
                <Link
                    :href="home()"
                    aria-label="Battlefront Computer Trading home"
                    class="w-24 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:ring-offset-background focus-visible:outline-none sm:w-52"
                >
                    <AppLogo />
                </Link>

                <nav
                    class="flex items-center gap-1 sm:gap-2"
                    aria-label="Primary"
                >
                    <Button as-child size="sm" variant="ghost">
                        <Link :href="branchIndex()">Branches</Link>
                    </Button>
                    <Button
                        v-if="$page.props.auth.user"
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

        <main>
            <section
                class="relative overflow-hidden border-b border-border"
                aria-labelledby="hero-heading"
            >
                <div
                    class="pointer-events-none absolute inset-0 opacity-35 bg-[linear-gradient(to_right,#2a2e36_1px,transparent_1px),linear-gradient(to_bottom,#2a2e36_1px,transparent_1px)] bg-size[48px_48px] mask-[linear-gradient(to_bottom,black,transparent_82%)]"
                    aria-hidden="true"
                />

                <div
                    class="relative mx-auto grid max-w-7xl gap-12 px-5 py-16 sm:px-8 sm:py-24 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-16 lg:py-28"
                >
                    <div>
                        <div
                            class="mb-6 inline-flex items-center gap-2 border border-primary/40 bg-primary/10 px-3 py-1.5 text-xs font-bold tracking-[0.18em] text-[#ef1b1b] uppercase"
                        >
                            <Cpu class="size-4" aria-hidden="true" />
                            Computer hardware, made clearer
                        </div>

                        <h1
                            id="hero-heading"
                            class="max-w-3xl text-4xl leading-[1.05] font-bold tracking-tight text-balance sm:text-6xl lg:text-7xl"
                        >
                            Build your next setup with
                            <span class="text-[#ef1b1b]">confidence.</span>
                        </h1>

                        <p
                            class="mt-6 max-w-2xl text-base leading-7 text-muted-foreground sm:text-lg sm:leading-8"
                        >
                            Battlefront Computer Trading brings computer
                            hardware, guided product discovery, and local branch
                            information together for a more focused buying
                            experience.
                        </p>

                        <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                            <Button
                                v-if="$page.props.auth.user"
                                as-child
                                size="lg"
                            >
                                <Link :href="dashboard()">
                                    Go to dashboard
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                            <Button v-else as-child size="lg">
                                <Link :href="register()">
                                    Create an account
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                            <Button as-child size="lg" variant="outline">
                                <Link :href="branchIndex()">
                                    Find a branch
                                    <MapPinned aria-hidden="true" />
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <div
                        class="border border-border bg-card shadow-2xl shadow-black/30"
                        aria-label="Hardware categories"
                    >
                        <div
                            class="flex items-center justify-between border-b border-border px-5 py-4"
                        >
                            <div>
                                <p
                                    class="text-xs font-bold tracking-[0.16em] text-[#ef1b1b] uppercase"
                                >
                                    Build station
                                </p>
                                <p class="mt-1 font-semibold">
                                    Start with the essentials
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2">
                            <div
                                v-for="(area, index) in hardwareAreas"
                                :key="area.name"
                                class="group flex min-h-36 flex-col justify-between border-border p-5 motion-safe:transition-colors motion-safe:hover:bg-secondary/60 sm:min-h-44 sm:p-6"
                                :class="[
                                    index % 2 === 0 ? 'border-r' : '',
                                    index < 2 ? 'border-b' : '',
                                ]"
                            >
                                <component
                                    :is="area.icon"
                                    class="size-7 text-muted-foreground motion-safe:transition-colors motion-safe:group-hover:text-[#ef1b1b]"
                                    aria-hidden="true"
                                />
                                <p class="text-sm font-semibold sm:text-base">
                                    {{ area.name }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24"
                aria-labelledby="shopping-heading"
            >
                <div class="max-w-2xl">
                    <p
                        class="text-xs font-bold tracking-[0.18em] text-[#ef1b1b] uppercase"
                    >
                        A focused buying experience
                    </p>
                    <h2
                        id="shopping-heading"
                        class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        From the first search to your order
                    </h2>
                    <p class="mt-4 leading-7 text-muted-foreground">
                        Battlefront is designed around the practical steps of
                        choosing computer hardware and staying informed after
                        checkout.
                    </p>
                </div>

                <div class="mt-10 grid border-y border-border lg:grid-cols-3">
                    <article
                        v-for="(highlight, index) in customerHighlights"
                        :key="highlight.title"
                        class="border-border py-8 lg:px-8"
                        :class="[
                            index > 0
                                ? 'border-t lg:border-t-0 lg:border-l'
                                : '',
                            index === 0 ? 'lg:pl-0' : '',
                            index === customerHighlights.length - 1
                                ? 'lg:pr-0'
                                : '',
                        ]"
                    >
                        <div
                            class="flex size-11 items-center justify-center border border-primary/40 bg-primary/10 text-[#ef1b1b]"
                        >
                            <component
                                :is="highlight.icon"
                                class="size-5"
                                aria-hidden="true"
                            />
                        </div>
                        <h3 class="mt-6 text-xl font-bold">
                            {{ highlight.title }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-muted-foreground">
                            {{ highlight.description }}
                        </p>
                    </article>
                </div>
            </section>

            <section class="border-y border-border bg-card">
                <div
                    class="mx-auto grid max-w-7xl gap-8 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[1fr_auto] lg:items-center"
                >
                    <div class="flex gap-5">
                        <div
                            class="hidden size-12 shrink-0 items-center justify-center border border-primary/40 bg-primary/10 text-[#ef1b1b] sm:flex"
                        >
                            <MapPinned class="size-6" aria-hidden="true" />
                        </div>
                        <div>
                            <p
                                class="text-xs font-bold tracking-[0.18em] text-[#ef1b1b] uppercase"
                            >
                                Visit Battlefront
                            </p>
                            <h2
                                class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                            >
                                Find the branch that works for you
                            </h2>
                            <p
                                class="mt-3 max-w-2xl text-sm leading-6 text-muted-foreground"
                            >
                                Review confirmed locations, contact details,
                                operating hours, and mapped branches before you
                                visit.
                            </p>
                        </div>
                    </div>

                    <Button as-child size="lg" variant="outline">
                        <Link :href="branchIndex()">
                            View all branches
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
            </section>
        </main>

        <footer class="mx-auto max-w-7xl px-5 py-8 sm:px-8">
            <div
                class="flex flex-col gap-4 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="font-medium text-foreground">
                    Battlefront Computer Trading
                </p>
                <p class="flex items-center gap-2">
                    <Headphones class="size-4" aria-hidden="true" />
                    Computer hardware and customer support
                </p>
            </div>
        </footer>
    </div>
</template>
