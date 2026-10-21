<script setup>
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    Mail,
    MapPinned,
    PackageCheck,
    Phone,
    Search,
    SlidersHorizontal,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';
import { index as branchIndex } from '@/routes/branches';
import { index as productIndex } from '@/routes/products';

const customerHighlights = [
    {
        title: 'Find the right hardware',
        description: 'Filter computer hardware by category, brand, and price.',
        icon: Search,
    },
    {
        title: 'Choose with confidence',
        description:
            'Discover product suggestions, with personalization controls in your profile.',
        icon: SlidersHorizontal,
    },
    {
        title: 'Keep track of your order',
        description:
            'Manage purchases and follow order progress from your account.',
        icon: PackageCheck,
    },
];
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Home">
            <meta
                head-key="description"
                name="description"
                content="Battlefront Computer Trading provides computer hardware, guided product discovery, and branch information."
            />
        </Head>

        <StorefrontHeader />

        <main>
            <section
                class="border-border relative overflow-hidden border-b"
                aria-labelledby="hero-heading"
            >
                <div
                    class="mx-auto grid max-w-7xl gap-6 px-5 py-6 sm:gap-10 sm:px-8 sm:py-14 lg:grid-cols-2 lg:items-center lg:gap-12 lg:py-16"
                >
                    <div>
                        <p
                            class="text-primary-ink mb-4 text-xs font-semibold tracking-[0.14em] uppercase sm:mb-5"
                        >
                            EXPLORE. CHOOSE. UPGRADE.
                        </p>

                        <h1
                            id="hero-heading"
                            class="max-w-3xl text-4xl leading-[1.05] font-bold tracking-tight text-balance sm:text-6xl lg:text-7xl"
                        >
                            Better <span class="text-primary-ink">tech</span>
                            starts here.
                        </h1>

                        <p
                            class="text-muted-foreground mt-4 max-w-2xl text-base leading-7 sm:mt-6 sm:text-lg sm:leading-8"
                        >
                            Discover computer hardware and accessories for every
                            setup.
                        </p>

                        <div
                            class="mt-5 flex flex-col gap-3 sm:mt-7 sm:flex-row"
                        >
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

                    <picture class="hidden sm:contents">
                        <source
                            class="hidden"
                            media="(min-width: 640px)"
                            srcset="/images/hero/battlefront-mouse-finger.webp"
                            type="image/webp"
                        />
                        <img
                            src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='1' height='1'/%3E"
                            alt="White computer mouse with a finger hovering above it"
                            width="1597"
                            height="985"
                            loading="eager"
                            fetchpriority="high"
                            decoding="async"
                            class="mx-auto block h-auto w-[85%] max-w-xs mask-[linear-gradient(to_right,#000_92%,transparent_100%)] object-contain sm:max-w-[26rem] lg:mr-0 lg:w-full lg:max-w-xl"
                        />
                    </picture>
                </div>
            </section>

            <section
                class="mx-auto max-w-7xl px-5 py-10 sm:px-8 sm:py-12"
                aria-labelledby="shopping-heading"
            >
                <h2
                    id="shopping-heading"
                    class="text-2xl font-bold tracking-tight sm:text-3xl"
                >
                    From the first search to your order
                </h2>

                <div class="mt-6 grid gap-6 sm:mt-8 sm:gap-8 md:grid-cols-3">
                    <article
                        v-for="highlight in customerHighlights"
                        :key="highlight.title"
                        class="flex items-start gap-3"
                    >
                        <component
                            :is="highlight.icon"
                            class="text-primary-ink mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <div>
                            <h3 class="font-semibold">
                                {{ highlight.title }}
                            </h3>
                            <p
                                class="text-muted-foreground mt-2 text-sm leading-6"
                            >
                                {{ highlight.description }}
                            </p>
                        </div>
                    </article>
                </div>
            </section>

            <section
                class="mx-auto max-w-7xl px-5 sm:px-8"
                aria-labelledby="branch-heading"
            >
                <div
                    class="border-border flex flex-col items-start gap-4 border-t py-6 sm:flex-row sm:items-center sm:justify-between"
                >
                    <h2 id="branch-heading" class="text-lg font-semibold">
                        Find your Battlefront branch
                    </h2>

                    <Button as-child variant="outline">
                        <Link :href="branchIndex()">
                            View all branches
                            <ArrowRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
            </section>
        </main>

        <footer class="border-border bg-card border-t">
            <div
                class="mx-auto grid max-w-7xl gap-6 px-5 py-8 sm:grid-cols-2 sm:px-8 lg:grid-cols-[1fr_1.4fr_1fr] lg:gap-10"
            >
                <div>
                    <div class="w-44">
                        <AppLogo />
                    </div>
                    <nav
                        aria-label="Footer"
                        class="text-muted-foreground mt-3 flex flex-wrap gap-x-5 text-sm"
                    >
                        <Link
                            :href="productIndex()"
                            class="hover:text-primary-ink focus-visible:outline-ring inline-flex min-h-11 items-center rounded-sm py-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >
                            Shop Products
                        </Link>
                        <Link
                            :href="branchIndex()"
                            class="hover:text-primary-ink focus-visible:outline-ring inline-flex min-h-11 items-center rounded-sm py-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >
                            Our Stores
                        </Link>
                    </nav>
                </div>

                <section
                    aria-labelledby="footer-contact-heading"
                    class="min-w-0"
                >
                    <h2
                        id="footer-contact-heading"
                        class="text-sm font-semibold"
                    >
                        Contact Us
                    </h2>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Sagay branch
                    </p>
                    <address
                        class="text-muted-foreground mt-2 flex flex-col text-sm not-italic"
                    >
                        <a
                            href="mailto:battlefrontcomputertrading@gmail.com"
                            class="hover:text-primary-ink focus-visible:outline-ring inline-flex min-h-11 items-center gap-2 rounded-sm py-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >
                            <Mail class="size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 break-all"
                                >battlefrontcomputertrading@gmail.com</span
                            >
                        </a>
                        <a
                            href="tel:09386476046"
                            class="hover:text-primary-ink focus-visible:outline-ring inline-flex min-h-11 items-center gap-2 rounded-sm py-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >
                            <Phone class="size-4 shrink-0" aria-hidden="true" />
                            0938 647 6046
                        </a>
                    </address>
                </section>

                <section aria-labelledby="footer-social-heading">
                    <h2
                        id="footer-social-heading"
                        class="text-sm font-semibold"
                    >
                        Follow our Sagay branch
                    </h2>
                    <div
                        class="text-muted-foreground mt-2 flex flex-wrap gap-x-5 text-sm"
                    >
                        <a
                            href="https://www.facebook.com/BattlefrontComputer"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="hover:text-primary-ink focus-visible:outline-ring inline-flex min-h-11 items-center gap-1 rounded-sm py-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >
                            Facebook
                            <ArrowUpRight class="size-4" aria-hidden="true" />
                        </a>
                        <a
                            href="https://www.tiktok.com/@ralphpgonzaga"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="hover:text-primary-ink focus-visible:outline-ring inline-flex min-h-11 items-center gap-1 rounded-sm py-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >
                            TikTok
                            <ArrowUpRight class="size-4" aria-hidden="true" />
                        </a>
                    </div>
                </section>
            </div>
        </footer>
    </div>
</template>
