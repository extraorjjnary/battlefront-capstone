<script setup>
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { edit } from '@/routes/profile';

const props = defineProps({
    canManageDefaultDeliveryAddress: { type: Boolean, required: true },
    defaultDeliveryAddress: { type: String, default: '' },
    searchRecommendationsEnabled: { type: Boolean, default: true },
    productViewRecommendationsEnabled: { type: Boolean, default: true },
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});
const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your account details and default delivery address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div
                v-if="props.canManageDefaultDeliveryAddress"
                class="grid gap-2"
            >
                <Label for="default-delivery-address">
                    Default delivery address (optional)
                </Label>
                <Textarea
                    id="default-delivery-address"
                    name="default_delivery_address"
                    maxlength="255"
                    autocomplete="street-address"
                    :default-value="props.defaultDeliveryAddress"
                    :aria-invalid="Boolean(errors.default_delivery_address)"
                    placeholder="House or building, street, barangay, city, and province"
                />
                <p class="text-muted-foreground text-sm">
                    This address will pre-fill delivery checkout and can still
                    be changed for each order.
                </p>
                <InputError
                    class="mt-2"
                    :message="errors.default_delivery_address"
                />
            </div>

            <div
                v-if="props.canManageDefaultDeliveryAddress"
                class="grid gap-2"
            >
                <Label for="search-recommendations-enabled"
                    >Search-based recommendations</Label
                >
                <input
                    type="hidden"
                    name="search_recommendations_enabled"
                    value="0"
                />
                <input
                    type="hidden"
                    name="product_view_recommendations_enabled"
                    value="0"
                />
                <label
                    class="text-muted-foreground flex items-start gap-3 text-sm"
                >
                    <input
                        id="search-recommendations-enabled"
                        type="checkbox"
                        name="search_recommendations_enabled"
                        value="1"
                        :checked="props.searchRecommendationsEnabled"
                        class="border-input accent-primary mt-0.5 size-4"
                    />
                    <span
                        >Use your catalog searches to personalize product
                        recommendations. Turn this off to stop recording
                        searches and delete saved search history. Search history
                        is kept for up to 90 days while enabled.</span
                    >
                </label>
                <label
                    class="text-muted-foreground flex items-start gap-3 text-sm"
                >
                    <input
                        id="product-view-recommendations-enabled"
                        type="checkbox"
                        name="product_view_recommendations_enabled"
                        value="1"
                        :checked="props.productViewRecommendationsEnabled"
                        class="border-input accent-primary mt-0.5 size-4"
                    />
                    <span
                        >Use products you view to personalize recommendations.
                        Turn this off to stop recording views and delete saved
                        view history. View history is kept for up to 90 days
                        while enabled.</span
                    >
                </label>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    class="cursor-pointer"
                    >Save</Button
                >
            </div>
        </Form>
    </div>
</template>
