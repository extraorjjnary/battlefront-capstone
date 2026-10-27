import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

export function useUnsavedEditor(dirty, confirm) {
    let removeNavigationGuard;
    let approvedUrl = null;
    function leave(event) {
        if (dirty.value) {
            event.preventDefault();
            event.returnValue = '';
        }
    }
    async function mayDiscard() {
        return (
            !dirty.value ||
            (await confirm({
                title: 'Discard unsaved changes?',
                description:
                    'Your changes have not been saved. The saved version will remain unchanged.',
                label: 'Discard changes',
                destructive: true,
            }))
        );
    }
    onMounted(() => {
        window.addEventListener('beforeunload', leave);
        removeNavigationGuard = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (
                visit.method !== 'get' ||
                visit.url.href === window.location.href ||
                !dirty.value
            )
                return;
            if (approvedUrl === visit.url.href) {
                approvedUrl = null;
                return;
            }
            event.preventDefault();
            void mayDiscard().then((allowed) => {
                if (allowed) {
                    approvedUrl = visit.url.href;
                    router.visit(visit.url, {
                        method: 'get',
                        data: visit.data,
                        preserveScroll: visit.preserveScroll,
                        preserveState: visit.preserveState,
                        replace: visit.replace,
                    });
                }
            });
        });
    });
    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', leave);
        removeNavigationGuard?.();
    });
    return { mayDiscard };
}
