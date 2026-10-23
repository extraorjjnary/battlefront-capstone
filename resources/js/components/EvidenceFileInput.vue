<script setup>
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

defineProps({
    label: { type: String, required: true },
    disabled: Boolean,
    imagesOnly: Boolean,
    multiple: { type: Boolean, default: true },
    name: { type: String, default: 'files[]' },
});
const emit = defineEmits(['update:files']);
const input = ref(null);
const selected = ref(false);
function clearSelection() {
    if (input.value) {
        input.value.value = '';
    }
    selected.value = false;
    emit('update:files', []);
}
function selectFiles(event) {
    const files = Array.from(event.target.files ?? []);
    selected.value = files.length > 0;
    emit('update:files', files);
}
</script>

<template>
    <div class="min-w-0 space-y-2">
        <label class="block max-w-full min-w-0 text-sm"
            >{{ label }}
            <input
                ref="input"
                :name="name"
                type="file"
                :multiple="multiple"
                required
                :accept="
                    imagesOnly
                        ? '.jpg,.jpeg,.png,.webp'
                        : '.jpg,.jpeg,.png,.webp,.pdf'
                "
                :disabled="disabled"
                class="mt-1 block w-full max-w-full min-w-0"
                @change="selectFiles"
            />
        </label>
        <Button
            v-if="selected"
            type="button"
            variant="outline"
            :disabled="disabled"
            @click="clearSelection"
            >Clear selection</Button
        >
        <p class="text-muted-foreground max-w-xl text-xs leading-5">
            {{ imagesOnly ? 'JPG, PNG or WebP; up to 5 MB each.' : '' }}
            {{
                multiple ? 'Up to five files in total.' : 'One image required.'
            }}
            Clearing keeps uploaded files.
        </p>
    </div>
</template>
