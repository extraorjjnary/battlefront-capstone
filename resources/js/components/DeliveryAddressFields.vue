<script setup>
import { computed } from 'vue';
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
});
const emit = defineEmits(['update:modelValue']);
const availableBarangays = computed(() =>
    props.locations.find(
        (location) =>
            location.destination === props.modelValue.delivery_destination,
    )?.barangays ?? [],
);

function fieldName(suffix) {
    return props.namePrefix + '_' + suffix;
}

function fieldId(suffix) {
    return fieldName(suffix).replaceAll('_', '-');
}

function updateDestination(destination) {
    if (destination === props.modelValue.delivery_destination) return;
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
    <div class="grid gap-5">
        <div class="grid gap-2">
            <Label :for="fieldId('destination')">City / Municipality</Label>
            <Select
                :model-value="modelValue.delivery_destination"
                @update:model-value="updateDestination"
            >
                <SelectTrigger
                    :id="fieldId('destination')"
                    class="w-full"
                    :aria-required="required"
                    :aria-invalid="Boolean(errors[fieldName('destination')])"
                >
                    <SelectValue placeholder="Select a supported destination" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="location in locations"
                        :key="location.code"
                        :value="location.destination"
                    >
                        {{ location.destination }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <input
                type="hidden"
                :name="fieldName('destination')"
                :value="modelValue.delivery_destination"
            />
            <InputError :message="errors[fieldName('destination')]" />
            <p class="text-muted-foreground text-xs leading-5">
                Delivery available in selected Negros Occidental areas.
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
