import { LayoutDashboard, UserRound } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

export function useAppNavigation() {
    const page = usePage();
    const isAdministrator = computed(
        () => page.props.auth?.can?.accessAdministration === true,
    );
    const sectionLabel = computed(() =>
        isAdministrator.value ? 'Administration' : 'Customer',
    );
    const mainNavItems = computed(() => [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutDashboard,
        },
        {
            title: 'Account',
            href: editProfile(),
            icon: UserRound,
        },
    ]);

    return {
        isAdministrator,
        mainNavItems,
        sectionLabel,
    };
}
