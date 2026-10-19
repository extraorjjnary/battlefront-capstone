import {
    Boxes,
    ChartColumn,
    LayoutDashboard,
    MapPin,
    MessageSquareText,
    PackageSearch,
    ReceiptText,
    ShoppingCart,
    TrendingUp,
    UsersRound,
    UserRound,
} from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import CustomerController from '@/actions/App/Http/Controllers/Administration/CustomerController';
import ForecastingController from '@/actions/App/Http/Controllers/Administration/ForecastingController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import AdministrationOrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import SalesReportController from '@/actions/App/Http/Controllers/Administration/SalesReportController';
import StaffPerformanceController from '@/actions/App/Http/Controllers/Administration/StaffPerformanceController';
import StockReconciliationController from '@/actions/App/Http/Controllers/Administration/StockReconciliationController';
import WorkQueueController from '@/actions/App/Http/Controllers/Administration/WorkQueueController';
import AuditLogController from '@/actions/App/Http/Controllers/Administration/AuditLogController';
import InventoryAdjustmentController from '@/actions/App/Http/Controllers/Administration/InventoryAdjustmentController';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import SupplierReturnController from '@/actions/App/Http/Controllers/Administration/SupplierReturnController';
import SupplierController from '@/actions/App/Http/Controllers/Administration/SupplierController';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';
import TeamController from '@/actions/App/Http/Controllers/Administration/TeamController';
import { dashboard } from '@/routes';
import { index as branchIndex } from '@/routes/branches';
import { index as cartIndex } from '@/routes/cart';
import { index as orderIndex } from '@/routes/orders';
import { index as productIndex } from '@/routes/products';
import { edit as editProfile } from '@/routes/profile';

export function useAppNavigation() {
    const page = usePage();
    const isAdministrator = computed(
        () => page.props.auth?.can?.accessAdministration === true,
    );
    const canUseCustomerCart = computed(
        () => page.props.auth?.can?.useCustomerCart === true,
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
            {
                title: 'Branches',
                href: branchIndex(),
                icon: MapPin,
            },
        ];

        if (isAdministrator.value) {
            items.push({ title: 'Work queue', href: WorkQueueController.index(), icon: ReceiptText });
            if (page.props.auth?.can?.browseProductCatalog) items.push({
                title: 'Product Catalog',
                href: productIndex(),
                icon: PackageSearch,
            });
            if (page.props.auth?.can?.manageProducts) items.push({
                title: 'Catalog',
                href: ProductController.index(),
                icon: PackageSearch,
                activeRoutes: [
                    ProductController.index(),
                    CategoryController.index(),
                ],
            });
            if (page.props.auth?.can?.viewInventory) items.push({
                title: 'Inventory',
                href: InventoryController.index(),
                icon: Boxes,
            });
            if (page.props.auth?.can?.viewInventory) items.push({ title: 'Stock requests', href: InventoryAdjustmentController.index(), icon: Boxes });
            if (page.props.auth?.can?.viewInventory) items.push({ title: 'Stock reconciliation', href: StockReconciliationController.index(), icon: Boxes });
            if (page.props.auth?.can?.viewInventory) items.push({ title: 'Damage reports', href: DamageReportController.index(), icon: Boxes });
            if (page.props.auth?.can?.viewInventory) items.push({ title: 'Supplier returns', href: SupplierReturnController.index(), icon: Boxes });
            if (page.props.auth?.can?.viewOrders) items.push({ title: 'Customer returns', href: CustomerReturnController.index(), icon: ReceiptText });
            if (page.props.auth?.can?.manageSuppliers) items.push({ title: 'Suppliers', href: SupplierController.index(), icon: Boxes });
            if (page.props.auth?.can?.viewOrders) items.push({
                title: 'Orders',
                href: AdministrationOrderController.index(),
                icon: ReceiptText,
            });
            if (page.props.auth?.can?.viewReports) items.push({
                title: 'Sales reports',
                href: SalesReportController.index(),
                icon: ChartColumn,
            });
            if (page.props.auth?.can?.viewOrders) items.push({ title: page.props.auth?.can?.viewStaffReports ? 'Staff sales' : 'My sales', href: StaffPerformanceController.index(), icon: ChartColumn });
            if (page.props.auth?.can?.viewAudit) items.push({ title: 'Audit log', href: AuditLogController.index(), icon: ReceiptText });
            if (page.props.auth?.can?.manageUsers) items.push({ title: 'Team access', href: TeamController.index(), icon: UsersRound });
            if (page.props.auth?.can?.viewReports) items.push({
                title: 'Forecasting',
                href: ForecastingController.index(),
                icon: TrendingUp,
            });
            if (page.props.auth?.can?.viewCustomers) items.push({
                title: 'Customers',
                href: CustomerController.index(),
                icon: UsersRound,
            });
            if (page.props.auth?.can?.manageProducts) items.push({
                title: 'Chatbot FAQs',
                href: ChatbotKnowledgeController.index(),
                icon: MessageSquareText,
                activeRoutes: [ChatbotKnowledgeController.index()],
            });
        } else {
            items.push({
                title: 'Products',
                href: productIndex(),
                icon: PackageSearch,
            });
        }

        if (canUseCustomerCart.value) {
            items.push({
                title: 'Orders',
                href: orderIndex(),
                icon: ReceiptText,
                activeRoutes: [orderIndex()],
            });
            items.push({
                title: 'Cart',
                href: cartIndex(),
                icon: ShoppingCart,
                badgeCount: page.props.cartQuantity ?? 0,
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
