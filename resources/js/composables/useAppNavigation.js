import {
    Boxes,
    ListTodo,
    ClipboardList,
    ClipboardCheck,
    PackageX,
    Truck,
    Undo2,
    Handshake,
    Heart,
    ChartColumnIncreasing,
    Logs,
    UserRoundCog,
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
import { index as administrationBranchIndex } from '@/actions/App/Http/Controllers/Administration/BranchController';
import ChatbotKnowledgeController from '@/actions/App/Http/Controllers/Administration/ChatbotKnowledgeController';
import CustomerController from '@/actions/App/Http/Controllers/Administration/CustomerController';
import ForecastingController from '@/actions/App/Http/Controllers/Administration/ForecastingController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import AdministrationOrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import ComponentSpecificationController from '@/actions/App/Http/Controllers/Administration/ComponentSpecificationController';
import PcBuildTemplateController from '@/actions/App/Http/Controllers/Administration/PcBuildTemplateController';
import PcBuildAnalyticsController from '@/actions/App/Http/Controllers/Administration/PcBuildAnalyticsController';
import SalesReportController from '@/actions/App/Http/Controllers/Administration/SalesReportController';
import WishlistReportController from '@/actions/App/Http/Controllers/Administration/WishlistReportController';
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
import { index as pcBuilderIndex } from '@/routes/pc-builder';
import { index as orderIndex } from '@/routes/orders';
import { index as productIndex } from '@/routes/products';
import { edit as editProfile } from '@/routes/profile';
import { index as wishlistIndex } from '@/routes/wishlist';

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
        ];

        if (!isAdministrator.value || page.props.auth?.can?.manageBranches) {
            items.push({
                title: isAdministrator.value ? 'Stores' : 'Our stores',
                href: isAdministrator.value
                    ? administrationBranchIndex()
                    : branchIndex(),
                icon: MapPin,
            });
        }

        if (isAdministrator.value) {
            const counts = page.props.workQueueCounts ?? {};
            const count = (...kinds) =>
                kinds.reduce((total, kind) => total + (counts[kind] ?? 0), 0);
            if (page.props.auth?.can?.browseProductCatalog) {
                items.push({
                    title: 'Tasks',
                    href: WorkQueueController.index(),
                    icon: ListTodo,
                    pendingCount: Object.values(counts).reduce(
                        (total, value) => total + value,
                        0,
                    ),
                });
            }
            if (page.props.auth?.can?.manageProducts)
                items.push({
                    title: 'Manage products',
                    href: ProductController.index(),
                    icon: PackageSearch,
                    activeRoutes: [
                        ProductController.index(),
                        CategoryController.index(),
                    ],
                });
            if (page.props.auth?.can?.manageProducts)
                items.push({
                    title: 'PC specifications',
                    href: ComponentSpecificationController.index(),
                    icon: PackageSearch,
                });
            if (page.props.auth?.can?.manageProducts)
                items.push({
                    title: 'PC build templates',
                    href: PcBuildTemplateController.index(),
                    icon: PackageSearch,
                });
            if (page.props.auth?.can?.manageProducts)
                items.push({
                    title: 'PC Builder analytics',
                    href: PcBuildAnalyticsController.index(),
                    icon: ChartColumn,
                });
            if (page.props.auth?.can?.viewInventory)
                items.push({
                    title: 'Inventory',
                    href: InventoryController.index(),
                    icon: Boxes,
                });
            if (page.props.auth?.can?.viewInventory)
                items.push({
                    title: 'Stock requests',
                    href: InventoryAdjustmentController.index(),
                    icon: ClipboardList,
                    pendingCount: count('stock_adjustment'),
                });
            if (page.props.auth?.can?.viewInventory)
                items.push({
                    title: 'Stock checks',
                    href: StockReconciliationController.index(),
                    icon: ClipboardCheck,
                });
            if (page.props.auth?.can?.viewInventory)
                items.push({
                    title: 'Damage reports',
                    href: DamageReportController.index(),
                    icon: PackageX,
                    pendingCount: count('damage'),
                });
            if (page.props.auth?.can?.viewInventory)
                items.push({
                    title: 'Supplier returns',
                    href: SupplierReturnController.index(),
                    icon: Truck,
                    pendingCount: count('supplier_return'),
                });
            if (page.props.auth?.can?.viewOrders)
                items.push({
                    title: 'Customer returns',
                    href: CustomerReturnController.index(),
                    icon: Undo2,
                    pendingCount: count(
                        'customer_request',
                        'customer_return',
                        'customer_handover',
                        'customer_resolution',
                        'refund',
                        'refund_reconciliation',
                    ),
                });
            if (page.props.auth?.can?.manageSuppliers)
                items.push({
                    title: 'Suppliers',
                    href: SupplierController.index(),
                    icon: Handshake,
                });
            if (page.props.auth?.can?.viewOrders)
                items.push({
                    title: 'Orders',
                    href: AdministrationOrderController.index(),
                    icon: ReceiptText,
                    pendingCount: count('order'),
                });
            if (page.props.auth?.can?.viewReports)
                items.push({
                    title: 'Sales reports',
                    href: SalesReportController.index(),
                    icon: ChartColumn,
                });
            if (page.props.auth?.can?.viewReports)
                items.push({ title: 'Wishlist analytics', href: WishlistReportController.index(), icon: ChartColumn });
            if (page.props.auth?.can?.viewOrders)
                items.push({
                    title: page.props.auth?.can?.viewStaffReports
                        ? 'Sales by handler'
                        : 'My sales',
                    href: StaffPerformanceController.index(),
                    icon: ChartColumnIncreasing,
                });
            if (page.props.auth?.can?.viewAudit)
                items.push({
                    title: 'Activity log',
                    href: AuditLogController.index(),
                    icon: Logs,
                });
            if (page.props.auth?.can?.manageUsers)
                items.push({
                    title: 'Team',
                    href: TeamController.index(),
                    icon: UserRoundCog,
                });
            if (page.props.auth?.can?.viewReports)
                items.push({
                    title: 'Forecasting',
                    href: ForecastingController.index(),
                    icon: TrendingUp,
                });
            if (page.props.auth?.can?.viewCustomers)
                items.push({
                    title: 'Customers',
                    href: CustomerController.index(),
                    icon: UsersRound,
                });
            if (page.props.auth?.can?.manageProducts)
                items.push({
                    title: 'Chatbot FAQs',
                    href: ChatbotKnowledgeController.index(),
                    icon: MessageSquareText,
                    activeRoutes: [ChatbotKnowledgeController.index()],
                });
        } else {
            items.push({
                title: 'Build a PC',
                href: pcBuilderIndex(),
                icon: PackageSearch,
            });
            if (canUseCustomerCart.value)
                items.push({ title: 'Wishlist', href: wishlistIndex(), icon: Heart, pendingCount: page.props.wishlistCount ?? 0 });
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
