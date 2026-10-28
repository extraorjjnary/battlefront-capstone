<script setup>
import { Button } from '@/components/ui/button';
defineProps({
    open: Boolean,
    savedBuilds: Array,
    comparedBuilds: Array,
    compareUuids: Array,
    componentLabels: Object,
    shareMessage: String,
    startNewBuild: Function,
    loadBuild: Function,
    addBuildToCart: Function,
    duplicateSavedBuild: Function,
    toggleCompare: Function,
    toggleShare: Function,
    deleteSavedBuild: Function,
    findProduct: Function,
    formatPrice: Function,
    buildPrice: Function,
});
</script>
<template>
    <div>
        <section
            v-if="open"
            class="border-border bg-card mb-6 rounded-xl border p-4"
            aria-labelledby="saved-builds-heading"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="saved-builds-heading" class="font-semibold">
                        Saved builds
                    </h2>
                    <p class="text-muted-foreground text-sm">
                        Your builds do not reserve stock.
                    </p>
                </div>
                <Button variant="outline" @click="startNewBuild"
                    >New build</Button
                >
            </div>
            <p
                v-if="savedBuilds.length === 0"
                class="text-muted-foreground mt-4 text-sm"
            >
                No saved builds yet.
            </p>
            <ul v-else class="mt-4 grid gap-3 md:grid-cols-2">
                <li
                    v-for="build in savedBuilds"
                    :key="build.uuid"
                    class="border-border bg-background flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ build.name }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ build.items.length }} components ·
                            {{ build.mode }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="loadBuild(build)"
                            >Resume</Button
                        >
                        <Button size="sm" @click="addBuildToCart(build)"
                            >Add to cart</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="duplicateSavedBuild(build)"
                            >Duplicate</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :aria-pressed="compareUuids.includes(build.uuid)"
                            @click="toggleCompare(build)"
                            >{{
                                compareUuids.includes(build.uuid)
                                    ? 'Remove compare'
                                    : 'Compare'
                            }}</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="toggleShare(build)"
                            >{{
                                build.share_enabled ? 'Stop sharing' : 'Share'
                            }}</Button
                        >
                        <Button
                            size="sm"
                            variant="destructive"
                            @click="deleteSavedBuild(build)"
                            >Delete</Button
                        >
                    </div>
                </li>
            </ul>
            <p
                v-if="shareMessage"
                class="mt-3 text-sm text-emerald-700 dark:text-emerald-300"
                role="status"
            >
                {{ shareMessage }}
            </p>
        </section>

        <section
            v-if="open && comparedBuilds.length"
            class="border-border bg-card mb-6 overflow-x-auto rounded-xl border p-4"
            aria-labelledby="build-comparison-heading"
        >
            <h2 id="build-comparison-heading" class="mb-4 font-semibold">
                Compare saved builds
            </h2>
            <table class="w-full min-w-[36rem] text-left text-sm">
                <thead>
                    <tr>
                        <th class="p-3">Component</th>
                        <th
                            v-for="build in comparedBuilds"
                            :key="build.uuid"
                            class="p-3"
                        >
                            {{ build.name }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-border divide-y">
                    <tr
                        v-for="type in Object.keys(componentLabels)"
                        :key="type"
                    >
                        <th class="p-3 font-medium">
                            {{ componentLabels[type] }}
                        </th>
                        <td
                            v-for="build in comparedBuilds"
                            :key="build.uuid"
                            class="p-3"
                        >
                            {{
                                build.items
                                    .filter(
                                        (item) => item.component_type === type,
                                    )
                                    .map(
                                        (item) =>
                                            `${item.name ?? findProduct(item.product_id)?.name ?? 'Unavailable'} × ${item.quantity}`,
                                    )
                                    .join(', ') || 'Not selected'
                            }}
                        </td>
                    </tr>
                    <tr>
                        <th class="p-3 font-medium">Estimated current total</th>
                        <td
                            v-for="build in comparedBuilds"
                            :key="build.uuid"
                            class="p-3"
                        >
                            {{ formatPrice(buildPrice(build)) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
