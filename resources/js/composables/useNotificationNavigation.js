import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdministrationNotificationController from '@/actions/App/Http/Controllers/Administration/NotificationController';
import AdministrationOrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';
import InventoryAdjustmentController from '@/actions/App/Http/Controllers/Administration/InventoryAdjustmentController';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import SupplierReturnController from '@/actions/App/Http/Controllers/Administration/SupplierReturnController';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';

export function useNotificationNavigation() {
    const page = usePage();
    const isAdministrator = computed(
        () => page.props.auth?.can?.accessAdministration === true,
    );
    const controller = computed(() =>
        isAdministrator.value
            ? AdministrationNotificationController
            : NotificationController,
    );
    const summary = computed(
        () => page.props.notificationSummary ?? { unread_count: 0, recent: [] },
    );

    function destination(notification) {
        if (isAdministrator.value && notification.subject) {
            const { type, id } = notification.subject;
            const options = { query: { case: id } };
            switch (type) {
                case 'stock_adjustment':
                    return InventoryAdjustmentController.index(options);
                case 'damage_report':
                    return DamageReportController.index(options);
                case 'supplier_return':
                    return SupplierReturnController.index(options);
                case 'customer_return':
                    return CustomerReturnController.index(options);
                case 'customer_return_request':
                    return CustomerReturnController.index({
                        query: { request_id: id },
                    });
            }
        }
        if (!notification.order) return null;
        return isAdministrator.value
            ? AdministrationOrderController.show(notification.order.id)
            : OrderController.show(notification.order.id);
    }

    return {
        summary,
        isAdministrator,
        destination,
        index: (options) => controller.value.index(options),
        summaryUrl: () => controller.value.summary.url(),
        read: (id) => controller.value.update(id),
        readAll: () => controller.value.readAll(),
        order: (id) =>
            isAdministrator.value
                ? AdministrationOrderController.show(id)
                : OrderController.show(id),
    };
}
