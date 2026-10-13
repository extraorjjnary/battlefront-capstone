<script setup>
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeliveryAddressFields from '@/components/DeliveryAddressFields.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

const props = defineProps({
    canManageDefaultDeliveryAddress: { type: Boolean, required: true },
    defaultDeliveryAddress: { type: String, default: '' },
    deliveryLocations: { type: Array, default: () => [] },
    defaultDeliveryAddressPrefill: {
        type: Object,
        default: () => ({
            delivery_destination: '',
            delivery_barangay_code: '',
            delivery_address_details: '',
        }),
    },
    searchRecommendationsEnabled: { type: Boolean, default: true },
    productViewRecommendationsEnabled: { type: Boolean, default: true },
    personalizedRecommendationsEnabled: { type: Boolean, default: true },
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
const defaultAddress = ref({ ...props.defaultDeliveryAddressPrefill });
const defaultAddressAction = ref('keep');
const hasAddress = computed(() =>
    Boolean(props.defaultDeliveryAddress) ||
    Object.values(defaultAddress.value).some(Boolean),
);

function updateDefaultAddress(address) {
    defaultAddress.value = address;
    defaultAddressAction.value = 'save';
}

function clearDefaultAddress() {
    defaultAddress.value = {
        delivery_destination: '',
        delivery_barangay_code: '',
        delivery_address_details: '',
    };
    defaultAddressAction.value = 'clear';
}

function profileSaved() {
    defaultAddress.value = { ...props.defaultDeliveryAddressPrefill };
    defaultAddressAction.value = 'keep';
}
const personalizedRecommendationsEnabled = ref(
    props.personalizedRecommendationsEnabled,
);
const searchRecommendationsEnabled = ref(props.searchRecommendationsEnabled);
const productViewRecommendationsEnabled = ref(
    props.productViewRecommendationsEnabled,
);
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Head title="Profile settings" />
        <h1 class="sr-only">Profile settings</h1>
        <Heading
            variant="small"
            title="Profile"
            description="Update your account details and default delivery address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
            @success="profileSaved"
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
                class="grid gap-4"
            >
                <p class="font-medium">
                    Default delivery address (optional)
                </p>
                <input
                    type="hidden"
                    name="default_delivery_address_action"
                    :value="defaultAddressAction"
                />
                <DeliveryAddressFields
                    nationwide
                    @hydrate="defaultAddress = $event"
                    :model-value="defaultAddress"
                    @update:model-value="updateDefaultAddress"
                    :locations="props.deliveryLocations"
                    :errors="errors"
                    name-prefix="default_delivery"
                    :required="defaultAddressAction === 'save'"
                />
                <p
                    v-if="props.defaultDeliveryAddress && defaultAddressAction !== 'clear'"
                    class="text-muted-foreground text-sm"
                >
                    Saved address: {{ props.defaultDeliveryAddress }}
                    Your saved address is kept until you replace or clear it.
                    Choose all three fields to replace it.
                </p>
                <p
                    v-if="defaultAddressAction === 'clear'"
                    class="text-muted-foreground text-sm"
                    role="status"
                >
                    Your saved address will be cleared when you save.
                </p>
                <p class="text-muted-foreground text-sm">
                    This address will pre-fill delivery checkout and can still
                    be changed for each order.
                </p>
                <InputError
                    :message="errors.default_delivery_address_action"
                />
                <Button
                    v-if="hasAddress && defaultAddressAction !== 'clear'"
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="processing"
                    @click="clearDefaultAddress"
                >
                    Clear saved address
                </Button>
            </div>

            <div
                v-if="props.canManageDefaultDeliveryAddress"
                class="grid gap-2"
            >
                <Label for="personalized-recommendations-enabled"
                    >Personalized recommendations</Label
                >
                <input
                    type="hidden"
                    name="personalized_recommendations_enabled"
                    value="0"
                />
                <label
                    class="text-muted-foreground flex items-start gap-3 text-sm"
                >
                    <input
                        id="personalized-recommendations-enabled"
                        type="checkbox"
                        name="personalized_recommendations_enabled"
                        value="1"
                        v-model="personalizedRecommendationsEnabled"
                        class="border-input accent-primary mt-0.5 size-4"
                    />
                    <span
                        >Use your activity and shopping history for personalized
                        product recommendations. Turning this off hides
                        recommendation sections on your dashboard and product
                        catalog, pauses personalized suggestions, and stops
                        recording searches and views. Popular picks can still
                        appear on product pages and in your cart. Saved search
                        and view history is kept until its 90-day expiry and can
                        be used again if you turn personalization back on before
                        then. Your cart and order records remain available for
                        store services.</span
                    >
                </label>
                <div class="border-border grid gap-3 border-l pl-4">
                    <p class="text-muted-foreground text-sm">
                        {{
                            personalizedRecommendationsEnabled
                                ? 'Choose which browsing signals to use while personalization is on.'
                                : 'Turn on personalized recommendations to change which browsing signals may be used. These signals are currently off.'
                        }}
                    </p>
                    <input
                        type="hidden"
                        name="search_recommendations_enabled"
                        value="0"
                        :disabled="!personalizedRecommendationsEnabled"
                    />
                    <label
                        class="text-muted-foreground flex items-start gap-3 text-sm"
                    >
                        <input
                            id="search-recommendations-enabled"
                            type="checkbox"
                            name="search_recommendations_enabled"
                            value="1"
                            :checked="
                                personalizedRecommendationsEnabled &&
                                searchRecommendationsEnabled
                            "
                            @change="
                                searchRecommendationsEnabled =
                                    $event.target.checked
                            "
                            :disabled="!personalizedRecommendationsEnabled"
                            class="border-input accent-primary mt-0.5 size-4"
                        />
                        <span
                            >Use catalog searches. Turning this off pauses
                            search-based suggestions and recording. Saved search
                            history is kept until its 90-day expiry and used
                            again if you turn this back on before then.</span
                        >
                    </label>
                    <input
                        type="hidden"
                        name="product_view_recommendations_enabled"
                        value="0"
                        :disabled="!personalizedRecommendationsEnabled"
                    />
                    <label
                        class="text-muted-foreground flex items-start gap-3 text-sm"
                    >
                        <input
                            id="product-view-recommendations-enabled"
                            type="checkbox"
                            name="product_view_recommendations_enabled"
                            value="1"
                            :checked="
                                personalizedRecommendationsEnabled &&
                                productViewRecommendationsEnabled
                            "
                            @change="
                                productViewRecommendationsEnabled =
                                    $event.target.checked
                            "
                            :disabled="!personalizedRecommendationsEnabled"
                            class="border-input accent-primary mt-0.5 size-4"
                        />
                        <span
                            >Use products you view. Turning this off pauses
                            view-based suggestions and recording. Saved view
                            history is kept until its 90-day expiry and used
                            again if you turn this back on before then.</span
                        >
                    </label>
                </div>
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
