<script setup>
import { Form, Link } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref } from 'vue';
import { index } from '@/actions/App/Http/Controllers/Administration/BranchController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps({
    branch: { type: Object, default: null },
    form: { type: Object, required: true },
    submitLabel: { type: String, required: true },
});

const published = ref(props.branch?.is_published ?? false);
const requiresDetail = (field) =>
    published.value &&
    (!props.branch?.is_published || Boolean(props.branch?.[field]));
</script>

<template>
    <Form v-bind="form" class="space-y-6" v-slot="{ errors, processing }">
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">Store name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="branch?.name"
                    maxlength="255"
                    required
                    autofocus
                    :aria-invalid="Boolean(errors.name)"
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="city">City / location</Label>
                <Input
                    id="city"
                    name="city"
                    :default-value="branch?.city"
                    maxlength="255"
                    required
                    :readonly="branch?.is_operational"
                    :aria-invalid="Boolean(errors.city)"
                />
                <InputError :message="errors.city" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="address">Address</Label>
            <Textarea
                id="address"
                name="address"
                :default-value="branch?.address"
                rows="3"
                maxlength="255"
                :required="requiresDetail('address')"
                :aria-invalid="Boolean(errors.address)"
            />
            <InputError :message="errors.address" />
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="contact-number">Phone</Label>
                <Input
                    id="contact-number"
                    name="contact_number"
                    type="tel"
                    :default-value="branch?.contact_number"
                    maxlength="20"
                    :aria-invalid="Boolean(errors.contact_number)"
                />
                <InputError :message="errors.contact_number" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    :default-value="branch?.email"
                    maxlength="255"
                    :aria-invalid="Boolean(errors.email)"
                />
                <InputError :message="errors.email" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="operating-hours">Store hours</Label>
            <Input
                id="operating-hours"
                name="operating_hours"
                :default-value="branch?.operating_hours"
                maxlength="255"
                :required="requiresDetail('operating_hours')"
                :aria-invalid="Boolean(errors.operating_hours)"
            />
            <p class="text-muted-foreground text-xs">
                Include days when hours vary. Address and hours are required
                before publishing.
            </p>
            <InputError :message="errors.operating_hours" />
        </div>

        <fieldset class="border-border space-y-4 border-t pt-5">
            <legend class="font-medium">
                Map coordinates
                <span class="text-muted-foreground font-normal"
                    >(optional)</span
                >
            </legend>
            <p id="coordinate-help" class="text-muted-foreground text-sm">
                Use verified coordinates, or leave both blank.
            </p>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="latitude">Latitude</Label>
                    <Input
                        id="latitude"
                        name="latitude"
                        type="number"
                        step="0.0000001"
                        min="-90"
                        max="90"
                        :default-value="branch?.latitude"
                        aria-describedby="coordinate-help"
                        :aria-invalid="Boolean(errors.latitude)"
                    />
                    <InputError :message="errors.latitude" />
                </div>
                <div class="grid gap-2">
                    <Label for="longitude">Longitude</Label>
                    <Input
                        id="longitude"
                        name="longitude"
                        type="number"
                        step="0.0000001"
                        min="-180"
                        max="180"
                        :default-value="branch?.longitude"
                        aria-describedby="coordinate-help"
                        :aria-invalid="Boolean(errors.longitude)"
                    />
                    <InputError :message="errors.longitude" />
                </div>
            </div>
        </fieldset>

        <div class="border-border space-y-2 border-t pt-5">
            <input
                type="hidden"
                name="is_published"
                :value="published ? '1' : '0'"
            />
            <div class="flex items-center gap-3">
                <Checkbox
                    id="is-published"
                    v-model="published"
                    :disabled="branch?.is_operational"
                    :aria-invalid="Boolean(errors.is_published)"
                    aria-describedby="publication-help"
                />
                <Label for="is-published">Published</Label>
            </div>
            <p id="publication-help" class="text-muted-foreground text-sm">
                {{
                    branch?.is_operational
                        ? 'Sagay City must remain visible to customers.'
                        : 'Verify details before publishing. Unpublished stores are visible only to administrators.'
                }}
            </p>
            <InputError :message="errors.is_published" />
            <p
                v-if="!branch?.is_operational"
                class="text-muted-foreground text-xs"
            >
                Orders and stock are managed at Sagay City.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button :disabled="processing">
                <Spinner v-if="processing" />
                <Save v-else />
                {{ submitLabel }}
            </Button>
            <Button type="button" variant="outline" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </Form>
</template>
