<script setup>
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fieldValue } from './specificationFields';

const props = defineProps({
    id: String,
    label: String,
    options: Array,
    kind: { type: String, default: 'text' },
    modelValue: [String, Number],
});
const emit = defineEmits(['update:modelValue']);
const customRequested = ref(false);
const presetIndex = computed(() => props.options.indexOf(props.modelValue));
const custom = computed(
    () =>
        customRequested.value ||
        (props.modelValue != null &&
            props.modelValue !== '' &&
            presetIndex.value < 0),
);
const selection = computed(() =>
    custom.value
        ? 'custom'
        : presetIndex.value < 0
          ? ''
          : String(presetIndex.value),
);
watch(
    () => props.id,
    () => {
        customRequested.value = false;
    },
);
function select(value) {
    customRequested.value = value === 'custom';
    if (value !== 'custom') {
        emit(
            'update:modelValue',
            value === '' ? null : props.options[Number(value)],
        );
    }
}
</script>

<template>
    <div class="grid min-w-0 gap-2">
        <select
            :id="id"
            :value="selection"
            class="border-input bg-background focus-visible:ring-ring h-10 w-full min-w-0 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            @change="select($event.target.value)"
        >
            <option value="">Unknown / not verified</option>
            <option
                v-for="(option, index) in options"
                :key="index"
                :value="String(index)"
            >
                {{ option }}
            </option>
            <option value="custom">Custom value</option>
        </select>
        <template v-if="custom">
            <Label :for="`${id}-custom`"
                >Custom {{ label.toLowerCase() }}</Label
            >
            <Input
                :id="`${id}-custom`"
                :type="kind === 'number' ? 'number' : 'text'"
                :min="kind === 'number' ? 0 : undefined"
                :step="kind === 'number' ? 'any' : undefined"
                :model-value="modelValue ?? ''"
                placeholder="Enter the verified source value"
                @update:model-value="
                    emit('update:modelValue', fieldValue(kind, $event))
                "
            />
        </template>
    </div>
</template>
