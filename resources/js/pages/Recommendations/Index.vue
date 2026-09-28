<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowRight, PackageSearch, SlidersHorizontal } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import ProductImage from '@/components/catalog/ProductImage.vue';
import ProductPrice from '@/components/catalog/ProductPrice.vue';
import StockAvailability from '@/components/catalog/StockAvailability.vue';
import StorefrontHeader from '@/components/StorefrontHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency } from '@/lib/currency';
import { rememberRecommendationVisit } from '@/lib/recommendationReturn';
import { show as productShow } from '@/routes/products';
import {
    index as recommendationIndex,
    results as recommendationResults,
} from '@/routes/recommendations';

const props = defineProps({
    criteria: { type: Object, default: null },
    intended_uses: { type: Array, required: true },
    filter_options: { type: Object, required: true },
    recommendations: { type: Array, default: null },
});

const page = usePage();

const form = useForm({
    budget: props.criteria?.budget ?? '',
    intended_use: props.criteria?.intended_use ?? '',
    preferred_brand: props.criteria?.preferred_brand ?? 'all',
    category_id: String(props.criteria?.category_id ?? 'all'),
    tag_ids: props.criteria?.tag_ids ?? [],
});

const tagError = computed(
    () =>
        form.errors.tag_ids ??
        Object.entries(form.errors).find(([field]) =>
            field.startsWith('tag_ids.'),
        )?.[1],
);

function submit() {
    form.transform((data) => ({
        budget: data.budget,
        intended_use: data.intended_use,
        preferred_brand:
            data.preferred_brand === 'all' ? null : data.preferred_brand,
        category_id: data.category_id === 'all' ? null : data.category_id,
        tag_ids: data.tag_ids,
    })).get(recommendationResults.url(), { preserveState: 'errors' });
}

function reasonText(reason) {
    switch (reason.code) {
        case 'within_budget':
            return `Within budget at ${formatCurrency(reason.value)}`;
        case 'sagay_stock':
            return 'Available in Sagay';
        case 'intended_use_category':
        case 'intended_use_tag':
            return `Fits your intended use: ${reason.value}`;
        case 'selected_category':
            return `Selected category: ${reason.value}`;
        case 'preferred_brand':
            return `Preferred brand: ${reason.value}`;
        case 'preferred_tag':
            return `Preferred tag: ${reason.value}`;
        default:
            return reason.value;
    }
}
</script>

