<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import { Button } from '@/components/ui/button';
import { store as storeRecommendationInteraction } from '@/routes/recommendations/interactions';
import { index as recommendationIndex } from '@/routes/recommendations';
import { show as productShow } from '@/routes/products';

const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, required: true },
    placement: { type: String, required: true },
    showViewAll: { type: Boolean, default: true },
    recommendations: { type: Array, default: () => [] },
});

const page = usePage();
const recommendationCards = ref([]);
const dismissedProductIds = ref(new Set());
const visibleRecommendations = computed(() =>
    props.recommendations.filter(
        (recommendation) =>
            !dismissedProductIds.value.has(recommendation.product.id),
    ),
);
const visibleCards = new Set();
const impressionTimers = new Map();
let observer;

function createEventId() {
    if (!globalThis.crypto?.getRandomValues) {
        return null;
    }

    if (globalThis.crypto.randomUUID) {
        return globalThis.crypto.randomUUID();
    }

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

function recordInteraction(recommendation, eventType, position) {
    const eventId = createEventId();

    if (!eventId) {
        return;
    }

    const route = storeRecommendationInteraction.post();
    const xsrfToken = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

    fetch(route.url, {
        method: route.method.toUpperCase(),
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken,
        },
        body: JSON.stringify({
            event_id: eventId,
            product_id: recommendation.product.id,
            event_type: eventType,
            placement: props.placement,
            position,
            reason_code: recommendation.reasons[0]?.code ?? null,
        }),
    }).catch(() => {});
}

function recommendationPosition(recommendation) {
    return (
        visibleRecommendations.value.findIndex(
            (item) => item.product.id === recommendation.product.id,
        ) + 1
    );
}

function dismissRecommendation(recommendation, eventType) {
    recordInteraction(
        recommendation,
        eventType,
        recommendationPosition(recommendation),
    );

    const nextDismissedIds = new Set(dismissedProductIds.value);
    nextDismissedIds.add(recommendation.product.id);
    dismissedProductIds.value = nextDismissedIds;

    try {
        const productIds = [...nextDismissedIds].slice(-100);
        localStorage.setItem(dismissalStorageKey(), JSON.stringify(productIds));
    } catch {
        // Recommendation feedback should never interrupt shopping.
    }
}

function dismissalStorageKey() {
    const customerId = page.props.auth?.user?.id;

    return customerId
        ? `battlefront:dismissed-recommendations:v1:customer:${customerId}`
        : 'battlefront:dismissed-recommendations:v1:guest';
}

function loadDismissedRecommendations() {
    try {
        const storedProductIds = JSON.parse(
            localStorage.getItem(dismissalStorageKey()) ?? '[]',
        );
        dismissedProductIds.value = new Set(
            Array.isArray(storedProductIds)
                ? storedProductIds.filter((id) => Number.isInteger(id))
                : [],
        );
    } catch {
        dismissedProductIds.value = new Set();
    }
}

function clearImpressionObservation() {
    observer?.disconnect();
    observer = undefined;
    visibleCards.clear();
    impressionTimers.forEach((timer) => window.clearTimeout(timer));
    impressionTimers.clear();
}

function observeRecommendationCards() {
    if (!('IntersectionObserver' in window)) {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                const card = entry.target;

                if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
                    visibleCards.add(card);

                    if (!impressionTimers.has(card)) {
                        const timer = window.setTimeout(() => {
                            const index = Number(
                                card.dataset.recommendationPosition,
                            );
                            const productId = Number(
                                card.dataset.recommendationProductId,
                            );
                            const recommendation =
                                visibleRecommendations.value.find(
                                    (item) => item.product.id === productId,
                                );

                            if (
                                visibleCards.has(card) &&
                                recommendation &&
                                card.dataset.impressionTracked !== 'true'
                            ) {
                                card.dataset.impressionTracked = 'true';
                                recordInteraction(
                                    recommendation,
                                    'impression',
                                    index,
                                );
                            }

                            impressionTimers.delete(card);
                        }, 1000);

                        impressionTimers.set(card, timer);
                    }

                    return;
                }

                visibleCards.delete(card);
                const timer = impressionTimers.get(card);

                if (timer) {
                    window.clearTimeout(timer);
                    impressionTimers.delete(card);
                }
            });
        },
        { threshold: 0.5 },
    );

    recommendationCards.value.forEach((card) => {
        if (card) {
            observer.observe(card);
        }
    });
}

onMounted(() => {
    loadDismissedRecommendations();
    observeRecommendationCards();
});

watch(visibleRecommendations, async () => {
    clearImpressionObservation();
    await nextTick();
    observeRecommendationCards();
});

onBeforeUnmount(() => {
    clearImpressionObservation();
});
</script>

<template>
    <section
        v-if="visibleRecommendations.length > 0"
        class="border-border border-t pt-10"
        aria-labelledby="recommendation-section-heading"
    >
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="text-primary text-xs font-bold tracking-[0.18em] uppercase"
                >
                    Product recommendations
                </p>
                <h2
                    id="recommendation-section-heading"
                    class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                >
                    {{ title }}
                </h2>
                <p class="text-muted-foreground mt-2 text-sm leading-6">
                    {{ description }}
                </p>
            </div>

            <Button v-if="showViewAll" as-child variant="outline">
                <Link :href="recommendationIndex()">
                    View all recommendations
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </Button>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="(recommendation, index) in visibleRecommendations"
                :key="recommendation.product.id"
                ref="recommendationCards"
                :data-recommendation-position="index + 1"
                :data-recommendation-product-id="recommendation.product.id"
                class="border-border bg-card flex min-w-0 flex-col overflow-hidden border"
            >
                <Link
                    :href="productShow(recommendation.product.id)"
                    @click="
                        recordInteraction(recommendation, 'click', index + 1)
                    "
                    class="focus-visible:ring-ring block focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    :aria-label="`View ${recommendation.product.name}`"
                >
                    <div class="aspect-3/2 overflow-hidden">
                        <ProductImage
                            :image-url="recommendation.product.image_url"
                            :product-name="recommendation.product.name"
                        />
                    </div>
                </Link>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div class="min-w-0">
                        <p
                            class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                        >
                            {{ recommendation.product.category.name }}
                        </p>
                        <Link
                            :href="productShow(recommendation.product.id)"
                            @click="
                                recordInteraction(
                                    recommendation,
                                    'click',
                                    index + 1,
                                )
                            "
                            class="focus-visible:ring-ring mt-1 block rounded-sm text-base leading-snug font-bold focus-visible:ring-2 focus-visible:outline-none"
                        >
                            {{ recommendation.product.name }}
                        </Link>
                    </div>

                    <p
                        class="text-muted-foreground line-clamp-2 text-xs leading-5"
                    >
                        {{ recommendation.reasons[0]?.value }}
                    </p>

                    <div
                        class="mt-auto flex flex-wrap items-end justify-between gap-3"
                    >
                        <ProductPrice
                            :price="recommendation.product.price"
                            :discount-price="
                                recommendation.product.discount_price
                            "
                        />
                        <StockAvailability
                            :inventory="recommendation.product.inventory"
                        />
                    </div>

                    <div
                        class="border-border flex flex-wrap gap-2 border-t pt-3"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="min-h-10 px-2 text-xs"
                            @click="
                                dismissRecommendation(recommendation, 'dismiss')
                            "
                        >
                            Hide
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="min-h-10 px-2 text-xs"
                            @click="
                                dismissRecommendation(
                                    recommendation,
                                    'report_wrong',
                                )
                            "
                        >
                            Report a problem
                        </Button>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
