<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GuestAppearanceToggle from '@/components/GuestAppearanceToggle.vue';
import CustomerChatAssistant from '@/components/chatbot/CustomerChatAssistant.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';
import { index as branchIndex } from '@/routes/branches';
import { index as productIndex } from '@/routes/products';
import { index as pcBuilderIndex } from '@/routes/pc-builder';

const props = defineProps({
    activeSection: { type: String, default: null },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const storefrontLinks = [
    {
        title: 'Build a PC',
        mobileTitle: 'Builder',
        section: 'pc-builder',
        href: pcBuilderIndex(),
    },
    {
        title: 'Products',
        mobileTitle: 'Shop',
        section: 'products',
        href: productIndex(),
    },
    {
        title: 'Our stores',
        mobileTitle: 'Stores',
        section: 'branches',
        href: branchIndex(),
    },
];
</script>

<template>
    <header
        v-if="!isAuthenticated"
        class="border-border bg-card/95 sticky top-0 z-30 border-b backdrop-blur"
    >
        <div
            class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-1 px-2 md:gap-6 md:px-8"
        >
            <Link
                :href="home()"
                aria-label="Battlefront Computer Trading home"
                class="focus-visible:ring-ring focus-visible:ring-offset-background w-10 shrink-0 rounded-sm focus-visible:ring-2 focus-visible:ring-offset-4 focus-visible:outline-none md:w-36 lg:w-52"
            >
                <AppLogo class="hidden md:block" />
                <AppLogoIcon class="mx-auto size-10 md:hidden" />
            </Link>

            <nav
                class="flex shrink-0 items-center gap-0 md:gap-2"
                aria-label="Primary"
            >
                <Button
                    v-for="item in storefrontLinks"
                    :key="item.section"
                    as-child
                    size="sm"
                    class="min-w-11 px-2 text-xs md:px-3 md:text-sm"
                    :variant="
                        props.activeSection === item.section
                            ? 'outline'
                            : 'ghost'
                    "
                >
                    <Link
                        :href="item.href"
                        :aria-label="item.title"
                        :aria-current="
                            props.activeSection === item.section
                                ? 'page'
                                : undefined
                        "
                    >
                        <span class="md:hidden">{{ item.mobileTitle }}</span>
                        <span class="hidden md:inline">{{ item.title }}</span>
                    </Link>
                </Button>

                <Button
                    v-if="isAuthenticated"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="dashboard()">Dashboard</Link>
                </Button>
                <template v-else>
                    <Button
                        as-child
                        size="sm"
                        variant="ghost"
                        class="min-w-11 px-2 text-xs md:px-3 md:text-sm"
                    >
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child size="sm" class="hidden md:inline-flex">
                        <Link :href="register()">Register</Link>
                    </Button>
                </template>
                <GuestAppearanceToggle />
            </nav>
        </div>
        <CustomerChatAssistant />
    </header>
</template>