<template>
    <div class="bg-background text-foreground min-h-screen">
        <Head title="Product recommendations">
            <meta
                head-key="description"
                name="description"
                content="Find Battlefront products that match your budget, intended use, and preferences."
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
                <div
                    class="border-primary/30 bg-primary/10 text-primary mb-4 flex size-11 items-center justify-center border"
                >
                    <SlidersHorizontal class="size-5" aria-hidden="true" />
                </div>
                <p
                    class="text-primary text-xs font-bold tracking-[0.2em] uppercase"
                >
                    Customer recommendations
                </p>
                <h1
                    id="recommendation-heading"
                    class="mt-3 max-w-3xl text-3xl font-bold tracking-tight sm:text-4xl"
                >
                    Find hardware for your needs
                </h1>
                <p class="text-muted-foreground mt-4 max-w-2xl leading-7">
                    Tell us your budget and intended use. You can narrow the
                    results by brand, category, and product tags.
                    Recommendations use the current Battlefront catalog and
                    Sagay stock.
                </p>
            </section>

            <div
                class="mt-6 grid gap-8 lg:grid-cols-[minmax(0,21rem)_minmax(0,1fr)] lg:items-start"
            >
                <section
                    id="requirements"
                    class="border-border bg-card border p-5 sm:p-6"
                    aria-labelledby="requirements-heading"
                >
                    <h2 id="requirements-heading" class="text-xl font-bold">
                        Your requirements
                    </h2>
                    <p class="text-muted-foreground mt-2 text-sm leading-6">
                        Budget and intended use are required. Other preferences
                        are optional.
                    </p>

                    <form class="mt-6 grid gap-5" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="recommendation-budget"
                                >Budget (PHP)</Label
                            >
                            <Input
                                id="recommendation-budget"
                                v-model="form.budget"
                                type="number"
                                inputmode="decimal"
                                min="0.01"
                                max="9999999999.99"
                                step="0.01"
                                placeholder="e.g. 15000.00"
                                :aria-invalid="Boolean(form.errors.budget)"
                                required
                            />
                            <InputError :message="form.errors.budget" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="recommendation-use">Intended use</Label>
                            <Select v-model="form.intended_use">
                                <SelectTrigger
                                    id="recommendation-use"
                                    class="w-full"
                                    :aria-invalid="
                                        Boolean(form.errors.intended_use)
                                    "
                                >
                                    <SelectValue
                                        placeholder="Select intended use"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="use in intended_uses"
                                        :key="use.value"
                                        :value="use.value"
                                    >
                                        {{ use.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.intended_use" />
                        </div>

                        <div class="border-border border-t pt-5">
                            <h3 class="font-semibold">Optional preferences</h3>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Leave these open to see more matching products.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="recommendation-brand"
                                >Preferred brand</Label
                            >
                            <Select v-model="form.preferred_brand">
                                <SelectTrigger
                                    id="recommendation-brand"
                                    class="w-full"
                                    :aria-invalid="
                                        Boolean(form.errors.preferred_brand)
                                    "
                                >
                                    <SelectValue placeholder="Any brand" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >Any brand</SelectItem
                                    >
                                    <SelectItem
                                        v-for="brand in filter_options.brands"
                                        :key="brand"
                                        :value="brand"
                                    >
                                        {{ brand }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="form.errors.preferred_brand"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="recommendation-category"
                                >Category</Label
                            >
                            <Select v-model="form.category_id">
                                <SelectTrigger
                                    id="recommendation-category"
                                    class="w-full"
                                    :aria-invalid="
                                        Boolean(form.errors.category_id)
                                    "
                                >
                                    <SelectValue placeholder="Any category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >Any category</SelectItem
                                    >
                                    <SelectItem
                                        v-for="category in filter_options.categories"
                                        :key="category.id"
                                        :value="String(category.id)"
                                    >
                                        {{ category.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.category_id" />
                        </div>

                        <fieldset class="grid gap-2">
                            <legend class="text-sm font-medium">
                                Product tags
                            </legend>
                            <p class="text-muted-foreground text-xs leading-5">
                                Matching tags move suitable products higher in
                                the results.
                            </p>
                            <div
                                v-if="filter_options.tags.length"
                                class="border-border bg-background mt-1 grid max-h-48 gap-1 overflow-y-auto border p-2"
                            >
                                <label
                                    v-for="tag in filter_options.tags"
                                    :key="tag.id"
                                    class="hover:bg-secondary focus-within:ring-ring flex cursor-pointer items-center gap-3 px-2 py-2 text-sm focus-within:ring-2"
                                >
                                    <input
                                        v-model="form.tag_ids"
                                        type="checkbox"
                                        :value="tag.id"
                                        :aria-invalid="Boolean(tagError)"
                                        class="accent-primary size-4 shrink-0"
                                    />
                                    <span>{{ tag.name }}</span>
                                </label>
                            </div>
                            <p v-else class="text-muted-foreground text-sm">
                                No product tags are currently available.
                            </p>
                            <InputError :message="tagError" />
                        </fieldset>

                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="form.processing"
                        >
                            {{
                                form.processing
                                    ? 'Finding products...'
                                    : 'Find recommendations'
                            }}
                            <ArrowRight
                                v-if="!form.processing"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </Button>
                    </form>
                </section>

                <section aria-labelledby="results-heading" aria-live="polite">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p
                                class="text-primary text-xs font-bold tracking-[0.18em] uppercase"
                            >
                                Suitable products
                            </p>
                            <h2
                                id="results-heading"
                                class="mt-2 text-2xl font-bold tracking-tight"
                            >
                                Your recommendations
                            </h2>
                        </div>
                        <p
                            v-if="
                                recommendations?.length &&
                                !Object.keys(form.errors).length
                            "
                            class="text-muted-foreground text-sm"
                        >
                            {{ recommendations.length }} matching
                            {{
                                recommendations.length === 1
                                    ? 'product'
                                    : 'products'
                            }}
                        </p>
                    </div>

                    <div
                        v-if="Object.keys(form.errors).length"
                        class="border-border bg-card mt-5 border p-6"
                    >
                        <h3 class="font-bold">Check your requirements</h3>
                        <p class="text-muted-foreground mt-2 text-sm leading-6">
                            Correct the highlighted fields and try again.
                        </p>
                    </div>
                    <div
                        v-else-if="recommendations === null"
                        class="border-border bg-card mt-5 border p-8 text-center sm:p-12"
                    >
                        <PackageSearch
                            class="text-primary mx-auto size-8"
                            aria-hidden="true"
                        />
                        <h3 class="mt-4 text-lg font-bold">
                            Start with your requirements
                        </h3>
                        <p
                            class="text-muted-foreground mx-auto mt-2 max-w-md text-sm leading-6"
                        >
                            Enter a budget and choose an intended use to see
                            products that fit your needs and are currently
                            available in Sagay.
                        </p>
                    </div>
                    <div
                        v-else-if="recommendations.length === 0"
                        class="border-border bg-card mt-5 border p-8 text-center sm:p-12"
                    >
                        <PackageSearch
                            class="text-primary mx-auto size-8"
                            aria-hidden="true"
                        />
                        <h3 class="mt-4 text-lg font-bold">
                            No matching products right now
                        </h3>
                        <p
                            class="text-muted-foreground mx-auto mt-2 max-w-md text-sm leading-6"
                        >
                            Try a higher budget or a different use, category, or
                            brand. Results reflect current Sagay availability.
                        </p>
                        <Button as-child variant="outline" class="mt-5">
                            <Link :href="recommendationIndex()"
                                >Start over</Link
                            >
                        </Button>
                    </div>
                    <div v-else class="mt-5 grid gap-4 sm:grid-cols-2">
                        <article
                            v-for="recommendation in recommendations"
                            :key="recommendation.product.id"
                            class="border-border bg-card flex min-w-0 flex-col overflow-hidden border"
                        >
                            <div class="aspect-3/2 overflow-hidden">
                                <ProductImage
                                    :image-url="
                                        recommendation.product.image_url
                                    "
                                    :product-name="recommendation.product.name"
                                />
                            </div>
                            <div class="flex flex-1 flex-col gap-4 p-5">
                                <div>
                                    <Badge variant="secondary">
                                        {{
                                            recommendation.product.category.name
                                        }}
                                    </Badge>
                                    <p
                                        v-if="recommendation.product.brand"
                                        class="text-muted-foreground mt-3 text-xs font-semibold tracking-wide uppercase"
                                    >
                                        {{ recommendation.product.brand }}
                                    </p>
                                    <h3
                                        class="mt-1 text-lg leading-snug font-bold"
                                    >
                                        {{ recommendation.product.name }}
                                    </h3>
                                    <p
                                        v-if="
                                            recommendation.product.description
                                        "
                                        class="text-muted-foreground mt-2 line-clamp-2 text-sm leading-6"
                                    >
                                        {{ recommendation.product.description }}
                                    </p>
                                </div>

                                <div
                                    class="flex flex-wrap items-center justify-between gap-3"
                                >
                                    <ProductPrice
                                        :price="recommendation.product.price"
                                        :discount-price="
                                            recommendation.product
                                                .discount_price
                                        "
                                    />
                                    <StockAvailability
                                        :inventory="
                                            recommendation.product.inventory
                                        "
                                    />
                                </div>

                                <div class="border-border border-t pt-4">
                                    <p class="text-sm font-semibold">
                                        Why it matches
                                    </p>
                                    <ul
                                        class="text-muted-foreground mt-2 grid gap-1.5 text-sm leading-5"
                                    >
                                        <li
                                            v-for="reason in recommendation.reasons"
                                            :key="`${reason.code}-${reason.value}`"
                                            class="flex gap-2"
                                        >
                                            <span
                                                class="text-primary"
                                                aria-hidden="true"
                                                >•</span
                                            >
                                            <span>{{
                                                reasonText(reason)
                                            }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <Button
                                    as-child
                                    variant="outline"
                                    class="mt-auto w-full"
                                >
                                    <Link
                                        :href="
                                            productShow(
                                                recommendation.product.id,
                                                {
                                                    query: {
                                                        return_to: page.url,
                                                    },
                                                },
                                            )
                                        "
                                        @click.capture="
                                            rememberRecommendationVisit(
                                                $event,
                                                recommendation.product.id,
                                            )
                                        "
                                    >
                                        View product
                                        <ArrowRight
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </Button>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>
