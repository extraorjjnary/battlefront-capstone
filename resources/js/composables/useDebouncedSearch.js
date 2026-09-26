import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

export function useDebouncedSearch({
    initialSearch = '',
    currentSearch,
    route,
    query = () => ({}),
    debounceMs = 400,
    reset = [],
    preserveScroll = true,
}) {
    const search = ref(initialSearch ?? '');
    const isSearching = ref(false);
    let debounceTimeout;
    let cancelToken;

    function cancelPendingSearch() {
        clearTimeout(debounceTimeout);
        cancelToken?.cancel();
        cancelToken = undefined;
        isSearching.value = false;
    }

    function visitSearch() {
        const normalizedSearch = search.value.trim();

        if (normalizedSearch === (currentSearch() ?? '')) {
            return;
        }

        cancelToken?.cancel();

        router.visit(
            route({
                query: {
                    ...query(),
                    q: normalizedSearch || undefined,
                },
            }),
            {
                preserveScroll,
                preserveState: true,
                replace: true,
                reset,
                onCancelToken: (token) => {
                    cancelToken = token;
                },
                onStart: () => {
                    isSearching.value = true;
                },
                onFinish: () => {
                    cancelToken = undefined;
                    isSearching.value = false;
                },
            },
        );
    }

    function clearSearch() {
        search.value = '';
    }

    watch(search, () => {
        cancelPendingSearch();
        debounceTimeout = setTimeout(visitSearch, debounceMs);
    });

    onBeforeUnmount(cancelPendingSearch);

    return {
        search,
        isSearching,
        clearSearch,
        cancelPendingSearch,
    };
}
