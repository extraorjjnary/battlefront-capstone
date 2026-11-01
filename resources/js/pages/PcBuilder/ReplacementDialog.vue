<script setup>
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';

defineProps({
    product: Object,
    items: Array,
    selectedKeys: Array,
    preview: Object,
    loading: Boolean,
    busy: Boolean,
    error: String,
});
defineEmits(['cancel', 'toggle', 'confirm', 'retry']);
</script>

<template>
    <Dialog :open="Boolean(product)" @update:open="!$event && $emit('cancel')">
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Replace part and resolve conflicts</DialogTitle>
                <DialogDescription
                    >Choose which selected parts to remove before adding
                    {{ product?.name }}. Nothing changes until you
                    confirm.</DialogDescription
                >
            </DialogHeader>
            <fieldset :disabled="busy" class="space-y-3">
                <legend class="mb-2 text-sm font-medium">
                    Parts to remove
                </legend>
                <label
                    v-for="item in items"
                    :key="item.key"
                    class="border-border flex items-start gap-3 rounded-md border p-3 text-sm"
                >
                    <input
                        type="checkbox"
                        :checked="selectedKeys.includes(item.key)"
                        class="mt-1 size-4"
                        @change="$emit('toggle', item.key)"
                    />
                    <span
                        >{{ item.name }}
                        <span
                            v-if="product?.affected_keys?.includes(item.key)"
                            class="text-muted-foreground block text-xs"
                            >Affected by the compatibility findings</span
                        ></span
                    >
                </label>
            </fieldset>
            <div aria-live="polite" :aria-busy="loading" class="text-sm">
                <p v-if="loading">Checking the resulting build…</p>
                <p v-else-if="error" class="text-destructive">
                    {{ error }}
                    <Button variant="outline" size="sm" @click="$emit('retry')"
                        >Try again</Button
                    >
                </p>
                <p v-else-if="preview?.selectable">
                    The resulting selected parts pass the applicable
                    compatibility checks.
                </p>
                <ul v-else class="space-y-2">
                    <li
                        v-for="reason in preview?.selection_reasons ?? []"
                        :key="reason"
                    >
                        {{ reason }}
                    </li>
                </ul>
            </div>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="busy"
                    @click="$emit('cancel')"
                    >Cancel</Button
                >
                <Button
                    :disabled="busy || loading || !preview?.selectable"
                    @click="$emit('confirm')"
                    >Confirm replacement</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
