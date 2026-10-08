import { router } from '@inertiajs/vue3';
import { onScopeDispose } from 'vue';

export function useProductPrefetch() {
    let hoverTimeout;

    function cancelProductPrefetch() {
        clearTimeout(hoverTimeout);
        hoverTimeout = undefined;
    }

    function prefetchProduct(event, href) {
        cancelProductPrefetch();

        if (
            event.pointerType !== 'mouse' ||
            !window.matchMedia('(any-hover: hover)').matches
        ) {
            return;
        }

        hoverTimeout = setTimeout(() => {
            hoverTimeout = undefined;

            if (!router.getCached(href) && !router.getPrefetching(href)) {
                router.prefetch(href);
            }
        }, 200);
    }

    onScopeDispose(cancelProductPrefetch);

    return { prefetchProduct, cancelProductPrefetch };
}
