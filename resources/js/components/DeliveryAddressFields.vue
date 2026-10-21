<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { index as deliveryLocationsIndex } from '@/routes/delivery-locations';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps({
    modelValue: { type: Object, required: true },
    locations: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    namePrefix: { type: String, default: 'delivery' },
    required: { type: Boolean, default: true },
    nationwide: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'hydrate']);
const regions = ref([]);
const provinces = ref([]);
const cities = ref([]);
const barangays = ref([]);
const regionCode = ref('');
const provinceCode = ref('');
const loading = ref(false);
const loadError = ref('');
let requestVersion = 0;
const displayedLocations = computed(() =>
    props.nationwide ? cities.value : props.locations,
);
const availableBarangays = computed(() =>
    props.nationwide
        ? barangays.value
        : (props.locations.find(
              (location) =>
                  location.destination ===
                  props.modelValue.delivery_destination,
          )?.barangays ?? []),
);

async function lookup(level, parentCode) {
    const response = await fetch(
        deliveryLocationsIndex.url({
            query: { level, parent_code: parentCode || undefined },
        }),
        { headers: { Accept: 'application/json' } },
    );
    if (!response.ok)
        throw new Error('Unable to load delivery locations. Please retry.');
    return (await response.json()).data.locations;
}

function emitGeography(destination = '') {
    emit('update:modelValue', {
        ...props.modelValue,
        delivery_region_code: regionCode.value,
        delivery_province_code: provinceCode.value || null,
        delivery_city_code: destination,
        delivery_destination: destination,
        delivery_barangay_code: '',
    });
}

async function runLocationRequest(operation) {
    const version = ++requestVersion;
    loading.value = true;
    loadError.value = '';
    try {
        await operation(version);
    } catch (error) {
        if (version === requestVersion) loadError.value = error.message;
    } finally {
        if (version === requestVersion) loading.value = false;
    }
}

async function loadRegionOptions(version) {
    const region = regionCode.value;
    const province = provinceCode.value;
    const [provinceOptions, cityOptions] = await Promise.all([
        lookup('provinces', region),
        lookup('cities', province || region),
    ]);
    if (version !== requestVersion) return;
    provinces.value = provinceOptions;
    cities.value = cityOptions;
}

async function updateRegion(code) {
    regionCode.value = code;
    provinceCode.value = '';
    provinces.value = [];
    cities.value = [];
    barangays.value = [];
    emitGeography();
    await runLocationRequest(loadRegionOptions);
}

async function updateProvince(code) {
    provinceCode.value = code === 'independent' ? '' : code;
    cities.value = [];
    barangays.value = [];
    emitGeography();
    await runLocationRequest(loadRegionOptions);
}

async function loadBarangays(code) {
    await runLocationRequest(async (version) => {
        const options = await lookup('barangays', code);
        if (version === requestVersion) barangays.value = options;
    });
}

async function initialize() {
    await runLocationRequest(async (version) => {
        const regionOptions = await lookup('regions');
        if (version !== requestVersion) return;
        regions.value = regionOptions;
        const saved = props.modelValue.delivery_destination;
        const code = /^\d{10}$/.test(saved ?? '')
            ? saved
            : props.locations.find((location) => location.destination === saved)
                  ?.code;
        if (code) {
            const [city] = await lookup('city', code);
            if (version !== requestVersion) return;
            regionCode.value = city.region_code;
            provinceCode.value = city.province_code ?? '';
            await loadRegionOptions(version);
            if (version !== requestVersion) return;
            const options = await lookup('barangays', code);
            if (version !== requestVersion) return;
            barangays.value = options;
            emit(
                props.namePrefix === 'default_delivery'
                    ? 'hydrate'
                    : 'update:modelValue',
                {
                    ...props.modelValue,
                    delivery_region_code: city.region_code,
                    delivery_province_code: city.province_code,
                    delivery_city_code: code,
                    delivery_destination: code,
                },
            );
        } else if (regionCode.value) {
            await loadRegionOptions(version);
        }
    });
}
onMounted(() => {
    if (props.nationwide) initialize();
});
watch(
    () => props.modelValue.delivery_destination,
    (destination) => {
        if (
            props.nationwide &&
            !destination &&
            !props.modelValue.delivery_region_code
        ) {
            requestVersion++;
            regionCode.value = '';
            provinceCode.value = '';
            provinces.value = [];
            cities.value = [];
            barangays.value = [];
            loading.value = false;
        }
    },
);

function fieldName(suffix) {
    return props.namePrefix + '_' + suffix;
}

function fieldId(suffix) {
    return fieldName(suffix).replaceAll('_', '-');
}

function updateDestination(destination) {
    if (destination === props.modelValue.delivery_destination) return;
    if (props.nationwide) {
        barangays.value = [];
        emitGeography(destination);
        if (destination) return loadBarangays(destination);
        requestVersion++;
        return;
    }
    emit('update:modelValue', {
        ...props.modelValue,
        delivery_destination: destination,
        delivery_barangay_code: '',
    });
}

function updateBarangay(code) {
    emit('update:modelValue', {
        ...props.modelValue,
        delivery_barangay_code: code,
    });
}

