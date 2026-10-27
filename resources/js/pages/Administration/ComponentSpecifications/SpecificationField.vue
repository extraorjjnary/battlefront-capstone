<script setup>
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fieldValue } from './specificationFields';

const props = defineProps({
    field: Object,
    modelValue: [String, Number, Boolean, Array, Object],
    id: String,
});
const emit = defineEmits(['update:modelValue']);
const newKey = ref('');
const keyError = ref('');
const entries = computed(() => Object.entries(props.modelValue ?? {}));
const supportedValue = computed(() => {
    const value = props.modelValue;
    if (value == null) return true;
    if (props.field.kind === 'list') return Array.isArray(value);
    if (['counts', 'object'].includes(props.field.kind))
        return (
            typeof value === 'object' &&
            (!Array.isArray(value) || value.length === 0)
        );
    if (props.field.kind === 'boolean') return typeof value === 'boolean';
    return typeof value === 'string' || typeof value === 'number';
});
function set(value) {
    emit('update:modelValue', value);
}
function setItem(index, value) {
    const items = [...(props.modelValue ?? [])];
    items[index] = value;
    set(items);
}
function addCount() {
    const key = newKey.value.trim();
    if (!key || Object.hasOwn(props.modelValue ?? {}, key)) {
        keyError.value = 'Enter a unique connector type or position.';
        return;
    }
    set({ ...props.modelValue, [key]: null });
    newKey.value = '';
    keyError.value = '';
}
function removeCount(key) {
    const values = { ...props.modelValue };
    delete values[key];
    set(values);
}
</script>

<template>
    <div class="grid min-w-0 gap-2">
        <Label :for="id">{{ field.label }}</Label>
        <p
            v-if="!supportedValue"
            :id="id"
            class="text-muted-foreground text-sm"
        >
            This saved value uses a different format. It is preserved; use
            Advanced attributes to review it.
        </p>
        <select
            v-else-if="field.kind === 'boolean'"
            :id="id"
            :value="modelValue == null ? '' : String(modelValue)"
            class="border-input bg-background h-10 rounded-md border px-3 text-sm"
            @change="set(fieldValue('boolean', $event.target.value))"
        >
            <option value="">Unknown / not verified</option>
            <option value="true">Yes</option>
            <option value="false">No</option>
        </select>
        <template v-else-if="field.kind === 'list'">
            <p class="text-muted-foreground text-xs">
                {{
                    modelValue == null
                        ? 'Unknown / not verified'
                        : modelValue.length
                          ? `${modelValue.length} entries`
                          : 'Verified: no entries'
                }}
            </p>
            <div
                v-for="(item, index) in modelValue ?? []"
                :key="index"
                class="border-border grid gap-3 rounded-md border p-3"
            >
                <SpecificationField
                    :id="`${id}-${index}`"
                    :field="field.item"
                    :model-value="item"
                    @update:model-value="setItem(index, $event)"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :aria-label="`Remove ${field.label} entry ${index + 1}`"
                    @click="set(modelValue.filter((_, i) => i !== index))"
                    >Remove entry</Button
                >
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    :id="id"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="
                        set([
                            ...(modelValue ?? []),
                            field.item.kind === 'object' ? {} : null,
                        ])
                    "
                    >Add entry</Button
                >
                <Button
                    v-if="modelValue == null"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="set([])"
                    >Confirm none</Button
                >
                <Button
                    v-else
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="set(null)"
                    >Mark unknown</Button
                >
            </div>
        </template>
        <template v-else-if="field.kind === 'counts'">
            <p class="text-muted-foreground text-xs">
                {{
                    modelValue == null
                        ? 'Unknown / not verified'
                        : entries.length
                          ? 'Use exact source names and verified counts.'
                          : 'Verified: none'
                }}
            </p>
            <div
                v-for="[key, count] in entries"
                :key="key"
                class="flex items-center gap-2"
            >
                <Label
                    :for="`${id}-${key}`"
                    class="min-w-0 flex-1 break-words"
                    >{{ key }}</Label
                >
                <Input
                    :id="`${id}-${key}`"
                    class="w-24"
                    type="number"
                    min="0"
                    step="1"
                    placeholder="Unknown"
                    :model-value="count ?? ''"
                    @update:model-value="
                        set({
                            ...modelValue,
                            [key]: fieldValue('number', $event),
                        })
                    "
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :aria-label="`Remove ${key}`"
                    @click="removeCount(key)"
                    >Remove</Button
                >
            </div>
            <div class="flex gap-2">
                <Input
                    :id="id"
                    v-model="newKey"
                    placeholder="Connector type or position"
                    @keydown.enter.prevent="addCount"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addCount"
                    >Add entry</Button
                >
            </div>
            <p v-if="keyError" role="alert" class="text-destructive text-xs">
                {{ keyError }}
            </p>
            <Button
                v-if="modelValue == null"
                type="button"
                variant="ghost"
                size="sm"
                @click="set({})"
                >Confirm none</Button
            >
            <Button
                v-else
                type="button"
                variant="ghost"
                size="sm"
                @click="set(null)"
                >Mark unknown</Button
            >
        </template>
        <div v-else-if="field.kind === 'object'" class="grid gap-4">
            <SpecificationField
                v-for="child in field.fields"
                :id="`${id}-${child.key}`"
                :key="child.key"
                :field="child"
                :model-value="modelValue?.[child.key]"
                @update:model-value="
                    set({ ...modelValue, [child.key]: $event })
                "
            />
        </div>
        <Input
            v-else
            :id="id"
            :type="field.kind === 'number' ? 'number' : 'text'"
            :min="field.kind === 'number' ? 0 : undefined"
            :step="field.kind === 'number' ? 'any' : undefined"
            :model-value="modelValue ?? ''"
            placeholder="Unknown / not verified"
            @update:model-value="set(fieldValue(field.kind, $event))"
        />
    </div>
</template>
