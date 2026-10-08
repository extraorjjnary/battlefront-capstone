<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, PackageSearch } from '@lucide/vue';
import { computed } from 'vue';
import RecommendationSection from '@/components/recommendations/RecommendationSection.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Button } from '@/components/ui/button';
import RecommendationPreferenceController from '@/actions/App/Http/Controllers/RecommendationPreferenceController';
import { login } from '@/routes';
import { index as productIndex } from '@/routes/products';

defineProps({
    is_personalized: { type: Boolean, default: false },
    has_featured_fallback: { type: Boolean, default: false },
    can_enable_personalization: { type: Boolean, default: false },
    recommendations: { type: Array, default: () => [] },
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Recommended products">
            <meta
                head-key="description"
                name="description"
                content="Product suggestions based on recent catalog activity, shopping patterns, and current Sagay availability."
            />
        </Head>

        <StorefrontHeader active-section="recommendations" />

        <main class="mx-auto max-w-7xl px-5 py-8 sm:px-8 sm:py-10">
            <section
                class="border-border bg-card relative overflow-hidden border px-6 py-8 sm:px-10"
                aria-labelledby="recommendation-heading"
            >
                <div
                    class="bg-primary absolute inset-y-0 left-0 w-1.5"
                    aria-hidden="true"
                />
                <div class="max-w-3xl">
                    <p
                        class="text-primary text-xs font-bold tracking-[0.2em] uppercase"
                    >
                        Product recommendations
                    </p>
                    <h1
                        id="recommendation-heading"
                        class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        {{
                            is_personalized
                                ? 'Recommended for you'
                                : has_featured_fallback
                                  ? 'Popular and featured products'
                                  : 'Popular products'
                        }}
                    </h1>
                    <p class="text-muted-foreground mt-4 max-w-2xl leading-7">
                        {{
                            is_personalized
                                ? 'Suggestions reflect recent searches, products you viewed, items in your cart, completed orders, and products bought by customers with overlapping purchase histories.'
                                : has_featured_fallback
                                  ? 'Browse popular products and currently available featured picks from Battlefront.'
                                  : 'These products appear often in completed Battlefront orders and are currently available in Sagay.'
                        }}
                    </p>
                </div>
            </section>

            <section
                v-if="can_enable_personalization"
                class="border-border bg-card mt-6 flex flex-col gap-4 border p-5 sm:flex-row sm:items-center sm:justify-between"
                role="status"
            >
                <div>
                    <h2 class="font-semibold">
                        Personalized recommendations are off
                    </h2>
                    <p
                        class="text-muted-foreground mt-1 max-w-2xl text-sm leading-6"
                    >
                        These suggestions use popular and featured products.
                        Turn personalization on to use your eligible browsing
                        and shopping activity. You can also change this in
                        Profile settings.
                    </p>
                </div>
                <Button as-child class="shrink-0">
                    <Link
                        :href="RecommendationPreferenceController.enable.url()"
                        method="post"
                        as="button"
                    >
                        Turn on personalization
                    </Link>
                </Button>
            </section>

            <section
                v-if="recommendations.length === 0"
                class="border-border bg-card mt-8 border p-8 text-center sm:p-12"
                aria-live="polite"
            >
                <PackageSearch
                    class="text-primary mx-auto size-8"
                    aria-hidden="true"
                />
                <h2 class="mt-4 text-lg font-bold">
                    No recommendations are available yet
                </h2>
                <p
                    class="text-muted-foreground mx-auto mt-2 max-w-md text-sm leading-6"
                >
                    {{
                        is_personalized
                            ? 'Try searching the catalog, viewing products, or adding an item to your cart to shape future suggestions.'
                            : 'Popular products will appear as completed orders build up. You can browse the current catalog in the meantime.'
                    }}
                </p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <Button as-child variant="outline">
                        <Link :href="productIndex()">Browse products</Link>
                    </Button>
                    <Button v-if="!isAuthenticated" as-child>
                        <Link :href="login()">Sign in</Link>
                    </Button>
                </div>
            </section>

            <RecommendationSection
                v-else
                :recommendations="recommendations"
                placement="recommendations"
                :show-view-all="false"
                title="Your current suggestions"
                description="Reasons on each card explain which catalog or shopping signal influenced it. Hide a product or report a suggestion that does not fit."
            />

            <div class="mt-8 flex justify-end">
                <Button as-child variant="outline">
                    <Link :href="productIndex()">
                        Browse all products
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </main>
    </div>
</template>
