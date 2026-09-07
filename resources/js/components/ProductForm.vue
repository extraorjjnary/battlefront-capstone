<script setup>
import { Form, Link } from '@inertiajs/vue3';
import { Image, Save } from '@lucide/vue';
import { ref } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import ProductController from '@/actions/App/Http/Controllers/Administration/ProductController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps({
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    product: { type: Object, default: null },
    form: { type: Object, required: true },
    submitLabel: { type: String, required: true },
});

const categoryId = ref(
    String(props.product?.category_id ?? props.categories[0]?.id ?? ''),
);
const isFeatured = ref(props.product?.is_featured ?? false);
const selectedTagIds = ref([...(props.product?.tag_ids ?? [])]);
const imageUrl = ref(props.product?.image_url ?? '');

function setTag(tagId, checked) {
    if (checked === true && !selectedTagIds.value.includes(tagId)) {
        selectedTagIds.value.push(tagId);
    }

    if (checked !== true) {
        selectedTagIds.value = selectedTagIds.value.filter(
            (selectedTagId) => selectedTagId !== tagId,
        );
    }
}

function firstTagError(errors) {
    return (
        errors.tag_ids ??
        Object.entries(errors).find(([key]) => key.startsWith('tag_ids.'))?.[1]
    );
}

function hideBrokenImage(event) {
    event.currentTarget.hidden = true;
}
</script>

<template>
    <Form v-bind="form" class="space-y-8" v-slot="{ errors, processing }">
        <section aria-labelledby="product-details-heading" class="space-y-5">
            <div>
                <h2 id="product-details-heading" class="font-semibold">
                    Product details
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Provide the catalog information customers use to identify
                    this item.
                </p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="grid gap-2 md:col-span-2">
                    <Label for="name">Product name</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="product?.name"
                        maxlength="255"
                        placeholder="AMD Ryzen 7 9700X"
                        :aria-invalid="Boolean(errors.name)"
                        required
                        autofocus
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="brand">Brand</Label>
                    <Input
                        id="brand"
                        name="brand"
                        :default-value="product?.brand"
                        maxlength="255"
                        placeholder="AMD"
                        :aria-invalid="Boolean(errors.brand)"
                        required
                    />
                    <InputError :message="errors.brand" />
                </div>

                <div class="grid gap-2">
                    <Label for="category">Category</Label>
                    <input
                        type="hidden"
                        name="category_id"
                        :value="categoryId"
                    />
                    <Select
                        v-model="categoryId"
                        :disabled="categories.length === 0"
                    >
                        <SelectTrigger
                            id="category"
                            class="w-full"
                            :aria-invalid="Boolean(errors.category_id)"
                        >
                            <SelectValue placeholder="Select a category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                                {{ category.is_active ? '' : '(Inactive)' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.category_id" />
                    <p v-if="categories.length === 0" class="text-sm">
                        Add a category before creating a product.
                        <Link
                            :href="CategoryController.create()"
                            class="text-primary underline underline-offset-4"
                        >
                            Add category
                        </Link>
                    </p>
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label for="description">Description</Label>
                    <Textarea
                        id="description"
                        name="description"
                        :default-value="product?.description"
                        maxlength="5000"
                        rows="5"
                        placeholder="Describe the product and its important specifications."
                        :aria-invalid="Boolean(errors.description)"
                    />
                    <InputError :message="errors.description" />
                </div>
            </div>
        </section>

        <section
            aria-labelledby="pricing-heading"
            class="border-border border-t pt-8"
        >
            <div>
                <h2 id="pricing-heading" class="font-semibold">
                    Pricing and presentation
                </h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Prices are stored and displayed in Philippine pesos.
                </p>
            </div>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="price">Regular price (PHP)</Label>
                    <Input
                        id="price"
                        name="price"
                        type="number"
                        min="0"
                        max="9999999999.99"
                        step="0.01"
                        inputmode="decimal"
                        :default-value="product?.price"
                        placeholder="0.00"
                        :aria-invalid="Boolean(errors.price)"
                        required
                    />
                    <InputError :message="errors.price" />
                </div>

                <div class="grid gap-2">
                    <Label for="discount_price">Discount price (PHP)</Label>
                    <Input
                        id="discount_price"
                        name="discount_price"
                        type="number"
                        min="0"
                        max="9999999999.99"
                        step="0.01"
                        inputmode="decimal"
                        :default-value="product?.discount_price"
                        placeholder="Optional"
                        :aria-invalid="Boolean(errors.discount_price)"
                    />
                    <InputError :message="errors.discount_price" />
                </div>

                <div class="grid gap-2 md:col-span-2">
                    <Label for="image_url">Product image URL</Label>
                    <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                        <div>
                            <Input
                                id="image_url"
                                v-model="imageUrl"
                                name="image_url"
                                type="url"
                                maxlength="255"
                                placeholder="https://example.com/product.jpg"
                                :aria-invalid="Boolean(errors.image_url)"
                            />
                            <InputError
                                class="mt-2"
                                :message="errors.image_url"
                            />
                        </div>
                        <div
                            class="border-border bg-muted/40 relative flex aspect-square items-center justify-center overflow-hidden border"
                        >
                            <Image class="text-muted-foreground size-7" />
                            <img
                                v-if="imageUrl"
                                :key="imageUrl"
                                :src="imageUrl"
                                alt="Product image preview"
                                class="absolute inset-0 size-full object-contain"
                                @error="hideBrokenImage"
                            />
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <input
                        type="hidden"
                        name="is_featured"
                        :value="isFeatured ? '1' : '0'"
                    />
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="is_featured"
                            v-model="isFeatured"
                            class="mt-0.5"
                        />
                        <div>
                            <Label for="is_featured">Featured product</Label>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Mark this product for featured catalog
                                placement.
                            </p>
                        </div>
                    </div>
                    <InputError class="mt-2" :message="errors.is_featured" />
                </div>
            </div>
        </section>

        <section
            aria-labelledby="tags-heading"
            class="border-border border-t pt-8"
        >
            <div>
                <h2 id="tags-heading" class="font-semibold">Product tags</h2>
                <p class="text-muted-foreground mt-1 text-sm">
                    Select the approved tags used by catalog and recommendation
                    workflows.
                </p>
            </div>

            <div
                v-if="tags.length > 0"
                class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <label
                    v-for="tag in tags"
                    :key="tag.id"
                    :for="`tag-${tag.id}`"
                    class="border-border hover:bg-accent flex cursor-pointer items-center gap-3 border p-3 text-sm transition-colors"
                >
                    <Checkbox
                        :id="`tag-${tag.id}`"
                        :model-value="selectedTagIds.includes(tag.id)"
                        @update:model-value="setTag(tag.id, $event)"
                    />
                    {{ tag.name }}
                </label>
                <input
                    v-for="tagId in selectedTagIds"
                    :key="`selected-${tagId}`"
                    type="hidden"
                    name="tag_ids[]"
                    :value="tagId"
                />
            </div>
            <p v-else class="text-muted-foreground mt-5 text-sm">
                No product tags are available yet. This product can still be
                saved without tags.
            </p>
            <InputError class="mt-2" :message="firstTagError(errors)" />
        </section>

        <div
            class="border-border flex flex-wrap items-center gap-3 border-t pt-6"
        >
            <Button :disabled="processing || categories.length === 0">
                <Spinner v-if="processing" />
                <Save v-else />
                {{ submitLabel }}
            </Button>
            <Button type="button" variant="outline" as-child>
                <Link :href="ProductController.index()">Cancel</Link>
            </Button>
        </div>
    </Form>
</template>
