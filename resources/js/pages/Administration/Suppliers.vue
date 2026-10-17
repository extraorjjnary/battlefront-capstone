<script setup>
import { Form, Head, Link } from '@inertiajs/vue3';
import SupplierController from '@/actions/App/Http/Controllers/Administration/SupplierController';
import SupplierReturnController from '@/actions/App/Http/Controllers/Administration/SupplierReturnController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps({ suppliers: Object });
defineOptions({ layout: { breadcrumbs: [{ title: 'Suppliers', href: SupplierController.index() }] } });
</script>

<template>
    <Head title="Suppliers" />
    <main class="mx-auto flex w-full max-w-5xl flex-col gap-7 p-6 lg:p-10">
        <div class="flex flex-wrap justify-between gap-3"><div><p class="text-primary text-xs font-semibold tracking-widest uppercase">Inventory control</p><h1 class="mt-2 text-2xl font-bold">Suppliers</h1></div><Link :href="SupplierReturnController.index()" class="text-primary underline">Supplier returns</Link></div>
        <Form v-bind="SupplierController.store.form()" v-slot="{ errors, processing }" class="border-border bg-card grid gap-4 border p-5 sm:grid-cols-2">
            <h2 class="sm:col-span-2 text-lg font-semibold">Add supplier</h2>
            <div class="space-y-2"><Label for="supplier-name">Name</Label><Input id="supplier-name" name="name" required /><InputError :message="errors.name" /></div>
            <div class="space-y-2"><Label for="supplier-contact">Contact person</Label><Input id="supplier-contact" name="contact_name" /></div>
            <div class="space-y-2"><Label for="supplier-number">Phone</Label><Input id="supplier-number" name="contact_number" /></div>
            <div class="space-y-2"><Label for="supplier-email">Email</Label><Input id="supplier-email" name="email" type="email" /><InputError :message="errors.email" /></div>
            <Button type="submit" :disabled="processing">Add supplier</Button>
        </Form>
        <section class="space-y-3" aria-label="Supplier directory">
            <p v-if="suppliers.data.length === 0" class="text-muted-foreground text-sm">No suppliers recorded.</p>
            <article v-for="supplier in suppliers.data" :key="supplier.id" class="border-border bg-card space-y-3 border p-5">
                <h2 class="font-semibold">{{ supplier.name }}</h2>
                <Form v-bind="SupplierController.update.form(supplier.id)" v-slot="{ errors, processing }" class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1"><Label :for="`name-${supplier.id}`">Name</Label><Input :id="`name-${supplier.id}`" name="name" :default-value="supplier.name" required /><InputError :message="errors.name" /></div>
                    <div class="space-y-1"><Label :for="`contact-${supplier.id}`">Contact person</Label><Input :id="`contact-${supplier.id}`" name="contact_name" :default-value="supplier.contact_name" /></div>
                    <div class="space-y-1"><Label :for="`phone-${supplier.id}`">Phone</Label><Input :id="`phone-${supplier.id}`" name="contact_number" :default-value="supplier.contact_number" /></div>
                    <div class="space-y-1"><Label :for="`email-${supplier.id}`">Email</Label><Input :id="`email-${supplier.id}`" name="email" type="email" :default-value="supplier.email" /></div>
                    <div class="space-y-1"><Label :for="`active-${supplier.id}`">Status</Label><select :id="`active-${supplier.id}`" name="is_active" :value="supplier.is_active ? '1' : '0'" class="border-border bg-background w-full border p-2"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                    <div class="flex items-end"><Button type="submit" variant="outline" :disabled="processing">Save supplier</Button></div>
                </Form>
            </article>
            <div v-if="suppliers.last_page > 1" class="flex justify-between text-sm"><Link v-if="suppliers.prev_page_url" :href="suppliers.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="suppliers.next_page_url" :href="suppliers.next_page_url" class="text-primary underline">Next</Link></div>
        </section>
    </main>
</template>
