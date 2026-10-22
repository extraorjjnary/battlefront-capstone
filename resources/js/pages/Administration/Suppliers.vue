<script setup>
import PageHeader from '@/components/PageHeader.vue';
import InlineEditor from '@/components/administration/InlineEditor.vue';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import SupplierController from '@/actions/App/Http/Controllers/Administration/SupplierController';
import SupplierReturnController from '@/actions/App/Http/Controllers/Administration/SupplierReturnController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

const props = defineProps({ suppliers: Object, filters: Object });
const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? 'all');
const supplierDrafts = reactive({});
watch(
    () => props.suppliers.data,
    (suppliers) => {
        for (const supplier of suppliers) {
            supplierDrafts[supplier.id] ??= {
                is_active: supplier.is_active ? '1' : '0',
            };
        }
    },
    { immediate: true },
);
function applyFilters() {
    router.get(SupplierController.index(), {
        q: search.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
    });
}
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Suppliers', href: SupplierController.index() }],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-5xl min-w-0 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Suppliers" />
        <PageHeader title="Suppliers"
            ><template #actions
                ><Button variant="outline" as-child
                    ><Link :href="SupplierReturnController.index()"
                        >Supplier returns</Link
                    ></Button
                ></template
            ></PageHeader
        >
        <Form
            v-bind="SupplierController.store.form()"
            v-slot="{ errors, processing }"
            class="surface-card border-border bg-card grid max-w-full min-w-0 gap-4 border p-5 sm:grid-cols-2"
        >
            <h2 class="text-lg font-semibold sm:col-span-2">Add supplier</h2>
            <div class="space-y-2">
                <Label for="supplier-name">Name</Label
                ><Input
                    class="min-h-11"
                    id="supplier-name"
                    name="name"
                    required
                /><InputError :message="errors.name" />
            </div>
            <div class="space-y-2">
                <Label for="supplier-contact">Contact person</Label
                ><Input
                    class="min-h-11"
                    id="supplier-contact"
                    name="contact_name"
                /><InputError :message="errors.contact_name" />
            </div>
            <div class="space-y-2">
                <Label for="supplier-number">Phone</Label
                ><Input
                    class="min-h-11"
                    id="supplier-number"
                    name="contact_number"
                /><InputError :message="errors.contact_number" />
            </div>
            <div class="space-y-2">
                <Label for="supplier-email">Email</Label
                ><Input
                    class="min-h-11"
                    id="supplier-email"
                    name="email"
                    type="email"
                /><InputError :message="errors.email" />
            </div>
            <Button class="min-h-11" type="submit" :disabled="processing"
                >Add supplier</Button
            >
        </Form>
        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyFilters"
        >
            <div class="space-y-1">
                <Label for="supplier-search">Supplier name</Label
                ><Input
                    class="min-h-11"
                    id="supplier-search"
                    v-model="search"
                    maxlength="100"
                />
            </div>
            <div class="space-y-1">
                <Label for="supplier-status">Status</Label
                ><select
                    id="supplier-status"
                    v-model="status"
                    class="border-input bg-background focus-visible:ring-ring block min-h-11 max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                >
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <Button class="min-h-11" type="submit" variant="outline"
                >Apply filters</Button
            >
        </form>
        <section class="space-y-3" aria-label="Supplier directory">
            <p
                v-if="suppliers.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No suppliers recorded.
            </p>
            <article
                v-for="supplier in suppliers.data"
                :key="supplier.id"
                class="surface-card border-border bg-card space-y-3 border p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">{{ supplier.name }}</h2>
                    <span class="text-muted-foreground text-sm">{{
                        supplier.is_active ? 'Active' : 'Inactive'
                    }}</span>
                </div>
                <p
                    v-if="
                        supplier.contact_name ||
                        supplier.contact_number ||
                        supplier.email
                    "
                    class="text-muted-foreground text-sm wrap-anywhere"
                >
                    {{
                        [
                            supplier.contact_name,
                            supplier.contact_number,
                            supplier.email,
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>
                <InlineEditor
                    label="Edit"
                    :trigger-label="'Edit ' + supplier.name"
                    v-slot="{ reveal }"
                    ><Form
                        @error="reveal"
                        v-bind="SupplierController.update.form(supplier.id)"
                        v-slot="{ errors, processing }"
                        class="grid max-w-full min-w-0 gap-3 sm:grid-cols-2"
                    >
                        <input
                            type="hidden"
                            name="edit_version"
                            :value="supplier.edit_version"
                        />
                        <InputError
                            :message="errors.edit_version"
                            class="sm:col-span-2"
                        />
                        <div class="space-y-1">
                            <Label :for="`name-${supplier.id}`">Name</Label
                            ><Input
                                class="min-h-11"
                                :id="`name-${supplier.id}`"
                                name="name"
                                :default-value="supplier.name"
                                required
                            /><InputError :message="errors.name" />
                        </div>
                        <div class="space-y-1">
                            <Label :for="`contact-${supplier.id}`"
                                >Contact person</Label
                            ><Input
                                class="min-h-11"
                                :id="`contact-${supplier.id}`"
                                name="contact_name"
                                :default-value="supplier.contact_name"
                            /><InputError :message="errors.contact_name" />
                        </div>
                        <div class="space-y-1">
                            <Label :for="`phone-${supplier.id}`">Phone</Label
                            ><Input
                                class="min-h-11"
                                :id="`phone-${supplier.id}`"
                                name="contact_number"
                                :default-value="supplier.contact_number"
                            /><InputError :message="errors.contact_number" />
                        </div>
                        <div class="space-y-1">
                            <Label :for="`email-${supplier.id}`">Email</Label
                            ><Input
                                class="min-h-11"
                                :id="`email-${supplier.id}`"
                                name="email"
                                type="email"
                                :default-value="supplier.email"
                            /><InputError :message="errors.email" />
                        </div>
                        <div class="space-y-1">
                            <Label :for="`active-${supplier.id}`">Status</Label
                            ><select
                                :id="`active-${supplier.id}`"
                                name="is_active"
                                v-model="supplierDrafts[supplier.id].is_active"
                                class="border-input bg-background focus-visible:ring-ring min-h-11 w-full max-w-full min-w-0 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <option value="1">Active</option>
                                <option value="0">Inactive</option></select
                            ><InputError :message="errors.is_active" />
                        </div>
                        <div class="flex items-end">
                            <Button
                                class="min-h-11"
                                type="submit"
                                variant="outline"
                                :disabled="processing"
                                >Save supplier</Button
                            >
                        </div>
                    </Form></InlineEditor
                >
            </article>
            <div
                v-if="suppliers.last_page > 1"
                class="flex justify-between text-sm"
            >
                <Link
                    v-if="suppliers.prev_page_url"
                    :href="suppliers.prev_page_url"
                    class="text-primary underline"
                    >Previous</Link
                ><span v-else></span
                ><Link
                    v-if="suppliers.next_page_url"
                    :href="suppliers.next_page_url"
                    class="text-primary underline"
                    >Next</Link
                >
            </div>
        </section>
    </main>
</template>
