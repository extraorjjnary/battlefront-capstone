let recommendationVisit = null;

export function rememberRecommendationVisit(event, productId) {
    if (
        event.button !== 0 ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey
    ) {
        return;
    }

    recommendationVisit = { productId, recordedAt: Date.now() };
}

export function consumeRecommendationVisit(productId) {
    const visit = recommendationVisit;
    recommendationVisit = null;

    return (
        visit?.productId === productId &&
        Date.now() - visit.recordedAt < 60_000 &&
        typeof window !== 'undefined' &&
        window.history.length > 1
    );
}

export function recommendationReturnUrl(productPageUrl, resultsPath) {
    const base = 'https://battlefront.invalid';
    const returnTo = new URL(productPageUrl, base).searchParams.get(
        'return_to',
    );

    if (!returnTo) {
        return null;
    }

    let destination;

    try {
        destination = new URL(returnTo, base);
    } catch {
        return null;
    }

    if (
        destination.origin !== base ||
        destination.pathname !== resultsPath ||
        !destination.searchParams.get('budget') ||
        !destination.searchParams.get('intended_use')
    ) {
        return null;
    }

    return destination.pathname + destination.search;
}
