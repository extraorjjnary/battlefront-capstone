<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import GuestAppearanceToggle from '@/components/GuestAppearanceToggle.vue';
import CustomerChatAssistant from '@/components/chatbot/CustomerChatAssistant.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard, home, login, register } from '@/routes';
import { index as branchIndex } from '@/routes/branches';
import { index as productIndex } from '@/routes/products';
import { index as pcBuilderIndex } from '@/routes/pc-builder';

const props = defineProps({
    activeSection: { type: String, default: null },
});

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();
const isDesktop = useMediaQuery('(width >= 64rem)');
const menuOpen = ref(false);
watch(isDesktop, () => {
    menuOpen.value = false;
});
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const storefrontLinks = [
    {
        title: 'Build a PC',
        mobileTitle: 'Build a PC',
        section: 'pc-builder',
        href: pcBuilderIndex(),
    },
    {
        title: 'Products',
        mobileTitle: 'Shop Products',
        section: 'products',
        href: productIndex(),
    },
    {
        title: 'Our stores',
        mobileTitle: 'Our Stores',
        section: 'branches',
        href: branchIndex(),
    },
];
const accountLinks = [
    { title: 'Log in', href: login() },
    { title: 'Create Account', href: register() },
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
                class="focus-visible:ring-ring focus-visible:ring-offset-background w-36 shrink-0 rounded-sm focus-visible:ring-2 focus-visible:ring-offset-4 focus-visible:outline-none lg:w-52"
            >
                <AppLogo />
            </Link>

            <nav
                class="flex shrink-0 items-center gap-0 md:gap-2"
                aria-label="Primary"
            >
                <Sheet v-model:open="menuOpen">
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="lg:hidden"
                            aria-label="Open navigation menu"
                        >
                            <Menu class="size-5" aria-hidden="true" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="left"
                        class="w-75 max-w-[calc(100%-1rem)] overflow-y-auto p-6"
                    >
                        <SheetHeader class="p-0 text-left">
                            <AppLogoIcon class="size-12" />
                            <SheetTitle>Navigation menu</SheetTitle>
                            <SheetDescription class="sr-only">
                                Explore Battlefront and your account.
                            </SheetDescription>
                        </SheetHeader>
                        <nav
                            class="-mx-3 space-y-1 py-4"
                            aria-label="Guest navigation"
                        >
                            <SheetClose
                                v-for="item in storefrontLinks"
                                :key="item.section"
                                as-child
                            >
                                <Link
                                    :href="item.href"
                                    class="hover:bg-accent focus-visible:ring-ring flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
                                    :class="
                                        props.activeSection === item.section
                                            ? 'bg-accent text-accent-foreground'
                                            : undefined
                                    "
                                    :aria-current="
                                        props.activeSection === item.section
                                            ? 'page'
                                            : undefined
                                    "
                                >
                                    {{ item.mobileTitle }}
                                </Link>
                            </SheetClose>
                            <SheetClose
                                v-for="item in accountLinks"
                                :key="item.title"
                                as-child
                            >
                                <Link
                                    :href="item.href"
                                    class="hover:bg-accent focus-visible:ring-ring flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
                                    :class="
                                        isCurrentUrl(item.href)
                                            ? 'bg-accent text-accent-foreground'
                                            : undefined
                                    "
                                    :aria-current="
                                        isCurrentUrl(item.href)
                                            ? 'page'
                                            : undefined
                                    "
                                >
                                    {{ item.title }}
                                </Link>
                            </SheetClose>
                        </nav>
                    </SheetContent>
                </Sheet>
                <Button
                    v-for="item in storefrontLinks"
                    :key="item.section"
                    as-child
                    size="sm"
                    class="hidden min-w-11 px-2 text-xs md:px-3 md:text-sm lg:inline-flex"
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
                        class="hidden min-w-11 px-2 text-xs md:px-3 md:text-sm lg:inline-flex"
                    >
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child size="sm" class="hidden lg:inline-flex">
                        <Link :href="register()">Register</Link>
                    </Button>
                </template>
                <GuestAppearanceToggle />
            </nav>
        </div>
        <CustomerChatAssistant />
    </header>
</template>
