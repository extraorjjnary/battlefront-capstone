<script setup>
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import CustomerReturnController from '@/actions/App/Http/Controllers/Administration/CustomerReturnController';
import AdministrationEvidenceController from '@/actions/App/Http/Controllers/Administration/AdministrationEvidenceController';
import OrderController from '@/actions/App/Http/Controllers/Administration/OrderController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { reactive } from 'vue';

defineProps({ returns: Object, selected_order: Object, suppliers: Array, refundPayments: Object, evidence: Object, paymentEvidence: Object, selected_case: { type: Number, default: null } });
const page = usePage();
const decisions = reactive({});
defineOptions({ layout: { breadcrumbs: [{ title: 'Customer returns', href: CustomerReturnController.index() }] } });
</script>

<template>
    <Head title="Customer returns" />
    <main class="mx-auto flex w-full max-w-5xl flex-col gap-7 p-6 lg:p-10">
        <p v-if="selected_case" class="text-sm">Showing customer return #{{ selected_case }} · <Link :href="CustomerReturnController.index()" class="text-primary underline">Show all</Link></p>
        <div><p class="text-primary text-xs font-semibold tracking-widest uppercase">Order accountability</p><h1 class="mt-2 text-2xl font-bold">Customer returns</h1><p class="text-muted-foreground mt-2 text-sm">Receive items from completed system orders. Record stock disposition and any externally paid refund separately.</p></div>
        <section class="border-border bg-card space-y-4 border p-5">
            <h2 class="text-lg font-semibold">Receive returned items</h2>
            <Form v-bind="CustomerReturnController.index.form()" class="flex flex-wrap items-end gap-3"><div class="space-y-2"><Label for="lookup-order">Order ID</Label><Input id="lookup-order" name="order_id" type="number" min="1" :default-value="selected_order?.id" required /></div><Button type="submit" variant="outline">Find completed order</Button></Form>
            <Form v-if="selected_order" v-bind="CustomerReturnController.store.form()" v-slot="{ errors, processing }" class="grid gap-4 sm:grid-cols-2">
                <p class="sm:col-span-2 text-sm">Order {{ selected_order.reference }}</p>
                <div class="space-y-2"><Label for="return-item">Purchased item</Label><select id="return-item" name="order_item_id" required class="border-border bg-background w-full border p-2"><option value="">Select item</option><option v-for="item in selected_order.items" :key="item.id" :value="item.id">{{ item.product }} · {{ item.quantity }} bought · ₱{{ item.price_at_time }} each</option></select><InputError :message="errors.order_item_id" /></div>
                <div class="space-y-2"><Label for="return-units">Units received</Label><Input id="return-units" name="quantity" type="number" min="1" required /><InputError :message="errors.quantity" /></div>
                <div class="space-y-2"><Label for="return-condition">Condition</Label><select id="return-condition" name="condition" class="border-border bg-background w-full border p-2"><option value="sealed">Sealed</option><option value="opened">Opened</option><option value="damaged">Damaged</option></select></div>
                <div class="space-y-2"><Label for="return-reason">Reason</Label><Input id="return-reason" name="reason" required minlength="5" maxlength="2000" /><InputError :message="errors.reason" /></div>
                <Button type="submit" :disabled="processing">Receive into quarantine</Button>
            </Form>
            <p v-else class="text-muted-foreground text-sm">Find a completed order to select its purchased items.</p>
        </section>
        <section class="space-y-3" aria-label="Return cases">
            <p v-if="returns.data.length === 0" class="text-muted-foreground text-sm">No customer returns recorded.</p>
            <article v-for="entry in returns.data" :key="entry.id" class="border-border bg-card space-y-3 border p-5">
                <div class="flex flex-wrap justify-between gap-2"><div><h2 class="font-semibold">{{ entry.product_name }} · {{ entry.quantity }} units</h2><p class="text-muted-foreground text-sm">Order <Link :href="OrderController.show(entry.purchase_order_id)" class="text-primary underline">#{{ entry.purchase_order_id }}</Link> · {{ entry.condition }} · Received by {{ entry.requester_name }}</p><p class="mt-1 text-sm">{{ entry.reason }}</p></div><span class="text-sm capitalize">{{ entry.status.replaceAll('_', ' ') }}</span></div>
                <p class="text-sm">Held in quarantine for this case: {{ entry.held_quantity }} units</p>
                <p v-if="entry.decision_reason" class="text-sm">Decision reason: {{ entry.decision_reason }}</p>
                <div v-if="evidence[entry.id]?.length" class="flex flex-wrap gap-3 text-sm"><a v-for="file in evidence[entry.id]" :key="file.id" :href="AdministrationEvidenceController.show(file.id).url" class="text-primary underline">{{ file.original_name }}</a></div>
                <Form v-if="(evidence[entry.id]?.length ?? 0) < 5" v-bind="AdministrationEvidenceController.store.form({ subjectType: 'customer_return', subjectId: entry.id })" v-slot="{ errors, processing }" class="flex flex-wrap items-end gap-2"><label class="text-sm">Inspection evidence<input name="files[]" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="mt-1 block" /></label><Button type="submit" variant="outline" :disabled="processing">Add evidence</Button><InputError :message="errors.files || errors['files.0']" /></Form>
                <Form v-if="entry.status === 'pending' && entry.requester_id !== page.props.auth.user.id && page.props.auth.can?.approveReturns" v-bind="CustomerReturnController.decide.form(entry.id)" v-slot="{ errors, processing }" class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1"><Label>Decision</Label><select v-model="decisions[entry.id]" name="decision" class="border-border bg-background w-full border p-2"><option value="accept">Accept</option><option value="reject">Reject</option></select></div>
                    <div class="space-y-1"><Label>Decision reason</Label><Input name="reason" required minlength="5" maxlength="2000" /><InputError :message="errors.reason" /></div>
                    <div v-if="decisions[entry.id] !== 'reject'" class="space-y-1"><Label>Stock disposition</Label><select name="disposition" class="border-border bg-background w-full border p-2"><option value="restock">Restock</option><option value="write_off">Write off</option><option value="supplier_return">Return to supplier</option></select></div>
                    <div v-if="decisions[entry.id] !== 'reject'" class="space-y-1"><Label :for="`refund-${entry.id}`">Approved refund (₱)</Label><Input :id="`refund-${entry.id}`" name="approved_amount" type="number" min="0" step="0.01" value="0" /><InputError :message="errors.approved_amount" /></div>
                    <div v-if="decisions[entry.id] !== 'reject'" class="space-y-1"><Label>Supplier, if needed</Label><select name="supplier_id" class="border-border bg-background w-full border p-2"><option value="">Select supplier</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select><InputError :message="errors.supplier_id" /></div>
                    <Button type="submit" :disabled="processing">Record decision</Button><InputError :message="errors.decision" />
                </Form>
                <Form v-if="entry.status === 'rejected_pending_handover'" v-bind="CustomerReturnController.handBack.form(entry.id)" v-slot="{ errors, processing }" class="flex gap-2"><Button type="submit" :disabled="processing">Confirm handover to customer</Button><InputError :message="errors.handover" /></Form>
                <div v-if="entry.refund_id" class="border-border bg-muted/40 space-y-2 border p-3 text-sm"><p>Approved refund ₱{{ entry.approved_amount }} · Staff recorded payments ₱{{ entry.paid_amount }} · Outstanding ₱{{ (Number(entry.approved_amount) - Number(entry.paid_amount)).toFixed(2) }}</p>
                    <div v-for="payment in refundPayments[entry.refund_id] ?? []" :key="payment.id" class="border-border space-y-2 border p-3">
                        <p>₱{{ payment.amount }} · {{ payment.payment_method }} · {{ payment.external_reference }} · {{ payment.review?.decision ?? 'Awaiting reconciliation' }}</p>
                        <div v-if="paymentEvidence[payment.id]?.length" class="flex flex-wrap gap-3"><a v-for="file in paymentEvidence[payment.id]" :key="file.id" :href="AdministrationEvidenceController.show(file.id).url" class="text-primary underline">{{ file.original_name }}</a></div>
                        <Form v-if="payment.recorded_by === page.props.auth.user.id && (paymentEvidence[payment.id]?.length ?? 0) < 5" v-bind="AdministrationEvidenceController.store.form({ subjectType: 'refund_payment', subjectId: payment.id })" v-slot="{ errors, processing }" class="flex flex-wrap items-end gap-2"><label>Payment evidence<input name="files[]" type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="mt-1 block" /></label><Button type="submit" variant="outline" :disabled="processing">Add evidence</Button><InputError :message="errors.files || errors['files.0']" /></Form>
                        <p v-if="payment.review?.reason" class="text-muted-foreground">Review: {{ payment.review.reason }}</p>
                        <Form v-if="page.props.auth.can?.reconcileReturns && payment.recorded_by !== page.props.auth.user.id && payment.review?.decision !== 'verified'" v-bind="CustomerReturnController.reviewPayment.form(payment.id)" v-slot="{ errors, processing }" class="grid gap-2 sm:grid-cols-2">
                            <select name="decision" class="border-border bg-background border p-2"><option value="verified">Verify payment record</option><option value="disputed">Dispute payment record</option></select>
                            <Input name="reason" required minlength="5" maxlength="2000" placeholder="Reconciliation reason" />
                            <Button type="submit" :disabled="processing">Record review</Button><InputError :message="errors.decision || errors.reason" />
                        </Form>
                    </div>
                    <Form v-if="Number(entry.paid_amount) < Number(entry.approved_amount)" v-bind="CustomerReturnController.recordPayment.form(entry.refund_id)" v-slot="{ errors, processing }" class="grid gap-2 sm:grid-cols-3"><Input name="amount" type="number" min="0.01" step="0.01" placeholder="Amount paid" required /><select name="payment_method" class="border-border bg-background border p-2"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select><Input name="external_reference" placeholder="Receipt or transfer reference" required /><Button type="submit" :disabled="processing">Record payment</Button><InputError :message="errors.amount || errors.external_reference" /></Form>
                </div>
            </article>
            <div v-if="returns.last_page > 1" class="flex justify-between text-sm"><Link v-if="returns.prev_page_url" :href="returns.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="returns.next_page_url" :href="returns.next_page_url" class="text-primary underline">Next</Link></div>
        </section>
    </main>
</template>
