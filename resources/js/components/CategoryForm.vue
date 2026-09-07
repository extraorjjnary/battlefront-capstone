<script setup>
import { Form, Link } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import CategoryController from '@/actions/App/Http/Controllers/Administration/CategoryController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

defineProps({
    category: { type: Object, default: null },
    form: { type: Object, required: true },
    submitLabel: { type: String, required: true },
});
</script>

<template>
    <Form v-bind="form" class="space-y-6" v-slot="{ errors, processing }">
        <div class="grid gap-2">
            <Label for="name">Category name</Label>
            <Input
                id="name"
                name="name"
                :default-value="category?.name"
                maxlength="255"
                placeholder="Graphics cards"
                :aria-invalid="Boolean(errors.name)"
                required
                autofocus
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="description">Description</Label>
            <Textarea
                id="description"
                name="description"
                :default-value="category?.description"
                maxlength="2000"
                rows="5"
                placeholder="Describe the products grouped in this category."
                :aria-invalid="Boolean(errors.description)"
            />
            <p class="text-muted-foreground text-xs">
                Optional. Keep this useful for customers browsing hardware.
            </p>
            <InputError :message="errors.description" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button :disabled="processing">
                <Spinner v-if="processing" />
                <Save v-else />
                {{ submitLabel }}
            </Button>
            <Button type="button" variant="outline" as-child>
                <Link :href="CategoryController.index()">Cancel</Link>
            </Button>
        </div>
    </Form>
</template>
