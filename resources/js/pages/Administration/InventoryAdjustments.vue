<script setup>
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import InventoryAdjustmentController from '@/actions/App/Http/Controllers/Administration/InventoryAdjustmentController';
import InventoryController from '@/actions/App/Http/Controllers/Administration/InventoryController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps({ requests: { type: Object, required: true } });
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
        </article>
        <div v-if="requests.last_page > 1" class="flex justify-between text-sm">
            <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span>
            <Link v-if="requests.next_page_url" :href="requests.next_page_url" class="text-primary underline">Next</Link>
        </div>
    </main>
</template>
