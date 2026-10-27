<script setup>
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
defineProps({ confirmation: Object });
defineEmits(['answer']);
</script>
<template>
    <Dialog
        :open="Boolean(confirmation)"
        @update:open="!$event && $emit('answer', false)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ confirmation?.title }}</DialogTitle>
                <DialogDescription>{{
                    confirmation?.description
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="$emit('answer', false)"
                    >Cancel</Button
                >
                <Button
                    :variant="
                        confirmation?.destructive
                            ? 'destructive-solid'
                            : 'default'
                    "
                    @click="$emit('answer', true)"
                    >{{ confirmation?.label ?? 'Continue' }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
