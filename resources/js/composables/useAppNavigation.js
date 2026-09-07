import { Boxes, LayoutDashboard, PackageSearch, UserRound } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
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
    const mainNavItems = computed(() => {
        const items = [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutDashboard,
            },
        ];

        if (isAdministrator.value) {
            items.push({
                title: 'Catalog',
                href: ProductController.index(),
                icon: PackageSearch,
                activeRoutes: [
                    ProductController.index(),
                    CategoryController.index(),
                ],
            });
            items.push({
                title: 'Inventory',
                href: InventoryController.index(),
                icon: Boxes,
            });
        }

        items.push({
            title: 'Account',
            href: editProfile(),
            icon: UserRound,
        });

        return items;
    });

    return {
        isAdministrator,
        mainNavItems,
        sectionLabel,
    };
}
