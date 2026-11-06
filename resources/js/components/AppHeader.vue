<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Menu, Undo2 } from '@lucide/vue';
import { computed } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useAppNavigation } from '@/composables/useAppNavigation';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { dashboard, logout } from '@/routes';
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
});
const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentUrl, isCurrentOrParentUrl, whenCurrentUrl } = useCurrentUrl();
const { isAdministrator, mainNavItems } = useAppNavigation();
const activeItemStyles = 'bg-accent text-accent-foreground';
const mobileNavItems = computed(() => {
    if (isAdministrator.value) {
        return mainNavItems.value;
    }

    return [
        ['Dashboard', 'Dashboard'],
        ['Products', 'Shop Products'],
        ['Build a PC', 'PC Builder'],
        ['Wishlist', 'My Wishlist'],
        ['Cart', 'My Cart'],
        ['Orders', 'My Orders'],
        ['Orders', 'Returns & refunds'],
        ['Account', 'Profile / Settings'],
    ].flatMap(([sourceTitle, title]) => {
        const item = mainNavItems.value.find(
            (item) => item.title === sourceTitle,
        );

        if (!item) {
            return [];
        }

        const isReturnLink = title === 'Returns & refunds';

        return [
            {
                ...item,
                title,
                isReturnLink,
                icon: isReturnLink ? Undo2 : item.icon,
            },
        ];
    });
});

function isMobileItemActive(item) {
    if (item.isReturnLink) {
        return false;
    }

    return item.title === 'Profile / Settings'
        ? page.component.startsWith('settings/')
        : isCurrentOrParentUrl(item.href);
}
</script>

<template>
    <header>
        <div class="border-border bg-background/95 border-b backdrop-blur">
            <div class="mx-auto flex h-18 items-center px-4 md:max-w-7xl">
                <!-- Mobile Menu -->
                <div class="lg:hidden">
                    <Sheet>
                        <SheetTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="mr-2 h-9 w-9"
                                aria-label="Open navigation menu"
                            >
                                <Menu class="h-5 w-5" aria-hidden="true" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            class="w-75 max-w-[calc(100%-1rem)] overflow-y-auto p-6"
                        >
                            <SheetTitle class="sr-only"
                                >Navigation menu</SheetTitle
                            >
                            <SheetHeader class="flex justify-start text-left">
                                <AppLogoIcon class="size-12" />
                                <SheetDescription class="sr-only">
                                    Navigate your Battlefront account.
                                </SheetDescription>
                            </SheetHeader>
                            <div class="flex flex-1 flex-col gap-4 py-4">
                                <nav
                                    class="-mx-3 space-y-1"
                                    aria-label="Account navigation"
                                >
                                    <SheetClose
                                        v-for="item in mobileNavItems"
                                        :key="item.title"
                                        as-child
                                    >
                                        <Link
                                            :href="item.href"
                                            class="hover:bg-accent focus-visible:ring-ring flex min-h-11 items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
                                            :class="
                                                isMobileItemActive(item)
                                                    ? activeItemStyles
                                                    : undefined
                                            "
                                            :aria-current="
                                                isMobileItemActive(item)
                                                    ? 'page'
                                                    : undefined
                                            "
                                        >
                                            <component
                                                v-if="item.icon"
                                                :is="item.icon"
                                                aria-hidden="true"
                                                class="h-5 w-5 shrink-0"
                                            />
                                            <span>
                                                {{ item.title }}
                                                <span
                                                    v-if="item.isReturnLink"
                                                    class="text-muted-foreground block text-xs font-normal"
                                                    >Manage returns from an
                                                    order</span
                                                >
                                            </span>
                                            <span
                                                v-if="
                                                    (item.badgeCount ??
                                                        item.pendingCount) > 0
                                                "
                                                class="bg-secondary ml-auto rounded-full px-2 py-0.5 text-xs tabular-nums"
                                                >{{
                                                    item.badgeCount ??
                                                    item.pendingCount
                                                }}</span
                                            >
                                        </Link>
                                    </SheetClose>
                                    <SheetClose
                                        v-if="!isAdministrator"
                                        as-child
                                    >
                                        <Link
                                            :href="logout()"
                                            as="button"
                                            class="hover:bg-accent focus-visible:ring-ring flex min-h-11 w-full items-center gap-x-3 rounded-lg px-3 py-2 text-left text-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
                                            data-test="logout-button"
                                            @click="router.flushAll()"
                                        >
                                            <LogOut
                                                class="size-5"
                                                aria-hidden="true"
                                            />
                                            Log out
                                        </Link>
                                    </SheetClose>
                                </nav>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>

                <Link
                    :href="dashboard()"
                    class="flex w-36 items-center sm:w-44"
                    aria-label="Battlefront dashboard"
                >
                    <AppLogo />
                </Link>

                <!-- Desktop Menu -->
                <div class="hidden h-full lg:flex lg:flex-1">
                    <NavigationMenu class="ml-10 flex h-full items-stretch">
                        <NavigationMenuList
                            class="flex h-full items-stretch space-x-2"
                        >
                            <NavigationMenuItem
                                v-for="(item, index) in mainNavItems"
                                :key="index"
                                class="relative flex h-full items-center"
                            >
                                <Link
                                    :class="[
                                        navigationMenuTriggerStyle(),
                                        whenCurrentUrl(
                                            item.href,
                                            activeItemStyles,
                                        ),
                                        'h-10 cursor-pointer rounded-md px-3',
                                    ]"
                                    :href="item.href"
                                >
                                    <component
                                        v-if="item.icon"
                                        :is="item.icon"
                                        aria-hidden="true"
                                        class="mr-2 h-4 w-4"
                                    />
                                    {{ item.title }}
                                </Link>
                                <div
                                    v-if="isCurrentUrl(item.href)"
                                    class="bg-primary absolute bottom-0 left-0 h-0.5 w-full translate-y-px"
                                ></div>
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>

                <div class="ml-auto flex shrink-0 items-center gap-2">
                    <AppearanceToggle />
                    <NotificationBell />
                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="focus-within:ring-primary relative size-10 w-auto rounded-full p-1 focus-within:ring-2"
                                :class="
                                    !isAdministrator
                                        ? 'max-lg:hidden'
                                        : undefined
                                "
                            >
                                <Avatar
                                    class="size-8 overflow-hidden rounded-full"
                                >
                                    <AvatarImage
                                        v-if="auth.user.avatar"
                                        :src="auth.user.avatar"
                                        :alt="auth.user.name"
                                    />
                                    <AvatarFallback
                                        class="bg-muted text-foreground rounded-lg font-semibold"
                                    >
                                        {{ getInitials(auth.user?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <div
            v-if="props.breadcrumbs.length > 1"
            class="border-sidebar-border/70 flex w-full border-b"
        >
            <div
                class="text-muted-foreground mx-auto flex h-12 w-full items-center justify-start px-4 md:max-w-7xl"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </header>
</template>
