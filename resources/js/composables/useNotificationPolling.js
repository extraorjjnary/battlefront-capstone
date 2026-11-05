import { router, useHttp, usePage } from '@inertiajs/vue3';
import {
    useDocumentVisibility,
    useEventListener,
    useIntervalFn,
} from '@vueuse/core';
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
    const idleTimeout = 120000;
    let lastActivity = Date.now();
    let idleTimer;
    let refreshAfterNavigation = false;

    function canPoll() {
        return (
            !disposed &&
            !unauthorized &&
            !navigating &&
            !!page.props.auth?.user?.id &&
            visibility.value === 'visible' &&
            Date.now() - lastActivity < idleTimeout
        );
    }

    async function fetchSummary() {
        if (!canPoll() || loading.value) return;
        const userId = page.props.auth.user.id;
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
                        updatePolling();
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
                updatePolling();
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

    const { pause, resume, isActive } = useIntervalFn(fetchSummary, 30000, {
        immediate: false,
    });
    const removeStartListener = router.on('start', () => {
        navigating = true;
        generation++;
        http.cancel();
        loading.value = false;
        updatePolling();
    });
    const removeFinishListener = router.on('finish', () => {
        navigating = false;
        updatePolling();
        if (refreshAfterNavigation) {
            refreshAfterNavigation = false;
            void fetchSummary();
        }
    });

    function updatePolling() {
        if (canPoll()) {
            if (!isActive.value) resume();
            if (idleTimer === undefined) {
                idleTimer = setTimeout(
                    () => {
                        idleTimer = undefined;
                        updatePolling();
                    },
                    idleTimeout - (Date.now() - lastActivity),
                );
            }
        } else {
            pause();
            clearTimeout(idleTimer);
            idleTimer = undefined;
        }
    }

    function recordActivity() {
        if (
            disposed ||
            unauthorized ||
            !page.props.auth?.user?.id ||
            visibility.value !== 'visible'
        )
            return false;
        const wasIdle = Date.now() - lastActivity >= idleTimeout;
        lastActivity = Date.now();
        if (wasIdle) updatePolling();
        return wasIdle;
    }

    function refreshOnReturn() {
        if (navigating) refreshAfterNavigation = true;
        else void fetchSummary();
    }

    function refresh() {
        recordActivity();
        return fetchSummary();
    }

    useEventListener(
        [
            'pointerdown',
            'pointermove',
            'keydown',
            'touchstart',
            'wheel',
            'scroll',
            'focus',
        ],
        () => {
            if (recordActivity()) refreshOnReturn();
        },
        { passive: true },
    );
    watch(
        visibility,
        (current, previous) => {
            if (current === 'visible' && previous !== undefined) {
                lastActivity = Date.now();
                updatePolling();
                refreshOnReturn();
            } else updatePolling();
        },
        { immediate: true, flush: 'sync' },
    );
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
            refreshAfterNavigation = false;
            updatePolling();
        },
    );
    onScopeDispose(() => {
        disposed = true;
        updatePolling();
        generation++;
        http.cancel();
        removeStartListener();
        removeFinishListener();
    });

    return { loading, error, refresh };
}
