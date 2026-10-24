<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });
const props = defineProps({ class: {} });
const emit = defineEmits(['change']);
const input = ref(null);
const filenames = ref([]);
let form;

function clear() {
    input.value.value = '';
    filenames.value = [];
}

function selectFiles(event) {
    filenames.value = Array.from(event.target.files ?? [], (file) => file.name);
    emit('change', event);
}

onMounted(() => {
    form = input.value.form;
    form?.addEventListener('reset', clear);
});
onUnmounted(() => form?.removeEventListener('reset', clear));
defineExpose({ clear });
</script>

<template>
    <div class="min-w-0 space-y-2">
        <input
            v-bind="$attrs"
            ref="input"
            type="file"
            data-slot="file-input"
            :class="
                cn(
                    'border-input bg-card text-foreground file:bg-primary file:text-primary-foreground enabled:hover:file:bg-primary/90 focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 block min-h-11 w-full min-w-0 cursor-pointer rounded-md border p-1 text-sm shadow-xs transition-colors outline-none file:mr-3 file:min-h-9 file:cursor-pointer file:rounded-sm file:border-0 file:px-3 file:py-2 file:text-sm file:font-semibold focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 disabled:file:cursor-not-allowed',
                    props.class,
                )
            "
            @change="selectFiles"
        />
        <ul
            v-if="filenames.length > 1"
            class="text-muted-foreground space-y-1 text-xs"
            aria-live="polite"
            aria-label="Selected files"
        >
            <li
                v-for="(filename, index) in filenames"
                :key="index"
                class="break-all"
            >
                {{ filename }}
            </li>
        </ul>
    </div>
</template>
