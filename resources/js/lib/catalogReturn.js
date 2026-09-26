let catalogVisit = null;

export function rememberCatalogVisit(event, productId) {
    if (
        event.button !== 0 ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey
    ) {
        return;
    }

    catalogVisit = { productId, recordedAt: Date.now() };
}

export function consumeCatalogVisit(productId) {
    const visit = catalogVisit;
    catalogVisit = null;

    return (
        visit?.productId === productId &&
        Date.now() - visit.recordedAt < 60_000 &&
        typeof window !== 'undefined' &&
        window.history.length > 1
    );
}
