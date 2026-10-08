import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdministrationNotificationController from '@/actions/App/Http/Controllers/Administration/NotificationController';
import AdministrationOrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import OrderController from '@/actions/App/Http/Controllers/OrderController';

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

    return {
        summary,
        isAdministrator,
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