function updateDetails(details) {
    emit('update:modelValue', {
        ...props.modelValue,
        delivery_address_details: details,
    });
}
</script>

<template>
    <div class="grid min-w-0 gap-5">
        <template v-if="nationwide">
            <div class="grid min-w-0 gap-2">
                <Label :for="fieldId('region_code')">Region</Label>
                <Select
                    :model-value="regionCode"
                    @update:model-value="updateRegion"
                >
                    <SelectTrigger
                        :id="fieldId('region_code')"
                        class="w-full min-w-0"
                        ><SelectValue placeholder="Select a region"
                    /></SelectTrigger>
                    <SelectContent
                        ><SelectItem
                            v-for="region in regions"
                            :key="region.code"
                            :value="region.code"
                            >{{ region.name }}</SelectItem
                        ></SelectContent
                    >
                </Select>
                <InputError :message="errors[fieldName('region_code')]" />
            </div>
            <div v-if="provinces.length" class="grid min-w-0 gap-2">
                <Label :for="fieldId('province_code')"
                    >Province / Administrative area</Label
                >
                <Select
                    :model-value="provinceCode || 'independent'"
                    @update:model-value="updateProvince"
                >
                    <SelectTrigger
                        :id="fieldId('province_code')"
                        class="w-full min-w-0"
                        ><SelectValue
                            placeholder="Select a province or independent locality"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="independent"
                            >Independent cities / localities (no
                            province)</SelectItem
                        >
                        <SelectItem
                            v-for="province in provinces"
                            :key="province.code"
                            :value="province.code"
                            >{{ province.name }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError :message="errors[fieldName('province_code')]" />
            </div>
            <input
                type="hidden"
                :name="fieldName('region_code')"
                :value="regionCode"
            />
            <input
                type="hidden"
                :name="fieldName('province_code')"
                :value="provinceCode"
            />
            <input
                type="hidden"
                :name="fieldName('city_code')"
                :value="modelValue.delivery_city_code ?? ''"
            />
            <p
                v-if="loading"
                class="text-muted-foreground text-sm"
                role="status"
            >
                Loading delivery locations…
            </p>
            <div v-if="loadError" role="alert" class="text-destructive text-sm">
                {{ loadError }}
                <button type="button" class="underline" @click="initialize">
                    Retry
                </button>
            </div>
        </template>
        <div class="grid gap-2">
            <Label :for="fieldId('destination')">City / Municipality</Label>
            <Select
                :model-value="modelValue.delivery_destination"
                @update:model-value="updateDestination"
            >
                <SelectTrigger
                    :id="fieldId('destination')"
                    class="w-full min-w-0"
                    :aria-required="required"
                    :aria-invalid="Boolean(errors[fieldName('destination')])"
                >
                    <SelectValue placeholder="Select a city or municipality" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="location in displayedLocations"
                        :key="location.code"
                        :value="
                            nationwide ? location.code : location.destination
                        "
                    >
                        {{ nationwide ? location.name : location.destination }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <input
                type="hidden"
                :name="fieldName('destination')"
                :value="modelValue.delivery_destination"
            />
            <InputError :message="errors[fieldName('destination')]" />
            <InputError
                v-if="nationwide"
                :message="errors[fieldName('city_code')]"
            />
            <p
                v-if="
                    nationwide &&
                    regionCode &&
                    !loading &&
                    !cities.length &&
                    !loadError
                "
                class="text-muted-foreground text-sm"
            >
                Choose a province to load its cities and municipalities.
            </p>
            <p class="text-muted-foreground text-xs leading-5">
                Select a Philippine address. Automatic checkout requires a
                configured delivery quote.
            </p>
        </div>
        <div class="grid gap-2">
            <Label :for="fieldId('barangay_code')">Barangay</Label>
            <Select
                :model-value="modelValue.delivery_barangay_code"
                :disabled="!modelValue.delivery_destination"
                @update:model-value="updateBarangay"
            >
                <SelectTrigger
                    :id="fieldId('barangay_code')"
                    class="w-full"
                    :aria-required="required"
                    :aria-invalid="Boolean(errors[fieldName('barangay_code')])"
                >
                    <SelectValue placeholder="Select a barangay" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="barangay in availableBarangays"
                        :key="barangay.code"
                        :value="barangay.code"
                    >
                        {{ barangay.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <input
                type="hidden"
                :name="fieldName('barangay_code')"
                :value="modelValue.delivery_barangay_code"
            />
            <InputError :message="errors[fieldName('barangay_code')]" />
        </div>
        <div class="grid gap-2">
            <Label :for="fieldId('address_details')">
                House / Building / Street / Landmark
            </Label>
            <Textarea
                :id="fieldId('address_details')"
                :model-value="modelValue.delivery_address_details"
                :name="fieldName('address_details')"
                @update:model-value="updateDetails"
                maxlength="255"
                autocomplete="street-address"
                placeholder="House number, building, street, or nearby landmark"
                :aria-invalid="Boolean(errors[fieldName('address_details')])"
                :required="required"
            />
            <slot name="details-help" />
            <InputError :message="errors[fieldName('address_details')]" />
        </div>
    </div>
</template>
