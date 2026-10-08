import { router, useHttp, usePage } from '@inertiajs/vue3';
import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import { onScopeDispose, ref, watch } from 'vue';

export function useNotificationPolling(navigation) {
    const page = usePage();
    const http = useHttp({});
    const visibility = useDocumentVisibility();
    const loading = ref(false);
    const error = ref('');
    let generation = 0;
    let disposed = false;
    let unauthorized = false;
    let navigating = false;

    async function refresh() {
        const userId = page.props.auth?.user?.id;
        if (
            disposed ||
            unauthorized ||
            navigating ||
            loading.value ||
            !userId ||
            visibility.value !== 'visible'
        )
            return;
        const requestGeneration = generation;
        const administrator = navigation.isAdministrator.value;
        loading.value = true;
        error.value = '';

        try {
            const response = await http.get(navigation.summaryUrl(), {
                onHttpException: ({ status }) => {
                    if (
                        requestGeneration === generation &&
                        (status === 401 || status === 403)
                    ) {
                        unauthorized = true;
                        pause();
                    }
                },
            });
            if (disposed || requestGeneration !== generation) return;
            if (
                response.meta.user_id !== userId ||
                response.meta.audience !==
                    (administrator ? 'administrator' : 'customer')
            ) {
                unauthorized = true;
                pause();
                error.value =
                    'Your session changed. Refresh the page to update notifications.';
                router.replaceProp('notificationSummary', (current, props) => {
                    if (
                        disposed ||
                        requestGeneration !== generation ||
                        props.auth?.user?.id !== userId ||
                        (props.auth?.can?.accessAdministration === true) !==
                            administrator
                    )
                        return current;
                    return { unread_count: 0, recent: [] };
                });
                return;
            }
            router.replaceProp('notificationSummary', (current, props) => {
                if (
                    disposed ||
                    requestGeneration !== generation ||
                    props.auth?.user?.id !== userId ||
                    (props.auth?.can?.accessAdministration === true) !==
                        administrator
                )
                    return current;
                return response.data;
            });
        } catch {
            if (!disposed && requestGeneration === generation) {
                error.value = 'Notifications could not be refreshed.';
            }
        } finally {
            if (requestGeneration === generation) loading.value = false;
        }
    }

    const { pause, resume } = useIntervalFn(refresh, 30000, {
        immediate: false,
    });
    const removeStartListener = router.on('start', () => {
        navigating = true;
        generation++;
        http.cancel();
        loading.value = false;
    });
    const removeFinishListener = router.on('finish', () => {
        navigating = false;
    });

    function updatePolling() {
        if (
            page.props.auth?.user?.id &&
            visibility.value === 'visible' &&
            !unauthorized
        )
            resume();
        else pause();
    }

    watch(visibility, updatePolling, { immediate: true });
    watch(
        [
            () => page.props.auth?.user?.id,
            () => navigation.isAdministrator.value,
        ],
        () => {
            generation++;
            http.cancel();
            loading.value = false;
            error.value = '';
            unauthorized = false;
            updatePolling();
        },
    );
    onScopeDispose(() => {
        disposed = true;
        generation++;
        http.cancel();
        removeStartListener();
        removeFinishListener();
    });

    return { loading, error, refresh };
}
