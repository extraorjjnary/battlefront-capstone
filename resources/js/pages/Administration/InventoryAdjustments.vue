<script setup>
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import InventoryAdjustmentController from '@/actions/App/Http/Controllers/Administration/InventoryAdjustmentController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps({ requests: { type: Object, required: true }, selected_case: { type: Number, default: null } });
const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Stock requests', href: InventoryAdjustmentController.index() }],
    },
});
</script>

<template>
    <Head title="Stock requests" />
    <main class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6 lg:p-10">
        <p v-if="selected_case" class="text-sm">Showing stock request #{{ selected_case }} · <Link :href="InventoryAdjustmentController.index()" class="text-primary underline">Show all</Link></p>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-primary text-xs font-semibold tracking-widest uppercase">Inventory control</p>
                <h1 class="mt-2 text-2xl font-bold">Stock requests</h1>
            </div>
            <Link :href="InventoryController.index()" class="text-primary underline">Back to inventory</Link>
        </div>
        <p v-if="requests.data.length === 0" class="border-border bg-card border p-6 text-sm text-muted-foreground">No stock requests yet.</p>
        <article v-for="entry in requests.data" :key="entry.id" class="border-border bg-card space-y-4 border p-5">
            <div class="flex flex-wrap justify-between gap-3">
                <div>
                    <h2 class="font-semibold">{{ entry.product_name }}</h2>
                    <p class="text-muted-foreground text-sm">{{ entry.requester_name }} requested {{ entry.observed_quantity }} → {{ entry.target_quantity }} units</p>
                    <p class="mt-2 text-sm">{{ entry.reason }}</p>
                    <p v-if="entry.replaces_request_id" class="text-muted-foreground text-sm">Replaces request #{{ entry.replaces_request_id }}</p>
                    <p v-if="entry.replacement_id" class="text-muted-foreground text-sm">Replaced by request #{{ entry.replacement_id }}</p>
                    <p v-if="entry.decision_reason" class="text-muted-foreground text-sm">Decision: {{ entry.decision_reason }}</p>
                </div>
                <span class="text-sm capitalize">{{ entry.status }}</span>
            </div>
            <Form v-if="entry.status === 'pending' && entry.requester_id !== page.props.auth.user.id && page.props.auth.can?.approveInventory" v-bind="InventoryAdjustmentController.decide.form(entry.id)" v-slot="{ errors, processing }" class="space-y-3">
                <label :for="`decision-reason-${entry.id}`" class="block text-sm">Decision reason</label>
                <Input :id="`decision-reason-${entry.id}`" name="reason" required minlength="5" maxlength="2000" />
                <InputError :message="errors.reason || errors.decision" />
                <div class="flex gap-2">
                    <Button type="submit" name="decision" value="approve" :disabled="processing">Approve</Button>
                    <Button type="submit" name="decision" value="reject" variant="outline" :disabled="processing">Reject</Button>
                </div>
            </Form>
            <Form v-if="entry.status === 'pending' && entry.requester_id === page.props.auth.user.id" v-bind="InventoryAdjustmentController.withdraw.form(entry.id)" v-slot="{ errors, processing }" class="flex flex-wrap items-end gap-2">
                <label class="text-sm">Withdrawal reason<Input name="reason" required minlength="5" maxlength="2000" /></label>
                <Button type="submit" variant="outline" :disabled="processing">Withdraw request</Button>
                <InputError :message="errors.reason || errors.withdrawal" />
            </Form>
            <Form v-if="entry.status === 'withdrawn' && !entry.replacement_id && entry.requester_id === page.props.auth.user.id" v-bind="InventoryAdjustmentController.replace.form(entry.id)" v-slot="{ errors, processing }" class="grid gap-2 sm:grid-cols-2">
                <label class="text-sm">Replacement quantity<Input name="quantity" type="number" min="0" required :default-value="entry.target_quantity" /></label>
                <label v-if="entry.initial_reorder_level !== null" class="text-sm">Initial reorder level<Input name="reorder_level" type="number" min="0" :default-value="entry.initial_reorder_level" /></label>
                <label class="text-sm sm:col-span-2">New request reason<Input name="reason" required minlength="5" maxlength="2000" /></label>
                <Button type="submit" :disabled="processing">Submit replacement</Button>
                <InputError :message="errors.quantity || errors.reason || errors.replacement" />
            </Form>
        </article>
        <div v-if="requests.last_page > 1" class="flex justify-between text-sm">
            <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span>
            <Link v-if="requests.next_page_url" :href="requests.next_page_url" class="text-primary underline">Next</Link>
        </div>
    </main>
</template>
