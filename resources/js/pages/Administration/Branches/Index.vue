<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { MapPin, Plus } from '@lucide/vue';
import {
    create,
    edit,
    index,
    show,
} from '@/actions/App/Http/Controllers/Administration/BranchController';
import CatalogPagination from '@/components/CatalogPagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

defineProps({ branches: { type: Object, required: true } });
defineOptions({
    layout: { breadcrumbs: [{ title: 'Stores', href: index() }] },
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 wrap-anywhere sm:p-6 lg:p-8"
    >
        <Head title="Stores" />
        <PageHeader
            title="Stores"
            description="Store details shown to customers."
        >
            <template #actions>
                <Button as-child
                    ><Link :href="create()"><Plus />Add store</Link></Button
                >
            </template>
        </PageHeader>

        <section aria-label="Branch records">
            <p class="text-muted-foreground mb-3 text-sm">
                {{ branches.total }}
                {{ branches.total === 1 ? 'store' : 'stores' }}
            </p>
            <div
                v-if="!branches.data.length"
                class="surface-panel border-border bg-card border p-8 text-center"
            >
                <MapPin
                    class="text-muted-foreground mx-auto size-7"
                    aria-hidden="true"
                />
                <p class="mt-3 font-medium">No stores available</p>
                <p class="text-muted-foreground mt-1 text-sm">
                    Add a store to enter its details.
                </p>
            </div>
            <div
                v-else
                class="surface-panel border-border bg-card divide-border divide-y border"
            >
                <article
                    v-for="branch in branches.data"
                    :key="branch.id"
                    class="grid gap-4 p-4 sm:p-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold break-words">
                                <Link
                                    :href="show(branch.id)"
                                    class="underline-offset-4 hover:underline"
                                    >{{ branch.city }}</Link
                                >
                            </h2>
                            <Badge
                                :variant="
                                    branch.is_published
                                        ? 'secondary'
                                        : 'outline'
                                "
                                >{{
                                    branch.is_published
                                        ? 'Published'
                                        : 'Unpublished'
                                }}</Badge
                            >
                            <Badge
                                v-if="branch.is_operational"
                                variant="outline"
                                >Orders handled here</Badge
                            >
                        </div>
                        <p
                            class="text-muted-foreground mt-1 text-sm break-words"
                        >
                            {{ branch.name }}
                        </p>
                        <p class="mt-2 text-sm break-words">
                            {{ branch.address || 'Address not yet confirmed' }}
                        </p>
                        <p
                            v-if="branch.contact_number || branch.email"
                            class="text-muted-foreground mt-1 text-sm break-all"
                        >
                            {{
                                [branch.contact_number, branch.email]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" as-child
                            ><Link :href="show(branch.id)">View</Link></Button
                        >
                        <Button variant="outline" size="sm" as-child
                            ><Link :href="edit(branch.id)">Edit</Link></Button
                        >
                    </div>
                </article>
            </div>
            <CatalogPagination
                :current-page="branches.current_page"
                :last-page="branches.last_page"
                :route="index"
                label="Branch pages"
            />
        </section>
    </main>
</template>
