<script setup>
import { Head, Link } from '@inertiajs/vue3';
import {
    edit,
    index,
} from '@/actions/App/Http/Controllers/Administration/BranchController';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

defineProps({ branch: { type: Object, required: true } });
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stores', href: index() },
            { title: 'Store details', href: index() },
        ],
    },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head :title="branch.city" />
        <PageHeader :title="branch.city" :description="branch.name">
            <template #metadata>
                <Badge
                    :variant="branch.is_published ? 'secondary' : 'outline'"
                    >{{
                        branch.is_published ? 'Published' : 'Unpublished'
                    }}</Badge
                >
                <Badge v-if="branch.is_operational" variant="outline"
                    >Orders handled here</Badge
                >
            </template>
            <template #actions>
                <Button as-child
                    ><Link :href="edit(branch.id)">Edit store</Link></Button
                >
                <Button variant="outline" as-child
                    ><Link :href="index()">Back</Link></Button
                >
            </template>
        </PageHeader>

        <dl
            class="surface-panel border-border bg-card grid gap-6 border p-4 sm:grid-cols-2 sm:p-6"
        >
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground text-sm">Address</dt>
                <dd class="mt-1 break-words">
                    {{ branch.address || 'Not yet confirmed' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm">Phone</dt>
                <dd class="mt-1">
                    {{ branch.contact_number || 'Not yet confirmed' }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-muted-foreground text-sm">Email</dt>
                <dd class="mt-1 break-all">
                    {{ branch.email || 'Not yet confirmed' }}
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground text-sm">Store hours</dt>
                <dd class="mt-1 break-words whitespace-pre-wrap">
                    {{ branch.operating_hours || 'Not yet confirmed' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm">Latitude</dt>
                <dd class="mt-1">{{ branch.latitude ?? 'Not provided' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-sm">Longitude</dt>
                <dd class="mt-1">{{ branch.longitude ?? 'Not provided' }}</dd>
            </div>
        </dl>
        <p v-if="!branch.is_operational" class="text-muted-foreground text-sm">
            Orders and stock are managed at Sagay City.
        </p>
    </main>
</template>
