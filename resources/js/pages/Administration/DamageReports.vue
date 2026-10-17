<script setup>
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import DamageReportController from '@/actions/App/Http/Controllers/Administration/DamageReportController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps({ reports: Object, products: Array, suppliers: Array });
const page = usePage();
defineOptions({ layout: { breadcrumbs: [{ title: 'Damage reports', href: DamageReportController.index() }] } });
</script>

<template>
    <Head title="Damage reports" />
    <main class="mx-auto flex w-full max-w-5xl flex-col gap-7 p-6 lg:p-10">
        <div><p class="text-primary text-xs font-semibold tracking-widest uppercase">Inventory control</p><h1 class="mt-2 text-2xl font-bold">Damaged stock</h1><p class="text-muted-foreground mt-2 text-sm">Reported units leave sellable stock immediately and remain in quarantine until disposition.</p></div>
        <Form v-bind="DamageReportController.store.form()" v-slot="{ errors, processing }" class="border-border bg-card grid gap-4 border p-5 sm:grid-cols-2">
            <div class="space-y-2"><Label for="damage-product">Product</Label><select id="damage-product" name="product_id" required class="border-border bg-background w-full border p-2"><option value="">Select product</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option></select><InputError :message="errors.product_id" /></div>
            <div class="space-y-2"><Label for="damage-quantity">Damaged units</Label><Input id="damage-quantity" name="quantity" type="number" min="1" required /><InputError :message="errors.quantity" /></div>
            <div class="space-y-2 sm:col-span-2"><Label for="damage-reason">Reason</Label><Input id="damage-reason" name="reason" required minlength="5" maxlength="2000" /><InputError :message="errors.reason" /></div>
            <Button type="submit" :disabled="processing">Quarantine and report</Button>
        </Form>
        <section class="space-y-3" aria-label="Damage cases">
            <p v-if="reports.data.length === 0" class="text-muted-foreground text-sm">No damage reports.</p>
            <article v-for="report in reports.data" :key="report.id" class="border-border bg-card space-y-3 border p-5">
                <div class="flex flex-wrap justify-between gap-2"><div><h2 class="font-semibold">{{ report.product_name }} · {{ report.quantity }} units</h2><p class="text-muted-foreground text-sm">Reported by {{ report.requester_name }}: {{ report.reason }}</p></div><span class="text-sm capitalize">{{ report.disposition ?? report.status }}</span></div>
                <Form v-if="report.status === 'pending' && report.requester_id !== page.props.auth.user.id && page.props.auth.can?.approveReturns" v-bind="DamageReportController.decide.form(report.id)" v-slot="{ errors, processing }" class="grid gap-3 sm:grid-cols-3">
                    <select name="disposition" required class="border-border bg-background border p-2"><option value="release">Release to sale</option><option value="write_off">Write off</option><option value="supplier_return">Return to supplier</option></select>
                    <select name="supplier_id" class="border-border bg-background border p-2"><option value="">Select supplier if returning</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option></select>
                    <Button type="submit" :disabled="processing">Record decision</Button>
                    <InputError :message="errors.decision || errors.supplier_id" />
                </Form>
            </article>
        </section>
        <div v-if="reports.last_page > 1" class="flex justify-between text-sm"><Link v-if="reports.prev_page_url" :href="reports.prev_page_url" class="text-primary underline">Previous</Link><span v-else></span><Link v-if="reports.next_page_url" :href="reports.next_page_url" class="text-primary underline">Next</Link></div>
    </main>
</template>
