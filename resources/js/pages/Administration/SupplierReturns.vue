<script setup>
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import SupplierReturnController from '@/actions/App/Http/Controllers/Administration/SupplierReturnController';
import SupplierController from '@/actions/App/Http/Controllers/Administration/SupplierController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps({ returns: Object, products: Array, suppliers: Array, selected_case: { type: Number, default: null } });
const page = usePage();
defineOptions({ layout: { breadcrumbs: [{ title: 'Supplier returns', href: SupplierReturnController.index() }] } });
</script>

<template>
    <Head title="Supplier returns" />
    <main class="mx-auto flex w-full max-w-5xl flex-col gap-7 p-6 lg:p-10">
        <p v-if="selected_case" class="text-sm">Showing supplier return #{{ selected_case }} · <Link :href="SupplierReturnController.index()" class="text-primary underline">Show all</Link></p>
        <div class="flex flex-wrap justify-between gap-3"><div><p class="text-primary text-xs font-semibold tracking-widest uppercase">Inventory control</p><h1 class="mt-2 text-2xl font-bold">Supplier returns</h1></div><Link :href="SupplierController.index()" class="text-primary underline">Suppliers</Link></div>
        <Form v-if="page.props.auth.can?.requestReturns" v-bind="SupplierReturnController.store.form()" v-slot="{ errors, processing }" class="border-border bg-card grid gap-4 border p-5 sm:grid-cols-2">
            <h2 class="sm:col-span-2 text-lg font-semibold">Request a return</h2>
            <div class="space-y-2"><Label for="return-supplier">Supplier</Label><select id="return-supplier" name="supplier_id" required class="border-border bg-background w-full border p-2"><option value="">Select supplier</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select><InputError :message="errors.supplier_id" /></div>
            <div class="space-y-2"><Label for="return-product">Product</Label><select id="return-product" name="product_id" required class="border-border bg-background w-full border p-2"><option value="">Select product</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option></select><InputError :message="errors.product_id" /></div>
            <div class="space-y-2"><Label for="return-quantity">Units</Label><Input id="return-quantity" name="quantity" type="number" min="1" required /><InputError :message="errors.quantity" /></div>
            <div class="space-y-2"><Label for="return-reason">Reason</Label><Input id="return-reason" name="reason" required minlength="5" maxlength="2000" /><InputError :message="errors.reason" /></div>
            <Button type="submit" :disabled="processing">Reserve stock and request</Button>
        </Form>
        <section class="space-y-3" aria-label="Supplier return cases">
            <p v-if="returns.data.length === 0" class="text-muted-foreground text-sm">No supplier returns.</p>
            <article v-for="entry in returns.data" :key="entry.id" class="border-border bg-card space-y-3 border p-5">
                <div class="flex flex-wrap justify-between gap-2"><div><h2 class="font-semibold">{{ entry.product_name }} · {{ entry.quantity }} units</h2><p class="text-muted-foreground text-sm">{{ entry.supplier_name }} · Requested by {{ entry.requester_name }}</p><p class="mt-1 text-sm">{{ entry.reason }}</p><p v-if="entry.dispatch_reference" class="text-muted-foreground text-sm">Dispatch: {{ entry.dispatch_reference }}</p><p v-if="entry.acknowledgement_reference" class="text-muted-foreground text-sm">Acknowledgment: {{ entry.acknowledgement_reference }}</p><p v-if="entry.external_reference" class="text-muted-foreground text-sm">Legacy reference: {{ entry.external_reference }}</p></div><span class="text-sm capitalize">{{ entry.status.replaceAll('_', ' ') }}</span></div>
                <p class="text-sm">Held in quarantine: {{ entry.held_quantity }} units · Origin: {{ entry.damage_report_id ? `damage report #${entry.damage_report_id}` : entry.customer_return_item_id ? `customer return item #${entry.customer_return_item_id}` : 'direct supplier return' }}</p>
                <Form v-if="entry.status === 'pending' && entry.requester_id !== page.props.auth.user.id && page.props.auth.can?.approveReturns" v-bind="SupplierReturnController.decide.form(entry.id)" v-slot="{ errors, processing }" class="flex flex-wrap items-end gap-2"><label class="text-sm">Decision reason<Input name="reason" required minlength="5" maxlength="2000" /></label><Button type="submit" name="decision" value="approve" :disabled="processing">Approve</Button><Button type="submit" name="decision" value="reject" variant="outline" :disabled="processing">Reject</Button><InputError :message="errors.decision || errors.reason" /></Form>
                <Form v-if="entry.status === 'approved' && page.props.auth.can?.dispatchReturns" v-bind="SupplierReturnController.dispatch.form(entry.id)" v-slot="{ errors, processing }" class="flex flex-wrap gap-2"><Input name="external_reference" placeholder="Dispatch reference" required /><Button type="submit" :disabled="processing">Record dispatch</Button><InputError :message="errors.dispatch || errors.external_reference" /></Form>
                <Form v-if="entry.status === 'dispatched' && page.props.auth.can?.approveReturns" v-bind="SupplierReturnController.acknowledge.form(entry.id)" v-slot="{ errors, processing }" class="flex flex-wrap gap-2"><Input name="external_reference" placeholder="Supplier acknowledgement" required /><Button type="submit" :disabled="processing">Close return</Button><InputError :message="errors.acknowledgement" /></Form>
                <Form v-if="entry.status === 'rejected_follow_up' && page.props.auth.can?.approveReturns && entry.requester_id !== page.props.auth.user.id && entry.reviewer_id !== page.props.auth.user.id" v-bind="SupplierReturnController.followUp.form(entry.id)" v-slot="{ errors, processing }" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">Follow-up decision<select name="decision" required class="border-border bg-background mt-1 block w-full border p-2"><option value="release">Release after inspection</option><option value="write_off">Write off</option><option value="retry">Request another supplier return</option></select></label>
                    <label class="text-sm">Supplier for retry<select name="supplier_id" class="border-border bg-background mt-1 block w-full border p-2"><option value="">Select if retrying</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select></label>
                    <label class="text-sm sm:col-span-2">Reason<Input name="reason" required minlength="5" maxlength="2000" /></label>
                    <Button type="submit" :disabled="processing">Record follow-up</Button><InputError :message="errors.decision || errors.reason || errors.supplier_id" />
                </Form>
                <p v-if="entry.resolution_outcome" class="text-sm">Supplier resolution: {{ entry.resolution_outcome.replaceAll('_', ' ') }} · {{ entry.resolution_reference }} · {{ entry.resolution_notes }}</p>
                <Form v-if="entry.status === 'closed' && !entry.resolution_outcome && page.props.auth.can?.resolveReturns" v-bind="SupplierReturnController.resolve.form(entry.id)" v-slot="{ errors, processing }" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">Supplier outcome<select name="outcome" required class="border-border bg-background mt-1 block w-full border p-2"><option value="replacement">Replacement</option><option value="credit">Credit</option><option value="refund">Refund</option><option value="rejected">Rejected</option></select></label>
                    <label class="text-sm">Supplier reference<Input name="external_reference" required maxlength="255" /></label>
                    <label class="text-sm sm:col-span-2">Resolution notes<Input name="notes" required minlength="5" maxlength="2000" /></label>
                    <Button type="submit" :disabled="processing">Record supplier outcome</Button><InputError :message="errors.outcome || errors.external_reference || errors.notes" />
                </Form>
            </article>
            <div v-if="returns.last_page > 1" class="flex justify-between text-sm"><Link v-if="returns.prev_page_url" :href="returns.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="returns.next_page_url" :href="returns.next_page_url" class="text-primary underline">Next</Link></div>
        </section>
    </main>
</template>
