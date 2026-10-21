<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    headingId: { type: String, default: null },
});
</script>

<template>
    <header
        data-page-header
        class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
    >
        <div class="min-w-0 space-y-2">
            <h1
                :id="headingId"
                class="text-2xl font-bold tracking-tight wrap-anywhere sm:text-3xl"
            >
                {{ title }}
            </h1>
            <p
                v-if="description"
                class="text-muted-foreground max-w-2xl text-sm leading-6"
            >
                {{ description }}
            </p>
            <div
                v-if="$slots.metadata"
                class="text-muted-foreground flex flex-wrap items-center gap-2 text-sm"
            >
                <slot name="metadata" />
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 flex-wrap items-center gap-2 sm:max-w-[50%]"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
