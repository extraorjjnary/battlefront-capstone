<script setup>
import { Form } from "@inertiajs/vue3";
import { Trash2 } from "@lucide/vue";
import { ref } from "vue";
import { toast } from "vue-sonner";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";
import { Spinner } from "@/components/ui/spinner";

defineProps({
    name: { type: String, required: true },
    form: { type: Object, required: true },
    errorBag: { type: String, required: true },
});

const open = ref(false);

function showError(errors) {
    if (errors.deletion) {
        toast.error(errors.deletion);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="destructive-outline" size="sm">
                <Trash2 />
                Move to deleted
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="form"
                :options="{ preserveScroll: true }"
                :error-bag="errorBag"
                @success="open = false"
                @error="showError"
                v-slot="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Move {{ name }} to deleted items?</DialogTitle>
                    <DialogDescription>
                        This removes it from the active catalog and records who
                        moved it. An owner can restore it later as inactive.
                    </DialogDescription>
                </DialogHeader>
                <InputError class="mt-4" :message="errors.deletion" />
                <DialogFooter class="mt-6 gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="processing"
                        >
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive-solid"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        <Trash2 v-else />
                        Move to deleted
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
